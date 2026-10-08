<?php

namespace App\Providers\Adapters;

use App\Providers\AbstractProviderAdapter;
use App\Providers\Support\ProviderCredentials;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use App\Support\Money;
use Illuminate\Http\Client\Response;

/**
 * VTpass — international airtime and data (controlled fallback).
 *
 * Contract: https://vtpass.com/documentation/foreign-airtime/
 *
 * ## Why this is the fallback rather than the primary, and why it is safe to fail
 * ## over *to* it
 *
 * Failover is only ever permitted from a result that says RETRYABLE — proof the
 * provider did not fulfil the request. What matters just as much is that the
 * provider we fail *over to* can resolve an ambiguous outcome, and VTpass
 * publishes a requery endpoint that answers exactly that. Falling back to a
 * provider with no status endpoint would convert one unknown into two.
 *
 * ## Authentication is per-method, and this is not a detail
 *
 * VTpass documents:
 *
 *   * **GET** — `api-key` + `public-key`
 *   * **POST** — `api-key` + `secret-key`
 *
 * The public key is not a public credential; it is simply the one VTpass expects
 * on a read. Sending the secret key on a GET, or the public key on a payment,
 * is rejected — so the method is passed into `authHeaders()` rather than picked
 * once.
 *
 * ## Cost model
 *
 * VTpass debits its own wallet and reports what it took:
 *
 *     amount          2.00   the face value being delivered
 *     commission      0.08   VTpass's reward to us, at rate_type=percent, rate=4.00
 *     total_amount    1.92   what was actually debited
 *
 * `total_amount` is therefore the provider cost, and the adapter uses it. It also
 * records `amount` and `commission` so the figure can be audited, because a
 * commission rate that silently changes is exactly how a quoted margin becomes a
 * real loss. No commission percentage is hard-coded: the rate is read from
 * `commission_details` when a caller needs it, and a fallback computation is used
 * only when `total_amount` is missing.
 *
 * ## Response codes, and the one line that matters
 *
 * VTpass publishes a code table. `000` means "processed — now read
 * `content.transactions.status`", and that inner status is the only thing that
 * says `delivered`, `pending` or `initiated`. A code the table does not list is
 * UNKNOWN, never FAILED: VTpass's own guidance is that any response differing
 * from the table should be treated as pending and requeried.
 */
class VtpassProvider extends AbstractProviderAdapter
{
    public function slug(): string
    {
        return 'vtpass';
    }

    public function label(): string
    {
        return (string) $this->config('label', 'VTpass');
    }

    protected function configKey(): string
    {
        return 'vtpass';
    }

    public function capabilities(): array
    {
        return (array) $this->config('capabilities', []);
    }

    protected function baseUrl(): string
    {
        return (string) ($this->isSandbox()
            ? $this->config('sandbox_base_url')
            : $this->config('base_url'));
    }

    /**
     * The per-method credential headers VTpass documents.
     *
     * @return array<string, string>
     */
    protected function authHeaders(?string $scope = null, string $method = 'POST'): array
    {
        $names = (array) $this->config('headers', []);

        // A read carries the public key; a write carries the secret key.
        $second = strtoupper($method) === 'GET'
            ? ($names['public_key'] ?? 'public-key')
            : ($names['secret_key'] ?? 'secret-key');

        $field = strtoupper($method) === 'GET' ? 'PUBLIC_KEY' : 'SECRET_KEY';

        return [
            (string) ($names['api_key'] ?? 'api-key') => ProviderCredentials::resolve($this->provider, null, 'API_KEY'),
            (string) $second => ProviderCredentials::resolve($this->provider, null, $field),
        ];
    }

