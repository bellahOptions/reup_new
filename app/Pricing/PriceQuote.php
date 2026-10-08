<?php

namespace App\Pricing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * The result of pricing one order: an exact, immutable record of every input and
 * every derived figure.
 *
 * This is what gets written to `pricing_snapshots`. It is deliberately a
 * value object rather than an array, because the pricing engine's contract with
 * the rest of the application is "here is exactly what was charged and why" and
 * a typo in an array key would be silent.
 *
 * ## Markup is not margin
 *
 * Both are computed and both are stored, and they are different numbers for the
 * same sale. Cost ₦1,000, price ₦1,250 means a **25% markup** and a **20% gross
 * margin**. Conflating them understates or overstates profitability depending on
 * which one is labelled, so this object never exposes a single ambiguous
 * "percentage".
 */
final class PriceQuote
{
    public function __construct(
        /** What the provider charges ReUp, in kobo. */
        public readonly int $providerCostMinor,
        /** Any provider-side fee, in kobo. */
        public readonly int $providerFeeMinor,
        /** providerCost + providerFee — the basis the markup applies to. */
        public readonly int $baseCostMinor,
        /** PricingRule::MARKUP_* */
        public readonly string $markupType,
        /** Basis points, e.g. 2000 for 20%. */
        public readonly int $markupPercentageBps,
        /** Fixed markup in kobo. */
        public readonly int $markupFixedMinor,
        /** The markup actually applied, in kobo. */
        public readonly int $markupAmountMinor,
        /** Customer-facing fee, in kobo. Already included in unitPrice? No — see below. */
        public readonly int $customerFeeMinor,
        /** Promotional discount, in kobo. */
        public readonly int $discountMinor,
        /** Price before rounding, in kobo. */
        public readonly int $calculatedPriceMinor,
        /** Price after rounding — what the customer is charged. */
        public readonly int $customerPriceMinor,
        /** customerPrice - calculatedPrice; may be negative. */
        public readonly int $roundingAdjustmentMinor,
        public readonly int $roundingStepMinor,
        /** customerPrice - baseCost - fee + discount. Always from the FINAL price. */
        public readonly int $grossProfitMinor,
        /** Gross margin in basis points: profit / customerPrice * 10000. */
        public readonly int $profitMarginBps,
        /** Quantity priced for. Provider cost is per-order; this is informational. */
        public readonly int $quantity,
        public readonly string $currency,
        /** The rule that produced this, or null when nothing priced it. */
        public readonly ?int $pricingRuleId,
        public readonly ?string $pricingRuleName,
        public readonly ?int $pricingRuleVersion,
        /** 'unavailable' | 'warning' | 'ok' — whether the rule's floor was met. */
        public readonly string $profitability,
        /** Populated when the rule refused the sale. */
        public readonly ?string $refusalReason,
    ) {
    }

    /**
     * The unit price is the customer price divided by quantity, exactly.
     *
     * Only meaningful for volumetric products (SMM) where the customer sees a
     * rate per unit; for everything else quantity is 1 and this equals the price.
     * Integer division is used deliberately: a fractional kobo cannot be charged,
     * so the per-unit figure is a display figure and `customerPriceMinor` remains
     * the authority for what is debited.
     */
    public function unitPriceMinor(): int
    {
        return $this->quantity > 0 ? intdiv($this->customerPriceMinor, $this->quantity) : $this->customerPriceMinor;
    }

    /** The price as an exact value object, for the wallet debit. */
    public function price(): Money
    {
        return Money::fromMinor($this->customerPriceMinor);
    }

    public function providerCost(): Money
    {
        return Money::fromMinor($this->providerCostMinor);
    }

    public function grossProfit(): Money
    {
        return Money::fromMinor($this->grossProfitMinor);
    }

    /**
     * Whether the sale may proceed.
     *
     * A quote is sellable unless the pricing engine decided it was not. The
     * engine returns a non-sellable quote rather than throwing, so that callers
     * which only want to *display* a price (the storefront, the preview screen)
     * can do so without exception handling.
     */
    public function isSellable(): bool
    {
        return $this->refusalReason === null;
    }

    public function meetsMinimumProfit(): bool
    {
        return $this->grossProfitMinor >= 0;
    }

    public function isLoss(): bool
    {
        return $this->grossProfitMinor < 0;
    }

    /** Markup as a human percentage string, e.g. "25.00%". */
    public function markupPercentageLabel(): string
    {
        if ($this->baseCostMinor <= 0) {
            return '—';
        }

        return number_format($this->markupAmountMinor / $this->baseCostMinor * 100, 2) . '%';
    }

