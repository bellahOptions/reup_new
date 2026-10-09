<?php

namespace App\Services;

use App\Pricing\PricingEngine;
use App\Pricing\ProviderCost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 * Two read paths, deliberately:
 *
 *   * `plans()` fetches, processes and caches (ten minutes, plus a day-long
 *     fallback copy) — what the pricelist page and the purchase resolver use;
 *   * `planPrice()` reads the cache only. A price lookup happens while a
 *     customer waits for checkout, so it must never add an upstream round trip:
 *     when the cache is cold the price is unknown and routing simply stays on
 *     the configured order.
 */
class ClubKonnectCatalogue
{
    /** The pricelist's refresh endpoint clears both of these. */
    public const CACHE_KEY = 'clubkonnect_data_plans';

    public const FALLBACK_KEY = 'clubkonnect_data_plans_fallback';

    /** Seconds a fetched catalogue stays usable. */
    public const TTL = 600;

    /** Seconds the last good copy survives so an outage cannot empty the list. */
    private const FALLBACK_TTL = 86400;

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

    public function __construct(
        private readonly PricingEngine $pricing,
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
     * Resolve a plan by product code or id.
     *
     * This is the only authoritative price. A request must never be able to tell
     * us what a plan costs — the pricelist markup previously carried
     * `plan_price` from a data attribute and posted it back, so editing the page
     * in devtools bought a ₦20,000 bundle for ₦1.
     *
     * @return array<string,mixed>|null
     */
    public function plan(string $code): ?array
    {
        foreach ($this->plans()['data_plans'] ?? [] as $plan) {
            if ($this->matches($plan, $code)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * What ClubKonnect charges us for a plan, or null when it is not known
     * without asking upstream. Never fetches — see the class docblock.
     */
    public function planPrice(string $code): ?float
    {
        foreach ($this->cachedPlans()['data_plans'] ?? [] as $plan) {
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

    /**
     * @param  array<string,mixed>  $plan
     */
    private function matches(array $plan, string $code): bool
    {
        return (string) ($plan['plan_code'] ?? '') === $code
            || (string) ($plan['plan_id'] ?? '') === $code;
    }

    /**
     * @return array{data_plans:array<int,array<string,mixed>>,last_updated:?string,total_plans:int,error?:string}
     */
    private function fetch(): array
    {
        try {
            // The config key is `client_id`; this previously read `user_id`,
            // which does not exist, so every catalogue fetch went upstream
            // unauthenticated and returned nothing.
            if (empty($this->clientId)) {
                throw new \Exception('Airtime provider is not configured.');
            }

            $response = Http::timeout(30)->get(self::ENDPOINT, [
                'UserID' => $this->clientId,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Catalogue request failed with status ' . $response->status());
            }

            $processedPlans = $this->processPlans($response->json());

            $payload = [
                'data_plans' => $processedPlans,
                'last_updated' => now()->toDateTimeString(),
                'total_plans' => count($processedPlans),
            ];

            // Keep a fallback copy so a transient upstream outage does not
            // empty the price list.
            Cache::put(self::FALLBACK_KEY, $payload, self::FALLBACK_TTL);

            return $payload;
        } catch (\Throwable $e) {
            // The transport message embeds the request URL, and `UserID` sits
            // in the query string — scrub it before it reaches the log.
            $message = $e->getMessage();

            if (! empty($this->clientId)) {
                $message = str_replace((string) $this->clientId, '[redacted]', $message);
            }

            $message = preg_replace('~\?[^\s\'"]+~', '?[redacted]', $message) ?? $message;

            Log::error('Data plan catalogue fetch failed', [
                'exception' => get_class($e),
                'message' => $message,
            ]);

            $fallback = Cache::get(self::FALLBACK_KEY);

            return is_array($fallback) ? $fallback : [
                'data_plans' => [],
                'last_updated' => null,
                'total_plans' => 0,
                'error' => 'Live rates are temporarily unavailable. Please try again shortly.',
            ];
        }
    }

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
                     * What the provider charges ReUp. This is provider-confidential:
                     * the pricelist view must not render it, and it is present here
                     * because `ProviderManager` compares it across providers to route
                     * the sale to the cheaper upstream.
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
        if ($providerCost <= 0) {
            return null;
        }

        $costMinor = (int) round($providerCost * 100);

        $quote = $this->pricing->quote(
            providerCost: $costMinor,
            quantity: 1,
            context: [
                'network' => NetworkResolver::key($networkCode, 'clubkonnect'),
                'capability' => 'data',
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

        return round($quote->customerPriceMinor / 100, 2);
    }
}
