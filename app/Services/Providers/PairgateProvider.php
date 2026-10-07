<?php

namespace App\Services\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pairgate adapter — the alternative upstream to ClubKonnect.
 *
 * API docs: https://pairgate.com/developers/introduction
 *
 * This is the failover path: when ClubKonnect rejects a request or its float is
 * short, BillPaymentService retries the *same* transaction here before deciding
 * whether to refund. Having a second vending route matters most for the
 * products that are least forgiving when one provider is down — electricity
 * tokens and exam PINs, which customers are waiting on.
 *
 * Configure via PAIRGATE_* in .env. Until the key is set the provider reports
 * itself unconfigured and is skipped, so behaviour falls back to ClubKonnect
 * alone.
 *
 * Three properties of this API shape the code below:
 *
 *   1. **Identifiers are Pairgate's own slugs** (`mtn`, `ikedc`, `bet9ja`) while
 *      the rest of the application speaks ClubKonnect's numeric codes
 *      (`01`–`04`, `01`–`12`). Translation lives in config/bills.php under
 *      `pairgate`; an identifier with no mapping is reported as `UNSUPPORTED`
 *      and failed over rather than being sent upstream as a guess.
 *   2. **The envelope is `{code, status, data}`** and the fields that matter
 *      sit inside `data`. Responses are normalised onto the status vocabulary
 *      the pipeline already understands, and a verified account name is lifted
 *      to the top level so controllers need no special case for this provider.
 *   3. **Electricity tokens and exam PINs are asynchronous**: the purchase call
 *      only acknowledges the order, and the token/PIN arrives on Pairgate's
 *      webhook. `issuedToken()` therefore returns whatever the acknowledgement
 *      carries and is usually null; PairgateWebhookController is what stores the
 *      real one (and what reverses an order Pairgate fails after accepting it).
 */
class PairgateProvider implements BillProvider
{
    /** Base URL documented at pairgate.com/developers/introduction. */
    private const BASE = 'https://pairgate.com/api/v1';

    /** The status the pipeline reads as "accepted and processing". */
    private const SUCCESS = 'ORDER_RECEIVED';

    /** Seconds a liveness probe may take before it counts as "not available". */
    private const PROBE_TIMEOUT = 10;

    public function name(): string
    {
        return 'pairgate';
    }

    public function label(): string
    {
        return 'Pairgate';
    }

    public function isConfigured(): bool
    {
        return ! empty(config('services.pairgate.api_key'));
    }

    public function supports(string $product): bool
    {
        return in_array($product, (array) config('bills.providers.pairgate.products', []), true);
    }

