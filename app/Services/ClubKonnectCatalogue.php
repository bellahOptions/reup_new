<?php

namespace App\Services;

use App\Pricing\PricingEngine;
use App\Pricing\ProviderCost;
use App\Providers\CatalogueSyncStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ClubKonnect's data-bundle catalogue, with prices.
 *
 * This was PricelistController's private logic. It moved here because the price
 * a plan costs *us* (`clubkonnect_price`) stopped being a display concern:
 * ProviderManager compares it with Pairgate's price for the same bundle and
 * routes the sale to the cheaper upstream, so it has to be readable outside the
 * controller — and the customer-facing price must keep coming from exactly one
 * place.
 *
 * ## The provider's price fields, and which one is the cost
 *
 * The payload publishes `PRODUCT_AMOUNT` per bundle. That is what ClubKonnect
 * charges ReUp for that bundle and it is the **only** figure the pricing engine
 * is given, as `clubkonnect_price`. `your_price` is not a provider field at all:
 * it is this application's selling price, computed from the cost by
 * `PricingEngine` so that the page and the purchase cannot disagree.
 *
 * Nothing else in the payload is a cost. There is no discount rate, no
 * commission and no separate list-versus-net pair on this endpoint, so there is
 * no second candidate for "the real cost" and markup must be applied to
 * `PRODUCT_AMOUNT` and nothing else.
 *
 * ## Three read paths, deliberately
 *
 *   * `plans()` fetches, processes and caches (ten minutes, plus a day-long
 *     fallback copy) — what the pricelist page and the purchase resolver use;
 *   * `planPrice()` reads the cache only. A price lookup happens while a
 *     customer waits for checkout, so it must never add an upstream round trip:
 *     when the cache is cold the price is unknown and routing simply stays on
 *     the configured order.
 *   * `forceRefresh()` is the scheduled synchronisation. It is the only path
 *     that treats a provider failure as an error rather than as "serve the last
 *     good copy", because a scheduled run that silently degraded to a cache read
 *     would report success for a provider that never answered.
 *
 * ## A failure never replaces a good catalogue
 *
 * `fetchOrFail()` returns a processed catalogue or throws. It never returns an
 * empty array to mean "no products", because a malformed response and a provider
 * with nothing to sell look identical from here — and writing the first over the
 * second empties the storefront. Every write to the cache happens after a
 * response has been proven non-empty.
 */
class ClubKonnectCatalogue
{
    /** The pricelist's refresh endpoint clears both of these. */
    public const CACHE_KEY = CatalogueSyncStatus::DATA_KEY;

    public const FALLBACK_KEY = CatalogueSyncStatus::FALLBACK_KEY;

    /** Seconds a fetched catalogue stays usable. */
    public const TTL = 600;

    /** Seconds the last good copy survives so an outage cannot empty the list. */
    private const FALLBACK_TTL = 86400;

    /**
     * The provider's catalogue endpoint, as documented by NelloBytes/ClubKonnect.
     *
     * Named once. The `UserID` credential travels in the query string, which is
     * why every log line below scrubs it.
     */
    private const ENDPOINT = 'https://www.nellobytesystems.com/APIDatabundlePlansV2.asp';

    /**
     * Margin added on top of the upstream price to get the customer price.
     *
     * @deprecated Superseded by the pricing engine. Kept only so the historical
     *             constant is greppable and the change is reviewable; the value is
     *             no longer used to compute a customer price. Set the margin on a
     *             pricing rule instead — see `App\Pricing\PricingRuleService`.
     */
    private const PROFIT_MARGIN = 0.015;

    private const NETWORK_NAMES = [
        '01' => 'MTN',
        '02' => 'GLO',
        '03' => '9MOBILE',
        '04' => 'AIRTEL',
    ];

    private ?string $clientId;

