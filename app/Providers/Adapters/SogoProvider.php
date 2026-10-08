<?php

namespace App\Providers\Adapters;

use App\Providers\AbstractProviderAdapter;
use App\Providers\Support\ProviderDelivery;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Sogo — Nigerian digital and bill services.
 *
 * Contract: https://developer.sogo.africa/docs
 *
 * ## What this adapter serves
 *
 * Airtime, data, electricity, cable TV, education, ePIN, bet funding and gift
 * card purchases, across the endpoints Sogo publishes. `esim` is **not** served:
 * Sogo's marketing page lists eSIM as a covered product, but the published API
 * reference documents no eSIM catalogue or purchase endpoint, and this adapter
 * does not call endpoints a provider has not documented. See
 * `config/providers.php` → `sogo.pending_capabilities`, which records the reason
 * so it is visible rather than folklore.
 *
 * ## The idempotency contract, which shapes everything here
 *
 * Sogo binds an `Idempotency-Key` **permanently and uniquely** to its
 * transaction, and reconciles by asking
 * `GET /v1/transactions/{idempotency-key}?type=…`:
 *
 *   * `404` → the transaction was never created, so retrying with the same key
 *     is safe;
 *   * `200` → it was created; the body's `status` decides what happens next;
 *   * `409 idempotency_request_in_progress` → an earlier attempt is still
 *     running; the outcome is unknown and must be reconciled, not retried.
 *
 * Hence `reconcile()` returns UNKNOWN rather than FAILED for anything other than
 * an explicit 404. Treating a 409 or a timeout as "it never happened" is exactly
 * how a customer gets charged twice for one purchase.
 *
 * ## Status vocabulary
 *
 * Sogo documents `processing`, `completed`, `failed`, `refunded`, `cancelled`.
 * `processing` maps to PROCESSING, never to a failure: Sogo's own documentation
 * says in as many words "never retry on processing".
 */
class SogoProvider extends AbstractProviderAdapter
{
    public function slug(): string
    {
        return 'sogo';
    }

    public function label(): string
    {
        return (string) $this->config('label', 'Sogo');
    }