    /**
     * A wallet enquiry proves reachability and that the bearer token is still
     * accepted. Deliberately not cached here — ProviderHealthService owns the
     * freshness policy — and bounded to a short timeout, because a probe that
     * hangs is worse than a probe that fails.
     */
    public function ping(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->client(self::PROBE_TIMEOUT)->get($this->url('/wallet/balance'));

            if (! $response->successful()) {
                return false;
            }

            $body = $response->json() ?? [];

            $balance = data_get($body, 'data.balance') ?? ($body['balance'] ?? null);

            return strtolower((string) ($body['status'] ?? '')) === 'success' && is_numeric($balance);
        } catch (\Throwable $e) {
            Log::warning('Pairgate health probe failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * What Pairgate takes from our float for this purchase.
     *
     *   * electricity and betting are billed at face value, so both providers
     *     cost the same and the configured order decides;
     *   * data and cable prices come from Pairgate's own catalogue, but only for
     *     plans the operator has mapped in config/bills.php — an unmapped plan
     *     cannot be bought here at all, so there is no price to compare;
     *   * airtime carries a discount Pairgate does not publish per network, and
     *     exam pins have no price endpoint, so both are unknown.
     *
     * Unknown (null) is safe by construction: ProviderManager only reorders when
     * every candidate has a price.
     *
     * @param  array<string,mixed>  $params
     */
    public function cost(string $product, array $params): ?float
    {
        return match ($product) {
            'electricity', 'betting' => isset($params['amount']) ? (float) $params['amount'] : null,
            'data' => $this->dataPlanCost($params),
            'cable_tv' => $this->cablePlanCost($params),
            default => null,
        };
    }

    private function client(int $timeout = 45)
    {
        $key = config('services.pairgate.api_key');

        if (empty($key)) {
            throw new RuntimeException('Pairgate is not configured.');
        }

        /*
         * No `retry()` here, deliberately.
         *
         * This framework version turns *any* non-2xx response into an exception
         * once retries are enabled (`PendingRequest` calls `$response->throw()`
         * when `tries > 1`), which would throw away the error body — and the
         * error body is the only thing that distinguishes "float is short"
         * (safe to fail over) from "this reference was already processed"
         * (failing over would vend twice). A dropped connection is already
         * covered by classify(): the EXCEPTION status below is retryable, so
         * the pipeline moves to the other provider immediately.
         */
        return Http::withToken($key)
            ->acceptJson()
            ->withHeaders(['Cache-Control' => 'no-cache'])
            ->timeout(45);
    }

    /**
     * Absolute URL for an endpoint.
     *
     * Test mode is a documented prefix on the same path (`/test/airtime/purchase`)
     * rather than a separate host, so it is applied here.
     */
    private function url(string $path): string
    {
        $base = rtrim((string) config('services.pairgate.base_url', self::BASE), '/');

        if ((bool) config('services.pairgate.test_mode', false)) {
            $base .= '/test';
        }

        return $base . $path;
    }

    public function balance(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'balance' => 0.0, 'currency' => 'NGN', 'message' => 'Not configured'];
        }

        return Cache::remember('provider.balance.pairgate', 120, function () {
            try {
                $response = $this->client()->get($this->url('/wallet/balance'));
                $body = $response->json() ?? [];

                $balance = data_get($body, 'data.balance')
                    ?? ($body['balance'] ?? null);

                if (! $response->successful() || ! is_numeric($balance)) {
                    return [
                        'success' => false,
                        'balance' => 0.0,
                        'currency' => 'NGN',
                        'message' => $body['message'] ?? 'Balance unavailable',
                    ];
                }

                return [
                    'success' => true,
                    'balance' => (float) $balance,
                    'currency' => data_get($body, 'data.currency', 'NGN'),
                    'checked_at' => now()->toDateTimeString(),
                ];
            } catch (\Throwable $e) {
                Log::warning('Pairgate balance check failed', ['error' => $e->getMessage()]);

                return ['success' => false, 'balance' => 0.0, 'currency' => 'NGN', 'message' => $e->getMessage()];
            }
        });
    }

    /* =====================================================================
     | Identifier translation
     |=================================================================== */

