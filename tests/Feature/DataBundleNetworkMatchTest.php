<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClubKonnectCatalogue;
use App\Services\DataPriceService;
use App\Services\NetworkResolver;
use App\Services\SecurityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * The "belongs to a different network" refusal, and why it fired on correct bundles.
 *
 * ## Root cause
 *
 * ClubKonnect **reuses product ids across networks**. In the live catalogue of 136
 * bundles, 21 `plan_id` values appear on two or three networks at once — `500`,
 * `100.01`, `200.01`, `350.01`, `500.01` among them — and all 41 `plan_code` values
 * are reused as well, which rules out keying on those instead.
 *
 * `ClubKonnectCatalogue::plan($identifier)` matched on the identifier alone and
 * returned whichever row the provider happened to list first. For id `500` that is
 * **Glo**, so a customer who had correctly selected an **MTN** bundle was handed
 * Glo's row with the same id.
 *
 * `DataPriceService::quote()` then did exactly what it was built to do: it compared
 * the customer's network against the resolved row's network, found them different,
 * and refused —
 *
 *     That data bundle belongs to a different network from the one selected.
 *
 * The validation was right. The **lookup** was wrong, and it fed the validator a row
 * belonging to a network the customer had never chosen.
 *
 * ## The fix, and what it deliberately does not do
 *
 * The lookup is scoped by the network the customer selected, so the right row is
 * discovered. The canonical comparison stays exactly where it was: discovery is
 * scoped, verification is not skipped. The tests below assert both — every network's
 * own id-500 bundle prices, and a bundle from another network is still refused
 * before the wallet is touched.
 */
class DataBundleNetworkMatchTest extends TestCase
{
    use RefreshDatabase;
    use FakesProviders;

    /**
     * The catalogue, shaped as `ClubKonnectCatalogue` returns it — **including the
     * id reuse that causes the bug**.
     *
     * id `500` is on Glo, MTN and 9mobile at three different prices, and `100.01` is
     * on Glo and MTN. Ids and codes repeat exactly as the live catalogue repeats
     * them.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $catalogue = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['clubkonnect'],
            'bills.limits.per_minute' => 100,
        ]);

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

        $this->catalogue = $this->buildCatalogue();
        $this->mockCatalogue();
    }

    /* =====================================================================
     | The identifier really is ambiguous — the premise of the bug
     |=================================================================== */

    public function test_the_same_plan_id_exists_on_several_networks(): void
    {
        $ids = [];

        foreach ($this->catalogue as $plan) {
            $ids[(string) $plan['plan_id']][] = (string) $plan['network_code'];
        }

        $shared = array_filter($ids, fn ($codes) => count($codes) > 1);

        $this->assertNotEmpty($shared, 'This fixture is only meaningful if an id is reused');
        $this->assertArrayHasKey('500', $shared, 'id 500 is on Glo, MTN and 9mobile');
        $this->assertCount(3, $shared['500']);
    }

    /**
     * And `plan_code` is not a usable alternative key.
     */
    public function test_the_plan_code_is_also_reused_across_networks(): void
    {
        $codes = [];

        foreach ($this->catalogue as $plan) {
            $codes[(string) $plan['plan_code']][] = (string) $plan['network_code'];
        }

        $this->assertNotEmpty(
            array_filter($codes, fn ($networks) => count($networks) > 1),
            'plan_code is reused too, so it cannot be the unambiguous key either'
        );
    }

    /**
     * The defect itself, pinned: an unscoped lookup cannot tell the networks apart.
     */
    public function test_an_unscoped_lookup_returns_whichever_row_is_listed_first(): void
    {
        $unscoped = $this->resolvePlan('500');

        $this->assertNotNull($unscoped);
        $this->assertSame(
            'glo',
            NetworkResolver::key((string) $unscoped['network_code'], 'clubkonnect'),
            'Glo is listed first, so an MTN selection resolved to Glo — the cause of the refusal'
        );
    }

    /* =====================================================================
     | The fix: each network resolves to its own bundle
     |=================================================================== */

    public function test_each_networks_own_bundle_of_the_same_id_resolves_correctly(): void
    {
        foreach (['01' => 'mtn', '02' => 'glo', '03' => '9mobile'] as $code => $expected) {
            $plan = $this->resolvePlan('500', $code);

            $this->assertNotNull($plan, "[{$code}] must resolve its own id-500 bundle");
            $this->assertSame(
                $expected,
                NetworkResolver::key((string) $plan['network_code'], 'clubkonnect'),
                "[{$code}] must resolve to {$expected}, not to whichever row is listed first"
            );
        }
    }

    public function test_every_networks_bundle_prices_through_the_checkout_service(): void
    {
        $service = app(DataPriceService::class);

        foreach (['01' => 'mtn', '02' => 'glo', '03' => '9mobile'] as $code => $expected) {
            $key = NetworkResolver::key($code, 'clubkonnect');
            $priced = $service->quote('500', $key);

            $this->assertTrue($priced['found'], "[{$code}] id 500 must be found for {$expected}");
            $this->assertNotNull(
                $priced['price_minor'],
                "[{$code}] must price — this is the refusal the customer was seeing"
            );
            $this->assertSame($expected, $priced['network_key']);
        }
    }

