<?php

namespace Database\Seeders;

use App\Models\PricingRule;
use App\Models\User;
use App\Pricing\PricingRuleService;
use App\Services\NetworkResolver;
use Illuminate\Database\Seeder;

/**
 * The pricing rules the platform needs to sell airtime and data at all.
 *
 * ## Why this exists
 *
 * The pricing engine refuses to sell anything no active rule covers — correctly,
 * because the alternative is selling at cost — so a fresh deployment with no rules
 * cannot sell airtime or data. That is a footgun, not a safety feature, so the
 * defaults ship here and are applied by `db:seed`.
 *
 * ## What it creates
 *
 *   * **Airtime, per network, FACE_VALUE.** The customer pays the airtime they
 *     asked for: ₦200 costs ₦200, ₦1,000 costs ₦1,000. No customer fee is enabled,
 *     so `service_fee` is ₦0.00 and the total equals the amount. This is the whole
 *     point of the change — the previous 2% is gone, and nothing replaces it.
 *   * **Data, global, 3% markup.** Data is cost-plus in this business: the
 *     customer pays the bundle price plus a margin. A global rule is a starting
 *     point; scope a rule to one network when a network's margin needs to differ,
 *     and the more specific rule takes precedence automatically.
 *
 * ## Idempotent, and it never clobbers an operator's work
 *
 * `firstOrCreate` on the rule's identity (scope + subject), so re-running leaves an
 * edited rule alone. Rules are created through `PricingRuleService`, which writes
 * the version history and the audit entry, so even a seeded rule has a provenance
 * record — the same path an operator's rule takes.
 */
class PricingDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $actor = $this->actor();

        if (! $actor) {
            $this->command?->warn('No administrator exists, so pricing rules cannot be attributed. Run AdminSeeder first.');

            return;
        }

        $this->seedAirtimeFaceValueRules($actor);
        $this->seedDataMarkupRule($actor);
    }

    /**
     * One FACE_VALUE rule per network.
     *
     * Per network rather than one global rule because the brief requires rates and
     * rules to be configurable independently by network — and becausedoing it now
     * means the operator has somewhere obvious to change MTN's rule without
     * affecting Airtel's.
     */
    private function seedAirtimeFaceValueRules(User $actor): void
    {
        foreach (NetworkResolver::options() as $key => $label) {
            $exists = PricingRule::where('scope', PricingRule::SCOPE_NETWORK)
                ->where('network', $key)
                ->where('is_active', true)
                ->exists();

            if ($exists) {
                continue;
            }

            app(PricingRuleService::class)->create([
                'name' => "Airtime — {$label} (face value)",
                'scope' => PricingRule::SCOPE_NETWORK,
                'network' => $key,
                // The default airtime strategy: the customer pays face value.
                'markup_type' => PricingRule::MARKUP_FACE_VALUE,
                'markup_percentage_bps' => 0,
                'markup_fixed_minor' => 0,
                /*
                 * No customer-facing fee. The engine only adds one when
                 * `customer_fee_enabled` is true, so leaving it false is what makes
                 * a ₦200 airtime purchase cost exactly ₦200.
                 */
                'customer_fee_enabled' => false,
                /*
                 * No profitability floor on airtime by default. The margin comes
                 * from the provider discount, and a floor set too high would block
                 * small denominations (a ₦50 top-up earning ₦1.50) that are a
                 * legitimate part of the business. Set one deliberately — the engine
                 * then refuses rather than silently raising the price above face
                 * value.
                 */
                'minimum_profit_minor' => 0,
                'minimum_margin_bps' => 0,
                'allow_negative_margin' => false,
                'rounding_step_minor' => 0,
                'rounding_mode' => PricingRule::ROUNDING_NEAREST,
                // Refuse rather than sell below cost, and never guess.
                'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
                'priority' => 100,
                'is_active' => true,
            ], $actor, 'Seeded default airtime face-value pricing');

            $this->command?->info("  Airtime face-value rule created for {$label}.");
        }
    }

    /**
     * The default data-bundle markup.
     *
     * **3% on the verified provider cost**, the top of the approved 2–3% band and
     * therefore the starting point an operator should be moving *down* from where
     * the market allows it — not a figure to be raised. The band is enforced by
     * `PricingRuleService`, so a seeded rule cannot be edited outside it by
     * accident.
     *
     * ## Markup, not margin
     *
     * These are different numbers for the same sale, and confusing them is how a
     * margin target becomes a price cut. On a ₦1,000 bundle a 3% *markup* charges
     * ₦1,030 — a ₦30 gross profit, which is a 2.91% *margin* (profit ÷ price).
     * Pricing a 3% *margin* instead would charge ₦1,030.93, and would mean
     * something different on every bundle.
     *
     * ## What a provider's catalogue price is
     *
     * The cost this markup applies to is the provider's own price for that exact
     * bundle — the `PRODUCT_AMOUNT` ClubKonnect publishes, which the catalogue
     * carries as `clubkonnect_price` and `ProviderCostResolver` reads as the
     * effective cost. It is not a list price with a discount to be derived from it:
     * this endpoint publishes no discount rate, no commission and no separate net
     * price, so there is no second candidate for the true cost and nothing has to
     * guess which field to use.
     */
    private function seedDataMarkupRule(User $actor): void
    {
        $exists = PricingRule::where('scope', PricingRule::SCOPE_GLOBAL)
            ->where('name', config('pricing.data_default_rule_name', 'Data bundles — default markup'))
            ->exists();

        if ($exists) {
            return;
        }

        app(PricingRuleService::class)->create([
            'name' => (string) config('pricing.data_default_rule_name', 'Data bundles — default markup'),
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => (int) config('pricing.data_default_markup_bps', 300),
            'markup_fixed_minor' => 0,
            /*
             * A one-kobo floor, so a bundle whose cost rounds to nearly nothing is
             * refused rather than sold at a loss. `minimum_margin_bps` is left at
             * zero: a percentage floor on a cheap bundle is a blunt instrument, and
             * the per-product floor is the clearer control.
             */
            'minimum_profit_minor' => 1,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ], $actor, 'Seeded default data-bundle markup');

        $this->command?->info('  Default data markup rule created.');
    }

    /**
     * The administrator a seeded rule is attributed to.
     *
     * `PricingRuleService` requires an actor so no financial change is unattributed,
     * and a seeder is no exception — the rule it creates can move money, so it must
     * say who created it.
     */
    private function actor(): ?User
    {
        return User::where('is_admin', true)->orderBy('id')->first();
    }
}
