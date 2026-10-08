<?php

namespace App\Services;

use App\Models\Transactions;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bachs — the fallback card gateway.
 *
 * ## Why this exists
 *
 * Paystack has to be reachable for a card payment to start at all, and when it
 * is not — a missing key, an expired credential, their API down — the customer
 * is left with a failed funding attempt and no way to pay. Bachs is the second
 * rail: the same naira card charge, started through a different gateway, so a
 * Paystack outage costs a retry rather than the sale.
 *
 * It is a *fallback*, never a choice the customer makes. The funding form still
 * offers "Card"; `WalletController::initiateCardPayment()` decides which
 * gateway serves it, and records the decision on the transaction.
 *
 * ## API shape (docs.bachs.io)
 *
 *   POST {base}/v1/checkout-sessions   -> { checkout_id, checkout_url, ... }
 *   GET  {base}/v1/payments/{charge}   -> the payment object
 *
 * Money is always a decimal string at the currency's precision — "5000.00",
 * never minor units. That is the opposite of Paystack, which takes integer
 * kobo; `Money` bridges the two and the conversion is asserted in tests.
 *
 * ## What it deliberately does not do
 *
 * There is no `balance()` and no dedicated-virtual-account support. Bank
 * transfer funding stays on Paystack: Bachs virtual accounts are a different
 * product with its own onboarding, and the funding page already offers Bank
 * transfer as a separate method that does not depend on cards working.
 */
class BachsService
{
    public const GATEWAY = 'bachs';

    public function __construct(
        private readonly PaystackService $paystack,
    ) {
    }

    /**
     * Is the fallback switched on *and* usable?
     *
     * Both halves matter: `enabled` is a deliberate switch so the fallback does
     * not surprise anyone by routing live money, and a missing key means every
     * attempt would fail anyway. Callers use this rather than checking the flag
     * alone.
     */
    public function isConfigured(): bool
    {
        return $this->enabled() && ! empty($this->secretKey());
    }

