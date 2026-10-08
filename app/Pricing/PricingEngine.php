<?php

namespace App\Pricing;

use App\Models\PricingRule;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place a customer price is decided.
 *
 * ## Why it is centralised
 *
 * Before this class, prices came from wherever the last developer put them: a
 * multiplier in a controller, a `clubkonnect_price` column, a `bills.fees`
 * config value, and a `plan_price` data attribute the browser posted back. There
 * was no way to answer "what is our margin on data bundles", and no way to change
 * a margin without a deploy.
 *
 * Every sellable price now comes from `quote()`, which is pure calculation over
 * a rule and a cost. The frontend never supplies a price, and no controller does
 * arithmetic on money.
 *
 * ## The calculation
 *
 *     base_cost      = provider_cost + provider_fee
 *     markup         = f(base_cost, markup_type, markup_percentage, markup_fixed)
 *     marked_up      = base_cost + markup
 *     with_fee       = marked_up + customer_fee
 *     discounted     = with_fee - discount
 *     customer_price = round(discounted, rounding_step, rounding_mode)
 *     gross_profit   = customer_price - base_cost
 *     margin         = gross_profit / customer_price
 *
 * `gross_profit` is computed from the **final** price. Rounding to the nearest
 * ₦10 can add or remove up to ₦5 of margin, and a dashboard that reported the
 * pre-rounding figure would disagree with the bank by that amount on every
 * rounded order.
 *
 * ## Markup versus margin
 *
 * The markup multiplier applies to *cost*; the margin divides by *price*. On
 * ₦1,000 cost and ₦1,250 price the markup is 25% and the margin is 20%. Both are
 * returned, and `PriceQuote` never conflates them.
 *
 * ## No floating point
 *
 * Every figure is an integer in kobo, and every percentage is an integer in
 * basis points. The only division is `intdiv` with an explicit, documented
 * rounding rule, so a quote is reproducible to the kobo on any platform.
 */
class PricingEngine
{
    /** 100% in basis points. */
    public const BPS_DENOMINATOR = 10000;

    /**
     * Price one order.
     *
     * @param  Money|int|string  $providerCost  what the provider charges, in naira or kobo
     * @param  int  $quantity  units ordered (1 for everything except SMM)
     * @param  array<string,mixed>  $context  category/provider/product ids, for rule resolution
     */
    public function quote(
        $providerCost,
        int $quantity = 1,
        array $context = [],
        ?Carbon $at = null
    ): PriceQuote {
        $at = $at ?? now();

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1 when pricing.');
        }

        $costMinor = $this->toMinor($providerCost);

        $rule = $this->resolveRule($context, $at);

        if ($rule === null) {
            /*
             * No rule at all. This is a configuration gap, not a decision, and
             * selling without one would mean selling at cost — so the quote
             * refuses. The admin console surfaces products in this state so the
             * gap is visible rather than silently absorbed.
             */
            return $this->refusingQuote(
                $costMinor,
                $quantity,
                'No active pricing rule covers this product. Configure one before selling it.',
                'unavailable',
            );
        }

