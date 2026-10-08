<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The immutable price a customer agreed to.
 *
 * Written once, in the same database transaction as the wallet debit, and never
 * updated. If the Super Admin later changes a markup from 20% to 30%, this row
 * is what keeps yesterday's orders priced at 20% — which is the difference
 * between a pricing change and a retroactive restatement of revenue.
 *
 * `pricing_rule_snapshot` stores the whole rule as it was at the time, so the
 * snapshot stays interpretable even if the rule is later edited or deleted.
 */
class PricingSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'service_order_id',
        'pricing_rule_id',
        'pricing_rule_snapshot',
        'pricing_rule_version',
        'provider_cost_minor',
        'provider_fee_minor',
        'base_cost_minor',
        'provider_currency',
        'provider_amount_minor',
        'customer_currency',
        'exchange_rate_micros',
        'markup_type',
        'markup_percentage_bps',
        'markup_fixed_minor',
        'markup_amount_minor',
        'customer_fee_minor',
        'discount_minor',
        'calculated_price_minor',
        'customer_price_minor',
        'rounding_adjustment_minor',
        'rounding_step_minor',
        'gross_profit_minor',
        'profit_margin_bps',
        'currency',
        'inputs',
    ];

    protected $casts = [
        'pricing_rule_snapshot' => 'array',
        'inputs' => 'array',
        'pricing_rule_version' => 'integer',
        'provider_cost_minor' => 'integer',
        'provider_fee_minor' => 'integer',
        'base_cost_minor' => 'integer',
        'provider_amount_minor' => 'integer',
        'exchange_rate_micros' => 'integer',
        'markup_percentage_bps' => 'integer',
        'markup_fixed_minor' => 'integer',
        'markup_amount_minor' => 'integer',
        'customer_fee_minor' => 'integer',
        'discount_minor' => 'integer',
        'calculated_price_minor' => 'integer',
        'customer_price_minor' => 'integer',
        'rounding_adjustment_minor' => 'integer',
        'rounding_step_minor' => 'integer',
        'gross_profit_minor' => 'integer',
        'profit_margin_bps' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $snapshot) {
            if (empty($snapshot->uuid)) {
                $snapshot->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Refuse updates, the same way `WalletLedger` does.
     *
     * "Immutable by convention" is not immutable. A snapshot that can be
     * rewritten is not evidence of anything.
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new RuntimeException(
                'Pricing snapshots are immutable. Create a new snapshot rather than editing snapshot '
                . $this->getKey() . '.'
            );
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new RuntimeException(
            'Pricing snapshots are immutable and cannot be deleted (snapshot ' . ($this->getKey() ?? 'new') . ').'
        );
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function pricingRule()
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function customerPrice(): Money
    {
        return Money::fromMinor((int) $this->customer_price_minor);
    }

    public function providerCost(): Money
    {
        return Money::fromMinor((int) $this->provider_cost_minor);
    }

    public function grossProfit(): Money
    {
        return Money::fromMinor((int) $this->gross_profit_minor);
    }

    /**
     * Margin as a human percentage. Derived from the stored basis points, never
     * recomputed from the markup.
     */
    public function grossMarginLabel(): string
    {
        return number_format($this->profit_margin_bps / 100, 2) . '%';
    }

    /**
     * What the customer sees. Cost and profit are deliberately absent.
     *
     * @return array<string, mixed>
     */
    public function toCustomerArray(): array
    {
        return [
            'price' => $this->customer_price_minor,
            'fee' => $this->customer_fee_minor,
            'discount' => $this->discount_minor,
            'total' => $this->customer_price_minor,
            'currency' => $this->currency,
            'formatted' => [
                'price' => $this->customerPrice()->format(),
                'fee' => Money::fromMinor((int) $this->customer_fee_minor)->format(),
                'discount' => Money::fromMinor((int) $this->discount_minor)->format(),
                'total' => $this->customerPrice()->format(),
            ],
        ];
    }

    /**
     * What the Super Admin sees.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return $this->toCustomerArray() + [
            'provider_cost' => $this->provider_cost_minor,
            'provider_fee' => $this->provider_fee_minor,
            'base_cost' => $this->base_cost_minor,
            'markup_type' => $this->markup_type,
            'markup_amount' => $this->markup_amount_minor,
            'markup_percentage_bps' => $this->markup_percentage_bps,
            'calculated_price' => $this->calculated_price_minor,
            'rounding_adjustment' => $this->rounding_adjustment_minor,
            'gross_profit' => $this->gross_profit_minor,
            'profit_margin_bps' => $this->profit_margin_bps,
            'pricing_rule_id' => $this->pricing_rule_id,
        ];
    }
}