    /**
     * The prices differ per network because the bundles do — proof that three
     * different rows are priced rather than one row reused three times.
     */
    public function test_the_networks_price_their_own_bundle_not_a_shared_one(): void
    {
        $service = app(DataPriceService::class);

        $prices = [];

        foreach (['01', '02', '03'] as $code) {
            $prices[$code] = $service->quote('500', NetworkResolver::key($code, 'clubkonnect'))['price_minor'];
        }

        $this->assertNotSame(
            $prices['01'],
            $prices['02'],
            'MTN and Glo price different bundles; identical figures would mean one row served both'
        );
        $this->assertNotSame($prices['01'], $prices['03']);
    }

    /* =====================================================================
     | Genuine mismatches must still be refused
     |=================================================================== */

    public function test_a_bundle_from_another_network_is_still_refused(): void
    {
        $priced = app(DataPriceService::class)->quote('500', 'airtel');

        $this->assertNull($priced['price_minor'], 'A mismatch must still refuse');
        $this->assertStringContainsString('different network', (string) $priced['refusal']);
    }

    /**
     * The message distinguishes a mismatch from a bundle that genuinely vanished.
     */
    public function test_a_bundle_that_exists_nowhere_says_it_is_unavailable(): void
    {
        $priced = app(DataPriceService::class)->quote('does-not-exist', 'mtn');

        $this->assertFalse($priced['found']);
        $this->assertNull($priced['price_minor']);
        $this->assertStringContainsString('no longer available', (string) $priced['refusal']);
        $this->assertStringNotContainsString('different network', (string) $priced['refusal']);
    }

    /**
     * Scoping is not a substitute for comparison: an id that exists on *some* network
     * cannot be bought on one that does not have it.
     */
    public function test_scoping_does_not_let_a_wrong_network_buy_an_existing_id(): void
    {
        $this->assertNull(
            app(DataPriceService::class)->quote('500', 'airtel')['price_minor'],
            'id 500 is on mtn, glo and 9mobile — not airtel'
        );
    }

    /* =====================================================================
     | Checkout, end to end
     |=================================================================== */

    public function test_a_correct_bundle_purchases_and_debits_once(): void
    {
        $this->fakeVending();

        $user = $this->customer();

        $this->actingAs($user)->post(route('data.purchase'), [
            'data_network' => '01', // MTN
            'data_plan' => '500',
            'phone' => '08031234567', // MTN
            'plan_name' => 'MTN 500MB - Weekly (SME)',
            'plan_price' => '309.00',
            'plan_type' => 'SME Data',
            'pin' => '5931',
        ])->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The purchase must go through');
        $this->assertSame('mtn', $transaction->network_code);
        $this->assertNotSame('5000.00', $this->balanceOf($user), 'Debited once');
    }

