<?php

namespace App\Services;

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

    /** Margin added on top of the upstream price to get the customer price. */
    private const PROFIT_MARGIN = 0.015;

    private const NETWORK_NAMES = [
        '01' => 'MTN',
        '02' => 'GLO',
        '03' => '9MOBILE',
        '04' => 'AIRTEL',
    ];

    private ?string $clientId;

    public function __construct()
    {
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
            Log::error('Data plan catalogue fetch failed: ' . $e->getMessage());

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
            Log::error('Invalid API response structure:', is_array($apiData) ? $apiData : []);

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
                $yourPrice = $this->calculateWithProfit($basePrice);

                $processedPlans[] = [
                    'network_code' => $networkCode,
                    'network' => $networkName,
                    'plan_id' => $productId,
                    'plan_code' => $productCode,
                    'plan_name' => $productName,
                    'data_volume' => $planDetails['data_volume'],
                    'validity' => $planDetails['validity'],
                    'plan_type' => $this->determinePlanType($productName),
                    'clubkonnect_price' => $basePrice,
                    'your_price' => $yourPrice,
                    'profit_margin' => '1.5%',
                    'profit_amount' => $yourPrice - $basePrice,
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

    private function calculateWithProfit(float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        return round($price * (1 + self::PROFIT_MARGIN), 2);
    }
}
