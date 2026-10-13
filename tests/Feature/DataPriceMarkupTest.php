<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\User;
use App\Pricing\PricingEngine;
use App\Pricing\ProviderCost;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The data-bundle markup: its default, its approved band, and the arithmetic.
 *
 * ## Markup is not margin
 *
 * The distinction this file exists to pin, because conflating the two is how a
 * margin target becomes a price cut:
 *
 *     selling price = provider cost × (1 + markup)
 *     gross margin  = profit ÷ selling price
 *
 * On a ₦1,000 bundle a 3% **markup** charges ₦1,030 — a ₦30 gross profit, which
 * is a 2.91% **margin**. Pricing a 3% margin would charge ₦1,030.93 and would
 * mean something different on every bundle.
 *
 * ## Airtime is untouched
 *
 * Airtime is priced by `FACE_VALUE` rules: the customer pays the airtime they
 * asked for and the margin comes from the provider discount. The band therefore
 * excludes `face_value` rules by type, and there are tests here for both
 * directions — that a face-value rule is not bounded, and that a network-scoped
 * face-value airtime rule still prices airtime exactly as it did.
 */
class DataPriceMarkupTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): PricingEngine
    {
        return app(PricingEngine::class);
    }

    /**
     * Price a bundle of the given cost in kobo with a bare percentage rule.
     */
    private function quoteAt(int $costMinor, int $markupBps): \App\Pricing\PriceQuote
    {
        return $this->engine()->quoteWithRule($costMinor, 1, $this->percentageRule($markupBps));
    }

    private function percentageRule(int $markupBps, array $attributes = []): PricingRule
    {
        return new PricingRule(array_merge([
            'name' => 'Test data rule',
            'scope' => PricingRule::SCOPE_GLOBAL,
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

    /* =====================================================================
     | The brief's worked example
     |=================================================================== */

    public function test_a_three_percent_markup_on_a_thousand_naira_bundle_charges_ten_thirty(): void
    {
        $quote = $this->quoteAt(100000, 300);

        $this->assertSame(100000, $quote->providerCostMinor, '₦1,000 cost');
        $this->assertSame(103000, $quote->calculatedPriceMinor, '₦1,030 before rounding');
        $this->assertSame(103000, $quote->customerPriceMinor, 'No rounding step, so the calculated price is final');
        $this->assertSame(3000, $quote->grossProfitMinor, '₦30 expected gross profit');
        $this->assertSame(300, $quote->markupPercentageBps);
    }

    public function test_a_two_percent_markup_is_the_bottom_of_the_band(): void
    {
        $quote = $this->quoteAt(100000, 200);

        $this->assertSame(102000, $quote->customerPriceMinor);
        $this->assertSame(2000, $quote->grossProfitMinor);
    }

    public function test_every_bundle_is_marked_up_on_its_own_cost(): void
    {
        $cheap = $this->quoteAt(25000, 300);   // ₦250
        $dear = $this->quoteAt(2000000, 300);  // ₦20,000

        $this->assertSame(25750, $cheap->customerPriceMinor);
        $this->assertSame(2060000, $dear->customerPriceMinor);
        $this->assertSame(750, $cheap->grossProfitMinor);
        $this->assertSame(60000, $dear->grossProfitMinor);
    }

    /* =====================================================================
     | Markup is not margin
     |=================================================================== */

    public function test_a_three_percent_markup_is_not_a_three_percent_margin(): void
    {
        $quote = $this->quoteAt(100000, 300);

        // 3000 / 103000 = 2.9126…%, rounded half-up to 291 bps.
        $this->assertSame(291, $quote->profitMarginBps);
        $this->assertSame('2.91%', $quote->grossMarginLabel());
        $this->assertSame('3.00%', $quote->markupPercentageLabel());
        $this->assertNotSame($quote->grossMarginLabel(), $quote->markupPercentageLabel());
    }

    /* =====================================================================
     | Rounding, and profit measured after it
     |=================================================================== */

    public function test_rounding_is_applied_and_profit_is_measured_from_the_rounded_price(): void
    {
        /*
         * A ₦940.90 cost with a 3% markup is ₦969.127, and a rule rounding to the
         * nearest ₦10 charges ₦970. The profit must be ₦29.10 — measured from the
         * price the customer actually pays — not the pre-rounding ₦28.23. A
         * dashboard that reported the pre-rounding figure would disagree with the
         * bank by the rounding adjustment on every rounded order.
         */
        $rule = $this->percentageRule(300, ['rounding_step_minor' => 1000]);

        $quote = $this->engine()->quoteWithRule(94090, 1, $rule);

        $this->assertSame(96913, $quote->calculatedPriceMinor, '3% on ₦940.90 = ₦969.127, half-up to 96913 kobo');
        $this->assertSame(97000, $quote->customerPriceMinor, 'Rounded half-up to the nearest ₦10');
        $this->assertSame(87, $quote->roundingAdjustmentMinor);
        $this->assertSame(2910, $quote->grossProfitMinor, '₦29.10 — measured from the rounded price');
    }

    public function test_every_figure_is_an_exact_integer_in_kobo(): void
    {
        $quote = $this->quoteAt(33333, 300);

        // 33333 × 3% = 999.99 kobo, half-up to 1000.
        $this->assertSame(1000, $quote->markupAmountMinor);
        $this->assertSame(34333, $quote->customerPriceMinor);
        $this->assertSame(1000, $quote->grossProfitMinor);
    }

    public function test_a_naira_cost_is_carried_as_exact_kobo(): void
    {
        $quote = $this->quoteAt(Money::fromNaira('940.90')->minor(), 300);

        $this->assertSame(94090, $quote->providerCostMinor);
        $this->assertSame(96913, $quote->customerPriceMinor, '94090 × 1.03 = 96912.7, half-up to 96913');
        $this->assertSame(2823, $quote->grossProfitMinor);
    }

    /* =====================================================================
     | The band: configuration, and enforcement where a rule is saved
     | =================================================================== */

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @return array<string,mixed>
     */
    private function form(string $percent): array
    {
        return [
            'name' => 'Data bundles — default markup',
            'scope' => 'global',
            'markup_type' => 'percentage',
            'markup_percentage' => $percent,
            'rounding_mode' => 'nearest',
            'on_unprofitable' => 'unavailable',
        ];
    }

    public function test_the_default_data_markup_is_three_percent(): void
    {
        $this->assertSame(300, (int) config('pricing.data_default_markup_bps'));
        $this->assertSame(200, (int) config('pricing.data_markup_bps_min'));
        $this->assertSame(300, (int) config('pricing.data_markup_bps_max'));
    }

    public function test_a_super_admin_may_configure_the_bottom_of_the_band(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), $this->form('2'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(200, (int) PricingRule::orderByDesc('id')->firstOrFail()->markup_percentage_bps);
    }

    public function test_a_super_admin_may_configure_a_decimal_inside_the_band(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), $this->form('2.5'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Exactly 250 bps, not 249 or 251.
        $this->assertSame(250, (int) PricingRule::orderByDesc('id')->firstOrFail()->markup_percentage_bps);
    }

    public function test_a_super_admin_may_configure_the_top_of_the_band(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), $this->form('3'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(300, (int) PricingRule::orderByDesc('id')->firstOrFail()->markup_percentage_bps);
    }

    public function test_a_markup_below_the_band_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), $this->form('1.5'))
            ->assertSessionHasErrors('rule');

        $this->assertSame(0, PricingRule::count());
    }

    public function test_a_markup_above_the_band_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), $this->form('30'))
            ->assertSessionHasErrors('rule');

        $this->assertSame(0, PricingRule::count());
    }

    /**
     * The refusal must say why, in the terms the operator was thinking in.
     */
    public function test_the_refusal_explains_the_band_and_the_markup_margin_distinction(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), $this->form('30'));

        $message = session('errors')->first('rule');

        $this->assertStringContainsString('between 2% and 3%', $message);
        $this->assertStringContainsString('You entered 30%', $message);
        $this->assertStringContainsString('Markup is not margin', $message);
        $this->assertStringContainsString('1,030.00', $message, 'The consequence is worked through');
    }

    /**
     * A rule that predates the band is not re-priced by it.
     *
     * An edit that leaves the markup alone must keep working. Silently re-pricing
     * live bundles to fit a policy introduced afterwards would change what
     * customers are charged without anybody deciding to.
     */
    public function test_an_existing_rule_outside_the_band_can_still_be_edited_without_touching_its_markup(): void
    {
        $rule = PricingRule::create([
            'name' => 'Legacy data markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 1000,
            'is_active' => true,
            'priority' => 100,
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.pricing.update', $rule), [
                'name' => 'Legacy data markup (renamed)',
                'scope' => 'global',
                'markup_type' => 'percentage',
                'markup_percentage' => '10', // unchanged
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1000, (int) $rule->fresh()->markup_percentage_bps);
    }

    /**
     * A `fixed` markup is not a margin stated as a percentage, so the band says
     * nothing about it. ₦1,500.50 of handling fee is a legitimate configuration.
     */
    public function test_a_fixed_markup_is_not_governed_by_the_percentage_band(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), [
                'name' => 'Fixed handling fee',
                'scope' => 'global',
                'markup_type' => 'fixed',
                'markup_fixed' => '1500.50',
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(150050, (int) PricingRule::firstOrFail()->markup_fixed_minor);
    }

    /**
     * A `FACE_VALUE` rule carries no markup at all, so a network-scoped one — an
     * airtime rule — must not be caught by the band.
     */
    public function test_a_face_value_rule_is_not_governed_by_the_data_band(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.store'), [
                'name' => 'Airtime — MTN (face value)',
                'scope' => 'network',
                'network' => 'mtn',
                'markup_type' => 'face_value',
                'markup_percentage' => '0',
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) PricingRule::firstOrFail()->markup_percentage_bps);
    }

    /* =====================================================================
     | Which rule may price a data bundle at all
     | =================================================================== */

    /**
     * The defect this pins cost every bundle its margin, silently.
     *
     * `PricingDefaultsSeeder` creates one `FACE_VALUE` rule per network for
     * airtime, and a data quote carries a network — so the network-scoped lookup
     * matched the *airtime* rule on every bundle. With no face value to price, the
     * strategy falls back to the base cost, so every bundle was sold at exactly
     * what the provider charges. Nothing failed: the storefront rendered prices,
     * the purchase charged them, and the margin was zero on every data sale.
     *
     * A `FACE_VALUE` rule is now ineligible for a product that has no face value,
     * so the lookup continues to the rule that can actually price it.
     */
    public function test_an_airtime_face_value_rule_does_not_price_a_data_bundle(): void
    {
        // The per-network airtime rule the seeder creates.
        $airtime = PricingRule::create([
            'name' => 'Airtime — MTN (face value)',
            'scope' => PricingRule::SCOPE_NETWORK,
            'network' => 'mtn',
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'minimum_profit_minor' => 0,
            'is_active' => true,
            'priority' => 100,
        ]);

        // The global data markup that should price the bundle instead.
        $data = PricingRule::create([
            'name' => 'Data bundles — default markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 300,
            'minimum_profit_minor' => 1,
            'is_active' => true,
            'priority' => 100,
        ]);

        $quote = $this->engine()->quote(
            providerCost: 50000,
            quantity: 1,
            context: ['network' => 'mtn', 'capability' => 'data'],
        );

        $this->assertTrue($quote->isSellable());
        $this->assertSame($data->getKey(), $quote->pricingRuleId, 'The data rule prices it, not the airtime rule');
        $this->assertNotSame($airtime->getKey(), $quote->pricingRuleId);
        $this->assertSame(51500, $quote->customerPriceMinor, '₦500 + 3%, not ₦500 at zero margin');
        $this->assertSame(1500, $quote->grossProfitMinor, 'The margin is not silently zero');
    }

    /**
     * The same rule is still the right one for airtime, which does have a face
     * value — so the fix must not disable `FACE_VALUE` pricing.
     */
    public function test_the_airtime_face_value_rule_still_prices_airtime(): void
    {
        $airtime = PricingRule::create([
            'name' => 'Airtime — MTN (face value)',
            'scope' => PricingRule::SCOPE_NETWORK,
            'network' => 'mtn',
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'minimum_profit_minor' => 0,
            'is_active' => true,
            'priority' => 100,
        ]);

        PricingRule::create([
            'name' => 'Data bundles — default markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 300,
            'minimum_profit_minor' => 1,
            'is_active' => true,
            'priority' => 100,
        ]);

        // ₦200 of airtime, costing us ₦194: the customer pays ₦200.
        $quote = $this->engine()->quote(
            providerCost: 19400,
            quantity: 1,
            context: ['network' => 'mtn', 'capability' => 'airtime'],
            priceBasisMinor: 20000,
        );

        $this->assertSame($airtime->getKey(), $quote->pricingRuleId);
        $this->assertSame(20000, $quote->customerPriceMinor, 'Face value, not cost plus a markup');
        $this->assertSame(600, $quote->grossProfitMinor);
    }

    /**
     * A bundle with no rule that can price it is refused, never sold at cost.
     */
    public function test_a_bundle_with_only_an_airtime_rule_available_is_refused(): void
    {
        PricingRule::create([
            'name' => 'Airtime — MTN (face value)',
            'scope' => PricingRule::SCOPE_NETWORK,
            'network' => 'mtn',
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'minimum_profit_minor' => 0,
            'is_active' => true,
            'priority' => 100,
        ]);

        $quote = $this->engine()->quote(
            providerCost: 50000,
            quantity: 1,
            context: ['network' => 'mtn', 'capability' => 'data'],
        );

        $this->assertFalse(
            $quote->isSellable(),
            'Refusing is right: the only alternative is selling the bundle at cost'
        );
        $this->assertStringContainsString('No active pricing rule', (string) $quote->refusalReason);
    }

    /* =====================================================================
     | Airtime pricing is preserved
     | =================================================================== */

    /**
     * The control the brief asks for: airtime prices at face value, unchanged.
     *
     * The customer pays ₦200 for ₦200 of airtime. The margin is the provider's
     * discount, which is a *cost* input and never enters the price.
     */
    public function test_airtime_still_prices_at_face_value_under_the_data_band(): void
    {
        $rule = new PricingRule([
            'name' => 'Airtime — MTN (face value)',
            'scope' => PricingRule::SCOPE_NETWORK,
            'network' => 'mtn',
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'markup_fixed_minor' => 0,
            'customer_fee_enabled' => false,
            'minimum_profit_minor' => 0,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'is_active' => true,
        ]);

        // ₦200 of airtime costs us ₦194 under the configured 3% airtime discount.
        $quote = $this->engine()->quoteWithRule(19400, 1, $rule, null, 20000);

        $this->assertSame(20000, $quote->customerPriceMinor, 'The customer pays ₦200, not ₦194 and not ₦206');
        $this->assertSame(600, $quote->grossProfitMinor, 'The ₦6 spread is the margin');
        $this->assertSame(0, $quote->roundingAdjustmentMinor, 'A face value is never rounded');
    }

    /**
     * The airtime discount is a per-network commercial term for *airtime*. It
     * says nothing about what a data bundle costs.
     */
    public function test_an_airtime_term_does_not_change_a_data_bundle_cost(): void
    {
        $cost = app(\App\Services\ProviderCostResolver::class)->forDataBundle([
            'plan_code' => 'MTN-1GB-30D',
            'network_code' => '01',
            'clubkonnect_price' => 500.0,
        ]);

        $this->assertSame(50000, $cost->costMinor);
        $this->assertSame(ProviderCost::SOURCE_CATALOGUE, $cost->source);
        $this->assertSame(0, $cost->discountBps, 'No airtime discount is applied to a bundle');
    }

    /* =====================================================================
     | Minimum profit and loss prevention
     | =================================================================== */

    public function test_a_bundle_whose_cost_leaves_no_room_for_the_floor_is_refused(): void
    {
        $rule = $this->percentageRule(300, [
            // A floor the markup would normally be lifted to meet — capped by the
            // ceiling below, so it cannot be met and the sale is refused.
            'minimum_profit_minor' => 10000,
            'maximum_markup_minor' => 1000,
        ]);

        $quote = $this->engine()->quoteWithRule(100000, 1, $rule);

        $this->assertFalse($quote->isSellable(), 'Below the configured floor, with no way to meet it');
        $this->assertNotNull($quote->refusalReason);
        $this->assertSame(101000, $quote->customerPriceMinor, '₦1,000 + the capped ₦10 markup');
        $this->assertSame(1000, $quote->grossProfitMinor);
    }

    public function test_the_band_cannot_be_used_to_sell_below_cost(): void
    {
        /*
         * The band bounds the markup, not the price: a cost that has risen above a
         * stale selling-price bound still produces a loss, and a loss is refused
         * regardless of how the markup was configured.
         */
        $rule = $this->percentageRule(200, [
            'minimum_selling_price_minor' => 40000,
            'maximum_selling_price_minor' => 40000,
        ]);

        $quote = $this->engine()->quoteWithRule(50000, 1, $rule);

        $this->assertTrue($quote->isLoss());
        $this->assertFalse($quote->isSellable(), 'Never sold below its approved cost');
    }

    public function test_a_markup_that_is_inside_the_band_is_still_subject_to_the_minimum_margin(): void
    {
        $rule = $this->percentageRule(300, ['minimum_margin_bps' => 1000]);

        // 3% markup is a 2.91% margin, below a 10% floor.
        $quote = $this->engine()->quoteWithRule(100000, 1, $rule);

        $this->assertFalse($quote->isSellable(), 'A markup inside the band can still breach a margin floor');
        $this->assertSame(291, $quote->profitMarginBps);
    }

    public function test_a_profit_floor_lifts_the_markup_rather_than_blocking_the_sale(): void
    {
        $rule = $this->percentageRule(200, ['minimum_profit_minor' => 1000]);

        // ₦250 + 2% is ₦5, below a ₦10 floor, so the floor wins.
        $quote = $this->engine()->quoteWithRule(25000, 1, $rule);

        $this->assertTrue($quote->isSellable());
        $this->assertSame(1000, $quote->markupAmountMinor);
        $this->assertSame(26000, $quote->customerPriceMinor);
        $this->assertSame(1000, $quote->grossProfitMinor);
    }
}
