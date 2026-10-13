<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Provider;
use App\Models\ProviderNetworkCost;
use App\Models\User;
use App\Services\NetworkResolver;
use App\Services\PhoneNumber;
use App\Services\ProviderCostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Network identity, detection, and the per-network data discount.
 *
 * ## The fault this file was written for
 *
 * A customer typing an ordinary Nigerian mobile number was told *"we could not tell
 * which network this number uses."* Detection was working — it simply did not know
 * the number's prefix, because `prefix_hints` listed 33 of the 37 prefixes the NCC
 * national numbering plan allocates to the four networks ReUp sells. `0704`, `0707`,
 * `0904` and `0911` were all absent, so every customer on one of them got a manual
 * selection prompt for no reason.
 *
 * Completeness is therefore the fix, and the tests below pin the whole allocated set
 * rather than the four examples — a partial list reads as a broken feature even
 * though nothing incorrect is ever selected.
 */
class NetworkDiscountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every prefix the NCC numbering plan allocates to the four networks ReUp sells,
     * and the canonical network each belongs to.
     *
     * Source: https://en.wikipedia.org/wiki/Telephone_numbers_in_Nigeria, which
     * reproduces the NCC allocation.
     */
    private const ALLOCATED = [
        'mtn' => [
            '0703', '0704', '0706', '0707',
            '0803', '0806', '0810', '0813', '0814', '0816',
            '0903', '0906', '0913', '0916',
        ],
        'airtel' => [
            '0701', '0708', '0802', '0808', '0812',
            '0901', '0902', '0904', '0907', '0911', '0912',
        ],
        'glo' => ['0705', '0805', '0807', '0811', '0815', '0905', '0915'],
        '9mobile' => ['0809', '0817', '0818', '0908', '0909'],
    ];

    /* =====================================================================
     | The network IDs the radio inputs must carry
     |=================================================================== */

    public function test_the_radio_input_ids_are_the_exact_codes_required(): void
    {
        // MTN = 01, GLO = 02, T2MOBILE = 03, AIRTEL = 04.
        $expected = [
            '01' => 'mtn',
            '02' => 'glo',
            '03' => '9mobile',
            '04' => 'airtel',
        ];

        foreach ($expected as $code => $canonical) {
            $this->assertSame(
                $canonical,
                NetworkResolver::key($code, 'clubkonnect'),
                "[{$code}] must resolve to {$canonical}"
            );
        }

        // And the reverse, which is what the page needs to tick a radio.
        foreach ($expected as $code => $canonical) {
            $this->assertSame($code, \App\Http\Controllers\AirtimeDataController::codeFor($canonical));
        }
    }

    public function test_the_ids_are_two_character_strings_not_integers(): void
    {
        /*
         * `01` as an integer is `1`, and a form that posts `1` would fail validation
         * against an `in:01,02,03,04` rule. The codes are strings and must stay
         * strings, which is why this asserts the type rather than the value.
         */
        foreach (array_keys(\App\Http\Controllers\AirtimeDataController::NETWORKS) as $code) {
            $this->assertIsString($code);
            $this->assertSame(2, strlen($code));
            $this->assertMatchesRegularExpression('/^0[1-4]$/', $code);
        }
    }

    public function test_t2mobile_aliases_resolve_to_the_canonical_network(): void
    {
        foreach (['9mobile', '9 Mobile', 'T2', 'T2mobile', 'T2MOBILE', '9MOBILE'] as $alias) {
            $this->assertSame(
                '9mobile',
                NetworkResolver::key($alias, 'clubkonnect'),
                "[{$alias}] must normalise to the canonical 9mobile key"
            );
        }

        // The customer-facing label is the current brand; the key is not.
        $this->assertSame('T2mobile', NetworkResolver::labelFor('9mobile'));
    }

    /**
     * A network code is not a product id, and the two must not be conflated.
     */
    public function test_a_network_code_is_not_a_product_identifier(): void
    {
        // `04` is Airtel as a *network*, and also a plan id in the catalogue. They
        // live in different namespaces and neither may be used for the other.
        $this->assertSame('airtel', NetworkResolver::key('04', 'clubkonnect'));
        $this->assertNull(NetworkResolver::key('MTN-1GB-30D', 'clubkonnect'));
        $this->assertNull(NetworkResolver::key('some-product-id', 'clubkonnect'));
    }

    /* =====================================================================
     | Detection across the whole allocated prefix set
     |=================================================================== */

    public function test_every_allocated_prefix_is_detected(): void
    {
        $failures = [];

        foreach (self::ALLOCATED as $expected => $prefixes) {
            foreach ($prefixes as $prefix) {
                $number = $prefix . '1234567';

                if (! PhoneNumber::isValid($number)) {
                    $failures[] = "{$prefix}: rejected as invalid";
                    continue;
                }

                $detection = PhoneNumber::detect($number);

                if (($detection['key'] ?? null) !== $expected) {
                    $failures[] = "{$prefix}: expected {$expected}, got " . ($detection['key'] ?? 'null');
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "These allocated prefixes are not detected — a customer on one is told we cannot tell:\n  - "
            . implode("\n  - ", $failures)
        );
    }

    /**
     * The four that actually failed in production.
     */
    public function test_the_prefixes_that_previously_failed_now_detect(): void
    {
        $this->assertSame('mtn', PhoneNumber::detect('07041234567')['key']);
        $this->assertSame('mtn', PhoneNumber::detect('07071234567')['key']);
        $this->assertSame('airtel', PhoneNumber::detect('09041234567')['key']);
        $this->assertSame('airtel', PhoneNumber::detect('09111234567')['key']);
    }

    public function test_toll_and_toll_free_numbers_are_refused_as_not_mobile(): void
    {
        // NCC: 0800 is toll-free and 0700 is toll. Both are ten-digit services, so
        // neither is a mobile number a bundle can be delivered to.
        foreach (['08001234567', '07001234567'] as $number) {
            $parsed = PhoneNumber::parse($number);

            $this->assertFalse($parsed['valid'], "[{$number}] is not a mobile number");
            $this->assertStringContainsString('toll', (string) $parsed['reason']);
        }
    }

    /**
     * An operator ReUp does not sell still yields no candidate rather than a wrong
     * one — and, importantly, does not block the customer.
     */
    public function test_an_unsold_operators_prefix_yields_no_candidate(): void
    {
        foreach (['07020123456', '07027123456', '08191234567'] as $number) {
            $detection = PhoneNumber::detect($number);

            $this->assertNotNull($detection, "[{$number}] is a valid mobile number");
            $this->assertNull($detection['key'], "[{$number}] must not be attributed to a network we sell");
        }
    }

    public function test_no_prefix_is_claimed_by_two_networks(): void
    {
        $seen = [];

        foreach ((array) config('networks.prefix_hints', []) as $key => $prefixes) {
            foreach ((array) $prefixes as $prefix) {
                $this->assertArrayNotHasKey($prefix, $seen, "Prefix {$prefix} is listed twice");
                $seen[$prefix] = $key;
            }
        }

        $this->assertCount(37, $seen, 'The allocated set is 37 prefixes');
    }

    /* =====================================================================
     | The discount schedule
     |=================================================================== */

    public function test_the_configured_discounts_are_the_supplied_schedule(): void
    {
        // MTN 0.970, Glo 0.955, T2mobile 0.930, Airtel 0.970.
        $this->assertSame(9700, (int) config('pricing.data_discount_bps_by_network.mtn'));
        $this->assertSame(9550, (int) config('pricing.data_discount_bps_by_network.glo'));
        $this->assertSame(9300, (int) config('pricing.data_discount_bps_by_network.9mobile'));
        $this->assertSame(9700, (int) config('pricing.data_discount_bps_by_network.airtel'));
    }

    public function test_the_catalogue_price_is_treated_as_gross(): void
    {
        // ClubKonnect returns its published rate, which has no discount applied —
        // so applying the schedule once is correct and applying it twice is not.
        $this->assertTrue((bool) config('pricing.data_catalogue_price_is_gross'));
    }

    public function test_the_effective_cost_is_the_catalogue_price_less_the_network_discount(): void
    {
        $resolver = app(ProviderCostResolver::class);

        foreach ([
            'mtn' => [9700, 48500],      // ₦500 x 0.970 = ₦485.00
            'glo' => [9550, 47750],      // ₦500 x 0.955 = ₦477.50
            '9mobile' => [9300, 46500],  // ₦500 x 0.930 = ₦465.00
            'airtel' => [9700, 48500],
        ] as $network => [$bps, $expectedCostMinor]) {
            $this->discount($network, $bps);

            $cost = $resolver->forDataBundle($this->plan($network), 'clubkonnect');

            $this->assertNotNull($cost, "[{$network}] must resolve a cost");
            $this->assertSame(50000, $cost->listMinor, '[{$network}] the catalogue price is the list price');
            $this->assertSame($expectedCostMinor, $cost->costMinor, "[{$network}] effective cost");
            $this->assertSame($bps, $cost->discountBps);
        }
    }

    public function test_the_discount_is_applied_exactly_once(): void
    {
        $resolver = app(ProviderCostResolver::class);
        $this->discount('9mobile', 9300);

        $cost = $resolver->forDataBundle($this->plan('9mobile'), 'clubkonnect');

        // 50000 x 0.930 = 46500. Applying it twice would give 43245, which would
        // understate what we pay and overstate the margin on every T2mobile sale.
        $this->assertSame(46500, $cost->costMinor);
        $this->assertNotSame((int) round(50000 * 0.93 * 0.93), $cost->costMinor);
    }

    public function test_the_discount_is_not_applied_when_the_catalogue_price_is_already_net(): void
    {
        config(['pricing.data_catalogue_price_is_gross' => false]);

        $resolver = app(ProviderCostResolver::class);
        $this->discount('mtn', 9700);

        $cost = $resolver->forDataBundle($this->plan('mtn'), 'clubkonnect');

        $this->assertSame(50000, $cost->costMinor, 'A net price is not discounted again');
        $this->assertSame(0, $cost->discountBps);
    }

    public function test_an_unconfigured_network_is_costed_at_the_catalogue_price_not_a_guess(): void
    {
        $resolver = app(ProviderCostResolver::class);

        // No discount row at all.
        $cost = $resolver->forDataBundle($this->plan('glo'), 'clubkonnect');

        $this->assertSame(50000, $cost->costMinor, 'The catalogue price, not an invented discount');
        $this->assertSame(0, $cost->discountBps);
    }

    /**
     * A discount that has never been checked is an assumption, and the recorded
     * profit has to say so rather than presenting it as a measurement.
     */
    public function test_an_unverified_discount_marks_the_cost_estimated(): void
    {
        $resolver = app(ProviderCostResolver::class);

        $this->discount('mtn', 9700, verifiedAt: null);
        $this->assertTrue(
            $resolver->forDataBundle($this->plan('mtn'), 'clubkonnect')->estimated,
            'An unverified schedule is an assumption'
        );

        ProviderNetworkCost::query()->update(['verified_at' => now()->subHour()]);

        $this->assertFalse(
            $resolver->forDataBundle($this->plan('mtn'), 'clubkonnect')->estimated,
            'Once confirmed against a provider figure it stops being one'
        );
    }

    /**
     * A discount is not profit.
     */
    public function test_the_discount_is_not_reported_as_the_margin(): void
    {
        $resolver = app(ProviderCostResolver::class);
        $this->discount('9mobile', 9300);

        $cost = $resolver->forDataBundle($this->plan('9mobile'), 'clubkonnect');

        // ₦500 list, ₦465 effective. The 7% is what the *provider* gave up; ReUp's
        // profit is the selling price minus ₦465, computed by the pricing engine.
        $this->assertSame(46500, $cost->costMinor);
        $this->assertSame(3500, $cost->listMinor - $cost->costMinor, 'The provider discount, ₦35 of ₦500');

        $quote = app(\App\Pricing\PricingEngine::class)->quote(
            providerCost: $cost->costMinor,
            quantity: 1,
            costMeta: $cost->toEngineMetadata(),
            context: ['capability' => ProviderCostResolver::CAPABILITY_DATA, 'network' => '9mobile'],
        );

        // With no rule configured the engine refuses rather than selling at cost.
        $this->assertFalse($quote->isSellable());

        // With a 3% markup rule the profit is 3% of the *effective* cost — ₦13.95 —
        // and not the 7% provider discount, nor 3% of the ₦500 list price.
        PricingRule::create([
            'name' => 'Data — default markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 300,
            'minimum_profit_minor' => 1,
            'customer_fee_enabled' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);

        $quote = app(\App\Pricing\PricingEngine::class)->quote(
            providerCost: $cost->costMinor,
            quantity: 1,
            costMeta: $cost->toEngineMetadata(),
            context: ['capability' => ProviderCostResolver::CAPABILITY_DATA, 'network' => '9mobile'],
        );

        $this->assertSame(46500, $quote->baseCostMinor, 'Priced from the effective cost');
        $this->assertSame(47895, $quote->customerPriceMinor, '₦465 + 3%');
        $this->assertSame(1395, $quote->grossProfitMinor, '₦13.95 of profit, not ₦35 of discount');
    }

    /* =====================================================================
     | Seeding
     |=================================================================== */

    public function test_the_seeder_writes_the_four_network_discounts_unverified(): void
    {
        /*
         * The seeder attributes the pricing rules it creates to an administrator —
         * `PricingRuleService` refuses an unattributed financial change — and returns
         * early without one. A provider row is the other hard dependency:
         * `provider_network_costs.provider_id` is NOT NULL, so the discount step
         * skips rather than aborting the whole seed.
         */
        User::factory()->superAdmin()->create();

        $provider = Provider::create([
            'slug' => 'clubkonnect', 'name' => 'Clubkonnect',
            'driver' => 'clubkonnect', 'is_active' => true, 'priority' => 100,
        ]);

        $this->seed(\Database\Seeders\PricingDefaultsSeeder::class);

        $rows = ProviderNetworkCost::where('capability', ProviderCostResolver::CAPABILITY_DATA)
            ->get()
            ->keyBy('network');

        $this->assertCount(4, $rows, 'One row per network');
        $this->assertSame(9700, (int) $rows['mtn']->discount_bps);
        $this->assertSame(9550, (int) $rows['glo']->discount_bps);
        $this->assertSame(9300, (int) $rows['9mobile']->discount_bps);
        $this->assertSame(9700, (int) $rows['airtel']->discount_bps);

        foreach ($rows as $network => $row) {
            $this->assertSame($provider->getKey(), $row->provider_id);
            $this->assertNull($row->verified_at, "[{$network}] is seeded as an assumption, not a measurement");
        }
    }

    /**
     * A discount an operator has corrected must not be rolled back by a re-seed.
     */
    public function test_re_seeding_does_not_overwrite_a_corrected_discount(): void
    {
        User::factory()->superAdmin()->create();

        Provider::create([
            'slug' => 'clubkonnect', 'name' => 'Clubkonnect',
            'driver' => 'clubkonnect', 'is_active' => true, 'priority' => 100,
        ]);

        $this->seed(\Database\Seeders\PricingDefaultsSeeder::class);

        // An operator confirms T2mobile against an invoice and finds it is 5%, not 7%.
        ProviderNetworkCost::where('capability', ProviderCostResolver::CAPABILITY_DATA)
            ->where('network', '9mobile')
            ->update(['discount_bps' => 9500, 'verified_at' => now()]);

        $this->seed(\Database\Seeders\PricingDefaultsSeeder::class);

        $row = ProviderNetworkCost::where('capability', ProviderCostResolver::CAPABILITY_DATA)
            ->where('network', '9mobile')
            ->firstOrFail();

        $this->assertSame(9500, (int) $row->discount_bps, 'The corrected rate survives a re-seed');
        $this->assertNotNull($row->verified_at);
        $this->assertSame(
            4,
            ProviderNetworkCost::where('capability', ProviderCostResolver::CAPABILITY_DATA)->count(),
            'And no duplicate rows are created'
        );
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function discount(string $network, int $bps, ?string $verifiedAt = 'default'): ProviderNetworkCost
    {
        $provider = Provider::firstOrCreate(
            ['slug' => 'clubkonnect'],
            ['name' => 'Clubkonnect', 'driver' => 'clubkonnect', 'is_active' => true, 'priority' => 100],
        );

        return ProviderNetworkCost::create([
            'provider_id' => $provider->getKey(),
            'capability' => ProviderCostResolver::CAPABILITY_DATA,
            'network' => $network,
            'discount_bps' => $bps,
            'provider_fee_minor' => 0,
            'currency' => 'NGN',
            'verified_at' => $verifiedAt === 'default' ? now()->subHour() : $verifiedAt,
            'is_active' => true,
        ]);
    }

    /** A catalogue plan shaped as `ClubKonnectCatalogue` returns one. */
    private function plan(string $network): array
    {
        return [
            'network_code' => ['mtn' => '01', 'glo' => '02', '9mobile' => '03', 'airtel' => '04'][$network],
            'network' => NetworkResolver::labelFor($network),
            'network_key' => $network,
            'plan_id' => '500',
            'plan_code' => strtoupper($network) . '-1GB-30D',
            'plan_name' => '1GB Monthly',
            'plan_type' => 'SME Data',
            // ₦500.00 — the provider's published rate, before any discount.
            'clubkonnect_price' => 500.0,
        ];
    }
}
