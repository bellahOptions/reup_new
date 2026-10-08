<?php

namespace App\Providers\Adapters;

use App\Providers\AbstractProviderAdapter;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use App\Support\Money;
use Illuminate\Http\Client\Response;

/**
 * VTUGate — international airtime and data (primary, once verified).
 *
 * Contract: https://vtugate.com/docs
 *
 * ## What is documented, and used verbatim
 *
 * Bearer API key, form-encoded POST bodies, JSON responses, 60 requests per
 * minute per key. The endpoint paths below are published and are called exactly
 * as published:
 *
 *   POST /api/v1/accountdetails
 *   POST /api/v1/transactions/status        (requery)
 *   POST /api/v1/international/countries
 *   POST /api/v1/international/operators
 *   POST /api/v1/international/detectoperator
 *   POST /api/v1/international/previewfx
 *   POST /api/v1/international/topup
 *   POST /api/v1/international/topupstatus
 *   POST /api/v1/international/history
 *
 * The response envelope is `{status: bool, message: string, data: {…}}`.
 *
 * ## What is NOT documented, and the position this adapter takes
 *
 * VTUGate's documentation is a client-rendered page: the endpoint list and the
 * authentication scheme are in the served HTML, but the request **body field
 * names** for the international top-up group are rendered by JavaScript on
 * selection and are not present in the document, not in any published OpenAPI
 * definition, and not indexed anywhere public. That was checked rather than
 * assumed.
 *
 * This application does not invent the field names of a financial request.
 * Sending `recipient` where the API expects `phone` does not fail loudly — it
 * sends a top-up to the wrong number, or to nobody, and either way the money
 * leaves. So:
 *
 *   * every field name is read from `providers.vtugate.fields` / `response_fields`
 *     at call time, so correcting one is an environment change and not a deploy;
 *   * `isOperational()` returns false until an operator sets
 *     `VTUGATE_FIELDS_VERIFIED=true`, and the registry skips an adapter that is
 *     not operational;
 *   * until then the international route uses VTpass, whose contract is fully
 *     published — so the product works on day one and VTUGate becomes primary the
 *     moment its field names are confirmed against the vendor's own dashboard.
 *
 * That is a deliberate, visible gate rather than a silent one: the alternative was
 * to guess, and a guess here is indistinguishable from a working integration until
 * the first customer's top-up goes missing.
 */
class VtugateProvider extends AbstractProviderAdapter
{
    public function slug(): string
    {
        return 'vtugate';
    }

    public function label(): string
    {
        return (string) $this->config('label', 'VTUGate');
    }