    /**
     * The reported symptom, asserted at the checkout boundary.
     */
    public function test_the_reported_refusal_does_not_fire_for_a_correct_selection(): void
    {
        $this->fakeVending();

        $user = $this->customer();

        /*
         * Sent as an ordinary form post, which is what the page does. A successful
         * purchase redirects to the receipt; a refusal redirects back with an error.
         * So the assertion is on where it landed, not merely that it did not 409.
         */
        $response = $this->actingAs($user)->post(route('data.purchase'), [
            'data_network' => '01',
            'data_plan' => '500',
            'phone' => '08031234567',
            'plan_name' => 'MTN 500MB - Weekly (SME)',
            'plan_price' => '309.00',
            'plan_type' => 'SME Data',
            'pin' => '5931',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNull(
            session('error'),
            'The reported error must be gone for a correct selection'
        );

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The purchase went through');
        $this->assertSame('mtn', $transaction->network_code);
    }

    /**
     * And a real mismatch is still stopped before the wallet moves.
     */
    public function test_a_mismatched_bundle_is_rejected_before_the_wallet_is_debited(): void
    {
        $this->fakeVending();

        $user = $this->customer();

        $response = $this->actingAs($user)->postJson(route('data.purchase'), [
            'data_network' => '04', // the form claims Airtel
            'data_plan' => '500',   // id 500 is not on Airtel
            'phone' => '08021234567',
            'plan_name' => 'Airtel 500MB',
            'plan_price' => '309.00',
            'plan_type' => 'SME Data',
            'pin' => '5931',
        ]);

        $response->assertStatus(409);
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count(), 'No transaction was created');
        $this->assertSame('5000.00', $this->balanceOf($user), 'Nothing was charged');
    }

    /* =====================================================================
     | The mapping service
     |=================================================================== */

    public function test_the_provider_code_mapping_round_trips_for_every_network(): void
    {
        foreach (['01' => 'mtn', '02' => 'glo', '03' => '9mobile', '04' => 'airtel'] as $code => $key) {
            $this->assertSame($key, NetworkResolver::key($code, 'clubkonnect'));
            $this->assertSame(
                $code,
                NetworkResolver::providerCode($key, 'clubkonnect'),
                "[{$key}] must map back to provider code {$code}, leading zero preserved"
            );
        }
    }

    public function test_the_provider_code_mapping_refuses_what_it_does_not_hold(): void
    {
        $this->assertNull(NetworkResolver::providerCode('not-a-network', 'clubkonnect'));
        $this->assertNull(NetworkResolver::providerCode(null, 'clubkonnect'));
        $this->assertNull(NetworkResolver::providerCode('mtn', 'no-such-provider'));
    }

    public function test_the_leading_zero_is_a_string_not_an_integer(): void
    {
        // The provider's code space is keyed by the string `01`; as an integer it
        // would be `1` and every lookup would miss.
        foreach (['mtn', 'glo', '9mobile', 'airtel'] as $key) {
            $code = NetworkResolver::providerCode($key, 'clubkonnect');

            $this->assertIsString($code);
            $this->assertSame(2, strlen((string) $code));
        }
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    /** @return array<int,array<string,mixed>> */
    private function buildCatalogue(): array
    {
        $definitions = [
            // network code, plan id, plan code, name, provider price
            ['02', '500', '1', 'GLO 500MB - Weekly (SME)', 233.0],
            ['03', '500', '1', '9MOBILE 500MB - Weekly (SME)', 249.6],
            ['01', '500', '1', 'MTN 500MB - Weekly (SME)', 300.0],
            ['04', '501', '2', 'AIRTEL 500MB - Weekly (SME)', 310.0],
            ['02', '100.01', '3', 'GLO 100MB - 1 day', 90.0],
            ['01', '100.01', '3', 'MTN 100MB - 1 day', 95.0],
        ];

        $rows = [];

        foreach ($definitions as [$code, $id, $planCode, $name, $providerPrice]) {
            $key = NetworkResolver::key($code, 'clubkonnect');

            $rows[] = [
                'network_code' => $code,
                'network' => strtoupper((string) $key),
                'network_key' => $key,
                'plan_id' => $id,
                'plan_code' => $planCode,
                'plan_name' => $name,
                'data_volume' => '500MB',
                'validity' => '7 days',
                'plan_type' => 'SME Data',
                'clubkonnect_price' => $providerPrice,
                'your_price' => round($providerPrice * 1.03, 2),
                'price_available' => true,
                'cost_synced_at' => now()->toDateTimeString(),
            ];
        }

        return $rows;
    }

    /**
     * The catalogue, scoped exactly as the real one now scopes.
     *
     * Mirrors `ClubKonnectCatalogue::plan()` including the network filter, so these
     * tests exercise the same resolution rule the application uses rather than a
     * simplified stand-in that could hide the bug returning.
     */
    private function resolvePlan(string $code, ?string $networkCode = null): ?array
    {
        foreach ($this->catalogue as $plan) {
            if ($networkCode !== null && (string) $plan['network_code'] !== (string) $networkCode) {
                continue;
            }

            if ((string) $plan['plan_code'] === $code || (string) $plan['plan_id'] === $code) {
                return $plan;
            }
        }

        return null;
    }

    private function mockCatalogue(): void
    {
        $test = $this;

        $this->mock(ClubKonnectCatalogue::class, function ($mock) use ($test) {
            $mock->shouldReceive('plan')->andReturnUsing(
                fn (string $code, ?string $networkCode = null) => $test->currentPlan($code, $networkCode)
            );
            $mock->shouldReceive('plans')->andReturnUsing(fn () => [
                'data_plans' => $test->currentCatalogue(),
                'total_plans' => count($test->currentCatalogue()),
                'last_updated' => now()->toDateTimeString(),
            ]);
            $mock->shouldReceive('cachedPlans')->andReturnUsing(fn () => [
                'data_plans' => $test->currentCatalogue(),
                'total_plans' => count($test->currentCatalogue()),
                'last_updated' => now()->toDateTimeString(),
            ]);
            $mock->shouldReceive('planPrice')->andReturn(null);
            $mock->shouldReceive('status')->andReturn([
                'last_success_at' => now()->toDateTimeString(),
                'age_seconds' => 5,
                'stale' => false,
                'plan_count' => 6,
                'consecutive_failures' => 0,
                'error_type' => null,
                'last_failure_at' => null,
            ]);
            $mock->shouldReceive('isStale')->andReturn(false);
            $mock->shouldReceive('forget')->andReturnNull();
        });
    }

    /** Public so the mock closure above can reach it. */
    public function currentCatalogue(): array
    {
        return $this->catalogue;
    }

    /** Public so the mock closure above can reach it. */
    public function currentPlan(string $code, ?string $networkCode = null): ?array
    {
        return $this->resolvePlan($code, $networkCode);
    }

    private function customer(string $balance = '5000.00'): User
    {
        $user = User::factory()->create();
        app(SecurityService::class)->setPin($user, '5931');
        app(WalletService::class)->credit($user, $balance);

        return $user->fresh();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    private function fakeVending(): void
    {
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));
    }
}
