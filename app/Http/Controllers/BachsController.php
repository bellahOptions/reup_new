<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BachsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bachs gateway endpoints — the fallback card rail.
 *
 * Same shape as PaystackController: the webhook is the authoritative
 * settlement path and is signature-authenticated over the raw body, while the
 * browser callback is a convenience that re-verifies server-to-server and never
 * decides anything on its own.
 */
class BachsController extends Controller
{
    public function __construct(
        private readonly BachsService $bachs,
    ) {
    }

    /**
     * Bachs server-to-server webhook (`collection.succeeded` and friends).
     *
     * Outside `auth` (Bachs holds no session) and exempt from CSRF, for the
     * same reasons as the Paystack webhook — it is authenticated by HMAC over
     * the raw request body instead.
     */
    public function webhook(Request $request)
    {
        if (! $this->bachs->isConfigured()) {
            Log::critical('Bachs webhook received but Bachs is not configured.');
            abort(503, 'Payment gateway not configured.');
        }

        $secret = (string) config('services.bachs.webhook_secret');

        if (trim($secret) === '') {
            Log::critical('Bachs webhook received but BACHS_WEBHOOK_SECRET is not set — refusing to run unsigned.');
            abort(503, 'Webhook secret not configured.');
        }

        $raw = $request->getContent();

        $verified = $this->bachs->verifySignature(
            rawBody: $raw,
            timestamp: (string) $request->header('X-Bachs-Timestamp', ''),
            signature: (string) $request->header('X-Bachs-Signature', ''),
            signatureV2: (string) $request->header('X-Bachs-Signature-V2', ''),
        );

        if (! $verified) {
            Log::warning('Bachs webhook rejected: signature mismatch or stale delivery.', [
                'ip' => $request->ip(),
            ]);

            abort(401, 'Invalid signature.');
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['status' => 'ignored', 'reason' => 'malformed json']);
        }

        $event = (string) ($payload['type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        Log::info('Bachs webhook received', [
            'event' => $event,
            'event_id' => $payload['id'] ?? null,
        ]);

        /*
         * Only the payment outcomes we settle on are acted upon. Everything
         * else — subscription, invoice, payout, dispute events — gets a 200 so
         * Bachs stops retrying rather than a 404 that looks like an outage.
         */
        if (! in_array($event, ['collection.succeeded', 'collection.failed', 'collection.underpaid'], true)) {
            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        $reference = (string) ($data['reference'] ?? '');

        if ($reference === '') {
            Log::warning('Bachs webhook carried no reference', ['event' => $event]);

            // 200: a retry cannot invent a reference.
            return response()->json(['status' => 'ignored', 'reason' => 'no reference']);
        }

        /*
         * Matched on our own reference, which we sent to Bachs and they echo
         * back. It is unique per transaction, so this cannot pick up another
         * customer's row.
         */
        $transaction = Transactions::where('payment_method', 'paystack')
            ->where(function ($query) use ($reference) {
                $query->where('uuid', $reference)
                    ->orWhere('reference', $reference)
                    ->orWhere('api_reference', $reference);
            })
            ->first();

        if (! $transaction) {
            Log::warning('Bachs webhook for unknown reference', ['reference' => $reference]);

            return response()->json(['status' => 'unknown_reference']);
        }

        try {
            $charge = $this->chargeFromWebhook($data, $transaction);

            $result = $this->bachs->settle($transaction, $charge);

            return response()->json(['status' => $result['status']]);
        } catch (Throwable $e) {
            report($e);

            Log::error('Bachs webhook settlement failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            // 500 so Bachs retries — the charge may be real and unsettled.
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Browser redirect target after a Bachs checkout.
     *
     * Public and session-free by design: Bachs appends `?checkout_id=`, and the
     * customer's session cookie does not survive the round trip through their
     * domain. Nothing here trusts the browser — the charge is verified with
     * Bachs server-to-server and the reference is matched against the row, so
     * an attacker replaying this URL with someone else's checkout id credits
     * that transaction and gains nothing.
     */
    public function callback(Request $request)
    {
        $checkoutId = (string) $request->query('checkout_id', '');

        if ($checkoutId === '') {
            return redirect()->route('wallet.fund')
                ->with('error', 'That payment link is missing its checkout reference.');
        }

        $transaction = Transactions::where('payment_method', 'paystack')
            ->where('api_reference', $checkoutId)
            ->first();

        if (! $transaction) {
            Log::warning('Bachs callback for unknown checkout', ['checkout_id' => $checkoutId]);

            abort(404);
        }

        $userId = (int) (($transaction->meta ?? [])['user_id'] ?? 0);

        if ($userId > 0 && auth()->check() && (int) auth()->id() !== $userId) {
            Log::warning('Bachs callback opened by a different user than the transaction belongs to', [
                'transaction_id' => $transaction->id,
                'checkout_id' => $checkoutId,
            ]);

            abort(404);
        }

        try {
            $charge = $this->bachs->verify($checkoutId);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('wallet.index')
                ->with('error', 'We could not confirm that payment. If you were debited, contact support with your reference.');
        }

        if ($charge === null) {
            // The customer came back before Bachs recorded a charge. Not an
            // error: the webhook is the authority and will settle it.
            return redirect()->route('wallet.payment.status', ['reference' => $transaction->reference])
                ->with('info', 'We have not received confirmation of that payment yet. This page updates as soon as it arrives.');
        }

        $result = $this->bachs->settle($transaction, $charge);

        return match ($result['status']) {
            'settled', 'already_settled' => redirect()->route('wallet.index')->with(
                'success',
                'Payment confirmed. ' . \App\Support\Money::fromDatabase($result['transaction']->amount)->format() . ' has been added to your wallet.'
            ),
            'amount_mismatch' => redirect()->route('wallet.index')->with(
                'error',
                'The amount paid did not match this transaction, so it was not credited. Support has been notified.'
            ),
            'currency_mismatch' => redirect()->route('wallet.index')->with(
                'error',
                'That payment was made in an unsupported currency, so it was not credited. Support has been notified.'
            ),
            'reference_mismatch' => redirect()->route('wallet.index')->with(
                'error',
                'We could not match that payment to this transaction. Support has been notified.'
            ),
            default => redirect()->route('wallet.payment.status', ['reference' => $transaction->reference])
                ->with('error', 'That payment was not successful. No money left your account.'),
        };
    }

    /**
     * Turn a webhook `data` block into the shape `settle()` validates.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function chargeFromWebhook(array $data, Transactions $transaction): array
    {
        return [
            'id' => $data['charge_id'] ?? null,
            'checkout_id' => $data['checkout_id'] ?? (($transaction->meta ?? [])['bachs_checkout_id'] ?? null),
            'status' => $data['status'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'gateway_response' => $data['gateway_response'] ?? 'Successful',
        ];
    }
}
