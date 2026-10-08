<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Realised profit for one finalized order.
 *
 * Distinct from `PricingSnapshot` on purpose. The snapshot records what was
 * *quoted*; this records what was *earned*, and the two diverge whenever an order
 * is refunded, cancelled or partially delivered. The profit dashboard reads this
 * table and only counts rows where `is_realized` is true, so unearned profit is
 * never reported as revenue.
 *
 * Profit is never a wallet adjustment. It is an accounting figure derived from
 * `revenue - provider_cost - fees - refunds` for a finalized order state; the
 * wallet only ever sees the customer's debit and any refund.
 */
class ProfitRecord extends Model
{
    protected $fillable = [
        'uuid',
        'service_order_id',
        'user_id',
        'transaction_id',
        'provider_id',
        'service_product_id',
        'product_key',
        'category_slug',
        'provider_slug',
        'revenue_minor',
        'provider_cost_minor',
        'provider_fee_minor',
        'customer_fee_minor',
        'discount_minor',
        'refunded_minor',
        'gross_profit_minor',
        'gross_margin_bps',
        'currency',
        'is_realized',
        'realized_at',
    ];

    protected $casts = [
        'revenue_minor' => 'integer',
        'provider_cost_minor' => 'integer',
        'provider_fee_minor' => 'integer',
        'customer_fee_minor' => 'integer',
        'discount_minor' => 'integer',
        'refunded_minor' => 'integer',
        'gross_profit_minor' => 'integer',
        'gross_margin_bps' => 'integer',
        'is_realized' => 'boolean',
        'realized_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            if (empty($record->uuid)) {
                $record->uuid = (string) Str::uuid();
            }
        });
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transactions::class, 'transaction_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function serviceProduct()
    {
        return $this->belongsTo(ServiceProduct::class);
    }

    public function revenue(): Money
    {
        return Money::fromMinor((int) $this->revenue_minor);
    }

    public function providerCost(): Money
    {
        return Money::fromMinor((int) $this->provider_cost_minor);
    }

    public function grossProfit(): Money
    {
        return Money::fromMinor((int) $this->gross_profit_minor);
    }

    public function grossMarginLabel(): string
    {
        return number_format($this->gross_margin_bps / 100, 2) . '%';
    }

    /**
     * Recompute the derived figures from the current column values.
     *
     * Kept on the model so the invariant (profit and margin always match the
     * columns beside them) cannot be broken by a caller that updates revenue
     * without updating profit.
     */
    public function recalculate(): void
    {
        $this->gross_profit_minor = (int) $this->revenue_minor
            + (int) $this->customer_fee_minor
            - (int) $this->provider_cost_minor
            - (int) $this->provider_fee_minor
            - (int) $this->refunded_minor;

        $this->gross_margin_bps = $this->revenue_minor + $this->customer_fee_minor > 0
            ? (int) round(
                $this->gross_profit_minor * 10000 / ($this->revenue_minor + $this->customer_fee_minor)
            )
            : 0;
    }

    public function scopeRealized($query)
    {
        return $query->where('is_realized', true);
    }
}