    /**
     * The pricing rule behind the most recent `customerPriceFor()` call.
     *
     * Carried on the plan row so a price-change confirmation can prove that the
     * rule which produced the shown price is still the rule producing the price
     * now — a rule edited between the two is a different price for the same
     * bundle, and the customer has to be asked again.
     */
    private ?int $lastQuoteRuleId = null;

    public function __construct(
        private readonly PricingEngine $pricing,
        private readonly CatalogueSyncStatus $status,
    ) {
        $this->clientId = config('services.clubkonnect.client_id');
    }

    /**
     * The whole priced catalogue, fetching when the cache is cold.
     *
     * @return array{data_plans:array<int,array<string,mixed>>,last_updated:?string,total_plans:int,error?:string}
     */
    public function plans(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, fn () => $this->fetch());
    }

    /**
     * The catalogue as it stands in cache, or null when there is none.
     *
     * @return array<string,mixed>|null
     */
    public function cachedPlans(): ?array
    {
        $payload = Cache::get(self::CACHE_KEY) ?? Cache::get(self::FALLBACK_KEY);

        return is_array($payload) ? $payload : null;
    }

    /**
     * How fresh the catalogue is, and what went wrong last time.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return $this->status->current();
    }

    public function isStale(): bool
    {
        return $this->status->isStale();
    }

    /**
     * Resolve a plan by product code or id, optionally scoped to one network.
     *
     * This is the only authoritative price. A request must never be able to tell
     * us what a plan costs — the pricelist markup previously carried
     * `plan_price` from a data attribute and posted it back, so editing the page
     * in devtools bought a ₦20,000 bundle for ₦1.
     *
     * ## `$networkCode` is what makes the lookup unambiguous
     *
     * ClubKonnect reuses product ids across networks: `500`, `100.01`, `200.01` and
     * eighteen others each exist on two or three networks at once. `plan_code` is
     * worse — every one of its 41 values appears on more than one network. So a
     * lookup by identifier alone returns whichever row the provider listed first,
     * and a customer who selected an MTN bundle was handed Glo's or 9mobile's row
     * with the same id.
     *
     * That is the whole of the reported fault: the row that came back belonged to a
     * network the customer had not selected, the checkout compared them honestly,
     * and refused with "that data bundle belongs to a different network from the one
     * selected" — while the customer looked at a bundle that was, in fact, correct.
     *
     * The identifier is **not** trusted to be unique, and the network is not taken on
     * faith either: `$networkCode` narrows the search, and the caller still compares
     * canonical networks afterwards. Discovery here, verification there.
     *
     * @param  string|null  $networkCode  ClubKonnect's network code, e.g. `01`
     * @return array<string,mixed>|null
     */
    public function plan(string $code, ?string $networkCode = null): ?array
    {
        foreach ($this->plans()['data_plans'] ?? [] as $plan) {
            if ($networkCode !== null && (string) ($plan['network_code'] ?? '') !== (string) $networkCode) {
                continue;
            }

            if ($this->matches($plan, $code)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * What ClubKonnect charges us for a plan, or null when it is not known
     * without asking upstream. Never fetches — see the class docblock.
     *
     * ## Scoped for the same reason `plan()` is
     *
     * Product ids are reused across networks, so an unscoped lookup can return a
     * *different network's* bundle — with that network's price. For `plan()` that
     * produced a spurious network refusal; here it would record the wrong provider
     * cost against a sale, which is the input to the margin on the snapshot and to
     * `ProfitRecorder`. A wrong figure there is worse than a refusal: it is a
     * plausible number that nobody will question.
     *
     * @param  string|null  $networkCode  ClubKonnect's code, e.g. `01`
     */
    public function planPrice(string $code, ?string $networkCode = null): ?float
    {
        foreach ($this->cachedPlans()['data_plans'] ?? [] as $plan) {
            if ($networkCode !== null && (string) ($plan['network_code'] ?? '') !== (string) $networkCode) {
                continue;
            }

            if ($this->matches($plan, $code)) {
                $price = $plan['clubkonnect_price'] ?? null;

                return is_numeric($price) ? (float) $price : null;
            }
        }

        return null;
    }

    public function networkName(string $code): string
    {
        return self::NETWORK_NAMES[$code] ?? 'Unknown';
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::FALLBACK_KEY);
    }

    /* =====================================================================
     | Synchronisation
     |=================================================================== */

    /**
     * Fetch the catalogue from the provider unconditionally, or throw.
     *
     * This is the scheduled synchronisation, and it is deliberately not the same
     * call as `plans()`. `plans()` is a cache read that degrades to the last good
     * copy; a scheduled run that did that would report success for a provider
     * that never answered, and the failure would surface only as prices quietly
     * ageing. Here a failure is a failure.
     *
     * Nothing is written to either cache key until a complete, priced catalogue
     * has been produced, so a timeout, a rate limit, an HTTP error or a malformed
     * body leaves the previous catalogue exactly as it was.
     *
     * @return array{data_plans:array<int,array<string,mixed>>,last_updated:string,total_plans:int}
     *
     * @throws CatalogueSyncFailed
     */
    public function forceRefresh(): array
    {
        $started = microtime(true);

        $this->status->recordAttempt();

        try {
            $payload = $this->fetchOrFail();
        } catch (CatalogueSyncFailed $e) {
            $this->status->recordFailure($e->reason, $e->getMessage(), $e->httpStatus);

            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $started) * 1000);

        /*
         * Written only now. The active key is *overwritten* rather than
         * forgotten first, so a request arriving between the two writes reads the
         * old catalogue rather than an empty one.
         */
        Cache::put(self::CACHE_KEY, $payload, self::TTL);
        Cache::put(self::FALLBACK_KEY, $payload, self::FALLBACK_TTL);

        $this->status->recordSuccess((int) $payload['total_plans'], $durationMs);

        Log::info('ClubKonnect data catalogue synchronised', [
            'plans' => $payload['total_plans'],
            'duration_ms' => $durationMs,
        ]);

        return $payload;
    }

    /**
     * Fetch, process and cache, falling back to the last good copy on failure.
     *
     * The behaviour `plans()` has always had, kept exactly: a customer must never
     * see an empty price list because the provider is briefly unavailable.
     *
     * @return array{data_plans:array<int,array<string,mixed>>,last_updated:?string,total_plans:int,error?:string}
     */
    private function fetch(): array
    {
        $started = microtime(true);

        $this->status->recordAttempt();

        try {
            $payload = $this->fetchOrFail();
        } catch (CatalogueSyncFailed $e) {
            $this->status->recordFailure($e->reason, $e->getMessage(), $e->httpStatus);

            $fallback = Cache::get(self::FALLBACK_KEY);

            if (is_array($fallback) && ($fallback['data_plans'] ?? []) !== []) {
                return $fallback + ['error' => $e->customerMessage()];
            }

            return [
                'data_plans' => [],
                'last_updated' => null,
                'total_plans' => 0,
                'error' => $e->customerMessage(),
            ];
        }

        Cache::put(self::FALLBACK_KEY, $payload, self::FALLBACK_TTL);

        $this->status->recordSuccess(
            (int) $payload['total_plans'],
            (int) ((microtime(true) - $started) * 1000),
        );

        return $payload;
    }

    /**
     * One complete, priced catalogue, or an exception naming what went wrong.
     *
     * @return array{data_plans:array<int,array<string,mixed>>,last_updated:string,total_plans:int}
     *
     * @throws CatalogueSyncFailed
     */
    private function fetchOrFail(): array
    {
        if (empty($this->clientId)) {
            throw new CatalogueSyncFailed(
                CatalogueSyncStatus::ERROR_NOT_CONFIGURED,
                'Airtime provider is not configured.',
            );
        }

        $response = $this->request();

        if ($response === null) {
            throw new CatalogueSyncFailed(
                CatalogueSyncStatus::ERROR_TIMEOUT,
                'The catalogue request timed out or could not be sent.',
            );
        }

        if ($response->status() === 429) {
            throw new CatalogueSyncFailed(
                CatalogueSyncStatus::ERROR_RATE_LIMITED,
                'The provider rate-limited the catalogue request.',
                429,
            );
        }

        if (! $response->successful()) {
            throw new CatalogueSyncFailed(
                CatalogueSyncStatus::ERROR_HTTP,
                'The catalogue request failed with status ' . $response->status() . '.',
                $response->status(),
            );
        }

        $processedPlans = $this->processPlans($response->json());

        /*
         * An empty result is a failure, never an empty catalogue.
         *
         * This is the guard that stops a malformed or half-answered response from
         * overwriting a working price list. `processPlans()` already refuses a
         * payload that does not carry the documented shape; this catches the case
         * where the shape is right and every row was unusable, which is what a
         * truncated response looks like.
         */
        if ($processedPlans === []) {
            throw new CatalogueSyncFailed(
                CatalogueSyncStatus::ERROR_EMPTY,
                'The provider returned a catalogue with no usable, priced bundles.',
            );
        }

        return [
            'data_plans' => $processedPlans,
            'last_updated' => now()->toDateTimeString(),
            'total_plans' => count($processedPlans),
        ];
    }

    /**
     * The provider call, with its failures already classified.
     *
     * `Http::get()` throws on a connection failure rather than returning a
     * status, so the transport exception is caught here and reported as a
     * timeout — which is what it is from the caller's point of view, and what the
     * status record must say.
     */
    private function request(): ?\Illuminate\Http\Client\Response
    {
        try {
            return Http::timeout($this->timeoutSeconds())->get(self::ENDPOINT, [
                'UserID' => $this->clientId,
            ]);
        } catch (\Throwable $e) {
            throw new CatalogueSyncFailed(
                CatalogueSyncStatus::ERROR_TIMEOUT,
                $this->scrub($e->getMessage()),
            );
        }
    }

    /**
     * How long to wait for the provider.
     *
     * Configurable, because a scheduled sync and a page render want different
     * answers: the scheduler can afford to wait, and a customer waiting for a
     * price cannot.
     */
    private function timeoutSeconds(): int
    {
        return max(1, (int) config('pricing.catalogue.timeout_seconds', 30));
    }

    /**
     * Remove the credential from a message before it is logged or stored.
     *
     * The client id travels in the query string, and a transport exception quotes
     * the URL. Removing it here rather than at each log site means a new log
     * statement cannot reintroduce the leak.
     */
    private function scrub(string $message): string
    {
        if (! empty($this->clientId)) {
            $message = str_replace((string) $this->clientId, '[redacted]', $message);
        }

        return preg_replace('~\?[^\s\'"]+~', '?[redacted]', $message) ?? $message;
    }

    /* =====================================================================
     | Parsing
     | =================================================================== */

    /**
     * @param  mixed  $apiData
     * @return array<int,array<string,mixed>>
     */
    private function processPlans($apiData): array
    {
        $processedPlans = [];

        if (empty($apiData) || ! isset($apiData['MOBILE_NETWORK'])) {
            // Shape only: an unexpected payload is often the provider echoing
            // the request, which carries UserID and APIKey.
            Log::error('Invalid API response structure', [
                'type' => gettype($apiData),
                'keys' => is_array($apiData) ? array_slice(array_keys($apiData), 0, 10) : [],
                'status' => is_array($apiData) ? ($apiData['status'] ?? null) : null,
            ]);

            return $processedPlans;
        }

        /*
         * Read once, outside the loop. Every row carries the time its provider
         * cost was read, and asking the cache per bundle would be one cache read
         * per plan for a value that cannot change mid-parse.
         */
        $costSyncedAt = $this->status->current()['last_success_at'];

        foreach ($apiData['MOBILE_NETWORK'] as $networkKey => $networkDataArray) {
            if (! is_array($networkDataArray) || empty($networkDataArray)) {
                continue;
            }

            // Each network is an array with one element.
            $networkData = $networkDataArray[0];

            if (! isset($networkData['ID'], $networkData['PRODUCT'])) {
                Log::warning("Missing ID or PRODUCT for network: {$networkKey}");

                continue;
            }

            $networkCode = $networkData['ID'];
            $networkName = self::NETWORK_NAMES[$networkCode] ?? strtoupper((string) $networkKey);

            foreach ($networkData['PRODUCT'] as $product) {
                $basePrice = (float) ($product['PRODUCT_AMOUNT'] ?? 0);
                $productId = $product['PRODUCT_ID'] ?? '';
                $productName = $product['PRODUCT_NAME'] ?? '';
                $productCode = $product['PRODUCT_CODE'] ?? '';

                // Skip if price is zero or invalid
                if ($basePrice <= 0) {
                    continue;
                }

                $planDetails = $this->extractPlanDetails($productName);
                $yourPrice = $this->customerPriceFor((float) $basePrice, (string) $networkCode, (string) $productCode);

                $processedPlans[] = [
                    'network_code' => $networkCode,
                    'network' => $networkName,
                    'network_key' => NetworkResolver::key((string) $networkCode, 'clubkonnect'),
                    'plan_id' => $productId,
                    'plan_code' => $productCode,
                    'plan_name' => $productName,
                    'data_volume' => $planDetails['data_volume'],
                    'validity' => $planDetails['validity'],
                    'plan_type' => $this->determinePlanType($productName),
                    /*
                     * What the provider charges ReUp, from `PRODUCT_AMOUNT`. This
                     * is provider-confidential: the pricelist view must not render
                     * it, and it is present here because `ProviderManager` compares
                     * it across providers to route the sale to the cheaper
                     * upstream.
                     */
                    'clubkonnect_price' => $basePrice,
                    /*
                     * What the customer pays, from the pricing engine. Null when no
                     * active rule covers the bundle: the sale is refused rather than
                     * priced at a guess, and the page renders the bundle as
                     * unavailable instead of inventing a number.
                     */
                    'your_price' => $yourPrice,
                    'price_available' => $yourPrice !== null,
                    /*
                     * The id of the rule that produced this price, and the moment
                     * the provider cost behind it was read. Both travel to the
                     * browser so a price-change confirmation can prove that the
                     * figure it is confirming is the figure it was shown — see
                     * `App\Pricing\PriceChangeQuote`.
                     */
                    'pricing_rule_id' => $this->lastQuoteRuleId,
                    'cost_synced_at' => $costSyncedAt,
                    'sort_key' => $this->createSortKey($basePrice, $networkName),
                ];
            }
        }

        // Sort plans: by network, then by price
        usort($processedPlans, function ($a, $b) {
            if ($a['network'] === $b['network']) {
                return $a['clubkonnect_price'] <=> $b['clubkonnect_price'];
            }

            return $a['network'] <=> $b['network'];
        });

        Log::info('Total processed plans:', ['count' => count($processedPlans)]);

        return $processedPlans;
    }

    /**
     * @return array{data_volume:string,validity:string}
     */
    private function extractPlanDetails(string $productName): array
    {
        $details = [
            'data_volume' => 'N/A',
            'validity' => 'N/A',
        ];

        if (preg_match('/(\d+(\.\d+)?)\s*(GB|MB|TB)/i', $productName, $matches)) {
            $details['data_volume'] = $matches[1] . ' ' . strtoupper($matches[3]);
        }

        if (preg_match('/(\d+)\s*(day|days|month|months|year|years)/i', $productName, $matches)) {
            $days = (int) $matches[1];
            $unit = strtolower($matches[2]);

            if ($days === 1) {
                $details['validity'] = "{$days} day";
            } elseif ($days < 30) {
                $details['validity'] = "{$days} days";
            } elseif (in_array($days, [30, 60, 90, 180, 365], true)) {
                $details['validity'] = "{$days} days";
            } else {
                $details['validity'] = "{$days} {$unit}";
            }
        }

        return $details;
    }

    private function determinePlanType(string $productName): string
    {
        $productName = strtoupper($productName);

        return match (true) {
            str_contains($productName, 'SME') => 'SME Data',
            str_contains($productName, 'AWOOF') => 'Awoof Data',
            str_contains($productName, 'DIRECT') => 'Direct Data',
            str_contains($productName, 'NIGHT') => 'Night Plan',
            str_contains($productName, 'WEEKEND') => 'Weekend Plan',
            str_contains($productName, 'DAILY') => 'Daily Plan',
            str_contains($productName, 'WEEKLY') => 'Weekly Plan',
            str_contains($productName, 'MONTHLY') => 'Monthly Plan',
            default => 'Direct Data',
        };
    }

    private function createSortKey(float $price, string $network): string
    {
        $networkOrder = ['MTN' => 1, 'AIRTEL' => 2, 'GLO' => 3, '9MOBILE' => 4];
        $networkValue = $networkOrder[$network] ?? 99;

        return sprintf('%02d-%010d', $networkValue, $price * 100);
    }

    /**
     * The price a customer pays for a bundle, from the central pricing engine.
     *
     * ## What this replaced
     *
     * `calculateWithProfit()` multiplied the provider cost by 1.015 and published
     * the result as `your_price`. That meant every bundle on every network carried
     * the same 1.5% margin, the margin could not be changed without a deploy, no
     * network could be priced differently, and — because the pricelist is a
     * *display* surface — the number a customer saw was computed independently of
     * the number the purchase path charged.
     *
     * Routing both through `PricingEngine` means one rule decides the price, the
     * page shows that rule's output, and the purchase re-prices through the same
     * rule. The margin is set per network in the admin console.
     *
     * Returns null when the engine refuses — no rule configured, cost below the
     * profit floor, or no usable cost. The caller renders the bundle as unavailable.
     * A null here is deliberate: substituting the provider cost would sell at zero
     * margin, and substituting the old 1.5% would hide a configuration gap that the
     * operator needs to see.
     *
     * @return float|null naira, or null when the bundle cannot be priced
     */
    private function customerPriceFor(float $providerCost, string $networkCode, string $planCode): ?float
    {
        $this->lastQuoteRuleId = null;

        if ($providerCost <= 0) {
            return null;
        }

        $costMinor = (int) round($providerCost * 100);

        $quote = $this->pricing->quote(
            providerCost: $costMinor,
            quantity: 1,
            context: [
                'network' => NetworkResolver::key($networkCode, 'clubkonnect'),
                'capability' => ProviderCostResolver::CAPABILITY_DATA,
            ],
            costMeta: [
                'source' => ProviderCost::SOURCE_CATALOGUE,
                'estimated' => false,
            ],
        );

        if (! $quote->isSellable()) {
            Log::info('Data bundle has no sellable price', [
                'plan_code' => $planCode,
                'network_code' => $networkCode,
                'provider_cost_minor' => $costMinor,
                'reason' => $quote->refusalReason,
            ]);

            return null;
        }

        $this->lastQuoteRuleId = $quote->pricingRuleId;

        return round($quote->customerPriceMinor / 100, 2);
    }

    /**
     * A stable identifier for a plan, for quoting and confirming a price.
     *
     * `plan_code` when the provider supplies one, `plan_id` otherwise — the same
     * pair `matches()` resolves on, so a quote and the purchase it confirms can
     * never address different rows.
     *
     * @param  array<string,mixed>  $plan
     */
    public static function planIdentifier(array $plan): ?string
    {
        foreach (['plan_code', 'plan_id'] as $field) {
            $value = $plan[$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function matches(array $plan, string $code): bool
    {
        return (string) ($plan['plan_code'] ?? '') === $code
            || (string) ($plan['plan_id'] ?? '') === $code;
    }
}
