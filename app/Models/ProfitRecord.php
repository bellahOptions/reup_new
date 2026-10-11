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
        /*
         * Which service was sold — airtime, data, cable-tv, electricity, exam.
         *
         * Copied from the transaction rather than joined at report time: the
         * per-service breakdown is the question an operator asks first, and a
         * group-by that has to join `transactions` is both slower and easy to write
         * wrongly. Nullable, and left null when it cannot be attributed — a wrong
         * service label is worse than an absent one.
         */
        'service_type',
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
        /*
         * Whether the provider cost this profit was measured against was a
         * *confirmed* rate or a configured assumption.
         *
         * The distinction the codebase already makes everywhere else — on
         * `ProviderNetworkCost`, on the pricing snapshot, in the receipt — and the
         * one a profit total is worthless without: a margin computed from an
         * assumed cost is a forecast, and presenting it as earned is the failure
         * the brief warns about. Stored per row because it is a fact about the cost
         * basis of *that sale*, not about the network today.
         */
        'cost_is_estimated',
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
        'cost_is_estimated' => 'boolean',
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

    /**
     * Rows whose profit rests on a confirmed provider cost.
     *
     * The distinction that makes a total trustworthy. "₦48,210 profit this month"
     * is a very different statement when 90% of it was measured against a rate
     * somebody typed into the console rather than one the provider confirmed.
     */
    public function scopeVerifiedCost($query)
    {
        return $query->where('cost_is_estimated', false);
    }

    /** Rows whose profit rests on an assumed cost — a forecast, not a result. */
    public function scopeEstimatedCost($query)
    {
        return $query->where('cost_is_estimated', true);
    }

    /** The service this sale belongs to, in words a report can print. */
    public function serviceLabel(): string
    {
        return (string) ($this->service_type ?: 'Unattributed');
    }
}