    private function enabled(): bool
    {
        $value = config('services.bachs.enabled', false);

        // `env()` hands back the *string* "false", which is truthy in PHP, so
        // casting is not enough — this is the classic .env boolean trap.
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function secretKey(): string
    {
        return trim((string) config('services.bachs.secret_key'));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.bachs.base_url', 'https://sandbox-api.bachs.io'), '/');
    }

    private function paymentMethodType(): string
    {
        return (string) config('services.bachs.payment_method_type', 'NGN_CARD');
    }

    private function client()
    {
        return Http::withToken($this->secretKey())
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * Create a checkout session and return the hosted URL.
     *
     * @throws RuntimeException when Bachs is not configured or refuses the request.
     */
    public function initialize(Transactions $transaction, \App\Models\User $user): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Bachs is not configured.');
        }

        $reference = $transaction->gatewayReference();

        $response = $this->client()->post($this->baseUrl() . '/v1/checkout-sessions', [
            /*
             * Raw amount pricing: no catalog product needed, because a wallet
             * top-up is an arbitrary amount decided at order time. NGN as the
             * pricing currency means the customer pays in naira and we are not
             * exposed to an FX rate moving between the quote and the charge —
             * `currency_options` is deliberately NOT used, since pinning an
             * exact naira figure that Bachs then has to convert *into* would
             * invite exactly the amount mismatch `settle()` refuses.
             */
            'pricing' => [
                'currency' => Money::CURRENCY,
                'amount' => $this->naira($transaction->total_amount),
            ],
            'customer' => [
                'email' => $user->email,
                'name' => $this->customerName($user),
            ],
            'payment_method_types' => [$this->paymentMethodType()],
            'success_url' => $this->callbackUrl(),
            'cancel_url' => route('wallet.fund'),
            // Bachs requires uniqueness per organization. Our UUID is already
            // the canonical gateway reference, so using it here means the
            // webhook can be matched to the row without a lookup table.
            'reference' => $reference,
            'metadata' => [
                'transaction_id' => (string) $transaction->id,
                'display_reference' => (string) $transaction->reference,
                'user_id' => (string) $user->id,
            ],
            // Sessions are short-lived on purpose: an abandoned Bachs checkout
            // is not something to keep alive for an hour while the customer
            // waits on the funding page.
            'expires_in_minutes' => 60,
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['checkout_url'])) {
            throw new RuntimeException(
                'Bachs rejected the request: ' . ($body['message'] ?? $body['error_code'] ?? ('HTTP ' . $response->status()))
            );
        }

        $transaction->forceFill([
            'api_reference' => $body['checkout_id'] ?? $reference,
            'meta' => array_merge($transaction->meta ?? [], [
                'gateway' => self::GATEWAY,
                'bachs_checkout_id' => $body['checkout_id'] ?? null,
                'bachs_checkout_url' => $body['checkout_url'],
                'bachs_reference' => $reference,
                'initialized_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        return $body['checkout_url'];
    }

    /**
     * Ask Bachs what happened to a checkout session.
     *
     * @return array{status:string,currency:?string,amount:?string,charge_id:?string,raw:array<string,mixed>}|null
     *         null when the session exists but no charge has been created yet.
     *
     * @throws RuntimeException when Bachs is unreachable or rejects the read.
     */
    public function verify(string $checkoutId): ?array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Bachs is not configured.');
        }

        $response = $this->client()->get($this->baseUrl() . '/v1/checkout-sessions/' . rawurlencode($checkoutId));
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw new RuntimeException(
                'Bachs rejected the lookup: ' . ($body['message'] ?? $body['error_code'] ?? ('HTTP ' . $response->status()))
            );
        }

        // A checkout that has not been paid through yet carries no payment.
        $payment = $body['payment'] ?? $body['charge'] ?? null;

        if (! is_array($payment)) {
            return null;
        }

        return $this->normalise(
            $payment,
            $body['charge_id'] ?? $payment['id'] ?? null,
            // Carried through explicitly: the payment object does not always
            // repeat the checkout id, and settlement needs it to prove the
            // charge belongs to the row it is being applied to.
            $body['checkout_id'] ?? $body['id'] ?? $checkoutId
        );
    }

    /**
     * Settle a Bachs charge against a funding row.
     *
     * Validation mirrors `PaystackService::settle()` exactly, because the risk
     * is identical: the gateway is the authority on what was paid, and a
     * mismatched amount or currency must never be credited at face value. The
     * crediting itself is delegated, so both gateways share one implementation
     * of "credit exactly once".
     *
     * @param  array<string,mixed>  $charge
     * @return array{status:string,transaction:Transactions}
     */
    public function settle(Transactions $transaction, array $charge): array
    {
        $checkoutId = (string) ($charge['checkout_id'] ?? '');
        $expectedCheckout = (string) (($transaction->meta ?? [])['bachs_checkout_id'] ?? '');

        /*
         * The charge must belong to the checkout we opened for this row. Without
         * this a verified charge could be applied to a different transaction.
         */
        if ($expectedCheckout !== '' && $checkoutId !== '' && $checkoutId !== $expectedCheckout) {
            Log::critical('Bachs charge does not belong to this transaction — refusing to credit', [
                'transaction_id' => $transaction->id,
                'expected_checkout' => $expectedCheckout,
                'received_checkout' => $checkoutId,
            ]);

            return ['status' => 'reference_mismatch', 'transaction' => $transaction];
        }

        $currency = strtoupper((string) ($charge['currency'] ?? ''));

        if ($currency !== Money::CURRENCY) {
            Log::critical('Bachs currency mismatch — refusing to credit', [
                'transaction_id' => $transaction->id,
                'expected_currency' => Money::CURRENCY,
                'paid_currency' => $currency,
            ]);

            $this->markFailed($transaction, "Currency mismatch: paid {$currency}, expected " . Money::CURRENCY . '.', $charge);

            return ['status' => 'currency_mismatch', 'transaction' => $transaction];
        }

        $expected = Money::fromDatabase($transaction->total_amount);

        // Bachs money is a decimal string, so this compares like for like in
        // kobo rather than trusting two float conversions to agree.
        $paid = Money::fromNaira((string) ($charge['amount'] ?? '0'));

        if (! $paid->equals($expected)) {
            Log::critical('Bachs amount mismatch — refusing to credit', [
                'transaction_id' => $transaction->id,
                'expected_kobo' => $expected->minor(),
                'paid_kobo' => $paid->minor(),
            ]);

            $this->markFailed(
                $transaction,
                'Amount mismatch: paid ' . $paid->format() . ' expected ' . $expected->format(),
                $charge
            );

            return ['status' => 'amount_mismatch', 'transaction' => $transaction];
        }

        $status = (string) ($charge['status'] ?? '');

        if (! in_array($status, ['succeeded', 'accepted'], true)) {
            $this->markFailed(
                $transaction,
                (string) ($charge['failure_reason'] ?? $charge['gateway_response'] ?? 'Payment was not successful.'),
                $charge
            );

            return ['status' => 'failed', 'transaction' => $transaction];
        }

        /*
         * Run the shared crediting path inside its own locked transaction. The
         * row is re-read under the lock and re-checked against the charge, so a
         * webhook racing the browser callback cannot credit twice.
         */
        return DB::transaction(function () use ($transaction, $charge) {
            $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'success') {
                return ['status' => 'already_settled', 'transaction' => $locked];
            }

            // Re-check everything against the locked row: the caller's copy is
            // from before the lock and may be stale.
            $recheck = $this->recheck($locked, $charge);

            if ($recheck !== null) {
                return ['status' => $recheck, 'transaction' => $locked];
            }

            return $this->paystack->creditVerifiedFunding($locked, [
                'channel' => 'card',
                'reference' => (string) ($charge['id'] ?? $charge['charge_id'] ?? ''),
                'currency' => (string) ($charge['currency'] ?? ''),
                'gateway_response' => (string) ($charge['gateway_response'] ?? 'Successful'),
                'gateway' => self::GATEWAY,
                'raw' => $charge,
            ]);
        });
    }

