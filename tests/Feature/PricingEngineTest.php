<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pricing engine.
 *
 * Every assertion here is about an exact figure in kobo, because that is what the
 * customer is charged and what the margin is computed from. The cases the brief
 * names explicitly — percentage, fixed, combined, minimum profit, rounding,
 * margin versus markup — are each pinned to a worked example.
 *
 * The engine is pure calculation over a rule and a cost, so most of these need no
 * database. `RefreshDatabase` is present because the two rule-resolution tests
 * (`quote()` with no rule) genuinely query for one.
 */
class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new PricingEngine();
    }

    /** A rule object that is never persisted, which is all the engine needs. */
    private function rule(array $attributes = []): PricingRule
    {
        $rule = new PricingRule();

        $rule->forceFill(array_merge([
            'name' => 'Test rule',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 0,
            'markup_fixed_minor' => 0,
            'minimum_profit_minor' => 0,
            'customer_fee_enabled' => false,
            'customer_fee_type' => 'fixed',
            'customer_fee_minor' => 0,
            'customer_fee_bps' => 0,
            'discount_bps' => 0,
            'discount_fixed_minor' => 0,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ], $attributes));

        return $rule;
    }

    /* =====================================================================
     | Markup types — the brief's worked examples
     |=================================================================== */

    public function test_percentage_markup_produces_the_expected_price_and_profit(): void
    {
        // Brief: Provider Cost ₦1,000, Markup 20% → Price ₦1,200, Profit ₦200.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule(['markup_percentage_bps' => 2000])
        );

        $this->assertTrue($quote->isSellable());
        $this->assertSame(120000, $quote->customerPriceMinor);
        $this->assertSame(20000, $quote->grossProfitMinor);
        $this->assertSame(20000, $quote->markupAmountMinor);
    }

    public function test_fixed_markup_produces_the_expected_price_and_profit(): void
    {
        // Brief: Provider Cost ₦1,000, Fixed Markup ₦300 → Price ₦1,300, Profit ₦300.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule(['markup_type' => PricingRule::MARKUP_FIXED, 'markup_fixed_minor' => 30000])
        );

        $this->assertSame(130000, $quote->customerPriceMinor);
        $this->assertSame(30000, $quote->grossProfitMinor);
    }

    public function test_combined_markup_adds_both_components(): void
    {
        // ₦1,000 cost + 20% (₦200) + ₦300 fixed = ₦1,500, profit ₦500.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_type' => PricingRule::MARKUP_PERCENTAGE_PLUS_FIXED,
                'markup_percentage_bps' => 2000,
                'markup_fixed_minor' => 30000,
            ])
        );

        $this->assertSame(150000, $quote->customerPriceMinor);
        $this->assertSame(50000, $quote->grossProfitMinor);

        // The two components are recorded separately so the audit trail shows
        // what each contributed, not just their sum.
        $this->assertSame(50000, $quote->markupAmountMinor);
        $this->assertSame(2000, $quote->markupPercentageBps);
        $this->assertSame(30000, $quote->markupFixedMinor);
    }

    public function test_no_markup_sells_at_cost_when_nothing_forbids_it(): void
    {
        /*
         * With no markup and no floor, the price equals the cost and the profit
         * is zero. That is not a loss, so it is permitted — a zero-profit
         * promotional item is a legitimate operator choice, and refusing it
         * would make the `none` markup type unusable.
         */
        $quote = $this->engine->quoteWithRule(100000, 1, $this->rule(['markup_type' => PricingRule::MARKUP_NONE]));

        $this->assertTrue($quote->isSellable());
        $this->assertSame(100000, $quote->customerPriceMinor);
        $this->assertSame(0, $quote->grossProfitMinor);
    }

    public function test_a_markup_too_small_for_the_minimum_margin_is_refused(): void
    {
        // A 2% markup on ₦1,000 gives a 1.96% margin against a 10% requirement
        // and no minimum-profit floor, so the margin rule alone refuses it.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 200,
                'minimum_margin_bps' => 1000,
                'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            ])
        );

        $this->assertFalse($quote->isSellable());
        $this->assertStringContainsString('temporarily unavailable', (string) $quote->refusalReason);
    }

    /* =====================================================================
     | Minimum profit
     |=================================================================== */

    public function test_a_minimum_profit_floor_lifts_a_small_percentage_markup(): void
    {
        /*
         * Brief: "20% markup BUT minimum profit = ₦100". On a ₦100 cost, 20% is
         * ₦20, which is below the ₦100 floor, so the floor wins and the price
         * becomes ₦200.
         */
        $quote = $this->engine->quoteWithRule(
            10000,
            1,
            $this->rule(['markup_percentage_bps' => 2000, 'minimum_profit_minor' => 10000])
        );

        $this->assertSame(10000, $quote->markupAmountMinor);
        $this->assertSame(20000, $quote->customerPriceMinor);
        $this->assertSame(10000, $quote->grossProfitMinor);
    }

    public function test_a_minimum_profit_floor_does_not_reduce_a_larger_markup(): void
    {
        // On a ₦1,000 cost the 20% markup is ₦200, above the ₦100 floor, so the
        // percentage stands. The floor is a floor, not a fixed value.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule(['markup_percentage_bps' => 2000, 'minimum_profit_minor' => 10000])
        );

        $this->assertSame(20000, $quote->markupAmountMinor);
        $this->assertSame(120000, $quote->customerPriceMinor);
    }

    /* =====================================================================
     | Markup versus margin
     |=================================================================== */

    public function test_markup_and_margin_are_different_numbers_and_both_are_correct(): void
    {
        /*
         * Brief, explicitly: Cost ₦1,000, Selling price ₦1,250 →
         *   Markup 25%, Gross profit ₦250, Gross margin 20%.
         *
         * A 25% markup is reached with `markup_percentage_bps = 2500`.
         */
        $quote = $this->engine->quoteWithRule(100000, 1, $this->rule(['markup_percentage_bps' => 2500]));

        $this->assertSame(125000, $quote->customerPriceMinor);
        $this->assertSame(25000, $quote->grossProfitMinor);

        // Markup is profit / cost = 250 / 1000 = 25%.
        $this->assertSame('25.00%', $quote->markupPercentageLabel());

        // Margin is profit / price = 250 / 1250 = 20%. NOT 25%.
        $this->assertSame(2000, $quote->profitMarginBps);
        $this->assertSame('20.00%', $quote->grossMarginLabel());
    }

    public function test_margin_is_never_reported_as_the_markup_percentage(): void
    {
        // A regression guard for the specific mistake the brief calls out:
        // labelling a markup percentage as a profit margin percentage.
        $quote = $this->engine->quoteWithRule(100000, 1, $this->rule(['markup_percentage_bps' => 5000]));

        // 50% markup on ₦1,000 → ₦1,500, margin is 500/1500 = 33.33%, not 50%.
        $this->assertSame('50.00%', $quote->markupPercentageLabel());
        $this->assertSame('33.33%', $quote->grossMarginLabel());
        $this->assertNotSame($quote->markupPercentageLabel(), $quote->grossMarginLabel());
    }

    /* =====================================================================
     | Rounding
     |=================================================================== */

    public function test_rounding_to_the_nearest_ten_naira_matches_the_brief(): void
    {
        // Brief: calculated ₦1,237, rounded to the nearest ₦10 → ₦1,240.
        // Constructed so the pre-rounding price is exactly 123700 kobo.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_type' => PricingRule::MARKUP_FIXED,
                'markup_fixed_minor' => 23700,
                'rounding_step_minor' => 1000,
                'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            ])
        );

        $this->assertSame(123700, $quote->calculatedPriceMinor);
        $this->assertSame(124000, $quote->customerPriceMinor);
        $this->assertSame(300, $quote->roundingAdjustmentMinor);
    }

    public function test_profit_is_recalculated_from_the_rounded_price_not_the_calculated_one(): void
    {
        /*
         * The brief is explicit: "Never calculate profit from the pre-rounded
         * price." Here rounding moves ₦1,237 up to ₦1,240, so profit must be
         * ₦240 — ₦3 more than the pre-rounding markup of ₦237.
         */
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_type' => PricingRule::MARKUP_FIXED,
                'markup_fixed_minor' => 23700,
                'rounding_step_minor' => 1000,
            ])
        );

        $this->assertSame(23700, $quote->markupAmountMinor);
        $this->assertSame(123700, $quote->calculatedPriceMinor);
        $this->assertSame(124000, $quote->customerPriceMinor);

        // Profit is derived from `customer_price_minor`, so it is 124000 - 100000.
        $this->assertSame(124000 - 100000, $quote->grossProfitMinor);
        $this->assertSame(24000, $quote->grossProfitMinor);
        $this->assertNotSame($quote->markupAmountMinor, $quote->grossProfitMinor);
    }

    public function test_rounding_down_and_up_are_honoured(): void
    {
        $down = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_type' => PricingRule::MARKUP_FIXED,
                'markup_fixed_minor' => 23700,
                'rounding_step_minor' => 1000,
                'rounding_mode' => PricingRule::ROUNDING_DOWN,
            ])
        );

        $up = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_type' => PricingRule::MARKUP_FIXED,
                'markup_fixed_minor' => 23700,
                'rounding_step_minor' => 1000,
                'rounding_mode' => PricingRule::ROUNDING_UP,
            ])
        );

        $this->assertSame(123000, $down->customerPriceMinor);
        $this->assertSame(124000, $up->customerPriceMinor);
    }

    public function test_every_supported_rounding_step_works(): void
    {
        foreach ([100 => 123700, 500 => 123500, 1000 => 124000, 5000 => 125000, 10000 => 120000] as $step => $expected) {
            $quote = $this->engine->quoteWithRule(
                100000,
                1,
                $this->rule([
                    'markup_type' => PricingRule::MARKUP_FIXED,
                    'markup_fixed_minor' => 23700,
                    'rounding_step_minor' => $step,
                ])
            );

            $this->assertSame($expected, $quote->customerPriceMinor, "Rounding to ₦" . ($step / 100) . " failed.");
        }
    }

    /* =====================================================================
     | Negative margin prevention
     |=================================================================== */

    public function test_a_loss_making_rule_is_refused_by_default(): void
    {
        /*
         * A discount larger than the markup would price below cost. With
         * `allow_negative_margin` false, the sale is refused — the brief's
         * "do not silently sell below cost".
         */
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 1000, // ₦100 markup
                'discount_bps' => 5000,          // ₦550 discount on ₦1,100
                'promotion_starts_at' => now()->subDay(),
                'promotion_ends_at' => now()->addDay(),
            ])
        );

        $this->assertTrue($quote->isLoss());
        $this->assertFalse($quote->isSellable());
        $this->assertStringContainsString('below cost', (string) $quote->refusalReason);
    }

    public function test_a_loss_making_rule_is_allowed_when_explicitly_enabled(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 1000,
                'discount_bps' => 5000,
                'promotion_starts_at' => now()->subDay(),
                'promotion_ends_at' => now()->addDay(),
                'allow_negative_margin' => true,
            ])
        );

        // Sold at a loss on purpose, and the quote says so rather than hiding it.
        $this->assertTrue($quote->isSellable());
        $this->assertTrue($quote->isLoss());
        $this->assertLessThan(0, $quote->grossProfitMinor);
    }

    public function test_margin_below_the_configured_minimum_is_refused(): void
    {
        // 5% markup on ₦1,000 → ₦1,050, a 4.76% margin. Minimum margin is 15%.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 500,
                'minimum_margin_bps' => 1500,
            ])
        );

        $this->assertFalse($quote->isSellable());
        $this->assertSame('unavailable', $quote->profitability);
    }

    public function test_the_warning_policy_keeps_the_product_on_sale(): void
    {
        // Same thin margin, but the operator chose to keep selling with a warning.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 500,
                'minimum_margin_bps' => 1500,
                'on_unprofitable' => PricingRule::ON_UNPROFITABLE_WARNING,
            ])
        );

        $this->assertTrue($quote->isSellable());
        $this->assertSame('warning', $quote->profitability);
    }

    public function test_the_approval_policy_requires_sign_off(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 500,
                'minimum_margin_bps' => 1500,
                'on_unprofitable' => PricingRule::ON_UNPROFITABLE_REQUIRE_APPROVAL,
            ])
        );

        $this->assertFalse($quote->isSellable());
        $this->assertSame('require_approval', $quote->profitability);
    }

    /* =====================================================================
     | Price bounds
     |=================================================================== */

    public function test_a_minimum_selling_price_is_enforced(): void
    {
        $quote = $this->engine->quoteWithRule(
            10000,
            1,
            $this->rule(['markup_percentage_bps' => 100, 'minimum_selling_price_minor' => 50000])
        );

        $this->assertSame(50000, $quote->customerPriceMinor);
    }

    public function test_a_maximum_markup_caps_an_expensive_percentage_rule(): void
    {
        // 50% of ₦100,000 is ₦50,000; the cap is ₦10,000.
        $quote = $this->engine->quoteWithRule(
            10000000,
            1,
            $this->rule(['markup_percentage_bps' => 5000, 'maximum_markup_minor' => 1000000])
        );

        $this->assertSame(1000000, $quote->markupAmountMinor);
        $this->assertSame(11000000, $quote->customerPriceMinor);
    }

    /* =====================================================================
     | Customer fee
     |=================================================================== */

    public function test_a_percentage_customer_fee_is_added_and_counts_as_revenue(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 2000,
                'customer_fee_enabled' => true,
                'customer_fee_type' => 'percentage',
                'customer_fee_bps' => 100, // 1%
            ])
        );

        // 1% of the marked-up ₦1,200 is ₦12.
        $this->assertSame(1200, $quote->customerFeeMinor);
        $this->assertSame(121200, $quote->customerPriceMinor);

        /*
         * The fee is revenue: it lands in the same gateway settlement as the
         * rest of the price, so profit is what the customer paid minus what the
         * provider cost. Reporting it as a pass-through would under-report every
         * order that carries one.
         */
        $this->assertSame(121200 - 100000, $quote->grossProfitMinor);
        $this->assertSame(21200, $quote->grossProfitMinor);

        // The fee is still reported on its own, so the two views do not collide.
        $this->assertSame(1200, $quote->customerFeeMinor);
    }

    public function test_a_fixed_customer_fee_is_added(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 2000,
                'customer_fee_enabled' => true,
                'customer_fee_type' => 'fixed',
                'customer_fee_minor' => 5000,
            ])
        );

        $this->assertSame(5000, $quote->customerFeeMinor);
        $this->assertSame(125000, $quote->customerPriceMinor);
    }

    /* =====================================================================
     | Promotions
     |=================================================================== */

    public function test_a_promotional_discount_applies_inside_its_window(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 2000,
                'discount_bps' => 500, // 5% of ₦1,200 = ₦60
                'promotion_starts_at' => now()->subDay(),
                'promotion_ends_at' => now()->addDay(),
            ])
        );

        $this->assertSame(6000, $quote->discountMinor);
        $this->assertSame(114000, $quote->customerPriceMinor);
        // Profit is from the final price: 1140 - 1000.
        $this->assertSame(14000, $quote->grossProfitMinor);
    }

    public function test_a_promotional_discount_does_not_apply_outside_its_window(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 2000,
                'discount_bps' => 500,
                'promotion_starts_at' => now()->subDays(10),
                'promotion_ends_at' => now()->subDay(),
            ])
        );

        $this->assertSame(0, $quote->discountMinor);
        $this->assertSame(120000, $quote->customerPriceMinor);
    }

    public function test_a_discount_with_no_window_configured_is_inert(): void
    {
        // A discount percentage with no dates must not become a permanent
        // unadvertised price cut.
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule(['markup_percentage_bps' => 2000, 'discount_bps' => 5000])
        );

        $this->assertSame(0, $quote->discountMinor);
        $this->assertSame(120000, $quote->customerPriceMinor);
    }

    public function test_a_discount_cannot_exceed_the_amount_it_discounts(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule([
                'markup_percentage_bps' => 2000,
                'discount_fixed_minor' => 999999999,
                'promotion_starts_at' => now()->subDay(),
                'promotion_ends_at' => now()->addDay(),
                'allow_negative_margin' => true,
            ])
        );

        // Capped at the amount, so the price floors at zero rather than going
        // negative — the customer is never paid to buy.
        $this->assertSame(120000, $quote->discountMinor);
        $this->assertSame(0, $quote->customerPriceMinor);
    }

    /* =====================================================================
     | SMM volumetric pricing
     |=================================================================== */

    public function test_a_volumetric_order_prices_the_whole_quantity(): void
    {
        /*
         * Brief example: SMM service at ₦2.72 per 1,000 units, 1,000 ordered →
         * provider cost ₦2.72, which is 272 kobo. A 30% markup prices it at
         * ₦3.54.
         */
        $quote = $this->engine->quoteWithRule(272, 1000, $this->rule(['markup_percentage_bps' => 3000]));

        $this->assertSame(272, $quote->providerCostMinor);
        $this->assertSame(82, $quote->markupAmountMinor); // 30% of 272 = 81.6 → 82
        $this->assertSame(354, $quote->customerPriceMinor);
        $this->assertSame(1000, $quote->quantity);
    }

    public function test_quantity_must_be_at_least_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Guarded on the quote path as well as the rule path, because a zero
        // quantity would be charged as zero by a volumetric rate.
        $this->engine->quote(100000, 0, []);
    }

    /* =====================================================================
     | Exactness
     |=================================================================== */

    public function test_a_single_kobo_cost_prices_exactly(): void
    {
        $quote = $this->engine->quoteWithRule(1, 1, $this->rule(['markup_percentage_bps' => 10000]));

        // 1 kobo + 100% = 2 kobo.
        $this->assertSame(2, $quote->customerPriceMinor);
        $this->assertSame(1, $quote->grossProfitMinor);
    }

    public function test_basis_point_application_rounds_half_up(): void
    {
        // 1.5% of 33333 kobo = 499.995 → 500.
        $this->assertSame(500, $this->engine->applyBps(33333, 150));

        // 1.5% of 10000 = 150 exactly.
        $this->assertSame(150, $this->engine->applyBps(10000, 150));
    }

    public function test_margin_basis_points_are_computed_from_price_not_cost(): void
    {
        // 250 profit on a 1250 price is 2000 bps (20%).
        $this->assertSame(2000, $this->engine->marginBps(25000, 125000));

        // A zero price cannot produce a margin; the method must not divide by zero.
        $this->assertSame(0, $this->engine->marginBps(100, 0));
    }

    public function test_repeated_pricing_of_a_small_amount_does_not_drift(): void
    {
        // Ten thousand sequential quotes must all be identical — proof that no
        // floating-point residue accumulates across the engine's arithmetic.
        $rule = $this->rule(['markup_percentage_bps' => 2000]);
        $first = $this->engine->quoteWithRule(150050, 1, $rule);

        for ($i = 0; $i < 10000; $i++) {
            $quote = $this->engine->quoteWithRule(150050, 1, $rule);

            $this->assertSame($first->customerPriceMinor, $quote->customerPriceMinor);
        }

        $this->assertSame(180060, $first->customerPriceMinor);
    }

    /* =====================================================================
     | No rule
     | =================================================================== */

    public function test_a_product_with_no_rule_cannot_be_sold(): void
    {
        // With no rule resolvable, the engine refuses rather than selling at cost.
        $quote = $this->engine->quote(100000, 1, []);

        $this->assertFalse($quote->isSellable());
        $this->assertStringContainsString('No active pricing rule', (string) $quote->refusalReason);
    }

    /* =====================================================================
     | Snapshots and breakdowns
     | =================================================================== */

    public function test_the_snapshot_attributes_carry_every_required_figure(): void
    {
        $quote = $this->engine->quoteWithRule(
            100000,
            1,
            $this->rule(['markup_percentage_bps' => 2500, 'rounding_step_minor' => 1000])
        );

        $attributes = $quote->toSnapshotAttributes();

        // The exact column list the brief requires, plus the markup type.
        foreach ([
            'provider_cost_minor', 'provider_fee_minor', 'base_cost_minor',
            'markup_type', 'markup_percentage_bps', 'markup_fixed_minor', 'markup_amount_minor',
            'customer_fee_minor', 'discount_minor',
            'calculated_price_minor', 'customer_price_minor', 'rounding_adjustment_minor',
            'gross_profit_minor', 'profit_margin_bps', 'currency', 'pricing_rule_id',
        ] as $key) {
            $this->assertArrayHasKey($key, $attributes, "Snapshot is missing [{$key}].");
        }

        // 25% of ₦1,000 = ₦1,250, rounded to ₦1,250 (already a multiple of 10).
        $this->assertSame(125000, $attributes['customer_price_minor']);
        $this->assertSame(25000, $attributes['gross_profit_minor']);
        $this->assertSame(2000, $attributes['profit_margin_bps']);
        $this->assertSame('NGN', $attributes['currency']);
    }

    public function test_the_customer_breakdown_never_exposes_cost_or_profit(): void
    {
        $quote = $this->engine->quoteWithRule(100000, 1, $this->rule(['markup_percentage_bps' => 2500]));

        $breakdown = $quote->toCustomerBreakdown();

        $this->assertArrayHasKey('total', $breakdown);
        $this->assertArrayNotHasKey('provider_cost', $breakdown);
        $this->assertArrayNotHasKey('gross_profit', $breakdown);
        $this->assertArrayNotHasKey('profit_margin_bps', $breakdown);
        $this->assertArrayNotHasKey('markup_amount', $breakdown);

        // And the serialised form contains none of the internal figures.
        $json = json_encode($breakdown);
        $this->assertStringNotContainsString('provider_cost', $json);
        $this->assertStringNotContainsString('gross_profit', $json);
    }

    public function test_the_admin_breakdown_exposes_cost_and_profit(): void
    {
        $quote = $this->engine->quoteWithRule(100000, 1, $this->rule(['markup_percentage_bps' => 2500]));

        $breakdown = $quote->toAdminBreakdown();

        $this->assertSame(100000, $breakdown['provider_cost']);
        $this->assertSame(25000, $breakdown['gross_profit']);
        $this->assertSame(2000, $breakdown['profit_margin_bps']);
    }
}