    protected function configKey(): string
    {
        return 'sogo';
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

    /* =====================================================================
     | Balance and catalogue
     |=================================================================== */

    /**
     * Sogo's wallet balance.
     *
     * Uses `GET /v1/wallet`. Sandbox reports a fixed figure by design, so a
     * sandbox balance must never be read as a real one — `isSandbox()` is
     * included in the result so the admin console can say so.
     *
     * @return array{success:bool,balance_minor:?int,currency:?string,message:?string,sandbox:bool}
     */
    public function balance(): array
    {
        if (! $this->isConfigured()) {
            return $this->balanceFailure('Not configured.');
        }

        $result = $this->send('GET', $this->url('wallet'), [], [], 'read', $this->probeTimeout());

        if (! $result->isSuccess()) {
            return $this->balanceFailure($result->message ?? 'Balance unavailable.');
        }

        $raw = data_get($result->payload, 'data.balance')
            ?? data_get($result->payload, 'balance');

        if (! is_numeric($raw)) {
            return $this->balanceFailure('The provider did not return a balance.');
        }

        return [
            'success' => true,
            'balance_minor' => $this->majorToMinor((string) $raw),
            'currency' => (string) (data_get($result->payload, 'data.currency') ?? 'NGN'),
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

    /**
     * The bills catalogue.
     *
     * `?type=` filters server-side; the whole catalogue is fetched when no type
     * is given.
     *
     * Every catalogue method here reads through `sendRead()` rather than `send()`.
     * The sync parses these responses for catalogue identifiers, and the redacted
     * `payload` has none: the redactor matches by key name, and Sogo's gift card
     * `code` and a plan's `variation_code` collide with keys that must stay
     * redacted elsewhere. See `ProviderResult::$raw`.
     *
     * @return array<string, mixed>
     */
    public function catalogue(?string $type = null): ProviderResult
    {
        return $this->sendRead(
            'GET',
            $this->url('catalog'),
            $type === null ? [] : ['type' => $type],
            [],
            'read',
        );
    }

    /**
     * Data plans, optionally filtered by network slug.
     *
     * @return array<string, mixed>
     */
    public function dataPlans(?string $network = null): ProviderResult
    {
        return $this->sendRead(
            'GET',
            $this->url('data_plans'),
            $network === null ? [] : ['network' => $network],
            [],
            'read',
        );
    }

    /**
     * Cable TV bouquets, optionally filtered by provider slug.
     */
    public function cablePackages(?string $provider = null): ProviderResult
    {
        return $this->sendRead(
            'GET',
            $this->url('cable_packages'),
            $provider === null ? [] : ['provider' => $provider],
            [],
            'read',
        );
    }

    /** Education plans (JAMB, WAEC). */
    public function educationPlans(?string $provider = null): ProviderResult
    {
        return $this->sendRead(
            'GET',
            $this->url('education_plans'),
            $provider === null ? [] : ['provider' => $provider],
            [],
            'read',
        );
    }

    /**
     * Per-category discount rates for this API key.
     *
     * This is Sogo's own statement of what it charges us, which makes it the
     * authoritative input to the pricing engine's provider-cost figure for bill
     * products whose face value is the customer's amount.
     */
    public function discountRates(): ProviderResult
    {
        return $this->sendRead('GET', $this->url('discount_rates'), [], [], 'read');
    }

    /** Gift card catalogue (buy side). */
    public function giftCardCatalogue(array $filters = []): ProviderResult
    {
        return $this->sendRead(
            'GET',
            $this->url('gift_card_catalog'),
            $filters,
            [],
            'gift_cards_read',
        );
    }

    /* =====================================================================
     | Verification
     * =================================================================== */

    /**
     * Verify an electricity meter.
     *
     * Sogo documents this as a read operation requiring no idempotency key, which
     * is why validation errors here are safe to surface directly.
     */
    public function verifyMeter(string $discoSlug, string $meterNumber, string $meterType): ProviderResult
    {
        return $this->send('POST', $this->url('verify_meter'), [
            'disco_slug' => $discoSlug,
            'meter_number' => $meterNumber,
            'meter_type' => $meterType,
        ], [], 'read');
    }

    public function verifySmartcard(string $provider, string $smartcardNumber): ProviderResult
    {
        return $this->send('POST', $this->url('verify_smartcard'), [
            'provider' => $provider,
            'smartcard_number' => $smartcardNumber,
        ], [], 'read');
    }

    public function verifyJambProfile(string $profileId, ?string $examType = null): ProviderResult
    {
        return $this->send('POST', $this->url('verify_jamb'), array_filter([
            'profile_id' => $profileId,
            'exam_type' => $examType,
        ]), [], 'read');
    }

    public function verifyBetAccount(string $provider, string $accountId): ProviderResult
    {
        return $this->send('POST', $this->url('verify_bet_account'), [
            'provider' => $provider,
            'account_id' => $accountId,
        ], [], 'read');
    }

    /* =====================================================================
     | Purchases
     | =================================================================== */

    /**
     * Purchase airtime.
     *
     * Amount is sent as an integer in the provider's major units — Sogo is
     * explicit that every numeric amount field is major units, and that fields
     * ending `_formatted` are display strings that must not be parsed.
     */
    public function purchaseAirtime(string $network, string $phone, int $amountNaira, string $idempotencyKey): ProviderResult
    {
        return $this->purchase('purchase_airtime', [
            'network' => $network,
            'phone' => $phone,
            'amount' => $amountNaira,
        ], $idempotencyKey);
    }

    public function purchaseData(string $network, string $phone, string $variationCode, string $idempotencyKey, bool $ported = false): ProviderResult
    {
        return $this->purchase('purchase_data', array_filter([
            'network' => $network,
            'phone' => $phone,
            'variation_code' => $variationCode,
            'ported' => $ported ?: null,
        ], fn ($v) => $v !== null), $idempotencyKey);
    }

    public function purchaseElectricity(string $discoSlug, string $meterNumber, string $meterType, int $amountNaira, string $idempotencyKey): ProviderResult
    {
        return $this->purchase('purchase_electricity', [
            'disco_slug' => $discoSlug,
            'meter_number' => $meterNumber,
            'meter_type' => $meterType,
            'amount' => $amountNaira,
        ], $idempotencyKey);
    }

    public function purchaseCableTv(string $provider, string $smartcardNumber, string $subscriptionType, string $variationCode, int $amountNaira, string $idempotencyKey): ProviderResult
    {
        return $this->purchase('purchase_cable_tv', [
            'provider' => $provider,
            'smartcard_number' => $smartcardNumber,
            'subscription_type' => $subscriptionType,
            'variation_code' => $variationCode,
            'amount' => $amountNaira,
        ], $idempotencyKey);
    }

    /**
     * Education (JAMB / WAEC).
     *
     * `service_type` is required by Sogo for WAEC only; JAMB needs `profile_id`.
     */
    public function purchaseEducation(string $provider, string $variationCode, int $amountNaira, string $idempotencyKey, ?string $profileId = null, ?string $serviceType = null): ProviderResult
    {
        return $this->purchase('purchase_education', array_filter([
            'provider' => $provider,
            'variation_code' => $variationCode,
            'amount' => $amountNaira,
            'profile_id' => $profileId,
            'service_type' => $serviceType,
        ], fn ($v) => $v !== null), $idempotencyKey);
    }

    /**
     * ePIN (recharge card) purchase.
     *
     * The response carries the PINs. They are extracted into `ProviderDelivery`
     * and never written to the transaction log — see `extractDelivery()`.
     */
    public function purchaseEpin(string $network, int $denominationNaira, int $quantity, string $idempotencyKey): ProviderResult
    {
        return $this->purchase('purchase_epin', [
            'network' => $network,
            'denomination' => $denominationNaira,
            'quantity' => $quantity,
        ], $idempotencyKey);
    }

    public function purchaseBetFunding(string $provider, string $accountId, int $amountNaira, string $idempotencyKey): ProviderResult
    {
        return $this->purchase('purchase_bet_funding', [
            'provider' => $provider,
            'account_id' => $accountId,
            'amount' => $amountNaira,
        ], $idempotencyKey);
    }

    /**
     * Gift card purchase.
     *
     * `expected_amount_ngn` is Sogo's own expectation of what the order will cost
     * in naira; sending it lets the provider reject a stale price rather than
     * silently charging a different figure — which is the right failure mode,
     * because a silent charge is a customer paying an amount they did not agree
     * to.
     */
    public function purchaseGiftCard(int $productId, string $amount, int $quantity, int $expectedAmountNgn, string $idempotencyKey): ProviderResult
    {
        return $this->purchase('gift_card_purchase', [
            'product_id' => $productId,
            'amount' => $amount,
            'quantity' => $quantity,
            'expected_amount_ngn' => $expectedAmountNgn,
        ], $idempotencyKey, 'write');
    }

    /**
     * The shared purchase path.
     *
     * Every financial write goes through here so that the `Idempotency-Key`
     * header and the `write` scope are impossible to forget.
     *
     * @param  array<string, mixed>  $body
     */
    private function purchase(string $endpoint, array $body, string $idempotencyKey, ?string $scope = 'write'): ProviderResult
    {
        if (trim($idempotencyKey) === '') {
            /*
             * Sogo requires the header and returns 400 without it. Refusing here
             * rather than sending the request means a caller that forgot the key
             * cannot create an unprotected financial operation — the request that
             * would have been unretryable forever simply is not made.
             */
            throw new \RuntimeException('A purchase requires an idempotency key.');
        }

        return $this->send(
            'POST',
            $this->url($endpoint),
            $body,
            ['Idempotency-Key' => $idempotencyKey],
            $scope,
        );
    }

    /* =====================================================================
     | Reconciliation
     * =================================================================== */

    /**
     * Ask Sogo what happened to a transaction we are unsure about.
     *
     * ## The whole point of this method
     *
     * A timeout on a purchase leaves the outcome unknown. This is how it becomes
     * known, and the return value encodes the only safe interpretation of each
     * answer:
     *
     *   * `404` → Sogo never created the transaction. `RETRYABLE`: nothing was
     *     charged, so retrying — with the **same** key — cannot double-charge.
     *   * `200` → the body's status decides. `completed` is SUCCESS, `failed` is
     *     FAILED, `refunded` is REFUNDED, `processing` stays PROCESSING.
     *   * anything else — a 5xx, a 409, a timeout — is **UNKNOWN**, never FAILED.
     *     The transaction may exist. Retrying is how a duplicate is created.
     *
     * @param  string  $idempotencyKey  the key the original request was sent with
     */
    public function reconcile(string $idempotencyKey, string $type = 'bill_payment'): ProviderResult
    {
        $result = $this->send(
            'GET',
            $this->url('transaction_by_idempotency_key', ['idempotency_key' => $idempotencyKey]),
            ['type' => $type],
            [],
            'read',
        );

        /*
         * A transport failure during reconciliation is still unknown. `send()`
         * already returns UNKNOWN for that, so nothing to do.
         */
        if ($result->status === ProviderStatus::UNKNOWN && $result->httpStatus === null) {
            return $result;
        }

        /*
         * A definitive 404 means the transaction was never created. Sogo states
         * this explicitly, and it is the one condition under which a retry is
         * provably safe.
         */
        if ($result->httpStatus === 404) {
            return new ProviderResult(
                status: ProviderStatus::RETRYABLE,
                providerStatus: 'not_found',
                message: 'The provider has no record of this request.',
                errorCode: 'not_found',
                httpStatus: 404,
            );
        }

        return $result;
    }

    /** Fetch a transaction by Sogo's own reference (the webhook's identifier). */
    public function transactionByReference(string $reference, ?string $type = null): ProviderResult
    {
        return $this->send(
            'GET',
            $this->url('transaction_by_reference', ['reference' => $reference]),
            $type === null ? [] : ['type' => $type],
            [],
            'read',
        );
    }

    /**
     * Verify a payment independently — Sogo's own Flow B.
     *
     * Used when confirming that a payment completed before fulfilling on our
     * side. Gating on `verified: true` is the caller's job; this only fetches.
     */
    public function verifyTransaction(string $reference, int $amountNaira, string $currency = 'NGN'): ProviderResult
    {
        return $this->send('POST', $this->url('verify_transaction'), [
            'reference' => $reference,
            'amount' => $amountNaira,
            'currency' => $currency,
        ], [], 'read');
    }

    /* =====================================================================
     | Response normalisation
     | =================================================================== */

    /**
     * Decode Sogo's envelope.
     *
     * Two shapes occur and both must be handled:
     *
     *   * the **bills** endpoints return `{message, data: {reference, status, …}}`;
     *   * the **gift card** endpoints return
     *     `{status, message, transaction: {reference, status: {value, is_final}, amount: {raw, …}}}`.
     *
     * A body that carries an `error` object is a rejection, and the error code
     * decides whether it is safe to fail over. A body with neither shape is
     * UNKNOWN.
     *
     * @param  array<string, mixed>  $body
     */
    protected function normalise(array $body, Response $response): ProviderResult
    {
        /* ---- Explicit error envelope ---------------------------------- */
        $error = $body['error'] ?? null;

        if (is_array($error) && isset($error['code'])) {
            return $this->errorResult(
                (string) $error['code'],
                isset($error['message']) ? (string) $error['message'] : null,
                $body,
                $response->status(),
            );
        }

        /*
         * A non-2xx response carrying no `error` envelope. The status code is then
         * the only signal, and it still carries meaning: a 404 from the
         * reconciliation endpoint is Sogo's documented "this transaction was never
         * created", which is the one answer that makes a retry provably safe.
         * Discarding the code here would turn a safe retry into an unknown one.
         */
        if (! $response->successful()) {
            return new ProviderResult(
                status: ProviderStatus::UNKNOWN,
                providerStatus: 'http_' . $response->status(),
                payload: $this->redactPayload($body),
                message: $this->safeMessage(
                    isset($body['message']) ? (string) $body['message'] : null,
                    'The provider returned an unexpected response.',
                ),
                httpStatus: $response->status(),
            );
        }

        /* ---- Gift card envelope --------------------------------------- */
        $transaction = $body['transaction'] ?? null;

        if (is_array($transaction)) {
            return $this->fromTransactionEnvelope($transaction, $body, $response->status());
        }

        /* ---- Bills envelope ------------------------------------------- */
        $data = $body['data'] ?? null;

        if (is_array($data)) {
            return $this->fromBillsData($data, $body, $response->status());
        }

        /*
         * Neither envelope. A catalogue response has its own shape and is not a
         * transaction, so it is reported as SUCCESS with the raw body — the
         * catalogue caller reads `payload` directly and never the status.
         */
        if ($response->successful() && ($body['data'] ?? null) !== null) {
            return new ProviderResult(
                status: ProviderStatus::SUCCESS,
                payload: $body,
                httpStatus: $response->status(),
            );
        }

        return ProviderResult::fromUnrecognised('unrecognised_envelope', $body, $response->status());
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $body
     */
    private function fromTransactionEnvelope(array $transaction, array $body, int $httpStatus): ProviderResult
    {
        // Gift card status is an object: {value, is_final}.
        $statusValue = $transaction['status'] ?? null;
        $providerStatus = is_array($statusValue)
            ? (string) ($statusValue['value'] ?? '')
            : (string) $statusValue;

        $canonical = $this->mapStatus($providerStatus);

        /*
         * `is_final: false` on a status the map calls SUCCESS would be a
         * contradiction. Trust the provider's own finality flag, because it is
         * the more specific signal: a status the map recognises but the provider
         * says is not yet final is PROCESSING, not a completed sale.
         */
        if ($canonical === ProviderStatus::SUCCESS
            && is_array($statusValue)
            && array_key_exists('is_final', $statusValue)
            && $statusValue['is_final'] === false) {
            $canonical = ProviderStatus::PROCESSING;
        }

        // Amounts are nested objects: {raw, formatted, currency}.
        $amount = $transaction['amount'] ?? null;
        $amountMinor = is_array($amount) && isset($amount['raw'])
            ? $this->majorToMinor((string) $amount['raw'])
            : null;

        $fee = $transaction['fee'] ?? null;
        $feeMinor = is_array($fee) && isset($fee['raw'])
            ? $this->majorToMinor((string) $fee['raw'])
            : null;

        return new ProviderResult(
            status: $canonical,
            providerStatus: $providerStatus !== '' ? $providerStatus : null,
            providerReference: isset($transaction['reference']) ? (string) $transaction['reference'] : null,
            providerCostMinor: $amountMinor,
            providerCurrency: is_array($amount) ? ($amount['currency'] ?? null) : null,
            providerAmountMinor: $amountMinor,
            payload: $this->redactPayload($body),
            message: isset($body['message']) ? $this->safeMessage((string) $body['message'], '') : null,
            httpStatus: $httpStatus,
            delivery: $this->extractDelivery($transaction),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $body
     */
    private function fromBillsData(array $data, array $body, int $httpStatus): ProviderResult
    {
        /*
         * A catalogue response also nests under `data` but carries no `status`.
         * Reporting it as UNKNOWN would be wrong — it is a successful read, not
         * an unknown transaction — so the absence of a status on a 2xx read is
         * treated as a successful read.
         */
        if (! isset($data['status'])) {
            if ($httpStatus >= 200 && $httpStatus < 300) {
                return new ProviderResult(
                    status: ProviderStatus::SUCCESS,
                    payload: $this->redactPayload($body),
                    httpStatus: $httpStatus,
                );
            }

            return ProviderResult::fromUnrecognised('no_status', $body, $httpStatus);
        }

        $providerStatus = (string) $data['status'];

        return new ProviderResult(
            status: $this->mapStatus($providerStatus),
            providerStatus: $providerStatus,
            providerReference: $data['reference'] ?? ($data['order_reference'] ?? null),
            providerCostMinor: isset($data['fee']) && is_numeric($data['fee'])
                ? $this->majorToMinor((string) $data['fee'])
                : null,
            providerCurrency: $data['currency'] ?? 'NGN',
            payload: $this->redactPayload($body),
            message: isset($body['message']) ? $this->safeMessage((string) $body['message'], '') : null,
            httpStatus: $httpStatus,
            delivery: $this->extractDelivery($data),
        );
    }

    /**
     * Pull a delivery token out of a response, into a type that is not logged.
     *
     * Sogo returns e-PINs, exam PINs and electricity tokens in the purchase
     * response; gift card codes must be fetched separately because Sogo omits
     * them from webhooks. Whichever way they arrive, they are lifted out here so
     * that the surrounding `payload` can be stored without them.
     *
     * @param  array<string, mixed>  $source
     */
    private function extractDelivery(array $source): ?ProviderDelivery
    {
        // Gift card: a list of {code, pinCode, validity}.
        $smiles = $source['smiles'] ?? null;

        if (is_array($smiles) && $smiles !== []) {
            $items = [];

            foreach ($smiles as $card) {
                if (is_array($card) && isset($card['code'])) {
                    $items[] = array_filter([
                        'code' => (string) $card['code'],
                        'pin' => isset($card['pinCode']) ? (string) $card['pinCode'] : null,
                        'validity' => isset($card['validity']) ? (string) $card['validity'] : null,
                    ], fn ($v) => $v !== null);
                }
            }

            if ($items !== []) {
                return new ProviderDelivery('gift_card', [], $items);
            }
        }

        // e-PIN: a list of PINs with serials.
        $pins = $source['pins'] ?? ($source['epins'] ?? null);

        if (is_array($pins) && $pins !== []) {
            $items = [];

            foreach ($pins as $entry) {
                if (is_array($entry)) {
                    $items[] = array_filter([
                        'pin' => isset($entry['pin']) ? (string) $entry['pin'] : null,
                        'serial' => isset($entry['serial']) ? (string) $entry['serial'] : null,
                    ], fn ($v) => $v !== null);
                } elseif (is_scalar($entry)) {
                    $items[] = ['pin' => (string) $entry];
                }
            }

            if ($items !== []) {
                return new ProviderDelivery('epin', [], $items);
            }
        }

        // Single token shapes.
        $token = data_get($source, 'token') ?? data_get($source, 'electricity_token');

        if (is_scalar($token) && (string) $token !== '') {
            return ProviderDelivery::electricityToken(
                (string) $token,
                isset($source['units']) ? (string) $source['units'] : null,
            );
        }

        return null;
    }

    /**
     * Map a Sogo error code onto a canonical status.
     *
     * The three-way split is the point: `insufficient_funds` and
     * `service_unavailable` mean nothing was charged (RETRYABLE), a validation
     * failure is deterministic and another provider would reject it identically
     * (FAILED), and `idempotency_request_in_progress` means an earlier attempt is
     * still running (UNKNOWN).
     */
    private function errorResult(string $code, ?string $message, array $body, int $httpStatus): ProviderResult
    {
        $status = match (true) {
            $this->isUnknownError($code) => ProviderStatus::UNKNOWN,
            $this->isRetryableError($code) => ProviderStatus::RETRYABLE,
            /*
             * Authentication and configuration failures are RETRYABLE rather than
             * FAILED: they are our problem, not the customer's request, so failing
             * over to a configured provider is a better outcome than refusing the
             * sale.
             */
            in_array($code, [
                'authentication_required', 'authentication_failed', 'invalid_scope',
                'secret_key_required', 'environment_mismatch', 'ip_not_whitelisted',
                'account_suspended', 'account_deactivated', 'kyc_tier_insufficient',
                'kyb_verification_required', 'https_required', 'api_disabled', 'sandbox_disabled',
            ], true) => ProviderStatus::RETRYABLE,

            // A deterministic rejection: another provider would say the same.
            $code === 'not_found' => ProviderStatus::RETRYABLE,
            $code === 'verification_failed', $code === 'validation_failed' => ProviderStatus::FAILED,

            /*
             * The default for an error code we do not recognise. UNKNOWN, not
             * FAILED: an unfamiliar error is not evidence that the customer was
             * not served, and treating it as one is how a delivered order is
             * refunded.
             */
            default => ProviderStatus::UNKNOWN,
        };

        return new ProviderResult(
            status: $status,
            providerStatus: $code,
            payload: $this->redactPayload($body),
            message: $this->safeMessage($message, 'The provider rejected the request.'),
            errorCode: $code,
            httpStatus: $httpStatus,
        );
    }

    /* =====================================================================
     | Helpers
     | =================================================================== */

    private function probeTimeout(): int
    {
        return (int) config('providers.http.probe_timeout', 10);
    }

    /**
     * Convert a major-unit string from Sogo into integer minor units (kobo).
     *
     * Sogo is explicit that all numeric amount fields are major units and that
     * `_formatted` fields must not be parsed. Parsing through `Money` keeps the
     * conversion exact and rejects a value with more precision than a kobo rather
     * than silently rounding someone's money.
     */
    private function majorToMinor(string $amount): int
    {
        return \App\Support\Money::fromNaira($amount)->minor();
    }
}
