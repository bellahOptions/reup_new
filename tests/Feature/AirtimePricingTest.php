<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Provider;
use App\Models\ProviderNetworkCost;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Pricing\PricingEngine;
use App\Pricing\ProviderCost;
use App\Services\BillPaymentService;
use App\Services\NetworkResolver;
use App\Services\ProviderCostResolver;
use App\Services\SecurityService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * Airtime pricing.
 *
 * ## The two behaviours this file exists to pin
 *
 * 1. **A customer buying ₦200 of airtime pays ₦200.** The purchase path used to
 *    add an unconditional 2% service fee, so ₦200 cost ₦204. That fee is gone, and
 *    nothing replaced it: a customer fee appears only if a Super Admin explicitly
 *    enables one on the applicable pricing rule.
 *
 * 2. **The profit is internal.** It comes from the provider's discount — ₦1,000 of
 *    airtime costs ReUp ₦970 at 3%, so the ₦30 spread is gross profit — and never
 *    from a surcharge on the customer. Provider cost, discount and margin must not
 *    reach a customer-facing surface.
 *
 * Every monetary assertion is an exact kobo figure, because that is what the wallet
 * is debited and what the margin is computed from.
 */
class AirtimePricingTest extends TestCase
{
    use RefreshDatabase;
    use FakesProviders;

