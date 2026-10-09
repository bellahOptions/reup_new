<?php

namespace App\Models\Concerns;

use App\Models\PricingSnapshot;
use App\Models\PricingRule;
use App\Pricing\PriceQuote;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

/**
 * Gives a wallet transaction the same immutable pricing snapshot a `service_orders`
 * row has.
 *
 * ## Why a trait rather than a second pipeline
 *
 * Airtime and data are sold through `BillPaymentService`, which owns the wallet
 * debit, the row lock, the idempotency reservation and the provider failover. That
 * pipeline is the financial authority and the brief forbids replacing it. What it
 * did *not* do was record how the price was arrived at, so the margin on an airtime
 * sale was unknowable after the fact.
 *
 * This trait closes that gap using the existing `pricing_snapshots` table and the
 * existing `PriceQuote` value object, so there is one snapshot format, one place
 * cost and profit live, and one thing for the profit dashboard to read.
 *
 * ## Written inside the debit transaction
 *
 * `attachPricingSnapshot()` is called from within the same database transaction as
 * the wallet debit. A charge with no recorded price, or a recorded price with no
 * charge, are both worse than either alone — the first makes margin unanswerable,
 * the second makes the snapshot a lie.
 */
trait HasPricingSnapshot
{
    public function pricingSnapshot(): HasOne
    {
        return $this->hasOne(PricingSnapshot::class, 'transaction_id');
    }

    /**
     * Persist the quote this purchase was priced with.
     *
     * Idempotent by the unique index on `pricing_snapshots.transaction_id`: a retry
     * that reaches here twice cannot create a second snapshot for one charge.
     *
     * @param  array<string,mixed>  $extraInputs  context to record alongside the quote
     */
    public function attachPricingSnapshot(PriceQuote $quote, array $extraInputs = []): ?PricingSnapshot
    {
        $existing = $this->pricingSnapshot()->first();

        if ($existing) {
            return $existing;
        }

        try {
            return $this->pricingSnapshot()->create(
                $quote->toSnapshotAttributes() + [
                    'pricing_rule_snapshot' => $this->pricingRuleSnapshot($quote),
                    'pricing_rule_version' => $quote->pricingRuleVersion,
                    'inputs' => $extraInputs + [
                        'provider_cost_minor' => $quote->providerCostMinor,
                        'price_basis_minor' => $quote->priceBasisMinor,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            /*
             * A snapshot failure must not roll back a completed sale — the money has
             * already moved and the customer has their item. It is logged loudly
             * instead, because a missing snapshot means an unanswerable margin and
             * somebody has to reconcile it.
             */
            Log::error('Could not record the pricing snapshot for a transaction', [
                'transaction_id' => $this->getKey(),
                'reference' => $this->reference ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The pricing decision behind this transaction, for administrative interfaces.
     *
     * Returns null when there is no snapshot — a wallet top-up, a reversal, or a
     * purchase made before this pricing path existed. Null is the honest answer
     * there; reporting zero profit would present "unmeasured" as "measured and
     * zero", which is precisely the confusion the brief warns about.
     *
     * @return array<string,mixed>|null
     */
    public function pricingAnalysis(): ?array
    {
        $snapshot = $this->relationLoaded('pricingSnapshot')
            ? $this->pricingSnapshot
            : $this->pricingSnapshot()->first();

        if (! $snapshot) {
            return null;
        }

        return [
            'provider_cost_minor' => (int) $snapshot->provider_cost_minor,
            'provider_fee_minor' => (int) $snapshot->provider_fee_minor,
            'base_cost_minor' => (int) $snapshot->base_cost_minor,
            'provider_cost' => Money::fromMinor((int) $snapshot->provider_cost_minor)->format(),
            'customer_price_minor' => (int) $snapshot->customer_price_minor,
            'customer_price' => Money::fromMinor((int) $snapshot->customer_price_minor)->format(),
            'customer_fee_minor' => (int) $snapshot->customer_fee_minor,
            'discount_minor' => (int) $snapshot->discount_minor,
            'markup_type' => $snapshot->markup_type,
            'markup_amount_minor' => (int) $snapshot->markup_amount_minor,
            'gross_profit_minor' => (int) $snapshot->gross_profit_minor,
            'gross_profit' => Money::fromMinor((int) $snapshot->gross_profit_minor)->format(),
            'gross_margin_bps' => (int) $snapshot->profit_margin_bps,
            'gross_margin' => $snapshot->grossMarginLabel(),
            'pricing_rule_id' => $snapshot->pricing_rule_id,
            'pricing_rule_version' => $snapshot->pricing_rule_version,
            'price_basis_minor' => $snapshot->price_basis_minor === null ? null : (int) $snapshot->price_basis_minor,
            'cost_source' => $snapshot->cost_source,
            'cost_verified_at' => $snapshot->cost_verified_at?->toDateTimeString(),
            /*
             * The distinction the brief insists on. A configured assumption is not a
             * confirmed cost, so the profit measured against it is *estimated* and
             * must never be presented as realised.
             */
            'cost_is_estimated' => (bool) $snapshot->cost_is_estimated,
            'profit_is_realised' => ! (bool) $snapshot->cost_is_estimated
                && in_array($this->status, ['success'], true),
        ];
    }

    /**
     * The pricing rule as it stood when this sale was priced.
     *
     * Stored whole, so the snapshot stays interpretable after the rule is edited or
     * deleted — the same reasoning `ServiceOrderService` follows. Returns null for a
     * quote priced with no rule (a refusal), rather than an empty array that would
     * look like a rule with every field unset.
     *
     * @return array<string,mixed>|null
     */
    private function pricingRuleSnapshot(PriceQuote $quote): ?array
    {
        if ($quote->pricingRuleId === null) {
            return null;
        }

        $rule = PricingRule::find($quote->pricingRuleId);

        if (! $rule) {
            return null;
        }

        return [
            'id' => $rule->getKey(),
            'name' => $rule->name,
            'scope' => $rule->scope,
            'network' => $rule->network,
            'markup_type' => $rule->markup_type,
            'markup_percentage_bps' => (int) $rule->markup_percentage_bps,
            'markup_fixed_minor' => (int) $rule->markup_fixed_minor,
            'minimum_profit_minor' => (int) $rule->minimum_profit_minor,
            'minimum_margin_bps' => (int) $rule->minimum_margin_bps,
            'customer_fee_enabled' => (bool) $rule->customer_fee_enabled,
            'customer_fee_type' => $rule->customer_fee_type,
            'customer_fee_minor' => (int) $rule->customer_fee_minor,
            'customer_fee_bps' => (int) $rule->customer_fee_bps,
            'rounding_step_minor' => (int) $rule->rounding_step_minor,
            'rounding_mode' => $rule->rounding_mode,
        ];
    }
}