    private function recheck(Transactions $locked, array $charge): ?string
    {
        $currency = strtoupper((string) ($charge['currency'] ?? ''));

        if ($currency !== Money::CURRENCY) {
            $this->markFailed($locked, "Currency mismatch: paid {$currency}.", $charge);

            return 'currency_mismatch';
        }

        $expected = Money::fromDatabase($locked->total_amount);
        $paid = Money::fromNaira((string) ($charge['amount'] ?? '0'));

        if (! $paid->equals($expected)) {
            $this->markFailed(
                $locked,
                'Amount mismatch: paid ' . $paid->format() . ' expected ' . $expected->format(),
                $charge
            );

            return 'amount_mismatch';
        }

        if (! in_array((string) ($charge['status'] ?? ''), ['succeeded', 'accepted'], true)) {
            $this->markFailed($locked, 'Payment was not successful.', $charge);

            return 'failed';
        }

        return null;
    }

    /**
     * Reduce whatever shape Bachs returned to the four fields settlement reads.
     *
     * Kept loose on purpose: Bachs documents that new fields may appear at any
     * time, so this reads what it needs and ignores the rest rather than
     * validating a schema.
     *
     * @param  array<string,mixed>  $payment
     * @return array{status:string,currency:?string,amount:?string,charge_id:?string,checkout_id:?string,raw:array<string,mixed>}
     */
    private function normalise(array $payment, mixed $chargeId, mixed $checkoutId = null): array
    {
        // Documented statuses are lowercase; the webhook example shows
        // "SUCCEEDED" in uppercase. Normalising here means callers only ever
        // see one spelling.
        $status = strtolower((string) ($payment['status'] ?? ''));

        return [
            'status' => $status,
            'currency' => isset($payment['currency']) ? (string) $payment['currency'] : null,
            'amount' => isset($payment['amount']) ? (string) $payment['amount'] : null,
            'charge_id' => $chargeId !== null ? (string) $chargeId : null,
            'checkout_id' => $checkoutId !== null ? (string) $checkoutId : null,
            'raw' => $payment,
        ];
    }

    /**
     * Verify a webhook signature.
     *
     * `X-Bachs-Signature-V2` is preferred: it carries the timestamp inline and
     * may hold several `v1=` signatures during a secret rotation, so accepting
     * only the first one fails during a rotation. `X-Bachs-Signature` (plain
     * hex digest of "{timestamp}.{raw_body}") is still accepted as a fallback.
     *
     * The raw body must be passed unparsed — re-encoding a decoded payload
     * changes its bytes and breaks the digest.
     */
    public function verifySignature(string $rawBody, string $timestamp, string $signature, string $signatureV2 = ''): bool
    {
        $secret = trim((string) config('services.bachs.webhook_secret'));

        if ($secret === '') {
            return false;
        }

        if ($signatureV2 !== '') {
            $parts = [];

            foreach (explode(',', $signatureV2) as $piece) {
                if (str_contains($piece, '=')) {
                    [$key, $value] = explode('=', $piece, 2);
                    $parts[trim($key)][] = trim($value);
                }
            }

            $timestamp = $parts['t'][0] ?? $timestamp;
            $candidates = $parts['v1'] ?? [];
        } else {
            $candidates = $signature !== '' ? [$signature] : [];
        }

        if ($candidates === [] || ! ctype_digit($timestamp)) {
            return false;
        }

        // Reject a stale delivery: without this a captured webhook body stays
        // replayable forever.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where Bachs sends the customer's browser after checkout.
     *
     * Public on purpose (see BachsController::callback): Bachs appends
     * `?checkout_id=`, and the session cookie may not survive the round trip
     * through their domain, so requiring a session would throw away a
     * legitimate payment. Correctness comes from verifying the charge and
     * matching the reference, never from the browser.
     */
    public function callbackUrl(): string
    {
        return route('wallet.bachs.callback');
    }

    private function markFailed(Transactions $transaction, string $message, array $raw): void
    {
        $transaction->forceFill([
            'status' => 'failed',
            'payment_status' => 'failed',
            'status_message' => $message,
            'api_response' => $raw,
            'completed_at' => now(),
        ])->save();
    }

    /** A decimal naira string, which is what Bachs expects. */
    private function naira(mixed $databaseAmount): string
    {
        return Money::fromDatabase($databaseAmount)->toDecimalString();
    }

    private function customerName(\App\Models\User $user): string
    {
        $name = trim((string) $user->name);

        // Bachs rejects a blank name when one is supplied, so omit-by-blank is
        // not an option; fall back to the local part of the email.
        return $name !== '' ? $name : (string) strstr((string) $user->email, '@', true);
    }
}