    protected function configKey(): string
    {
        return 'vtugate';
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
     * Whether this adapter may be routed to at all.
     *
     * False while the international field names are unverified — see the class
     * docblock. `ProviderRegistry` consults this and falls through to the next
     * candidate rather than dispatching an order into an unverified integration.
     *
     * This is not a configuration toggle for convenience. It is the difference
     * between "we have not confirmed how to address this API" and "we have".
     */
    public function isOperational(): bool
    {
        return (bool) $this->config('fields_verified', false);
    }

    /**
     * Why this adapter is not routable, in the words an operator needs.
     *
     * Shown in the admin console, so it names the exact configuration key to change
     * rather than saying "unavailable" and leaving someone to search the codebase.
     */
    public function operationalReason(): ?string
    {
        if ($this->isOperational()) {
            return null;
        }

        return 'VTUGate international request field names are not published in its API '
            . 'reference and have not been confirmed against the vendor. Set '
            . 'VTUGATE_FIELDS_VERIFIED=true only after confirming every name in '
            . 'providers.vtugate.fields against the vendor dashboard. Until then the '
            . 'international route uses VTpass.';
    }

    /* =====================================================================
     | Account
     * =================================================================== */

    /**
     * Account details, including the wallet balance.
     *
     * The balance is nested under `data.wallet_balance` in the documented
     * response. Like every other adapter's `balance()`, this returns the same shape
     * so the health dashboard can compare providers.
     *
     * @return array{success:bool,balance_minor:?int,currency:?string,message:?string,sandbox:bool}
     */
    public function balance(): array
    {
        if (! $this->isConfigured()) {
            return $this->balanceFailure('Not configured.');
        }

        $result = $this->send('POST', $this->url('account'), [], [], null, $this->probeTimeout());

        $balanceField = (string) $this->responseField('balance', 'wallet_balance');
        $raw = data_get($result->payload, 'data.' . $balanceField);

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
     | Catalogue
     * =================================================================== */

    /** Countries that can be topped up, with currency and dialling prefix. */
    public function countries(): ProviderResult
    {
        return $this->sendRead('POST', $this->url('countries'), [], [], null, $this->probeTimeout());
    }

    /** Operators available in a country, for a product type. */
    public function operators(string $countryCode, ?string $productType = null): ProviderResult
    {
        return $this->sendRead('POST', $this->url('operators'), $this->body(array_filter([
            'country' => $countryCode,
            'product' => $productType,
        ], fn ($value) => $value !== null)), [], null, $this->probeTimeout());
    }

    /**
     * Detect the operator for a recipient number.
     *
     * Used before a purchase to confirm the operator the customer selected matches
     * the number they typed. A mismatch is a deterministic rejection, so it is
     * caught here rather than becoming a failed top-up.
     */
    public function detectOperator(string $recipientPhone, string $countryCode): ProviderResult
    {
        return $this->send('POST', $this->url('detect_operator'), $this->body([
            'phone' => $recipientPhone,
            'country' => $countryCode,
        ]), [], null, $this->probeTimeout());
    }

    /**
     * Preview the exchange rate and the naira amount for a top-up.
     *
     * This is the input to pricing: the pricing engine must not compute a
     * customer price from a rate it assumed, and an FX rate that has moved is the
     * single fastest way for a profitable product to become a loss-making one. The
     * rate returned here is recorded on the pricing snapshot alongside the
     * converted amounts.
     */
    public function previewFx(string $recipientPhone, string $countryCode, int|float|string $amount, ?string $productType = null): ProviderResult
    {
        return $this->send('POST', $this->url('preview_fx'), $this->body(array_filter([
            'phone' => $recipientPhone,
            'country' => $countryCode,
            'amount' => $amount,
            'product' => $productType,
        ], fn ($value) => $value !== null)), [], null, $this->probeTimeout());
    }

    /* =====================================================================
     | Purchase
     * =================================================================== */

    /**
     * Buy an international top-up.
     *
     * `$reference` is our own identifier, sent under whichever field name the
     * vendor documents (`fields.reference`). It is the value reconciliation uses,
     * so it must be derived once per order and never regenerated while the outcome
     * is unresolved.
     */
    public function purchaseInternational(
        string $reference,
        string $recipientPhone,
        string $countryCode,
        int|float|string $amount,
        ?string $operator = null,
        ?string $productType = null,
        ?string $currency = null,
    ): ProviderResult {
        return $this->send('POST', $this->url('topup'), $this->body(array_filter([
            'reference' => $reference,
            'phone' => $recipientPhone,
            'country' => $countryCode,
            'operator' => $operator,
            'product' => $productType,
            'amount' => $amount,
            'currency' => $currency,
        ], fn ($value) => $value !== null && $value !== '')));
    }

    /* =====================================================================
     | Reconciliation
     * =================================================================== */

    /**
     * Ask VTUGate what happened to a top-up.
     *
     * VTUGate publishes both a status endpoint and a history endpoint. The status
     * endpoint takes our reference; the history endpoint is the fallback when a
     * status lookup for an unverified field name returns nothing useful. Both are
     * read-only and neither can move money, so trying the second after the first is
     * safe — unlike trying a second *purchase*.
     */
    public function reconcile(string $reference): ProviderResult
    {
        return $this->send('POST', $this->url('topup_status'), $this->body(['reference' => $reference]));
    }

    /** Recent top-ups, for reconciling a reference the status endpoint does not know. */
    public function history(array $filters = []): ProviderResult
    {
        return $this->send('POST', $this->url('topup_history'), $this->body($filters), [], null, $this->probeTimeout());
    }

    /* =====================================================================
     | Normalisation
     * =================================================================== */

    /**
     * Decode VTUGate's `{status, message, data}` envelope.
     *
     * `status` is documented as a boolean, so:
     *
     *   * `true` on a call that carries no transaction — a catalogue read — is a
     *     successful read, and the payload is the data;
     *   * `true` on a call that carries a transaction is a success, but only once
     *     an inner status (if the vendor sends one) has been consulted, because a
     *     boolean cannot express "pending";
     *   * `false` is a rejection, and the message decides its class. A message
     *     matching `retryable_errors` means nothing was vended; anything else is
     *     UNKNOWN, never FAILED. A false with an unfamiliar message is not evidence
     *     that the customer was not served, and this is the single most important
     *     line in this adapter.
     *
     * The `false` case deliberately does **not** consult an error-code table even
     * when one is present, because VTUGate's documented error vocabulary is a free
     * text message. Matching free text to decide a refund is how a delivered order
     * gets refunded, so free text only ever selects between "provably not vended"
     * and "unknown".
     *
     * @param  array<string, mixed>  $body
     */
    protected function normalise(array $body, Response $response): ProviderResult
    {
        $httpStatus = $response->status();

        if (! array_key_exists('status', $body)) {
            return ProviderResult::fromUnrecognised('no_status', $this->redactPayload($body), $httpStatus);
        }

        $rawStatus = $body['status'];
        $message = $this->safeMessage(
            isset($body['message']) ? (string) $body['message'] : null,
            'The provider returned an unexpected response.',
        );

        $data = $body['data'] ?? null;

        /* ---- Rejection ------------------------------------------------ */
        if ($this->statusIsFalse($rawStatus) || ! $response->successful()) {
            $retryable = $this->isRetryableError($this->normalisedMessage($message));

            return new ProviderResult(
                status: $retryable ? ProviderStatus::RETRYABLE : ProviderStatus::UNKNOWN,
                providerStatus: $this->statusIsFalse($rawStatus) ? 'false' : 'http_' . $httpStatus,
                payload: $this->redactPayload($body),
                message: $message,
                errorCode: $retryable ? 'retryable' : null,
                httpStatus: $httpStatus,
            );
        }

        /* ---- A read: no transaction in the payload --------------------- */
        $transaction = $this->transactionFrom($data);

        if ($transaction === null) {
            return new ProviderResult(
                status: ProviderStatus::SUCCESS,
                providerStatus: 'true',
                payload: $this->redactPayload($body),
                message: $message,
                httpStatus: $httpStatus,
            );
        }

        /* ---- A transaction -------------------------------------------- */
        $innerStatus = $this->responseFieldValue($transaction, 'status');

        return new ProviderResult(
            status: $innerStatus !== null
                ? $this->mapStatus((string) $innerStatus)
                : ProviderStatus::SUCCESS,
            providerStatus: $innerStatus !== null ? (string) $innerStatus : 'true',
            providerReference: $this->referenceFrom($transaction, $body),
            providerCostMinor: $this->minorFrom($transaction, 'ngn_amount') ?? $this->minorFrom($transaction, 'fee'),
            providerAmountMinor: $this->minorFrom($transaction, 'local_amount'),
            providerCurrency: $this->responseFieldValue($transaction, 'currency'),
            payload: $this->redactPayload($body),
            message: $message,
            httpStatus: $httpStatus,
        );
    }

    /**
     * Pull the transaction object out of `data`, if there is one.
     *
     * The status endpoint may nest it under `data.transaction`, and a bare top-up
     * response may put the fields directly under `data`. Both are accepted, and
     * "is this a transaction at all" is decided by the presence of the reference
     * field, not by the shape — a catalogue response has no reference, so it cannot
     * be mistaken for one.
     */
    private function transactionFrom(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        $nested = $this->responseField('transaction', 'transaction');

        if (isset($data[$nested]) && is_array($data[$nested])) {
            return $data[$nested];
        }

        return $this->referenceFrom($data, []) !== null ? $data : null;
    }

    private function referenceFrom(array $transaction, array $body): ?string
    {
        foreach ([
            $this->responseFieldValue($transaction, 'reference'),
            $this->responseFieldValue($body, 'reference'),
        ] as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    /** Read a configured amount field and convert it to kobo. */
    private function minorFrom(array $transaction, string $logical): ?int
    {
        $value = $this->responseFieldValue($transaction, $logical);

        return is_numeric($value) ? $this->majorToMinor((string) $value) : null;
    }

    /* =====================================================================
     | Field-name indirection
     * =================================================================== */

    /**
     * Build a request body using the configured field names.
     *
     * Every key is translated from the logical name this adapter uses to the name
     * the vendor documents, so a correction is a config edit. A logical name with no
     * configured counterpart is dropped rather than sent under its logical name —
     * sending `phone` because nobody configured `recipient` is precisely the silent
     * failure this indirection exists to prevent.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function body(array $values): array
    {
        $map = (array) $this->config('fields', []);
        $body = [];

        foreach ($values as $logical => $value) {
            $name = $map[$logical] ?? null;

            if (is_string($name) && $name !== '') {
                $body[$name] = $value;
            }
        }

        return $body;
    }

    /** The configured request field name for a logical name. */
    protected function field(string $logical, ?string $default = null): ?string
    {
        $name = data_get($this->config('fields', []), $logical, $default);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** The configured response field name for a logical name. */
    protected function responseField(string $logical, ?string $default = null): ?string
    {
        $name = data_get($this->config('response_fields', []), $logical, $default);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Read a value from a response array under its configured name. */
    private function responseFieldValue(array $source, string $logical): mixed
    {
        $name = $this->responseField($logical);

        return $name === null ? null : ($source[$name] ?? null);
    }

    /* =====================================================================
     | Helpers
     | =================================================================== */

    /** VTUGate documents `status` as a boolean, but JSON may carry a string. */
    private function statusIsFalse(mixed $status): bool
    {
        return $status === false || $status === 0 || $status === '0'
            || (is_string($status) && strtolower($status) === 'false');
    }

    /**
     * The message, reduced to a comparable token.
     *
     * Lower-cased with punctuation and spaces removed, so `"Insufficient balance."`
     * and `insufficient_balance` match the same configured entry. The messages are
     * documentation rather than constants, and a provider may reword one.
     */
    private function normalisedMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return preg_replace('/[^a-z0-9]/', '', strtolower($message)) ?: null;
    }

    private function probeTimeout(): int
    {
        return (int) config('providers.http.probe_timeout', 10);
    }

    private function majorToMinor(string $amount): int
    {
        return Money::fromNaira(number_format((float) $amount, 2, '.', ''))->minor();
    }
}
