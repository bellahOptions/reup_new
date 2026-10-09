<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Provider;
use App\Models\ProviderNetworkCost;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Pricing\PricingEngine;
use App\Pricing\ProviderCost;
use App\Services\NetworkResolver;
use App\Services\ProviderCostResolver;
use App\Services\SecurityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * Mobile data bundle pricing.
 *
 * ## What this covers
 *
 * The brief requires **one** profitability algorithm for airtime *and* data, so
 * this file tests the same engine and the same cost resolver from the data side.
 * A separate data-pricing engine is exactly what it must not be.
 *
 * The two rules that matter commercially:
 *
 *   * a data bundle is priced **cost-plus** — the customer pays the bundle's cost
 *     to ReUp plus a configured markup — unlike airtime, which is face value;
 *   * an airtime discount is **not** applied to data. A 3% airtime rate says
 *     nothing about a bundle's commercial terms, so a bundle's cost is the price
 *     the catalogue publishes for that exact bundle.
 *
 * Before this work the catalogue multiplied every bundle cost by 1.015 and called
 * the result the price: one margin for every bundle on every network, changeable
 * only by deployment.
 */
class DataBundlePricingTest extends TestCase
{
    use RefreshDatabase;
    use FakesProviders;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['clubkonnect'],
            'bills.limits.per_minute' => 100,
            'pricing.policy.allow_stale' => false,
            'pricing.policy.max_age_hours' => 168,
        ]);
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    /**
     * A catalogue row shaped like the one `ClubKonnectCatalogue` produces.
     *
     * `clubkonnect_price` is what the provider charges ReUp; the customer price is
     * deliberately absent because the engine computes it.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function plan(array $attributes = []): array
    {
        return array_merge([
            'network_code' => '01',
            'network' => 'MTN',
            'network_key' => 'mtn',
            'plan_id' => '500',
            'plan_code' => 'MTN-1GB-30D',
            'plan_name' => 'MTN 1GB Monthly',
            'data_volume' => '1 GB',
            'validity' => '30 days',
            'plan_type' => 'SME Data',
            'clubkonnect_price' => 500.0,
        ], $attributes);
    }

    /**
     * A cost-plus pricing rule.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function markupRule(?string $network = null, int $markupBps = 1000, array $attributes = []): PricingRule
    {
        return PricingRule::create(array_merge([
            'name' => $network ? "Data — {$network}" : 'Data — default markup',
            'scope' => $network ? PricingRule::SCOPE_NETWORK : PricingRule::SCOPE_GLOBAL,
            'network' => $network,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => $markupBps,
            'markup_fixed_minor' => 0,
            'minimum_profit_minor' => 1,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ], $attributes));
    }

    /** Price a bundle through the engine, exactly as the purchase path does. */
    private function quotePlan(?array $plan, ?string $networkKey = null): ?\App\Pricing\PriceQuote
    {
        $cost = app(ProviderCostResolver::class)->forDataBundle($plan);

        if (! $cost) {
            return null;
        }

        return app(PricingEngine::class)->quote(
            providerCost: $cost->costMinor,
            quantity: 1,
            context: [
                'network' => $networkKey ?? ($plan['network_key'] ?? null),
                'capability' => ProviderCostResolver::CAPABILITY_DATA,
            ],
            costMeta: $cost->toEngineMetadata(),
        );
    }

    private function user(string $balance = '10000.00'): User
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

    /* =====================================================================
     | Cost resolution
     |=================================================================== */

    public function test_a_bundle_cost_comes_from_the_catalogue_price_not_a_margin(): void
    {
        $cost = app(ProviderCostResolver::class)->forDataBundle($this->plan(['clubkonnect_price' => 500.0]));

        $this->assertNotNull($cost);
        $this->assertSame(50000, $cost->costMinor, 'The catalogue price is the cost, in kobo');
        $this->assertSame(ProviderCost::SOURCE_CATALOGUE, $cost->source);
        $this->assertFalse($cost->estimated, 'A catalogue price is a real figure, not an assumption');
    }

    public function test_a_bundle_with_no_cost_in_the_catalogue_cannot_be_priced(): void
    {
        $this->assertNull(app(ProviderCostResolver::class)->forDataBundle(
            $this->plan(['clubkonnect_price' => null])
        ));

        $this->assertNull(app(ProviderCostResolver::class)->forDataBundle(
            $this->plan(['clubkonnect_price' => 0])
        ));

        $this->assertNull(app(ProviderCostResolver::class)->forDataBundle(null));
    }

    /**
     * The 3% airtime discount is a term for *airtime*. Applying it to a bundle
     * would invent a cost the provider never quoted.
     */
    public function test_an_airtime_discount_does_not_change_a_data_bundle_cost(): void
    {
        Provider::create([
            'slug' => 'clubkonnect', 'name' => 'ClubKonnect',
            'driver' => 'clubkonnect', 'is_active' => true, 'priority' => 100,
        ]);

        ProviderNetworkCost::create([
            'provider_id' => Provider::where('slug', 'clubkonnect')->value('id'),
            'capability' => 'airtime',
            'network' => 'mtn',
            'discount_bps' => 300,
            'is_active' => true,
        ]);

        $cost = app(ProviderCostResolver::class)->forDataBundle($this->plan(['clubkonnect_price' => 500.0]));

        $this->assertSame(50000, $cost->costMinor, 'A data bundle cost is not discounted by an airtime term');
    }

    /* =====================================================================
     | Pricing: cost plus markup
     | =================================================================== */

    public function test_a_bundle_is_priced_at_cost_plus_the_configured_markup(): void
    {
        $this->markupRule(markupBps: 1000); // 10%

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        // ₦500 cost + 10% = ₦550, profit ₦50.
        $this->assertSame(55000, $quote->customerPriceMinor);
        $this->assertSame(50000, $quote->providerCostMinor);
        $this->assertSame(5000, $quote->grossProfitMinor);
    }

    public function test_bundles_with_different_costs_are_priced_independently(): void
    {
        $this->markupRule(markupBps: 1000);

        $small = $this->quotePlan($this->plan(['clubkonnect_price' => 250.0]), 'mtn');
        $large = $this->quotePlan($this->plan(['clubkonnect_price' => 2000.0]), 'mtn');

        $this->assertSame(27500, $small->customerPriceMinor);
        $this->assertSame(220000, $large->customerPriceMinor);
        $this->assertSame(2500, $small->grossProfitMinor);
        $this->assertSame(20000, $large->grossProfitMinor);
    }

    public function test_a_fixed_markup_is_supported(): void
    {
        $this->markupRule(attributes: [
            'markup_type' => PricingRule::MARKUP_FIXED,
            'markup_percentage_bps' => 0,
            'markup_fixed_minor' => 2000, // ₦20
        ]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertSame(52000, $quote->customerPriceMinor);
        $this->assertSame(2000, $quote->grossProfitMinor);
    }

    public function test_a_custom_selling_price_is_supported_via_a_selling_price_bound(): void
    {
        /*
         * A rule that pins the price regardless of cost. `minimum_selling_price`
         * equals `maximum_selling_price`, which is how the existing engine expresses
         * a fixed custom price for a product.
         */
        $this->markupRule(attributes: [
            'minimum_selling_price_minor' => 60000,
            'maximum_selling_price_minor' => 60000,
        ]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertSame(60000, $quote->customerPriceMinor, 'The custom price overrides cost plus markup');
        $this->assertSame(10000, $quote->grossProfitMinor);
    }

    public function test_a_face_value_rule_on_data_sells_that_bundle_at_cost(): void
    {
        /*
         * FACE_VALUE with no separate face value falls back to the base cost, which
         * for a bundle is its price. That is the correct behaviour — a bundle has no
         * face value distinct from its cost — and it is why data is given a markup
         * rule in production.
         */
        $this->markupRule(attributes: [
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'minimum_profit_minor' => 0,
        ]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertSame(50000, $quote->customerPriceMinor);
        $this->assertSame(0, $quote->grossProfitMinor);
    }

    /* =====================================================================
     | Per-network rules
     | =================================================================== */

    public function test_each_network_can_carry_its_own_data_rule(): void
    {
        $this->markupRule('mtn', 1000);     // 10%
        $this->markupRule('airtel', 2000);  // 20%
        $this->markupRule('glo', 500);      // 5%

        $mtn = $this->quotePlan($this->plan(['network_code' => '01', 'network_key' => 'mtn']), 'mtn');
        $airtel = $this->quotePlan($this->plan(['network_code' => '04', 'network_key' => 'airtel']), 'airtel');
        $glo = $this->quotePlan($this->plan(['network_code' => '02', 'network_key' => 'glo']), 'glo');

        // Same ₦500 cost, three different customer prices.
        $this->assertSame(55000, $mtn->customerPriceMinor);
        $this->assertSame(60000, $airtel->customerPriceMinor);
        $this->assertSame(52500, $glo->customerPriceMinor);
    }

    public function test_a_network_rule_beats_the_global_default_for_data(): void
    {
        $this->markupRule(null, 1000);      // global 10%
        $this->markupRule('mtn', 2500);     // MTN 25%

        $mtn = $this->quotePlan($this->plan(['network_key' => 'mtn']), 'mtn');
        $airtel = $this->quotePlan(
            $this->plan(['network_code' => '04', 'network_key' => 'airtel']),
            'airtel'
        );

        $this->assertSame(62500, $mtn->customerPriceMinor, '₦500 + 25%');
        $this->assertSame(55000, $airtel->customerPriceMinor, '₦500 + the global 10%');
    }

    public function test_the_network_key_is_resolved_from_the_catalogue_code(): void
    {
        // The catalogue speaks ClubKonnect codes; rules are keyed canonically.
        $this->assertSame('mtn', NetworkResolver::key('01', 'clubkonnect'));
        $this->assertSame('airtel', NetworkResolver::key('04', 'clubkonnect'));
        $this->assertSame('glo', NetworkResolver::key('02', 'clubkonnect'));
        $this->assertSame('9mobile', NetworkResolver::key('03', 'clubkonnect'));
    }

    /* =====================================================================
     | Profitability protection
     | =================================================================== */

    public function test_a_bundle_below_the_minimum_profit_is_blocked_by_default(): void
    {
        /*
         * The floor is enforced as a *refusal* under FACE_VALUE, because the only
         * other way to meet it would be to raise the price — and raising a face
         * value is exactly what face-value pricing forbids. (Under a markup rule the
         * floor instead lifts the markup, which the next test pins.)
         */
        $this->markupRule(attributes: [
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'minimum_profit_minor' => 1000, // ₦10 floor, no discount to earn it from
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
        ]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertFalse($quote->isSellable(), 'A bundle under the profit floor must be refused');
        $this->assertNotNull($quote->refusalReason);
        // The price is reported for the audit trail but is not sold at.
        $this->assertSame(50000, $quote->customerPriceMinor);
    }

    public function test_a_profit_floor_lifts_a_markup_rather_than_blocking_the_sale(): void
    {
        // "10% markup, but never less than ₦10": on a ₦200 bundle the floor wins.
        $this->markupRule(markupBps: 100, attributes: ['minimum_profit_minor' => 1000]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 200.0]), 'mtn');

        $this->assertTrue($quote->isSellable());
        $this->assertSame(1000, $quote->markupAmountMinor, 'The floor raised the ₦2 markup to ₦10');
        $this->assertSame(21000, $quote->customerPriceMinor);
        $this->assertSame(1000, $quote->grossProfitMinor);
    }

    public function test_a_bundle_below_the_minimum_margin_is_blocked(): void
    {
        // ₦500 cost marked up 5% is a 4.76% margin, below a 10% floor.
        $this->markupRule(markupBps: 500, attributes: [
            'minimum_margin_bps' => 1000,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
        ]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertFalse($quote->isSellable());
        $this->assertSame(476, $quote->profitMarginBps, '4.76% margin on ₦525');
    }

    public function test_a_bundle_with_no_active_rule_cannot_be_sold(): void
    {
        // A cost is known, but nothing prices it. Refusing is right: the only
        // alternative the engine has is selling at cost.
        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertNotNull($quote);
        $this->assertFalse($quote->isSellable());
        $this->assertStringContainsString('No active pricing rule', (string) $quote->refusalReason);
    }

    public function test_a_negative_margin_bundle_is_refused_and_never_sold_at_a_loss(): void
    {
        /*
         * A cost that exceeds the price bound: the custom price is set below cost,
         * which is what a stale bound looks like after a provider price rise.
         */
        $this->markupRule(attributes: [
            'minimum_selling_price_minor' => 40000,
            'maximum_selling_price_minor' => 40000,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
        ]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertTrue($quote->isLoss(), '₦400 charged against a ₦500 cost');
        $this->assertFalse($quote->isSellable(), 'A loss-making bundle must be refused');
    }

    public function test_negative_margin_can_be_allowed_only_by_explicit_configuration(): void
    {
        // The engine permits a deliberate loss-leader, but only when the rule says
        // so — never by accident.
        $this->markupRule(attributes: [
            'minimum_selling_price_minor' => 40000,
            'maximum_selling_price_minor' => 40000,
            'allow_negative_margin' => true,
        ]);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 500.0]), 'mtn');

        $this->assertTrue($quote->isSellable());
        $this->assertSame(-10000, $quote->grossProfitMinor);
    }

    /* =====================================================================
     | The full purchase path
     |=================================================================== */

    private function buyData(User $user, string $planCode, string $price, ?string $idempotencyKey = null)
    {
        return $this->actingAs($user)->post(route('pricelist.purchase.data'), [
            'plan_code' => $planCode,
            'phone' => '08031234567',
            'pin' => '5931',
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** Put a catalogue row where the controller will find it. */
    private function withCatalogue(array $plan, string $displayPrice): void
    {
        $this->mock(\App\Services\ClubKonnectCatalogue::class, function ($mock) use ($plan, $displayPrice) {
            $mock->shouldReceive('plan')->andReturn($plan);
            $mock->shouldReceive('plans')->andReturn([
                'data_plans' => [$plan + ['your_price' => (float) $displayPrice]],
                'total_plans' => 1,
                'last_updated' => now()->toDateTimeString(),
            ]);
            // Cold cache: provider routing must not add an upstream round trip.
            $mock->shouldReceive('cachedPlans')->andReturn(null);
            $mock->shouldReceive('planPrice')->andReturn(null);
        });
    }

    public function test_a_data_purchase_debits_the_quoted_price_and_snapshots_the_margin(): void
    {
        $this->markupRule(markupBps: 1000); // ₦500 + 10% = ₦550

        $plan = $this->plan(['clubkonnect_price' => 500.0]);
        $this->withCatalogue($plan, '550.00');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyData($user, 'MTN-1GB-30D', '550.00');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The purchase should have produced a transaction');
        $this->assertSame('550.00', (string) $transaction->total_amount);
        $this->assertSame('0.00', (string) $transaction->service_fee, 'No flat data fee');
        $this->assertSame('450.00', $this->balanceOf($user->fresh()), '₦1,000 − ₦550');

        $snapshot = $transaction->pricingSnapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(50000, (int) $snapshot->provider_cost_minor);
        $this->assertSame(55000, (int) $snapshot->customer_price_minor);
        $this->assertSame(5000, (int) $snapshot->gross_profit_minor);
        $this->assertSame(909, (int) $snapshot->profit_margin_bps, '₦50 on ₦550 is 9.09%');
        $this->assertSame('MTN', $transaction->network_name);
    }

    /**
     * The purchase path must ignore any price the client supplies.
     *
     * The pricelist markup once carried `plan_price` in a data attribute and posted
     * it back, so editing the page with devtools bought a ₦20,000 bundle for ₦1. The
     * form no longer sends a price at all and the controller no longer validates one;
     * this pins both halves of that, because the failure mode is silent profit loss.
     */
    public function test_a_client_supplied_price_cannot_influence_what_is_charged(): void
    {
        $this->markupRule(markupBps: 1000); // ₦500 cost → ₦550 charged

        $plan = $this->plan(['clubkonnect_price' => 500.0]);
        $this->withCatalogue($plan, '550.00');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        // Post a tampered price alongside the honest request.
        $this->actingAs($user)->post(route('pricelist.purchase.data'), [
            'plan_code' => 'MTN-1GB-30D',
            'phone' => '08031234567',
            'pin' => '5931',
            'plan_price' => '1.00',
            'amount' => '1.00',
        ]);

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The purchase should have gone through at the real price');
        $this->assertSame('550.00', (string) $transaction->total_amount, 'The engine price is charged, not the posted one');
        $this->assertSame('450.00', $this->balanceOf($user->fresh()));
    }

    public function test_the_airtime_amount_and_a_data_price_cannot_be_confused(): void
    {
        /*
         * Airtime sends the face value upstream (₦200 of airtime), while its cost is
         * lower. Data sends the bundle price. Both go through the same pipeline, so
         * this pins that the two amount conventions stay distinct.
         */
        $this->markupRule(markupBps: 1000);

        $quote = $this->quotePlan($this->plan(['clubkonnect_price' => 200.0]), 'mtn');

        // Cost ₦200, price ₦220 — the customer buys a ₦220 bundle.
        $this->assertSame(20000, $quote->providerCostMinor);
        $this->assertSame(22000, $quote->customerPriceMinor);
        // A data bundle has no separate face value, so the basis is null.
        $this->assertNull($quote->priceBasisMinor);
    }

    /* =====================================================================
     | Existing behaviour is preserved
     | =================================================================== */

    public function test_cable_television_pricing_is_unaffected(): void
    {
        // Other bill products price through their own config fees today. The engine
        // change must not have altered cost-plus arithmetic for them.
        $rule = PricingRule::create([
            'name' => 'Cable default',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 1500,
            'is_active' => true,
            'priority' => 100,
        ]);

        $quote = app(PricingEngine::class)->quoteWithRule(1000000, 1, $rule);

        $this->assertSame(1150000, $quote->customerPriceMinor);
        $this->assertSame(150000, $quote->grossProfitMinor);
    }

    public function test_a_bundle_whose_cost_is_absent_is_not_priced_at_zero(): void
    {
        $this->markupRule(markupBps: 1000);

        // No cost means no quote at all, so nothing downstream can charge ₦0.
        $this->assertNull($this->quotePlan($this->plan(['clubkonnect_price' => null]), 'mtn'));
    }
}