        return $this->quoteWithRule($costMinor, $quantity, $rule, $at);
    }

    /**
     * Price with an explicitly supplied rule.
     *
     * Used by the admin preview screen, which must show what a *proposed* rule
     * would produce before it has been saved, and by tests, which need a rule
     * without a database.
     */
    public function quoteWithRule(
        int $costMinor,
        int $quantity,
        PricingRule $rule,
        ?Carbon $at = null
    ): PriceQuote {
        $at = $at ?? now();

        $providerFeeMinor = 0;
        $baseCostMinor = $costMinor + $providerFeeMinor;

        /* ---- Markup ---------------------------------------------------- */

        $markupAmountMinor = $this->markupAmount($baseCostMinor, $rule);

        /*
         * The minimum-profit floor. "20% markup, but never less than ₦100" is a
         * single rule whose effective markup is whichever of the two produces
         * more — so a cheap product still earns its floor.
         */
        if ($rule->minimum_profit_minor > 0 && $markupAmountMinor < $rule->minimum_profit_minor) {
            $markupAmountMinor = (int) $rule->minimum_profit_minor;
        }

        /*
         * A ceiling on the markup, so a percentage rule on an expensive item
         * does not produce an unsellable price. Applied after the floor, because
         * the ceiling is the operator's hard limit.
         */
        if ($rule->maximum_markup_minor !== null && $markupAmountMinor > $rule->maximum_markup_minor) {
            $markupAmountMinor = (int) $rule->maximum_markup_minor;
        }

        /* ---- Customer fee ---------------------------------------------- */

        $customerFeeMinor = $this->customerFee($baseCostMinor + $markupAmountMinor, $rule);

        /* ---- Discount -------------------------------------------------- */

        $discountMinor = $this->discount($baseCostMinor + $markupAmountMinor + $customerFeeMinor, $rule, $at);

        /* ---- Price ----------------------------------------------------- */

        $calculatedPriceMinor = $baseCostMinor + $markupAmountMinor + $customerFeeMinor - $discountMinor;

        if ($rule->minimum_selling_price_minor !== null && $calculatedPriceMinor < $rule->minimum_selling_price_minor) {
            $calculatedPriceMinor = (int) $rule->minimum_selling_price_minor;
        }

        if ($rule->maximum_selling_price_minor !== null && $calculatedPriceMinor > $rule->maximum_selling_price_minor) {
            $calculatedPriceMinor = (int) $rule->maximum_selling_price_minor;
        }

        /* ---- Rounding, then profit from the rounded price --------------- */

        $roundedPriceMinor = $this->round($calculatedPriceMinor, $rule);

        /*
         * Gross profit is measured against the price the customer actually pays,
         * including the customer fee — because that fee is revenue that lands in
         * the same gateway settlement as the rest of the order. It has to be
         * counted here or the profit dashboard would under-report by the fee on
         * every order that carries one.
         *
         * `PriceQuote` reports `customerFeeMinor` separately as well, so the two
         * views an operator needs are both available: "what did we earn" (this
         * figure) and "how much of the price was our fee" (that column).
         *
         * The margin is computed from the same final figure. Where the customer
         * fee is a pure pass-through to the provider, `provider_fee_minor` is
         * populated too, and `ProfitRecorder` deducts it — which is why the
         * accounting record and this quote can differ, deliberately, and why the
         * quote is never used as the accounting record.
         */
        $grossProfitMinor = $roundedPriceMinor - $baseCostMinor;
        $marginBps = $this->marginBps($grossProfitMinor, $roundedPriceMinor);

        $quote = new PriceQuote(
            providerCostMinor: $costMinor,
            providerFeeMinor: $providerFeeMinor,
            baseCostMinor: $baseCostMinor,
            markupType: (string) $rule->markup_type,
            markupPercentageBps: (int) $rule->markup_percentage_bps,
            markupFixedMinor: (int) $rule->markup_fixed_minor,
            markupAmountMinor: $markupAmountMinor,
            customerFeeMinor: $customerFeeMinor,
            discountMinor: $discountMinor,
            calculatedPriceMinor: $calculatedPriceMinor,
            customerPriceMinor: $roundedPriceMinor,
            roundingAdjustmentMinor: $roundedPriceMinor - $calculatedPriceMinor,
            roundingStepMinor: (int) $rule->rounding_step_minor,
            grossProfitMinor: $grossProfitMinor,
            profitMarginBps: $marginBps,
            quantity: $quantity,
            currency: Money::CURRENCY,
            pricingRuleId: $rule->getKey(),
            pricingRuleName: $rule->name,
            pricingRuleVersion: $this->latestVersion($rule),
            profitability: 'ok',
            refusalReason: null,
        );

        return $this->applyProfitabilityPolicy($quote, $rule);
    }

    /**
     * Enforce the configured profitability policy.
     *
     * Order of checks matters: a negative margin is the most serious condition
     * and is reported as such even if a minimum-margin rule is also breached, so
     * the log line says "this loses money" rather than "margin is low".
     */
    private function applyProfitabilityPolicy(PriceQuote $quote, PricingRule $rule): PriceQuote
    {
        // A completely free product cannot be profitable; treat it as a refusal
        // rather than a zero-margin sale.
        if ($quote->customerPriceMinor <= 0) {
            return $quote->refuse('The calculated price is not a positive amount.');
        }

        if ($quote->isLoss() && ! $rule->allow_negative_margin) {
            return $quote->refuse(
                'This would be sold below cost. No loss-making rule is enabled for this product.',
                $this->policyOutcome($rule),
            );
        }

        $belowMinimumProfit = $rule->minimum_profit_minor > 0
            && $quote->grossProfitMinor < (int) $rule->minimum_profit_minor;

        $belowMinimumMargin = $rule->minimum_margin_bps > 0
            && $quote->profitMarginBps < (int) $rule->minimum_margin_bps;

        if (! $belowMinimumProfit && ! $belowMinimumMargin) {
            return $quote;
        }

        /*
         * The floor is breached. What happens next is the operator's choice, and
         * it is deliberately not "sell anyway": silently selling below the
         * configured floor is the exact failure this whole subsystem exists to
         * prevent.
         */
        return match ($rule->on_unprofitable) {
            /*
             * `warning` keeps the product on sale at the calculated price and
             * records that it is under target. `AVAILABLE_WITH_WARNING` on the
             * product row is the visible half of this.
             */
            PricingRule::ON_UNPROFITABLE_WARNING => new PriceQuote(
                providerCostMinor: $quote->providerCostMinor,
                providerFeeMinor: $quote->providerFeeMinor,
                baseCostMinor: $quote->baseCostMinor,
                markupType: $quote->markupType,
                markupPercentageBps: $quote->markupPercentageBps,
                markupFixedMinor: $quote->markupFixedMinor,
                markupAmountMinor: $quote->markupAmountMinor,
                customerFeeMinor: $quote->customerFeeMinor,
                discountMinor: $quote->discountMinor,
                calculatedPriceMinor: $quote->calculatedPriceMinor,
                customerPriceMinor: $quote->customerPriceMinor,
                roundingAdjustmentMinor: $quote->roundingAdjustmentMinor,
                roundingStepMinor: $quote->roundingStepMinor,
                grossProfitMinor: $quote->grossProfitMinor,
                profitMarginBps: $quote->profitMarginBps,
                quantity: $quote->quantity,
                currency: $quote->currency,
                pricingRuleId: $quote->pricingRuleId,
                pricingRuleName: $quote->pricingRuleName,
                pricingRuleVersion: $quote->pricingRuleVersion,
                profitability: 'warning',
                refusalReason: null,
            ),

            /*
             * `fallback` hands over to a broader rule. Guarded against a cycle:
             * a fallback that is itself a fallback to the original would recurse
             * forever.
             */
            PricingRule::ON_UNPROFITABLE_FALLBACK => $this->fallbackQuote($quote, $rule),

            PricingRule::ON_UNPROFITABLE_REQUIRE_APPROVAL => $quote->refuse(
                'This price needs Super Admin approval before it can be sold.',
                'require_approval',
            ),

            default => $quote->refuse(
                'This service is temporarily unavailable while we update its pricing.',
                'unavailable',
            ),
        };
    }

    private function fallbackQuote(PriceQuote $quote, PricingRule $rule): PriceQuote
    {
        $fallback = $rule->fallbackRule;

        if (! $fallback || ! $fallback->is_active || $fallback->getKey() === $rule->getKey()) {
            return $quote->refuse(
                'This service is temporarily unavailable while we update its pricing.',
                'unavailable',
            );
        }

        $fallbackQuote = $this->quoteWithRule($quote->providerCostMinor, $quote->quantity, $fallback);

        /*
         * A fallback that also refuses must not be retried — one level only. Two
         * hops would make the effective rule impossible to reason about, and the
         * audit trail would name a rule the operator never chose.
         */
        if (! $fallbackQuote->isSellable()) {
            return $fallbackQuote->refuse(
                'This service is temporarily unavailable while we update its pricing.',
                'unavailable',
            );
        }

        return $fallbackQuote;
    }

    private function policyOutcome(PricingRule $rule): string
    {
        return match ($rule->on_unprofitable) {
            PricingRule::ON_UNPROFITABLE_WARNING => 'warning',
            PricingRule::ON_UNPROFITABLE_REQUIRE_APPROVAL => 'require_approval',
            default => 'unavailable',
        };
    }

    /* =====================================================================
     | Rule resolution
     |=================================================================== */

    /**
     * Find the most specific active rule that covers a context.
     *
     * Walks narrowest-first so the first match wins: a rule pinned to one
     * provider product beats a product rule, which beats a provider rule, which
     * beats a category rule, which beats the global default.
     *
     * @param  array<string,mixed>  $context  provider_product_id, service_product_id,
     *                                        provider_id, category_id
     */
    public function resolveRule(array $context, ?Carbon $at = null, ?PricingRule $skip = null): ?PricingRule
    {
        $at = $at ?? now();

        $lookup = [
            PricingRule::SCOPE_PROVIDER_PRODUCT => ['provider_product_id', $context['provider_product_id'] ?? null],
            PricingRule::SCOPE_PRODUCT => ['service_product_id', $context['service_product_id'] ?? null],
            PricingRule::SCOPE_PROVIDER => ['provider_id', $context['provider_id'] ?? null],
            PricingRule::SCOPE_CATEGORY => ['category_id', $context['category_id'] ?? null],
            PricingRule::SCOPE_GLOBAL => [null, null],
        ];

        foreach ($lookup as $scope => [$column, $value]) {
            // A scope we cannot match on is skipped rather than falling through
            // to a broader rule, which would silently apply the wrong margin.
            if ($scope !== PricingRule::SCOPE_GLOBAL && ($column === null || $value === null)) {
                continue;
            }

            $query = PricingRule::query()
                ->where('scope', $scope)
                ->where('is_active', true)
                ->where(function ($q) use ($at) {
                    // A promotable rule is selectable whenever it is active; the
                    // promotional *window* governs the discount, not eligibility,
                    // so a rule with a lapsed window still supplies its markup.
                    $q->whereNull('promotion_ends_at')->orWhere('promotion_ends_at', '>=', $at);
                })
                ->orderBy('priority')
                ->orderByDesc('updated_at');

            if ($scope !== PricingRule::SCOPE_GLOBAL) {
                $query->where($column, $value);
            }

            if ($skip) {
                $query->where('id', '!=', $skip->getKey());
            }

            $rule = $query->first();

            if ($rule) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The rule that would apply, with its specificity, for the admin console.
     *
     * @param  array<string,mixed>  $context
     * @return array{rule:?PricingRule,source:string}
     */
    public function explainRule(array $context): array
    {
        $rule = $this->resolveRule($context);

        return [
            'rule' => $rule,
            'source' => $rule ? $rule->scope : 'none',
        ];
    }

    /* =====================================================================
     | Arithmetic primitives — every one integer, none floating point
     |=================================================================== */

    /**
     * The markup for a base cost, before the floor and ceiling are applied.
     */
    private function markupAmount(int $baseCostMinor, PricingRule $rule): int
    {
        return match ($rule->markup_type) {
            PricingRule::MARKUP_PERCENTAGE => $this->applyBps($baseCostMinor, (int) $rule->markup_percentage_bps),

            PricingRule::MARKUP_FIXED => (int) $rule->markup_fixed_minor,

            PricingRule::MARKUP_PERCENTAGE_PLUS_FIXED => $this->applyBps($baseCostMinor, (int) $rule->markup_percentage_bps)
                + (int) $rule->markup_fixed_minor,

            PricingRule::MARKUP_NONE => 0,

            default => 0,
        };
    }

    private function customerFee(int $amountMinor, PricingRule $rule): int
    {
        if (! $rule->customer_fee_enabled) {
            return 0;
        }

        return match ($rule->customer_fee_type) {
            'percentage' => $this->applyBps($amountMinor, (int) $rule->customer_fee_bps),
            default => (int) $rule->customer_fee_minor,
        };
    }

    private function discount(int $amountMinor, PricingRule $rule, Carbon $at): int
    {
        if (! $rule->promotionIsRunning($at)) {
            return 0;
        }

        $discount = $this->applyBps($amountMinor, (int) $rule->discount_bps) + (int) $rule->discount_fixed_minor;

        // A discount can never exceed the amount it discounts, or the customer
        // would be paid to buy.
        return min($discount, $amountMinor);
    }

    /**
     * Apply basis points to a minor-unit amount, rounding half-up to the kobo.
     *
     * `amount * bps / 10000` with integer arithmetic. The remainder decides the
     * rounding, and the sign is handled so a negative base (which cannot occur
     * here, but would come from a corrupt cost) still rounds away from zero
     * consistently.
     */
    public function applyBps(int $amountMinor, int $bps): int
    {
        if ($bps === 0) {
            return 0;
        }

        $product = $amountMinor * $bps;
        $quotient = intdiv($product, self::BPS_DENOMINATOR);
        $remainder = $product % self::BPS_DENOMINATOR;

        if (abs($remainder) * 2 >= self::BPS_DENOMINATOR) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return $quotient;
    }

    /**
     * Gross margin in basis points: profit / price * 10000.
     *
     * Returns 0 for a non-positive price rather than dividing by zero. A negative
     * price is refused earlier, so this is defence in depth.
     */
    public function marginBps(int $profitMinor, int $priceMinor): int
    {
        if ($priceMinor <= 0) {
            return 0;
        }

        $product = $profitMinor * self::BPS_DENOMINATOR;
        $quotient = intdiv($product, $priceMinor);
        $remainder = $product % $priceMinor;

        if (abs($remainder) * 2 >= abs($priceMinor)) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return $quotient;
    }

    /**
     * Round a price according to the rule.
     *
     * `nearest` uses half-up, the convention the rest of the application's fee
     * arithmetic already follows, so switching a rule's rounding on does not
     * move a previously round price.
     */
    public function round(int $priceMinor, PricingRule $rule): int
    {
        $step = (int) $rule->rounding_step_minor;

        if ($step <= 0) {
            return $priceMinor;
        }

        return match ($rule->rounding_mode) {
            PricingRule::ROUNDING_UP => intdiv($priceMinor + $step - 1, $step) * $step,
            PricingRule::ROUNDING_DOWN => intdiv($priceMinor, $step) * $step,
            default => intdiv($priceMinor + intdiv($step, 2), $step) * $step,
        };
    }

    private function toMinor($amount): int
    {
        if ($amount instanceof Money) {
            return $amount->minor();
        }

        return Money::fromNaira($amount)->minor();
    }

    /**
     * The rule's current version number, or null when it has no history.
     *
     * A rule that has never been persisted has no version, and querying for one
     * would both hit the database pointlessly and make the engine unusable for an
     * unsaved rule — which the admin preview screen depends on, because it prices
     * a proposed rule before it exists.
     */
    private function latestVersion(PricingRule $rule): ?int
    {
        if (! $rule->exists) {
            return null;
        }

        $version = DB::table('pricing_rule_versions')
            ->where('pricing_rule_id', $rule->getKey())
            ->max('version');

        return $version === null ? null : (int) $version;
    }

    private function refusingQuote(int $costMinor, int $quantity, string $reason, string $profitability): PriceQuote
    {
        return (new PriceQuote(
            providerCostMinor: $costMinor,
            providerFeeMinor: 0,
            baseCostMinor: $costMinor,
            markupType: PricingRule::MARKUP_NONE,
            markupPercentageBps: 0,
            markupFixedMinor: 0,
            markupAmountMinor: 0,
            customerFeeMinor: 0,
            discountMinor: 0,
            calculatedPriceMinor: 0,
            customerPriceMinor: 0,
            roundingAdjustmentMinor: 0,
            roundingStepMinor: 0,
            grossProfitMinor: -$costMinor,
            profitMarginBps: 0,
            quantity: $quantity,
            currency: Money::CURRENCY,
            pricingRuleId: null,
            pricingRuleName: null,
            pricingRuleVersion: null,
            profitability: $profitability,
            refusalReason: $reason,
        ));
    }
}