    /** Whether every credential this adapter needs is present. */
    public function isConfigured(): bool
    {
        foreach (['API_KEY', 'PUBLIC_KEY', 'SECRET_KEY'] as $field) {
            try {
                ProviderCredentials::resolve($this->provider, null, $field);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /* =====================================================================
     | Catalogue
     * =================================================================== */

    /**
     * Countries VTpass can deliver to.
     *
     * Each entry carries its own currency and dialling prefix, which is what the
     * catalogue sync needs to quote a product in the recipient's currency and to
     * validate a recipient number before charging for it.
     */
    public function countries(): ProviderResult
    {
        return $this->sendRead('GET', $this->url('countries'), [], [], null, $this->probeTimeout());
    }

    /** Product types for a country — Mobile Top Up versus Mobile Data. */
    public function productTypes(string $countryCode): ProviderResult
    {
        return $this->sendRead(
            'GET',
            $this->url('product_types'),
            ['code' => $countryCode],
            [],
            null,
            $this->probeTimeout(),
        );
    }

    /** Operators for a country and product type. */
    public function operators(string $countryCode, string|int $productTypeId): ProviderResult
    {
        return $this->sendRead('GET', $this->url('operators'), [
            'code' => $countryCode,
            'product_type_id' => $productTypeId,
        ], [], null, $this->probeTimeout());
    }

    /**
     * Variation codes — the sellable products — for one operator.
     *
     * Documented as a **GET** with `serviceID`, `operator_id` and
     * `product_type_id` as query parameters. The response's `convinience_fee` is
     * VTpass's own statement of the fee for this product family, and
     * `variation_amount` is the naira-equivalent charge for a fixed-price
     * variation; a flexible-price variation instead documents a `variation_rate`
     * to multiply by the recipient-currency amount. Both are preserved on the
     * catalogue row so a price is never derived from a name.
     */
    public function variations(string|int $operatorId, string|int $productTypeId): ProviderResult
    {
        return $this->sendRead('GET', $this->url('variations'), [
            'serviceID' => $this->serviceId(),
            'operator_id' => $operatorId,
            'product_type_id' => $productTypeId,
        ], [], null, $this->probeTimeout());
    }

    /**
     * The VTpass wallet balance.
     *
     * Documented response:
     *
     *     {"code": 1, "contents": {"balance": 1081.8199999998}}
     *
     * Three things about that body are traps, and all three are handled here
     * rather than being discovered in production:
     *
     *   1. `contents` is **plural**, unlike every other VTpass response, which uses
     *      `content`. A parser keyed on `content` finds nothing.
     *   2. `code` is the integer `1`, not a response code. Read as a transaction
     *      code it would be padded to `001` and interpreted as a requery.
     *   3. The balance is a float with more decimal places than a kobo — a binary
     *      floating-point artefact, not a real fraction of a kobo.
     *
     * The balance is therefore read directly from the body, and the returned shape
     * matches every other adapter's `balance()` so the provider health dashboard
     * treats them alike.
     *
     * @return array{success:bool,balance_minor:?int,currency:?string,message:?string,sandbox:bool}
     */
    public function balance(): array
    {
        if (! $this->isConfigured()) {
            return $this->balanceFailure('Not configured.');
        }

        $result = $this->send('GET', $this->url('balance'), [], [], null, $this->probeTimeout());

        $raw = data_get($result->payload, 'contents.balance')
            ?? data_get($result->payload, 'content.balance');

        if (! is_numeric($raw)) {
            return $this->balanceFailure($result->message ?? 'Balance unavailable.');
        }

        return [
            'success' => true,
            'balance_minor' => $this->majorToMinor((string) $raw),
            'currency' => 'NGN',
            'message' => null,
            'sandbox' => $this->isSandbox(),
        ];
    }

    /** @return array{success:bool,balance_minor:null,currency:null,message:string,sandbox:bool} */
    private function balanceFailure(string $message): array
    {
        return [
            'success' => false,
            'balance_minor' => null,
            'currency' => null,
            'message' => $message,
            'sandbox' => $this->isSandbox(),
        ];
    }

    /* =====================================================================
     | Purchase
     * =================================================================== */

    /**
     * Buy an international top-up or data bundle.
     *
     * `$requestId` is our own reference and VTpass binds it to the transaction:
     * reusing it returns `014 REQUEST ID ALREADY EXIST`, and a requery with an id
     * VTpass never saw returns `015`. That pair is what makes reconciliation
     * meaningful, so the caller must derive it once and never regenerate it for an
     * unresolved order — the same rule as `service_orders.provider_idempotency_key`.
     *
     * `phone` is the *customer's* number for VTpass's records and `billersCode` is
     * the recipient's. They are different fields and passing one where the other
     * belongs sends a top-up to the wrong number, so they are separate parameters
     * here rather than a single `$phone`.
     */
    public function purchaseInternational(
        string $requestId,
        string $recipientPhone,
        string $variationCode,
        string|int $operatorId,
        string $countryCode,
        string|int $productTypeId,
        string $customerPhone,
        string $customerEmail,
        ?int $amountNaira = null,
    ): ProviderResult {
        return $this->send('POST', $this->url('purchase'), array_filter([
            'request_id' => $requestId,
            'serviceID' => $this->serviceId(),
            'billersCode' => $recipientPhone,
            'variation_code' => $variationCode,
            'amount' => $amountNaira,
            'phone' => $customerPhone,
            'operator_id' => $operatorId,
            'country_code' => $countryCode,
            'product_type_id' => $productTypeId,
            'email' => $customerEmail,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /* =====================================================================
     | Reconciliation
     * =================================================================== */

    /**
     * Ask VTpass what happened to a transaction we are unsure about.
     *
     * The answer is read the same way a purchase response is, because VTpass
     * documents them as the same shape — which is the property that makes this
     * provider a safe place to land after another provider returned UNKNOWN.
     */
    public function requery(string $requestId): ProviderResult
    {
        return $this->send('POST', $this->url('requery'), ['request_id' => $requestId]);
    }

    /* =====================================================================
     | Normalisation
     * =================================================================== */

    /**
     * Decode a VTpass response.
     *
     * Order of interpretation, and why:
     *
     *   1. A transport failure never reaches here — `send()` returns UNKNOWN.
     *   2. The balance envelope is checked first, because its `code` is not a
     *      response code and reading it as one misinterprets the whole body.
     *   3. The body's `code` decides the class of outcome. `000` and `001` mean
     *      "read the inner status"; the rest are matched against the published
     *      table.
     *   4. An unlisted code is UNKNOWN. VTpass's own instruction is to treat
     *      anything outside the table as pending and requery, and an UNKNOWN result
     *      does exactly that.
     *
     * @param  array<string, mixed>  $body
     */
    protected function normalise(array $body, Response $response): ProviderResult
    {
        /* ---- Balance: `contents` (plural) and a non-response `code` ----- */
        if (is_numeric(data_get($body, 'contents.balance'))) {
            return new ProviderResult(
                status: ProviderStatus::SUCCESS,
                payload: $this->redactPayload($body),
                httpStatus: $response->status(),
            );
        }

        $code = $this->code($body);

        if ($code === null) {
            /*
             * A catalogue read. VTpass documents its country, product-type,
             * operator and variation responses with a `response_description` and a
             * `content` — and **no `code` field at all** — unlike `/pay` and
             * `/requery`, which both carry one. Requiring a code here would classify
             * every catalogue as unreadable, which is exactly the kind of silent
             * failure that empties a storefront.
             *
             * A 2xx carrying `content` and no code is therefore a successful read —
             * the same shape Sogo uses for its catalogue endpoints.
             */
            if ($response->successful() && array_key_exists('content', $body)) {
                return new ProviderResult(
                    status: ProviderStatus::SUCCESS,
                    payload: $this->redactPayload($body),
                    httpStatus: $response->status(),
                );
            }

            return ProviderResult::fromUnrecognised('no_code', $this->redactPayload($body), $response->status());
        }

        $message = $this->safeMessage(
            isset($body['response_description']) ? (string) $body['response_description'] : null,
            'The provider rejected the request.',
        );

        /* ---- Success and requery: the inner status is authoritative ----- */
        if (in_array($code, [(string) $this->config('success_code', '000'), (string) $this->config('query_code', '001')], true)) {
            return $this->fromTransactions($body, $response->status(), $code, $message);
        }

        $status = $this->statusForCode($code);

        /*
         * A reversal is money coming back to VTpass's wallet, and the reversal
         * payload carries the amount actually credited — which is the figure a
         * refund must be based on, not the original face value. It is passed
         * through as the provider amount.
         */
        return new ProviderResult(
            status: $status,
            providerStatus: $code,
            providerReference: isset($body['requestId']) ? (string) $body['requestId'] : null,
            providerAmountMinor: $status === ProviderStatus::REFUNDED && isset($body['amount']) && is_numeric($body['amount'])
                ? $this->majorToMinor((string) $body['amount'])
                : null,
            payload: $this->redactPayload($body),
            message: $message,
            errorCode: $code,
            httpStatus: $response->status(),
        );
    }

    /**
     * Map a published response code onto a canonical status.
     *
     * The three lists live in configuration so that correcting one is a config
     * edit, and so the classification is reviewable in one place rather than
     * buried in a `match` arm.
     *
     * Default is UNKNOWN. A code nobody has classified is not evidence that the
     * customer was not served.
     */
    private function statusForCode(string $code): string
    {
        if (in_array($code, (array) $this->config('reversal_codes', []), true)) {
            return ProviderStatus::REFUNDED;
        }

        if (in_array($code, (array) $this->config('pending_codes', []), true)) {
            return ProviderStatus::PENDING;
        }

        if (in_array($code, (array) $this->config('fatal_codes', []), true)) {
            return ProviderStatus::FAILED;
        }

        if (in_array($code, (array) $this->config('retryable_codes', []), true)) {
            return ProviderStatus::RETRYABLE;
        }

        /*
         * Listed explicitly, even though the fallthrough below produces the same
         * UNKNOWN. The point is auditability: a reviewer reading this method and
         * `unknown_codes` can see that every code VTpass documents as ambiguous has
         * been considered and deliberately not classified as a failure, instead of
         * wondering whether it was overlooked.
         */
        if (in_array($code, (array) $this->config('unknown_codes', []), true)) {
            return ProviderStatus::UNKNOWN;
        }

        return ProviderStatus::UNKNOWN;
    }

    /**
     * Build a result from a `content.transactions` envelope.
     *
     * @param  array<string, mixed>  $body
     */
    private function fromTransactions(array $body, int $httpStatus, string $code, ?string $message): ProviderResult
    {
        $transaction = data_get($body, 'content.transactions');

        if (! is_array($transaction)) {
            /*
             * `000` with no transaction object. This happens on a catalogue call
             * that happens to carry a code, and it also happens on a malformed
             * success. The two are distinguished by whether there is content at
             * all: content but no transaction is a read, no content at all is not.
             */
            if (array_key_exists('content', $body)) {
                return new ProviderResult(
                    status: ProviderStatus::SUCCESS,
                    providerStatus: $code,
                    payload: $this->redactPayload($body),
                    message: $message,
                    httpStatus: $httpStatus,
                );
            }

            return ProviderResult::fromUnrecognised('no_transactions', $this->redactPayload($body), $httpStatus);
        }

        $providerStatus = isset($transaction['status']) ? (string) $transaction['status'] : '';

        if ($providerStatus === '') {
            return ProviderResult::fromUnrecognised('no_status', $this->redactPayload($body), $httpStatus);
        }

        return new ProviderResult(
            status: $this->mapStatus($providerStatus),
            providerStatus: $providerStatus,
            providerReference: $this->referenceFrom($transaction, $body),
            providerCostMinor: $this->costMinorFrom($transaction),
            providerCurrency: 'NGN',
            providerAmountMinor: isset($transaction['amount']) && is_numeric($transaction['amount'])
                ? $this->majorToMinor((string) $transaction['amount'])
                : null,
            payload: $this->redactPayload($body),
            message: $message,
            errorCode: $code,
            httpStatus: $httpStatus,
        );
    }

    /**
     * The provider's identifier for the transaction.
     *
     * `requestId` is preferred over `transactionId` because `requestId` is the
     * value we sent and can therefore be matched back to an order without a
     * lookup table; `transactionId` is VTpass's own and is kept in the payload.
     */
    private function referenceFrom(array $transaction, array $body): ?string
    {
        foreach ([$body['requestId'] ?? null, $transaction['transactionId'] ?? null] as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * What VTpass actually charged, in kobo.
     *
     * `total_amount` is the debit — the documented relationship is
     * `total_amount = amount − commission` — so it is the provider cost. When it
     * is absent the computation is done from the parts, and never the other way
     * round: deriving `total_amount` from `amount` alone would overstate the cost
     * by the commission and understate every margin.
     */
    private function costMinorFrom(array $transaction): ?int
    {
        $total = $transaction['total_amount'] ?? null;

        if (is_numeric($total)) {
            return $this->majorToMinor((string) $total);
        }

        $amount = $transaction['amount'] ?? null;

        if (! is_numeric($amount)) {
            return null;
        }

        $commission = is_numeric($transaction['commission'] ?? null) ? (float) $transaction['commission'] : 0.0;
        $fee = is_numeric($transaction['convinience_fee'] ?? null) ? (float) $transaction['convinience_fee'] : 0.0;

        return $this->majorToMinor(number_format((float) $amount - $commission + $fee, 2, '.', ''));
    }

    /**
     * VTpass's commission for a transaction, as a basis-point rate.
     *
     * Exposed because the pricing engine's profitability check wants to know what
     * the effective cost was, and because a rate that drifts is worth alerting on.
     * A `rate_type` of `flat` is an amount, not a percentage, and is reported as
     * null rather than being converted into a misleading rate.
     */
    public function commissionRateBps(array $transaction): ?int
    {
        $details = $transaction['commission_details'] ?? null;

        if (! is_array($details) || ($details['rate_type'] ?? null) !== 'percent') {
            return null;
        }

        $rate = $details['rate'] ?? null;

        if (! is_numeric($rate)) {
            return null;
        }

        // "4.00" percent → 400 basis points.
        return (int) round(((float) $rate) * 100);
    }

    /* =====================================================================
     | Helpers
     * =================================================================== */

    private function serviceId(): string
    {
        return (string) $this->config('service_id', 'foreign-airtime');
    }

    private function probeTimeout(): int
    {
        return (int) config('providers.http.probe_timeout', 10);
    }

    /**
     * The response code, as a string.
     *
     * VTpass returns it as a string on a purchase and as an integer on a balance
     * read, so it is normalised here rather than at each comparison. Left-padding
     * matters: a provider that answered `16` instead of `016` would otherwise miss
     * the table entirely and be reported UNKNOWN forever.
     */
    private function code(array $body): ?string
    {
        $code = $body['code'] ?? null;

        if (! is_scalar($code)) {
            return null;
        }

        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return ctype_digit($code) ? str_pad($code, 3, '0', STR_PAD_LEFT) : $code;
    }

    /**
     * Convert a major-unit figure into integer kobo.
     *
     * The balance endpoint returns values such as `1081.8199999998` — a float
     * artefact, not a fraction of a kobo — so the value is rounded to two decimal
     * places before conversion. `Money` rejects excess precision by design, which
     * is what makes that rounding explicit and deliberate rather than silent.
     */
    private function majorToMinor(string $amount): int
    {
        return Money::fromNaira(number_format((float) $amount, 2, '.', ''))->minor();
    }
}