    /**
     * Look a value up in one of the `pairgate` maps in config/bills.php.
     *
     * The map is fetched whole and indexed directly: plan ids and package codes
     * legitimately contain dots, which dot-notation lookup would misread as
     * nesting.
     */
    private function map(string $map, string $key): ?string
    {
        $values = (array) config('bills.pairgate.' . $map, []);
        $value = $values[$key] ?? $values[strtolower($key)] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A request this upstream cannot serve.
     *
     * Reported as retryable so the pipeline moves on to a provider that can,
     * rather than surfacing it to the customer as a failure. Nothing was sent
     * upstream and nothing was charged, so failing over is safe.
     *
     * The message is carried through to the customer and to the failover log —
     * `errorMessage()` has no canned text for this status — so it names the
     * thing that is missing rather than saying "unsupported".
     *
     * @return array{status:string,message:string}
     */
    private function unsupported(string $kind, string $value, string $hint = ''): array
    {
        Log::warning('Pairgate cannot serve this request; failing over', [
            'kind' => $kind,
            'value' => $value,
        ]);

        return [
            'status' => 'UNSUPPORTED',
            'message' => "Pairgate cannot serve {$kind} [{$value}]."
                . ($hint !== '' ? ' ' . $hint : ' Add a mapping to config/bills.php under `pairgate`.'),
        ];
    }

    /** Pairgate takes 1 for prepaid and 2 for postpaid; the app passes words. */
    private function meterType(?string $value): ?int
    {
        return match (strtolower((string) $value)) {
            '1', 'prepaid' => 1,
            '2', 'postpaid' => 2,
            default => null,
        };
    }

    /**
     * One entry of a plan-translation map, in either supported shape.
     *
     * The maps started as `clubkonnect-plan-id => pairgate-plan-id`. Cost
     * comparison needs more than an id, so an entry may also be an array:
     *
     *   'CK-123' => '45',
     *   'CK-124' => ['plan_id' => '46', 'plan_type' => 'SME'],
     *   'CK-125' => ['plan_id' => '47', 'price' => 500.0],
     *
     * `plan_type` lets the live catalogue be consulted for the current price;
     * `price` short-circuits that with a figure the operator has recorded.
     *
     * @return array{plan_id:string,price:?float,plan_type:?string}|null
     */
    private function planEntry(string $map, string $key): ?array
    {
        $values = (array) config('bills.pairgate.' . $map, []);
        $value = $values[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return ['plan_id' => $value, 'price' => null, 'plan_type' => null];
        }

        if (! is_array($value) || empty($value['plan_id'])) {
            return null;
        }

        return [
            'plan_id' => (string) $value['plan_id'],
            'price' => isset($value['price']) && is_numeric($value['price']) ? (float) $value['price'] : null,
            'plan_type' => isset($value['plan_type']) ? (string) $value['plan_type'] : null,
        ];
    }

    private function planId(string $map, string $key): ?string
    {
        return $this->planEntry($map, $key)['plan_id'] ?? null;
    }

    /* =====================================================================
     | Pricing — what this upstream would charge us
     |=================================================================== */

    /**
     * The price Pairgate publishes for a data bundle we have mapped.
     *
     * @param  array<string,mixed>  $params
     */
    private function dataPlanCost(array $params): ?float
    {
        $entry = $this->planEntry('data_plans', (string) ($params['plan'] ?? ''));

        if ($entry === null) {
            return null;
        }

        if ($entry['price'] !== null) {
            return $entry['price'];
        }

        $network = $this->map('networks', (string) ($params['network'] ?? ''));

        if ($network === null || $entry['plan_type'] === null) {
            return null;
        }

        return $this->cataloguePrice(
            sprintf('pairgate.data_prices.%s.%s', $network, $entry['plan_type']),
            '/data-plans',
            ['provider_id' => $network, 'plan_type' => $entry['plan_type']],
            $entry['plan_id'],
        );
    }

    /**
     * The price Pairgate publishes for a cable bouquet we have mapped.
     *
     * @param  array<string,mixed>  $params
     */
    private function cablePlanCost(array $params): ?float
    {
        $entry = $this->planEntry('cable_packages', (string) ($params['package'] ?? ''));

        if ($entry === null) {
            return null;
        }

        if ($entry['price'] !== null) {
            return $entry['price'];
        }

        $slug = $this->map('cable_providers', (string) ($params['provider'] ?? ''));

        if ($slug === null) {
            return null;
        }

        return $this->cataloguePrice(
            sprintf('pairgate.cable_prices.%s', $slug),
            '/cable-plans',
            ['provider_id' => $slug],
            $entry['plan_id'],
        );
    }

    /**
     * Look a plan's price up in Pairgate's catalogue, cached for hours.
     *
     * A failed or empty fetch is *not* cached: caching it would freeze routing
     * for the whole TTL because of one blip. Callers get null, which means
     * "unknown" and leaves provider selection on the configured order.
     *
     * @param  array<string,string>  $query
     */
    private function cataloguePrice(string $cacheKey, string $path, array $query, string $planId): ?float
    {
        $prices = Cache::get($cacheKey);

        if (! is_array($prices)) {
            try {
                $response = $this->client(self::PROBE_TIMEOUT)->get($this->url($path), $query);

                if (! $response->successful()) {
                    return null;
                }

                $prices = $this->flattenPrices($response->json() ?? []);

                if ($prices === []) {
                    return null;
                }

                Cache::put($cacheKey, $prices, now()->addHours(6));
            } catch (\Throwable $e) {
                Log::warning('Pairgate price lookup failed', ['path' => $path, 'error' => $e->getMessage()]);

                return null;
            }
        }

        $price = $prices[$planId] ?? null;

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * The catalogue is grouped by network or brand:
     * `{"MTN": [{"plan_id": "45", "name": "...", "price": 500}]}`.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,float>
     */
    private function flattenPrices(array $body): array
    {
        $prices = [];

        foreach ((array) ($body['data'] ?? []) as $group) {
            foreach (is_array($group) ? $group : [] as $plan) {
                if (! is_array($plan)) {
                    continue;
                }

                $id = $plan['plan_id'] ?? ($plan['id'] ?? null);
                $price = $plan['price'] ?? null;

                if ($id !== null && is_numeric($price)) {
                    $prices[(string) $id] = (float) $price;
                }
            }
        }

        return $prices;
    }

    /* =====================================================================
     | Transport
     |=================================================================== */

    /**
     * Every call funnels through here, so status normalisation and logging
     * happen once.
     *
     * @return array<string,mixed>|null
     */
    private function post(string $path, array $payload): ?array
    {
        try {
            $response = $this->client()->post($this->url($path), $payload);

            $body = $response->json();

            if (! is_array($body)) {
                return ['status' => 'INVALID_RESPONSE', 'message' => 'Malformed provider response'];
            }

            $body = $this->normalise($body, $response->successful(), $response->status());

            Log::debug('Pairgate response', ['path' => $path, 'status' => $body['status']]);

            return $body;
        } catch (\Throwable $e) {
            Log::error('Pairgate request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return ['status' => 'EXCEPTION', 'message' => $e->getMessage()];
        }
    }

    /**
     * Collapse Pairgate's envelope onto the status vocabulary the pipeline
     * understands.
     *
     * Success is `{"status":"success","data":{"status":true,…}}`; failure is an
     * HTTP error whose only explanation is a human-readable `message`, which is
     * why the failure path matches on text. An unrecognised failure is left as
     * VALIDATION_ERROR, i.e. fatal — the pipeline stops rather than risking a
     * second vendor on an error it does not understand.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function normalise(array $body, bool $httpOk, int $httpStatus): array
    {
        $envelope = strtolower((string) ($body['status'] ?? ''));
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($httpOk && $envelope === 'success' && ($data['status'] ?? true) !== false) {
            $body['status'] = self::SUCCESS;
            $body['message'] = $data['message'] ?? ($body['message'] ?? null);

            return $body;
        }

        $body['status'] = $this->failureStatus($body, $data, $httpStatus);

        return $body;
    }

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $data
     */
    private function failureStatus(array $body, array $data, int $httpStatus): string
    {
        if (in_array($httpStatus, [401, 403], true)) {
            return 'AUTH_ERROR';
        }

        if ($httpStatus === 429) {
            return 'RATE_LIMITED';
        }

        if ($httpStatus >= 500) {
            return 'API_ERROR';
        }

        $message = strtolower((string) ($body['message'] ?? ($data['message'] ?? '')));

        return match (true) {
            str_contains($message, 'insufficient balance') => 'INSUFFICIENT_BALANCE',
            str_contains($message, 'already processed'),
            str_contains($message, 'duplicate') => 'DUPLICATE_REFERENCE',
            str_contains($message, 'plan not found'),
            str_contains($message, 'package not found'),
            str_contains($message, 'wrong provider') => 'PLAN_NOT_FOUND',
            str_contains($message, 'minimum') => 'INVALID_AMOUNT',
            str_contains($message, 'invalid provider'),
            str_contains($message, 'unknown exam type') => 'INVALID_PROVIDER',
            str_contains($message, 'not found') => 'NOT_FOUND',
            default => 'VALIDATION_ERROR',
        };
    }

    /**
     * Verification responses carry the account name inside `data`, while every
     * controller reads `customer_name` off the top level (that is where
     * ClubKonnect puts it). Lift it here so the caller sees one shape.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function verified(string $path, array $payload): ?array
    {
        $response = $this->post($path, $payload);

        if (is_array($response)) {
            foreach (['customer_name', 'customer_address'] as $field) {
                if (! isset($response[$field]) && isset($response['data'][$field])) {
                    $response[$field] = $response['data'][$field];
                }
            }
        }

        return $response;
    }

    /* =====================================================================
     | BillProvider
     |=================================================================== */

    public function verifyCustomer(string $product, array $params): ?array
    {
        switch ($product) {
            case 'cable_tv':
                $slug = $this->map('cable_providers', (string) ($params['provider'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('cable provider', (string) ($params['provider'] ?? ''));
                }

                return $this->verified('/cable/verify', [
                    'provider_id' => $slug,
                    'smartcard' => (string) $params['smartcard_number'],
                ]);

            case 'electricity':
                $slug = $this->map('discos', (string) ($params['disco'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('disco', (string) ($params['disco'] ?? ''));
                }

                $type = $this->meterType($params['meter_type'] ?? null);

                if ($type === null) {
                    return $this->unsupported('meter type', (string) ($params['meter_type'] ?? ''));
                }

                return $this->verified('/electricity/verify', [
                    'provider_id' => $slug,
                    'meter_number' => (string) $params['meter_number'],
                    'meter_type' => $type,
                ]);

            case 'betting':
                $slug = $this->map('betting_providers', (string) ($params['betting_code'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('bookmaker', (string) ($params['betting_code'] ?? ''));
                }

                return $this->verified('/bet/verify', [
                    'provider_id' => $slug,
                    'customer_id' => (string) $params['customer_id'],
                ]);

            case 'jamb':
                // Pairgate sells WAEC / NECO / NABTEB pins. It has no JAMB
                // product and documents no profile-verification endpoint, so
                // this must come back as a refusal rather than an empty body
                // that reads like "the name could not be found".
                return $this->unsupported(
                    'JAMB e-PINs',
                    'jamb',
                    'Pairgate sells WAEC, NECO and NABTEB pins only; ClubKonnect serves this product.'
                );

            default:
                return null;
        }
    }

    public function purchase(string $product, array $params, string $reference): ?array
    {
        switch ($product) {
            case 'airtime':
                $slug = $this->map('networks', (string) ($params['network'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('network', (string) ($params['network'] ?? ''));
                }

                return $this->post('/airtime/purchase', [
                    'provider_id' => $slug,
                    'amount' => (float) $params['amount'],
                    'recipient' => (string) $params['phone'],
                    'reference' => $reference,
                ]);

            case 'data':
                $slug = $this->map('networks', (string) ($params['network'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('network', (string) ($params['network'] ?? ''));
                }

                /*
                 * Plan ids are per-provider and there is no way to derive one
                 * from the other, so the ClubKonnect plan id the catalogue
                 * posted has to be mapped explicitly. Unmapped means "this
                 * upstream cannot sell that bundle", not "the sale failed".
                 */
                $plan = $this->planId('data_plans', (string) ($params['plan'] ?? ''));

                if ($plan === null) {
                    return $this->unsupported(
                        'data plan',
                        (string) ($params['plan'] ?? ''),
                        'Populate `pairgate.data_plans` from GET /data-plans.'
                    );
                }

                return $this->post('/data/purchase', [
                    'provider_id' => $slug,
                    'plan_id' => $plan,
                    'recipient' => (string) $params['phone'],
                    'reference' => $reference,
                ]);

            case 'cable_tv':
                $slug = $this->map('cable_providers', (string) ($params['provider'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('cable provider', (string) ($params['provider'] ?? ''));
                }

                // Same reasoning as data plans: the bouquet the catalogue
                // posted is ClubKonnect's, and Pairgate bills its own plan ids.
                $plan = $this->planId('cable_packages', (string) ($params['package'] ?? ''));

                if ($plan === null) {
                    return $this->unsupported(
                        'cable package',
                        (string) ($params['package'] ?? ''),
                        'Populate `pairgate.cable_packages` from GET /cable-plans.'
                    );
                }

                return $this->post('/cable/purchase', [
                    'provider_id' => $slug,
                    'plan_id' => $plan,
                    'smartcard' => (string) $params['smartcard_number'],
                    'reference' => $reference,
                ]);

            case 'electricity':
                $slug = $this->map('discos', (string) ($params['disco'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('disco', (string) ($params['disco'] ?? ''));
                }

                $type = $this->meterType($params['meter_type'] ?? null);

                if ($type === null) {
                    return $this->unsupported('meter type', (string) ($params['meter_type'] ?? ''));
                }

                return $this->post('/electricity/purchase', [
                    'provider_id' => $slug,
                    'amount' => (float) $params['amount'],
                    'meter_number' => (string) $params['meter_number'],
                    'meter_type' => $type,
                    'reference' => $reference,
                ]);

            case 'waec':
                $exam = $this->map('exam_providers', strtolower((string) ($params['exam_type'] ?? 'waec')));

                if ($exam === null) {
                    return $this->unsupported('exam body', (string) ($params['exam_type'] ?? ''));
                }

                return $this->post('/education/purchase', [
                    'provider_id' => $exam,
                    'quantity' => (int) $params['quantity'],
                    'reference' => $reference,
                ]);

            case 'jamb':
                return $this->unsupported(
                    'JAMB e-PINs',
                    'jamb',
                    'Pairgate sells WAEC, NECO and NABTEB pins only; ClubKonnect serves this product.'
                );

            case 'betting':
                $slug = $this->map('betting_providers', (string) ($params['betting_code'] ?? ''));

                if ($slug === null) {
                    return $this->unsupported('bookmaker', (string) ($params['betting_code'] ?? ''));
                }

                return $this->post('/bet/purchase', [
                    'provider_id' => $slug,
                    'amount' => (float) $params['amount'],
                    'customer_id' => (string) $params['customer_id'],
                    'reference' => $reference,
                ]);

            default:
                return null;
        }
    }

    public function isSuccess(?array $response): bool
    {
        return strtoupper((string) ($response['status'] ?? '')) === self::SUCCESS;
    }

    public function errorMessage(?array $response): string
    {
        if (! $response) {
            return 'No response from the alternative provider.';
        }

        $messages = [
            'INSUFFICIENT_BALANCE' => 'Provider float is insufficient.',
            'INVALID_PROVIDER' => 'The alternative provider does not serve that network or biller.',
            'INVALID_AMOUNT' => "The amount is outside the alternative provider's limits.",
            'PLAN_NOT_FOUND' => 'That plan is not available on the alternative provider.',
            'DUPLICATE_REFERENCE' => 'The alternative provider has already processed this reference.',
            'AUTH_ERROR' => 'The alternative provider rejected our credentials.',
            'RATE_LIMITED' => 'The alternative provider is rate limiting us.',
            'API_ERROR' => 'The alternative provider is unavailable.',
            'EXCEPTION' => 'Could not reach the alternative provider.',
            'INVALID_RESPONSE' => 'The alternative provider returned an unexpected response.',
            'NOT_FOUND' => 'The alternative provider has no record of that transaction.',
        ];

        $status = strtoupper((string) ($response['status'] ?? 'FAILED'));

        return $messages[$status] ?? ($response['message'] ?? 'Transaction failed: ' . $status);
    }

    public function orderReference(?array $response): ?string
    {
        /*
         * Purchases return `data.reference_code`. Exam pins are the exception:
         * the acknowledgement carries our own reference in `data.reference`
         * and one generated reference_code per pin in `data.details`.
         */
        return data_get($response, 'data.reference_code')
            ?? data_get($response, 'data.details.0.reference')
            ?? ($response['reference'] ?? null);
    }

    public function issuedToken(?array $response): ?string
    {
        // Electricity tokens and exam PINs are delivered by webhook, so this is
        // usually null at purchase time; it is set when Pairgate returns the
        // token inline (or when a response carrying one is replayed here).
        $token = data_get($response, 'data.pin')
            ?? data_get($response, 'data.token')
            ?? data_get($response, 'data.units');

        if (is_scalar($token) && (string) $token !== '') {
            return (string) $token;
        }

        $details = data_get($response, 'data.details');

        if (is_array($details)) {
            foreach ($details as $detail) {
                $pin = is_array($detail) ? ($detail['pin'] ?? null) : null;

                if (is_scalar($pin) && (string) $pin !== '') {
                    return (string) $pin;
                }
            }
        }

        return null;
    }
}