    /**
     * Gross margin as a human percentage string, e.g. "20.00%".
     *
     * Derived from the stored basis points, which were derived from the final
     * price — never recomputed from the markup.
     */
    public function grossMarginLabel(): string
    {
        return number_format($this->profitMarginBps / 100, 2) . '%';
    }

    /**
     * The complete record for `pricing_snapshots`.
     *
     * @return array<string, mixed>
     */
    public function toSnapshotAttributes(): array
    {
        return [
            'pricing_rule_id' => $this->pricingRuleId,
            'provider_cost_minor' => $this->providerCostMinor,
            'provider_fee_minor' => $this->providerFeeMinor,
            'base_cost_minor' => $this->baseCostMinor,
            'markup_type' => $this->markupType,
            'markup_percentage_bps' => $this->markupPercentageBps,
            'markup_fixed_minor' => $this->markupFixedMinor,
            'markup_amount_minor' => $this->markupAmountMinor,
            'customer_fee_minor' => $this->customerFeeMinor,
            'discount_minor' => $this->discountMinor,
            'calculated_price_minor' => $this->calculatedPriceMinor,
            'customer_price_minor' => $this->customerPriceMinor,
            'rounding_adjustment_minor' => $this->roundingAdjustmentMinor,
            'rounding_step_minor' => $this->roundingStepMinor,
            'gross_profit_minor' => $this->grossProfitMinor,
            'profit_margin_bps' => $this->profitMarginBps,
            'currency' => $this->currency,
        ];
    }

    /**
     * Customer-facing breakdown. Exposes price and fees, never cost or profit.
     *
     * @return array<string, mixed>
     */
    public function toCustomerBreakdown(): array
    {
        return [
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPriceMinor(),
            'price' => $this->customerPriceMinor,
            'fee' => $this->customerFeeMinor,
            'discount' => $this->discountMinor,
            'total' => $this->customerPriceMinor,
            'currency' => $this->currency,
            'formatted' => [
                'unit_price' => Money::fromMinor($this->unitPriceMinor())->format(),
                'price' => $this->price()->format(),
                'fee' => Money::fromMinor($this->customerFeeMinor)->format(),
                'discount' => Money::fromMinor($this->discountMinor)->format(),
                'total' => $this->price()->format(),
            ],
        ];
    }

    /**
     * Super-Admin breakdown, including cost and profit.
     *
     * @return array<string, mixed>
     */
    public function toAdminBreakdown(): array
    {
        return $this->toCustomerBreakdown() + [
            'provider_cost' => $this->providerCostMinor,
            'provider_fee' => $this->providerFeeMinor,
            'base_cost' => $this->baseCostMinor,
            'markup_amount' => $this->markupAmountMinor,
            'markup_type' => $this->markupType,
            'markup_percentage_bps' => $this->markupPercentageBps,
            'gross_profit' => $this->grossProfitMinor,
            'profit_margin_bps' => $this->profitMarginBps,
            'profitability' => $this->profitability,
            'pricing_rule_id' => $this->pricingRuleId,
        ];
    }

    /**
     * A quote that refuses the sale, keeping every figure for the audit trail.
     *
     * Used when the rule's floor cannot be met, the price falls outside its
     * configured bounds, or the product has no usable provider cost at all.
     */
    public function refuse(string $reason, string $profitability = 'unavailable'): self
    {
        if ($reason === '') {
            throw new InvalidArgumentException('A refusal must state a reason.');
        }

        return new self(
            providerCostMinor: $this->providerCostMinor,
            providerFeeMinor: $this->providerFeeMinor,
            baseCostMinor: $this->baseCostMinor,
            markupType: $this->markupType,
            markupPercentageBps: $this->markupPercentageBps,
            markupFixedMinor: $this->markupFixedMinor,
            markupAmountMinor: $this->markupAmountMinor,
            customerFeeMinor: $this->customerFeeMinor,
            discountMinor: $this->discountMinor,
            calculatedPriceMinor: $this->calculatedPriceMinor,
            customerPriceMinor: $this->customerPriceMinor,
            roundingAdjustmentMinor: $this->roundingAdjustmentMinor,
            roundingStepMinor: $this->roundingStepMinor,
            grossProfitMinor: $this->grossProfitMinor,
            profitMarginBps: $this->profitMarginBps,
            quantity: $this->quantity,
            currency: $this->currency,
            pricingRuleId: $this->pricingRuleId,
            pricingRuleName: $this->pricingRuleName,
            pricingRuleVersion: $this->pricingRuleVersion,
            profitability: $profitability,
            refusalReason: $reason,
        );
    }
}
