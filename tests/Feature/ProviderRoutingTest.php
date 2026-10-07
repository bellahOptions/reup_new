<?php

namespace Tests\Feature;

use App\Services\ProviderManager;
use App\Services\Providers\ClubKonnectProvider;
use App\Services\Providers\PairgateProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provider selection: who vends, and why.
 *
 * Two behaviours are being pinned here, both about not losing money:
 *
 *   * availability — a provider that cannot answer a wallet enquiry is dropped
 *     before the customer is charged, and if none answer the sale is refused
 *     rather than debited and refunded;
 *   * price — when *every* candidate can be priced, the cheapest vends; when any
 *     price is unknown, the operator's configured order stands untouched.
 *
 * The float check is switched off in these tests so they isolate selection from
 * balance logic (which has its own service), and the pair of wallet endpoints is
 * faked so no test reaches a real upstream.
 */
class ProviderRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bills.provider_order' => ['clubkonnect', 'pairgate'],
            'bills.check_provider_balance' => false,
            'bills.check_provider_health' => true,
            'bills.optimise_cost' => true,
            'services.clubkonnect.client_id' => 'ck-id',
            'services.clubkonnect.api_key' => 'ck-key',
            'services.pairgate.api_key' => 'pg-key',
        ]);
    }

    private function manager(): ProviderManager
    {
        return $this->app->make(ProviderManager::class);
    }

    /** @return array<int, string> */
    private function names(array $providers): array
    {
        return array_map(fn ($provider) => $provider->name(), $providers);
    }

    /** Both upstreams answering their wallet enquiry. */
    private function fakeHealthy(): void
    {
        Http::fake([
            '*APIWalletBalanceV1.asp*' => Http::response(['balance' => '50000.00']),
            '*pairgate.com/api/v1/wallet/balance' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['balance' => 25000.00, 'currency' => 'NGN'],
            ]),
        ]);
    }

    private function dataParams(): array
    {
        return ['network' => '01', 'plan' => 'CK-1', 'phone' => '08031234567'];
    }

    /** ClubKonnect's wholesale price, as the pricelist records it. */
    private function seedClubKonnectCatalogue(float $price): void
    {
        Cache::put('clubkonnect_data_plans', [
            'data_plans' => [
                ['plan_id' => 'CK-1', 'plan_code' => 'CK1', 'clubkonnect_price' => $price],
            ],
        ], 600);
    }

    /* =====================================================================
     | Availability
     |=================================================================== */

    public function test_a_provider_that_is_not_answering_is_dropped_before_the_sale(): void
    {
        Http::fake([
            '*APIWalletBalanceV1.asp*' => Http::response(['status' => 'ERROR'], 500),
            '*pairgate.com/api/v1/wallet/balance' => Http::response([
                'code' => 200, 'status' => 'success', 'data' => ['balance' => 25000.00],
            ]),
        ]);

        $this->assertSame(
            ['pairgate'],
            $this->names($this->manager()->affordable('airtime', 500, ['network' => '01', 'phone' => '08031234567', 'amount' => 500]))
        );
    }

    public function test_when_no_provider_answers_the_sale_is_refused_rather_than_debited(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error'], 500)]);

        // An empty list is what makes BillPaymentService refuse the purchase and
        // leave the wallet alone.
        $this->assertSame(
            [],
            $this->manager()->affordable('airtime', 500, ['network' => '01', 'phone' => '08031234567', 'amount' => 500])
        );
    }

    public function test_availability_checking_can_be_switched_off(): void
    {
        config(['bills.check_provider_health' => false]);

        Http::fake(['*' => Http::response(['status' => 'error'], 500)]);

        $this->assertSame(
            ['clubkonnect', 'pairgate'],
            $this->names($this->manager()->affordable('airtime', 500, ['network' => '01', 'phone' => '08031234567', 'amount' => 500]))
        );
    }

    public function test_availability_is_probed_once_a_minute_not_once_a_purchase(): void
    {
        $this->fakeHealthy();

        $manager = $this->manager();

        $manager->affordable('airtime', 500, ['network' => '01', 'phone' => '08031234567', 'amount' => 500]);
        $manager->affordable('airtime', 500, ['network' => '01', 'phone' => '08031234567', 'amount' => 500]);

        // One probe per provider for both purchases, not four.
        Http::assertSentCount(2);
    }

    /* =====================================================================
     | Price
     |=================================================================== */

    public function test_the_cheaper_provider_wins_when_both_prices_are_known(): void
    {
        $this->fakeHealthy();
        $this->seedClubKonnectCatalogue(500.00);

        // The operator has mapped the plan *and* recorded Pairgate's price for it.
        config(['bills.pairgate.data_plans' => ['CK-1' => ['plan_id' => '45', 'price' => 480.00]]]);

        $this->assertSame(
            ['pairgate', 'clubkonnect'],
            $this->names($this->manager()->affordable('data', 507.50, $this->dataParams()))
        );
    }

    public function test_the_incumbent_is_kept_when_it_is_the_cheaper_one(): void
    {
        $this->fakeHealthy();
        $this->seedClubKonnectCatalogue(450.00);

        config(['bills.pairgate.data_plans' => ['CK-1' => ['plan_id' => '45', 'price' => 480.00]]]);

        $this->assertSame(
            ['clubkonnect', 'pairgate'],
            $this->names($this->manager()->affordable('data', 507.50, $this->dataParams()))
        );
    }

    public function test_a_plan_price_is_read_from_pairgates_catalogue_when_a_type_is_mapped(): void
    {
        Http::fake([
            '*APIWalletBalanceV1.asp*' => Http::response(['balance' => '50000.00']),
            '*pairgate.com/api/v1/wallet/balance' => Http::response([
                'code' => 200, 'status' => 'success', 'data' => ['balance' => 25000.00],
            ]),
            '*pairgate.com/api/v1/data-plans*' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['MTN' => [['plan_id' => '45', 'name' => 'MTN 1GB', 'price' => 470.00]]],
            ]),
        ]);

        $this->seedClubKonnectCatalogue(500.00);

        // No price recorded, but the plan type says where to look it up.
        config(['bills.pairgate.data_plans' => ['CK-1' => ['plan_id' => '45', 'plan_type' => 'SME']]]);

        $this->assertSame(
            ['pairgate', 'clubkonnect'],
            $this->names($this->manager()->affordable('data', 507.50, $this->dataParams()))
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/data-plans')
            && $request['provider_id'] === 'mtn'
            && $request['plan_type'] === 'SME');
    }

    public function test_an_unknown_price_leaves_the_configured_order_alone(): void
    {
        $this->fakeHealthy();
        $this->seedClubKonnectCatalogue(500.00);

        // Mapped enough to sell, but nothing establishes Pairgate's price for it.
        config(['bills.pairgate.data_plans' => ['CK-1' => '45']]);

        $this->assertSame(
            ['clubkonnect', 'pairgate'],
            $this->names($this->manager()->affordable('data', 507.50, $this->dataParams()))
        );
    }

    public function test_price_routing_can_be_switched_off(): void
    {
        config(['bills.optimise_cost' => false]);

        $this->fakeHealthy();
        $this->seedClubKonnectCatalogue(500.00);
        config(['bills.pairgate.data_plans' => ['CK-1' => ['plan_id' => '45', 'price' => 480.00]]]);

        $this->assertSame(
            ['clubkonnect', 'pairgate'],
            $this->names($this->manager()->affordable('data', 507.50, $this->dataParams()))
        );
    }

    public function test_face_value_products_are_not_reordered_by_price(): void
    {
        $this->fakeHealthy();

        // Both providers charge the amount itself, so the two prices tie and the
        // tie goes to the configured order.
        $this->assertSame(
            ['clubkonnect', 'pairgate'],
            $this->names($this->manager()->affordable('electricity', 5000, [
                'disco' => '01', 'meter_number' => '1234567890', 'meter_type' => 'prepaid', 'amount' => 5000,
            ]))
        );
    }

    /* =====================================================================
     | Provider-published prices
     |=================================================================== */

    public function test_clubkonnect_prices_data_from_the_pricelist_catalogue(): void
    {
        $this->seedClubKonnectCatalogue(500.00);

        $provider = $this->app->make(ClubKonnectProvider::class);

        $this->assertSame(500.00, $provider->cost('data', ['plan' => 'CK-1']));

        // A cold catalogue means unknown, and unknown must never read as free.
        Cache::flush();
        $this->assertNull($provider->cost('data', ['plan' => 'CK-1']));
    }

    public function test_clubkonnect_leaves_unpriceable_products_unknown(): void
    {
        $provider = $this->app->make(ClubKonnectProvider::class);

        $this->assertNull($provider->cost('airtime', ['amount' => 500]));
        $this->assertNull($provider->cost('cable_tv', ['package' => 'DSTV-PADI', 'amount' => 4400]));
        $this->assertNull($provider->cost('waec', ['exam_type' => 'WAEC']));
        $this->assertSame(5000.00, $provider->cost('electricity', ['amount' => 5000]));
        $this->assertSame(1000.00, $provider->cost('betting', ['amount' => 1000]));
    }

    public function test_pairgate_leaves_unpriceable_products_unknown(): void
    {
        $provider = $this->app->make(PairgateProvider::class);

        $this->assertNull($provider->cost('airtime', ['amount' => 500]));
        $this->assertNull($provider->cost('waec', ['exam_type' => 'WAEC']));
        $this->assertSame(5000.00, $provider->cost('electricity', ['amount' => 5000]));
        $this->assertSame(1000.00, $provider->cost('betting', ['amount' => 1000]));
    }
}