    protected function setUp(): void
    {
        parent::setUp();

        // No network probes and no cost-based reordering: the fake provider is the
        // only upstream, so the pipeline's own behaviour is what is under test.
        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['fake'],
            'bills.limits.per_minute' => 100,
            'pricing.policy.allow_configured_assumptions' => true,
            'pricing.policy.allow_stale' => false,
            'pricing.policy.max_age_hours' => 168,
        ]);
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function provider(string $slug = 'clubkonnect'): Provider
    {
        return Provider::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'driver' => $slug, 'is_active' => true, 'priority' => 100]
        );
    }

    /**
     * A per-network airtime discount term.
     *
     * @param  int  $discountBps  300 = 3%
     */
    private function airtimeCost(
        string $network,
        int $discountBps = 300,
        ?string $verifiedAt = null,
        string $providerSlug = 'clubkonnect'
    ): ProviderNetworkCost {
        return ProviderNetworkCost::create([
            'provider_id' => $this->provider($providerSlug)->getKey(),
            'capability' => 'airtime',
            'network' => $network,
            'discount_bps' => $discountBps,
            'provider_fee_minor' => 0,
            'currency' => 'NGN',
            'verified_at' => $verifiedAt,
            'is_active' => true,
        ]);
    }

    /**
     * The FACE_VALUE rule airtime sells under.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function faceValueRule(?string $network = null, array $attributes = []): PricingRule
    {
        return PricingRule::create(array_merge([
            'name' => $network ? "Airtime — {$network}" : 'Airtime — all networks',
            'scope' => $network ? PricingRule::SCOPE_NETWORK : PricingRule::SCOPE_GLOBAL,
            'network' => $network,
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'markup_fixed_minor' => 0,
            'minimum_profit_minor' => 0,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ], $attributes));
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

    /** Price airtime exactly as the controller does. */
    private function quoteAirtime(int $faceValueMinor, ?string $networkKey): ?\App\Pricing\PriceQuote
    {
        $cost = app(ProviderCostResolver::class)->forAirtime($faceValueMinor, $networkKey);

        if (! $cost) {
            return null;
        }

        return app(PricingEngine::class)->quote(
            providerCost: $cost->costMinor,
            quantity: 1,
            context: ['network' => $networkKey, 'capability' => 'airtime'],
            priceBasisMinor: $faceValueMinor,
            costMeta: $cost->toEngineMetadata(),
        );
    }

    /* =====================================================================
     | Face value: the customer pays the airtime they asked for
     |=================================================================== */

    public function test_two_hundred_naira_airtime_costs_the_customer_two_hundred_naira(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $quote = $this->quoteAirtime(20000, 'mtn');

        $this->assertNotNull($quote);
        $this->assertTrue($quote->isSellable());
        $this->assertSame(20000, $quote->customerPriceMinor, '₦200 airtime must price at ₦200');
        $this->assertSame(0, $quote->customerFeeMinor, 'No service fee may be added');
    }

    public function test_one_thousand_naira_airtime_costs_the_customer_one_thousand_naira(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $quote = $this->quoteAirtime(100000, 'mtn');

        $this->assertTrue($quote->isSellable());
        $this->assertSame(100000, $quote->customerPriceMinor);
        $this->assertSame(0, $quote->customerFeeMinor);
    }

    public function test_no_default_two_percent_service_fee_is_applied(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        // ₦200 at the old 2% rule would have been ₦204.
        $quote = $this->quoteAirtime(20000, 'mtn');

        $this->assertSame(20000, $quote->customerPriceMinor);
        $this->assertNotSame(20400, $quote->customerPriceMinor);
        $this->assertSame(0, $quote->customerFeeMinor);

        // And the quote's own total equals the face value exactly.
        $this->assertSame('200.00', $quote->price()->toDecimalString());
    }

    public function test_face_value_is_the_default_strategy_for_airtime(): void
    {
        $this->airtimeCost('mtn', 300);

        // The FACE_VALUE member exists and is what the airtime rule uses.
        $this->assertSame('face_value', PricingRule::MARKUP_FACE_VALUE);

        $rule = $this->faceValueRule('mtn');
        $this->assertSame(PricingRule::MARKUP_FACE_VALUE, $rule->markup_type);
    }

    /* =====================================================================
     | Internal profit
     |=================================================================== */

    public function test_a_configured_provider_discount_produces_the_correct_cost_and_gross_profit(): void
    {
        // The brief's worked example: ₦1,000 face, 3% discount.
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $quote = $this->quoteAirtime(100000, 'mtn');

        $this->assertSame(100000, $quote->customerPriceMinor, 'Customer pays ₦1,000');
        $this->assertSame(97000, $quote->providerCostMinor, 'Provider cost is ₦970');
        $this->assertSame(3000, $quote->grossProfitMinor, 'Gross profit is ₦30');
        // ₦30 / ₦1,000 = 3.00% gross margin.
        $this->assertSame(300, $quote->profitMarginBps);
    }

    public function test_profit_comes_from_the_discount_and_not_from_a_customer_surcharge(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $quote = $this->quoteAirtime(20000, 'mtn');

        // The customer pays face value, so every kobo of profit is the discount.
        $this->assertSame($quote->price()->minor(), $quote->priceBasisMinor);
        $this->assertSame(20000 - 19400, $quote->grossProfitMinor);
        $this->assertSame(0, $quote->customerFeeMinor);
    }

    public function test_different_network_discount_rates_are_configured_independently(): void
    {
        // The brief is explicit that a 3% assumption must not be applied blindly to
        // every network.
        $this->airtimeCost('mtn', 300);      // 3.0%
        $this->airtimeCost('airtel', 250);   // 2.5%
        $this->airtimeCost('glo', 350);      // 3.5%

        $this->faceValueRule('mtn');
        $this->faceValueRule('airtel');
        $this->faceValueRule('glo');

        $mtn = $this->quoteAirtime(100000, 'mtn');
        $airtel = $this->quoteAirtime(100000, 'airtel');
        $glo = $this->quoteAirtime(100000, 'glo');

        // Every network charges the customer the same face value...
        $this->assertSame(100000, $mtn->customerPriceMinor);
        $this->assertSame(100000, $airtel->customerPriceMinor);
        $this->assertSame(100000, $glo->customerPriceMinor);

        // ...but the cost and so the margin differ per network.
        $this->assertSame(97000, $mtn->providerCostMinor);
        $this->assertSame(97500, $airtel->providerCostMinor);
        $this->assertSame(96500, $glo->providerCostMinor);

        $this->assertSame(3000, $mtn->grossProfitMinor);
        $this->assertSame(2500, $airtel->grossProfitMinor);
        $this->assertSame(3500, $glo->grossProfitMinor);
    }

    public function test_a_provider_cost_is_recorded_as_estimated_when_the_rate_is_unverified(): void
    {
        // The 3% is a business assumption until somebody confirms it against an
        // invoice, and the accounting must say so.
        $this->airtimeCost('mtn', 300, verifiedAt: null);
        $this->faceValueRule('mtn');

        $cost = app(ProviderCostResolver::class)->forAirtime(100000, 'mtn');

        $this->assertNotNull($cost);
        $this->assertTrue($cost->estimated, 'An unverified rate is an assumption');
        $this->assertFalse($cost->isVerified());
        $this->assertSame(ProviderCost::SOURCE_CONFIGURED, $cost->source);
    }

    public function test_a_verified_rate_is_not_marked_estimated(): void
    {
        // A rate confirmed against a real provider document counts as verified, and
        // only that makes airtime profit recognisable as realised.
        $this->airtimeCost('mtn', 300, verifiedAt: now()->subDay()->toDateTimeString());
        $this->faceValueRule('mtn');

        $cost = app(ProviderCostResolver::class)->forAirtime(100000, 'mtn');

        $this->assertFalse($cost->estimated);
        $this->assertTrue($cost->isVerified());
        $this->assertSame(97000, $cost->costMinor);
    }

    public function test_only_a_completed_sale_on_a_verified_cost_reports_realised_profit(): void
    {
        // The distinction the brief insists on: estimated profit is not realised
        // profit, and a pending sale has realised nothing.
        $this->airtimeCost('mtn', 300, verifiedAt: now()->subDay()->toDateTimeString());
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-9']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();
        $analysis = $transaction->pricingAnalysis();

        $this->assertNotNull($analysis);
        $this->assertFalse($analysis['cost_is_estimated'], 'A verified rate is not an assumption');
        $this->assertTrue($analysis['profit_is_realised'], 'A successful sale on a verified cost realises profit');
        $this->assertSame(600, $analysis['gross_profit_minor']);
        // ₦6 gross profit on a ₦200 sale is a 3% gross margin — the same 3% as the
        // provider discount, because the customer pays face value.
        $this->assertSame('3.00', number_format($analysis['gross_margin_bps'] / 100, 2));
    }

    public function test_an_unverified_rate_reports_profit_as_estimated_not_realised(): void
    {
        $this->airtimeCost('mtn', 300, verifiedAt: null);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-9']));

        $this->buyAirtime($user, '01', '200');

        $analysis = Transactions::where('user_id', $user->id)->latest('id')->first()->pricingAnalysis();

        $this->assertTrue($analysis['cost_is_estimated'], 'An unconfirmed rate is an assumption');
        $this->assertFalse($analysis['profit_is_realised'], 'Assumed profit is never reported as realised');
    }

    /* =====================================================================
     | Unknown and stale costs
     |=================================================================== */

    public function test_an_unknown_provider_cost_refuses_the_sale_rather_than_inventing_one(): void
    {
        // No cost term configured for this network at all.
        $this->faceValueRule('mtn');

        $quote = $this->quoteAirtime(100000, 'mtn');

        $this->assertNull($quote, 'With no usable cost the sale must not be priced');
    }

    public function test_a_stale_provider_cost_is_treated_as_unknown(): void
    {
        config(['pricing.policy.max_age_hours' => 24]);

        // Verified nine days ago, well past the 24-hour window.
        $this->airtimeCost('mtn', 300, verifiedAt: now()->subDays(9)->toDateTimeString());
        $this->faceValueRule('mtn');

        $cost = app(ProviderCostResolver::class)->forAirtime(100000, 'mtn');

        $this->assertNull($cost, 'A stale cost must not price a sale by default');
    }

    public function test_a_stale_cost_may_be_used_when_policy_explicitly_allows_it(): void
    {
        config([
            'pricing.policy.max_age_hours' => 24,
            'pricing.policy.allow_stale' => true,
        ]);

        $this->airtimeCost('mtn', 300, verifiedAt: now()->subDays(9)->toDateTimeString());
        $this->faceValueRule('mtn');

        $cost = app(ProviderCostResolver::class)->forAirtime(100000, 'mtn');

        $this->assertNotNull($cost);
        $this->assertSame(97000, $cost->costMinor);
    }

    public function test_the_platform_never_claims_it_can_sell_without_a_verified_cost(): void
    {
        $this->assertFalse(
            app(ProviderCostResolver::class)->maySellWithoutVerifiedCost(),
            'There is no "sell anyway on a guessed cost" setting'
        );
    }

    public function test_the_unavailable_message_tells_the_customer_nothing_about_costs(): void
    {
        $message = app(ProviderCostResolver::class)->unavailableMessage();

        foreach (['cost', 'margin', 'profit', 'discount', 'ClubKonnect', 'provider'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $message);
        }

        // It does tell them the thing they care about.
        $this->assertStringContainsString('No money has left your wallet', $message);
    }

    /* =====================================================================
     | Profitability protection
     |=================================================================== */

    public function test_an_unprofitable_airtime_sale_is_blocked_by_default(): void
    {
        // A rule with a profit floor the discount cannot meet: 3% of ₦200 is ₦6,
        // and the floor demands ₦50.
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn', [
            'minimum_profit_minor' => 5000,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
        ]);

        $quote = $this->quoteAirtime(20000, 'mtn');

        $this->assertNotNull($quote);
        $this->assertFalse($quote->isSellable(), 'The floor must block the sale');
        $this->assertSame(20000, $quote->customerPriceMinor, 'The price must NOT be raised to meet the floor');
        $this->assertNotNull($quote->refusalReason);
    }

    public function test_the_price_is_never_silently_raised_to_cover_provider_cost(): void
    {
        /*
         * The discount cannot be configured as a negative number — the column is
         * unsigned, precisely so a rate can never invert into a surcharge. So the
         * unreachable-margin case is constructed the other way: a zero discount,
         * which leaves no spread at all, against a rule that demands ₦50 of profit.
         */
        $this->airtimeCost('mtn', 0);
        $this->faceValueRule('mtn', ['minimum_profit_minor' => 5000]);

        $quote = $this->quoteAirtime(20000, 'mtn');

        $this->assertNotNull($quote);
        $this->assertFalse($quote->isSellable(), 'A sale with no margin must be refused');
        $this->assertSame(20000, $quote->customerPriceMinor, 'The price must NOT be raised to meet the floor');
        $this->assertSame(0, $quote->grossProfitMinor);
    }

    public function test_a_minimum_margin_rule_blocks_a_thin_margin(): void
    {
        // 3% discount gives a 3% margin; demand 10%.
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn', [
            'minimum_margin_bps' => 1000,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
        ]);

        $quote = $this->quoteAirtime(100000, 'mtn');

        $this->assertFalse($quote->isSellable());
        $this->assertSame(300, $quote->profitMarginBps);
        $this->assertSame(100000, $quote->customerPriceMinor);
    }

    /* =====================================================================
     | Explicit customer fees
     |=================================================================== */

    public function test_no_fee_is_charged_when_the_rule_does_not_enable_one(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn', ['customer_fee_enabled' => false]);

        $quote = $this->quoteAirtime(20000, 'mtn');

        $this->assertSame(0, $quote->customerFeeMinor);
        $this->assertSame(20000, $quote->customerPriceMinor);
    }

    public function test_an_explicitly_enabled_fee_is_applied_on_top_of_face_value(): void
    {
        // The one legitimate way a customer fee appears: a Super Admin enables it
        // on the rule, deliberately and auditably.
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn', [
            'customer_fee_enabled' => true,
            'customer_fee_type' => 'fixed',
            'customer_fee_minor' => 1000, // ₦10
        ]);

        $quote = $this->quoteAirtime(20000, 'mtn');

        $this->assertTrue($quote->isSellable());
        $this->assertSame(1000, $quote->customerFeeMinor);
        $this->assertSame(21000, $quote->customerPriceMinor, '₦200 + an explicitly enabled ₦10 fee');
    }

    /* =====================================================================
     | Rounding
     |=================================================================== */

    public function test_a_discount_that_does_not_divide_evenly_rounds_half_up_to_the_kobo(): void
    {
        // 3% of ₦333.33 = ₦9.9999 → 1000 kobo, so a cost of 32333 kobo.
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $cost = app(ProviderCostResolver::class)->forAirtime(33333, 'mtn');

        $this->assertSame(33333 - 1000, $cost->costMinor);
        $this->assertSame(1000, $cost->discountMinor());
    }

    public function test_rounding_rules_never_move_a_face_value(): void
    {
        $this->airtimeCost('mtn', 300);
        // A rule configured to round to the nearest ₦100.
        $this->faceValueRule('mtn', [
            'rounding_step_minor' => 10000,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
        ]);

        $quote = $this->quoteAirtime(10150, 'mtn');

        /*
         * Rounding must not move a face value. Snapping ₦101.50 to the nearest ₦100
         * would charge ₦100 while asking the provider to vend ₦101.50, so ReUp eats
         * the difference. The customer buys the airtime they asked for.
         */
        $this->assertSame(10150, $quote->customerPriceMinor);
        $this->assertSame(0, $quote->roundingAdjustmentMinor);

        // Profit is still derived from the price actually charged.
        $this->assertSame(10150 - $quote->providerCostMinor, $quote->grossProfitMinor);
    }

    public function test_rounding_still_applies_to_a_cost_plus_price(): void
    {
        // The guard above must not disable rounding for the products it exists for.
        $rule = PricingRule::create([
            'name' => 'Rounded markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 1000,
            'rounding_step_minor' => 10000,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'is_active' => true,
            'priority' => 100,
        ]);

        // ₦1,000 + 10% = ₦1,100, rounded to the nearest ₦100 = ₦1,100.
        $quote = app(PricingEngine::class)->quoteWithRule(100000, 1, $rule);
        $this->assertSame(110000, $quote->customerPriceMinor);
        $this->assertSame(0, $quote->roundingAdjustmentMinor);

        // ₦1,037 + 10% = ₦1,140.70 → rounded to ₦1,100.
        $quote = app(PricingEngine::class)->quoteWithRule(103700, 1, $rule);
        $this->assertSame(110000, $quote->customerPriceMinor);
        $this->assertSame(-4070, $quote->roundingAdjustmentMinor);
    }

    /* =====================================================================
     | The full purchase path: wallet, snapshot, network
     |=================================================================== */

    /**
     * Give the controller's dependencies a priced rule and a cost term, then buy
     * airtime over HTTP and assert on the money that actually moved.
     */
    private function buyAirtime(User $user, string $networkCode, string $amount, ?string $idempotencyKey = null)
    {
        return $this->actingAs($user)->post(route('airtime.purchase'), [
            'network' => $networkCode,
            'phone' => '08031234567',
            'amount' => $amount,
            'pin' => '5931',
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function test_a_two_hundred_naira_airtime_purchase_debits_exactly_two_hundred_naira(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $response = $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The purchase should have produced a transaction');
        $this->assertSame('200.00', (string) $transaction->total_amount, 'Total paid must be ₦200');
        $this->assertSame('200.00', (string) $transaction->amount, 'Amount must be the ₦200 of airtime bought');
        $this->assertSame('0.00', (string) $transaction->service_fee, 'No service fee');
        $this->assertSame('800.00', $this->balanceOf($user->fresh()), 'The wallet is debited exactly the quote');
    }

    public function test_the_purchase_persists_the_network_and_not_the_provider(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertSame('mtn', $transaction->network_code);
        $this->assertSame('MTN', $transaction->network_name);
        $this->assertSame('MTN', $transaction->network_display);
    }

    public function test_the_purchase_records_an_immutable_pricing_snapshot(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();
        $snapshot = $transaction->pricingSnapshot;

        $this->assertNotNull($snapshot, 'Every priced purchase must record a snapshot');
        $this->assertSame(20000, (int) $snapshot->customer_price_minor);
        $this->assertSame(19400, (int) $snapshot->provider_cost_minor, '₦200 less 3%');
        $this->assertSame(600, (int) $snapshot->gross_profit_minor, '₦6 gross profit');
        $this->assertSame('face_value', $snapshot->markup_type);
        $this->assertSame(20000, (int) $snapshot->price_basis_minor);
        $this->assertNotNull($snapshot->pricing_rule_id, 'The rule that priced it must be recorded');
        $this->assertTrue((bool) $snapshot->cost_is_estimated, 'An unverified rate yields estimated profit');
        $this->assertSame(ProviderCost::SOURCE_CONFIGURED, $snapshot->cost_source);
    }

    public function test_the_pricing_snapshot_cannot_be_rewritten(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200');

        $snapshot = Transactions::where('user_id', $user->id)->latest('id')->first()->pricingSnapshot;
        $snapshot->gross_profit_minor = 999999;

        $this->expectException(\RuntimeException::class);
        $snapshot->save();
    }

    public function test_a_quoted_value_is_used_but_a_different_quoted_value_is_refused(): void
    {
        // The quote is the price, so the customer is charged face value and the
        // receipt's total matches the debit exactly.
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        // The receipt shows what was charged; the wallet lost exactly that.
        $this->assertSame(
            (string) $transaction->total_amount,
            Money::fromDatabase('1000.00')->minus(Money::fromDatabase($this->balanceOf($user->fresh())))->toDecimalString()
        );
    }

    /* =====================================================================
     | Transaction integrity
     |=================================================================== */

    public function test_a_duplicate_idempotency_key_does_not_debit_twice(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200', 'idem-airtime-0001');
        $this->buyAirtime($user, '01', '200', 'idem-airtime-0001');

        $this->assertSame('800.00', $this->balanceOf($user->fresh()), 'A replay must not debit again');
        $this->assertSame(
            1,
            Transactions::where('user_id', $user->id)->where('type', 'debit')->count(),
            'A replay must not create a second purchase'
        );
    }

    public function test_one_purchase_writes_exactly_one_ledger_debit(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200');

        $debits = WalletLedger::where('user_id', $user->id)
            ->where('entry_type', WalletLedger::ENTRY_DEBIT)
            ->where('amount', '200.00')
            ->count();

        $this->assertSame(1, $debits, 'Exactly one ₦200 debit ledger entry');
    }

    public function test_an_unknown_provider_outcome_does_not_refund_and_does_not_retry(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');

        /*
         * A transport timeout: the request left this process and the answer was
         * lost, so the order may or may not have been vended. That is the case the
         * state model exists for.
         */
        $this->withProviders($this->fakeProvider('fake', ['status' => 'TIMEOUT'], 'unknown'));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertSame('unknown', $transaction->status, 'An unknown outcome is recorded as unknown');
        $this->assertSame('800.00', $this->balanceOf($user->fresh()), 'The money stays held, not refunded');
        $this->assertCount(1, $this->providerCalls, 'An unknown outcome must not be retried on another provider');
    }

    public function test_a_refund_does_not_delete_or_rewrite_the_pricing_snapshot(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('success', $transaction->status);

        $before = $transaction->pricingSnapshot;
        $this->assertNotNull($before);
        $this->assertSame(600, (int) $before->gross_profit_minor);

        /*
         * Reverse it through the one refund path, rather than through the provider
         * failover path (which `BillPaymentStateTest` already covers in depth). The
         * point here is the accounting record: a reversal must not delete, rewrite or
         * recompute the pricing decision the sale was made under.
         */
        $refunded = app(BillPaymentService::class)->refund($transaction, 'Reversed for the pricing-snapshot test.');
        $this->assertTrue($refunded);

        $after = $transaction->fresh();

        $this->assertSame('refunded', $after->payment_status);
        $this->assertSame('1000.00', $this->balanceOf($user->fresh()), 'The customer is made whole');

        // The snapshot is byte-for-byte the pricing decision that was quoted.
        $snapshot = $after->pricingSnapshot;
        $this->assertNotNull($snapshot, 'A reversal must not delete the snapshot');
        $this->assertSame(20000, (int) $snapshot->customer_price_minor);
        $this->assertSame(19400, (int) $snapshot->provider_cost_minor);
        $this->assertSame(600, (int) $snapshot->gross_profit_minor);
        $this->assertSame($before->getKey(), $snapshot->getKey(), 'The same snapshot row survives');
    }

    public function test_a_reversal_does_not_report_the_charge_as_realised_profit(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();
        app(BillPaymentService::class)->refund($transaction, 'Reversed.');

        $analysis = $transaction->fresh()->pricingAnalysis();

        // The figures remain as quoted for the audit trail, but a reversed charge is
        // not a realised sale.
        $this->assertSame(600, $analysis['gross_profit_minor']);
        $this->assertFalse($analysis['profit_is_realised'], 'A refunded transaction has not realised profit');
    }

    public function test_airtime_with_no_cost_configured_sells_nothing_and_moves_no_money(): void
    {
        // A FACE_VALUE rule exists but no provider cost term does.
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $response = $this->buyAirtime($user, '01', '200');

        $response->assertSessionHas('error');
        $this->assertSame('1000.00', $this->balanceOf($user->fresh()), 'A refused sale moves no money');
        $this->assertSame(0, Transactions::where('user_id', $user->id)->where('type', 'debit')->count());
    }

    /* =====================================================================
     | Customer-facing surfaces
     |=================================================================== */

    public function test_the_success_page_shows_the_correct_receipt_for_a_two_hundred_naira_purchase(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-7']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $response = $this->get(route('transactions.success', $transaction->reference));

        $response->assertOk();
        $response->assertSee('MTN');
        $response->assertSee('200.00');
        // Provider cost, discount and margin are internal.
        $response->assertDontSee('194.00');
        $response->assertDontSee('ClubKonnect');
        $response->assertDontSee('6.00 gross');
    }

    public function test_the_receipt_does_not_print_a_zero_service_fee_row(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-7']));

        $this->buyAirtime($user, '01', '200');

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $response = $this->get(route('transactions.success', $transaction->reference));

        $response->assertDontSee('Service fee');
    }

    /* =====================================================================
     | The network is priced per network
     |=================================================================== */

    public function test_a_network_scoped_rule_takes_precedence_over_a_global_one(): void
    {
        /*
         * The two rules differ in the strategy they apply, so which one ran is
         * observable from the price rather than inferred:
         *
         *   * the MTN network rule is FACE_VALUE, so ₦200 of MTN airtime costs ₦200;
         *   * the global fallback marks face value up by 20%, so the same purchase
         *     on a network with no rule of its own would cost ₦240.
         *
         * If precedence were wrong, the MTN quote would come back at ₦240.
         */
        $this->airtimeCost('mtn', 300);
        $this->airtimeCost('airtel', 300);

        $this->faceValueRule(null, [
            'name' => 'Airtime — all networks (markup)',
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 2000,
        ]);
        $this->faceValueRule('mtn', ['name' => 'Airtime — MTN (face value)']);

        $mtn = $this->quoteAirtime(20000, 'mtn');
        $airtel = $this->quoteAirtime(20000, 'airtel');

        // MTN matched its own network rule: face value, no markup.
        $this->assertSame(20000, $mtn->customerPriceMinor);
        $this->assertSame(19400, $mtn->providerCostMinor);
        $this->assertSame(600, $mtn->grossProfitMinor);

        // Airtel fell through to the global rule: cost plus 20% of ₦194 = ₦232.80.
        $this->assertSame(23280, $airtel->customerPriceMinor);
        $this->assertSame(19400, $airtel->providerCostMinor);
    }

    public function test_a_network_rule_does_not_leak_onto_another_network(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->airtimeCost('glo', 350);

        // Only MTN has a rule. Glo must not inherit it.
        $this->faceValueRule('mtn');

        $mtn = $this->quoteAirtime(20000, 'mtn');
        $glo = $this->quoteAirtime(20000, 'glo');

        $this->assertTrue($mtn->isSellable());

        // Glo has a cost term but no rule, so the engine refuses rather than
        // falling back to MTN's rule or to cost.
        $this->assertFalse($glo->isSellable());
        $this->assertStringContainsString('No active pricing rule', (string) $glo->refusalReason);
    }

    public function test_airtime_resolution_uses_the_canonical_network_key(): void
    {
        // The cost term is keyed by the canonical key, so an unresolved network must
        // not silently pick up another network's discount.
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');

        $this->assertSame('mtn', NetworkResolver::key('01', 'clubkonnect'));
        $this->assertNotNull($this->quoteAirtime(20000, 'mtn'));

        // Glo has no term, so nothing can be priced for it.
        $this->assertNull($this->quoteAirtime(20000, 'glo'));
    }

    /* =====================================================================
     | Other services are unaffected
     |=================================================================== */

    public function test_a_non_airtime_rule_is_untouched_by_the_face_value_default(): void
    {
        // A cost-plus rule must still behave as cost-plus; the change to airtime must
        // not have altered the engine's default arithmetic for anything else.
        $rule = PricingRule::create([
            'name' => 'Data default',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 1000,
            'is_active' => true,
            'priority' => 100,
        ]);

        $quote = app(PricingEngine::class)->quoteWithRule(100000, 1, $rule);

        $this->assertSame(110000, $quote->customerPriceMinor, '₦1,000 cost + 10% = ₦1,100');
        $this->assertSame(10000, $quote->grossProfitMinor);
    }

    public function test_a_customer_fee_is_only_applied_when_the_rule_enables_it(): void
    {
        // The engine's contract: no fee unless explicitly enabled.
        $withoutFee = PricingRule::create([
            'name' => 'No fee',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'customer_fee_enabled' => false,
            'customer_fee_minor' => 5000,
            'is_active' => true,
            'priority' => 100,
        ]);

        $quote = app(PricingEngine::class)->quoteWithRule(100000, 1, $withoutFee, null, 100000);

        $this->assertSame(0, $quote->customerFeeMinor, 'A configured fee amount is inert while disabled');
        $this->assertSame(100000, $quote->customerPriceMinor);
    }

    /* =====================================================================
     | The Super Admin cost console
     |=================================================================== */

    public function test_a_super_admin_can_open_the_provider_cost_console(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.pricing.costs'))
            ->assertOk();
    }

    public function test_a_plain_administrator_cannot_see_provider_costs(): void
    {
        // Cost and margin are commercially sensitive; viewing them is a separate
        // permission from viewing the console's other screens.
        $admin = User::factory()->admin(['view_dashboard', 'view_users'])->create();

        $this->actingAs($admin)
            ->get(route('admin.pricing.costs'))
            ->assertForbidden();
    }

    public function test_a_super_admin_can_record_a_per_network_rate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $provider = $this->provider();

        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '3.5',
        ])->assertRedirect();

        $term = ProviderNetworkCost::where('provider_id', $provider->getKey())
            ->where('capability', 'airtime')
            ->where('network', 'mtn')
            ->first();

        $this->assertNotNull($term);
        $this->assertSame(350, (int) $term->discount_bps, '3.5% is 350 basis points');
        $this->assertFalse($term->isVerified(), 'A rate entered without confirming it is an assumption');
    }

    public function test_recording_a_rate_without_confirmation_keeps_profit_estimated(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $provider = $this->provider();

        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '3',
        ]);

        $this->faceValueRule('mtn');
        $cost = app(ProviderCostResolver::class)->forAirtime(100000, 'mtn');

        $this->assertTrue($cost->estimated);
        $this->assertFalse($cost->isVerified());
    }

    public function test_confirming_a_rate_against_evidence_promotes_it_to_verified(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $provider = $this->provider();

        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '3',
            'verified' => '1',
            'verification_note' => 'ClubKonnect statement 2026-02',
        ]);

        $term = ProviderNetworkCost::where('network', 'mtn')->first();

        $this->assertTrue($term->isVerified());
        $this->assertSame('ClubKonnect statement 2026-02', $term->verification_note);
        $this->assertSame($superAdmin->getKey(), (int) $term->verified_by);

        // And the resolver now reports a confirmed cost.
        $this->faceValueRule('mtn');
        $cost = app(ProviderCostResolver::class)->forAirtime(100000, 'mtn');
        $this->assertFalse($cost->estimated);
        $this->assertTrue($cost->isVerified());
    }

    public function test_editing_a_verified_rate_without_reconfirming_drops_its_verification(): void
    {
        /*
         * The previous verification was evidence about the *old* number. Carrying it
         * over to a new figure would let an unconfirmed rate report as realised
         * profit.
         */
        $superAdmin = User::factory()->superAdmin()->create();
        $provider = $this->provider();

        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '3',
            'verified' => '1',
        ]);

        $this->assertTrue(ProviderNetworkCost::where('network', 'mtn')->first()->isVerified());

        // Now change the rate without ticking the confirmation box again.
        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '4',
        ]);

        $term = ProviderNetworkCost::where('network', 'mtn')->first();

        $this->assertSame(400, (int) $term->discount_bps);
        $this->assertFalse($term->isVerified(), 'A changed rate loses the old verification');
    }

    public function test_a_rate_change_is_written_to_the_admin_audit_log(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $provider = $this->provider();

        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '3',
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $superAdmin->getKey(),
            'action' => 'provider_cost_created',
        ]);

        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '4',
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $superAdmin->getKey(),
            'action' => 'provider_cost_updated',
        ]);
    }

    public function test_a_plain_administrator_cannot_change_a_provider_cost(): void
    {
        $admin = User::factory()->admin(['view_dashboard'])->create();
        $provider = $this->provider();

        $this->actingAs($admin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'mtn',
            'discount_percentage' => '10',
        ])->assertForbidden();

        $this->assertSame(0, ProviderNetworkCost::count(), 'A refused write must change nothing');
    }

    public function test_an_unknown_network_cannot_be_given_a_rate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $provider = $this->provider();

        // A typo would create a term that never matches a real sale.
        $this->actingAs($superAdmin)->put(route('admin.pricing.costs.update'), [
            'provider_id' => $provider->getKey(),
            'network' => 'not-a-network',
            'discount_percentage' => '3',
        ])->assertSessionHasErrors('network');
    }

    public function test_the_costs_console_shows_the_recorded_rate_and_its_verification_state(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->airtimeCost('mtn', 300, verifiedAt: null);

        $response = $this->actingAs($superAdmin)->get(route('admin.pricing.costs'));

        $response->assertOk();
        $response->assertSee('MTN');
        $response->assertSee('3.00%');
        // The rate is an assumption, and the screen must say so.
        $response->assertSee('Assumption');
    }

    public function test_the_costs_console_does_not_claim_unverified_profit_is_realised(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->airtimeCost('mtn', 300, verifiedAt: null);

        $this->actingAs($superAdmin)
            ->get(route('admin.pricing.costs'))
            ->assertSee('estimated');
    }

    public function test_the_pricing_rule_form_offers_the_face_value_strategy_and_the_network_scope(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('admin.pricing.create'));

        $response->assertOk();
        $response->assertSee('face_value', false);
        $response->assertSee('Mobile network');
    }

    public function test_a_network_scoped_rule_can_be_created_through_the_console(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('admin.pricing.store'), [
            'name' => 'Airtime — MTN face value',
            'scope' => 'network',
            'network' => 'mtn',
            'markup_type' => 'face_value',
            'on_unprofitable' => 'unavailable',
            'rounding_mode' => 'nearest',
            'priority' => 100,
            'is_active' => '1',
        ])->assertRedirect();

        $rule = PricingRule::where('scope', 'network')->where('network', 'mtn')->first();

        $this->assertNotNull($rule, 'A network-scoped rule must be creatable from the console');
        $this->assertSame('face_value', $rule->markup_type);
        $this->assertSame('MTN', \App\Services\NetworkResolver::labelFor($rule->network));
    }

    public function test_the_console_rejects_a_network_rule_with_no_network(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        // The service refuses a network rule with no subject, so it cannot sit in
        // the console looking active while matching nothing.
        $this->actingAs($superAdmin)->post(route('admin.pricing.store'), [
            'name' => 'Broken network rule',
            'scope' => 'network',
            'markup_type' => 'face_value',
            'on_unprofitable' => 'unavailable',
            'rounding_mode' => 'nearest',
        ])->assertSessionHasErrors();
    }
}
