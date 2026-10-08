<?php

namespace App\Orders;

use App\Models\ProfitRecord;
use App\Models\ServiceOrder;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a finalized order into an accounting record.
 *
 * ## Realized versus quoted
 *
 * `pricing_snapshots` records what was **quoted**. This records what was
 * **earned**, and only for a finalized, fulfilled order — which is why the profit
 * dashboard reads `profit_records` and not the snapshots. A PENDING or UNKNOWN
 * order has a row here with a zero revenue figure and `is_realized = false`, so
 * it exists for the funnel but contributes nothing to reported profit.
 *
 * ## Profit is not a wallet adjustment
 *
 * Nothing here touches a wallet. The customer's debit and any refund are the only
 * money movements, and they belong to `WalletService` and `wallet_ledger`. Profit
 * is a derived accounting figure: revenue minus provider cost minus fees minus
 * refunds. Putting it in a wallet would double-count it and destroy the ledger's
 * meaning.
 *
 * ## Why the figures come from the snapshot
 *
 * The snapshot is immutable and was written in the same transaction as the debit,
 * so it is the authoritative record of what the customer agreed to pay and what
 * the provider cost was assumed to be. Reading prices from `transactions` instead
 * would lose the cost basis entirely, and reading from the live `pricing_rules`
 * table would silently restate history every time a markup changed.
 */
class ProfitRecorder
{
    /**
     * Record profit for a successful order.
     *
     * Idempotent: `profit_records.service_order_id` is unique, so a webhook plus a
     * reconciliation poll cannot produce two revenue rows for one sale.
     */
    public function record(ServiceOrder $order, ?ProviderResult $result = null): ?ProfitRecord
    {
        $snapshot = $order->pricingSnapshot;

        if (! $snapshot) {
            Log::warning('Cannot record profit: the order has no pricing snapshot', [
                'order_id' => $order->getKey(),
            ]);

            return null;
        }

        $existing = ProfitRecord::where('service_order_id', $order->getKey())->first();

        /*
         * The provider may report a charge that differs from the one we priced
         * on. Where it does, the provider's figure is the real cost and using it
         * keeps profit honest; where it does not report one, the snapshot's
         * figure stands.
         */
        $providerCostMinor = $result?->providerCostMinor ?? (int) $snapshot->provider_cost_minor;

        $attributes = [
            'user_id' => $order->user_id,
            'transaction_id' => $order->transaction_id,
            'provider_id' => $order->provider_id,
            'service_product_id' => $order->service_product_id,
            'product_key' => $order->product_key,
            'category_slug' => $order->serviceProduct?->category?->slug,
            'provider_slug' => $order->provider?->slug,
            'revenue_minor' => (int) $snapshot->customer_price_minor,
            'provider_cost_minor' => $providerCostMinor,
            'provider_fee_minor' => (int) $snapshot->provider_fee_minor,
            'customer_fee_minor' => (int) $snapshot->customer_fee_minor,
            'discount_minor' => (int) $snapshot->discount_minor,
            'currency' => $snapshot->currency,
            'is_realized' => true,
            'realized_at' => now(),
        ];

        if ($existing) {
            /*
             * A re-delivery or a late provider confirmation. The revenue does not
             * change — the customer paid what they paid — but the cost may now be
             * known where it was not.
             */
            $existing->fill($attributes);
            $existing->refunded_minor = $existing->refunded_minor;
            $existing->recalculate();
            $existing->save();

            return $existing;
        }

        return DB::transaction(function () use ($order, $attributes) {
            $record = new ProfitRecord($attributes + [
                'service_order_id' => $order->getKey(),
                'refunded_minor' => 0,
            ]);

            $record->recalculate();
            $record->save();

            return $record;
        });
    }

    /**
     * Record that an order was refunded, reducing realized revenue.
     *
     * The order's own `revenue_minor` is left intact and the refund is recorded
     * separately, so gross sales and net sales both remain answerable. Zeroing
     * revenue would erase the fact that a sale happened and was reversed, which
     * is precisely the information a refund-rate alert needs.
     */
    public function markRefunded(ServiceOrder $order): void
    {
        $record = ProfitRecord::where('service_order_id', $order->getKey())->first();

        if (! $record) {
            return;
        }

        $record->refunded_minor = $record->revenue_minor + $record->customer_fee_minor;
        $record->recalculate();

        /*
         * A refunded order is not realized profit. Flipping `is_realized` off
         * removes it from every dashboard total in one place, rather than relying
         * on each query to subtract refunds correctly.
         */
        $record->is_realized = false;
        $record->save();
    }

    /**
     * Recompute every derived figure for one order, from the snapshot.
     *
     * Used by the reconciliation job after a late status change, so a dashboard
     * cannot report a figure derived from a stale row.
     */
    public function refresh(ServiceOrder $order): void
    {
        if ($order->status === ServiceOrder::STATUS_SUCCESS) {
            $this->record($order);

            return;
        }

        if (in_array($order->status, [ServiceOrder::STATUS_REFUNDED], true)) {
            $this->markRefunded($order);
        }
    }

    /* =====================================================================
     | Analytics
     | =================================================================== */

    /**
     * Revenue and profit for a period, optionally filtered.
     *
     * Only realized rows count, and refunds are subtracted through
     * `gross_profit_minor`, which `recalculate()` derives from them. So the
     * figures on the dashboard are the ones the accounting rules produce, not a
     * second interpretation of them written in SQL.
     *
     * @param  array<string, mixed>  $filters  product_key, provider_slug, category_slug, from, to
     * @return array<string, mixed>
     */
    public function summary(\DateTimeInterface $from, \DateTimeInterface $to, array $filters = []): array
    {
        $query = ProfitRecord::query()
            ->realized()
            ->whereBetween('realized_at', [$from, $to]);

        if (! empty($filters['product_key'])) {
            $query->where('product_key', $filters['product_key']);
        }

        if (! empty($filters['provider_slug'])) {
            $query->where('provider_slug', $filters['provider_slug']);
        }

        if (! empty($filters['category_slug'])) {
            $query->where('category_slug', $filters['category_slug']);
        }

        $aggregate = $query->selectRaw('COUNT(*) AS transactions')
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(customer_fee_minor), 0) AS fees')
            ->selectRaw('COALESCE(SUM(provider_cost_minor), 0) AS provider_cost')
            ->selectRaw('COALESCE(SUM(provider_fee_minor), 0) AS provider_fees')
            ->selectRaw('COALESCE(SUM(discount_minor), 0) AS discounts')
            ->selectRaw('COALESCE(SUM(refunded_minor), 0) AS refunds')
            ->selectRaw('COALESCE(SUM(gross_profit_minor), 0) AS gross_profit')
            ->first();

        /*
         * Refunded orders are excluded from realized rows by `markRefunded()`, so
         * the refund total above is normally zero for this filter. It is still
         * selected and reported, because the moment a path exists that records a
         * refund without un-realizing the row, this figure is what makes it
         * visible instead of silently inflating profit.
         */
        $revenue = (int) ($aggregate->revenue ?? 0);
        $fees = (int) ($aggregate->fees ?? 0);
        $grossProfit = (int) ($aggregate->gross_profit ?? 0);

        return [
            'transactions' => (int) ($aggregate->transactions ?? 0),
            'revenue_minor' => $revenue,
            'fees_minor' => $fees,
            'provider_cost_minor' => (int) ($aggregate->provider_cost ?? 0),
            'provider_fees_minor' => (int) ($aggregate->provider_fees ?? 0),
            'discounts_minor' => (int) ($aggregate->discounts ?? 0),
            'refunds_minor' => (int) ($aggregate->refunds ?? 0),
            'gross_profit_minor' => $grossProfit,
            'gross_margin_bps' => $revenue + $fees > 0
                ? (int) round($grossProfit * 10000 / ($revenue + $fees))
                : 0,
            'currency' => Money::CURRENCY,
            'formatted' => [
                'revenue' => Money::fromMinor($revenue)->format(),
                'fees' => Money::fromMinor($fees)->format(),
                'provider_cost' => Money::fromMinor((int) ($aggregate->provider_cost ?? 0))->format(),
                'refunds' => Money::fromMinor((int) ($aggregate->refunds ?? 0))->format(),
                'gross_profit' => Money::fromMinor($grossProfit)->format(),
                'gross_margin' => number_format(($revenue + $fees > 0 ? $grossProfit * 10000 / ($revenue + $fees) : 0) / 100, 2) . '%',
            ],
        ];
    }

    /**
     * Realized profit grouped by a column, for the dashboard breakdowns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function breakdown(string $column, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 20): array
    {
        if (! in_array($column, ['product_key', 'provider_slug', 'category_slug'], true)) {
            throw new \InvalidArgumentException("Cannot break profit down by [{$column}].");
        }

        return ProfitRecord::query()
            ->realized()
            ->whereBetween('realized_at', [$from, $to])
            ->select($column)
            ->selectRaw('COUNT(*) AS transactions')
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(provider_cost_minor), 0) AS provider_cost')
            ->selectRaw('COALESCE(SUM(gross_profit_minor), 0) AS gross_profit')
            ->groupBy($column)
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(function ($row) use ($column) {
                $revenue = (int) $row->revenue;
                $profit = (int) $row->gross_profit;

                return [
                    'key' => (string) ($row->{$column} ?? '—'),
                    'transactions' => (int) $row->transactions,
                    'revenue_minor' => $revenue,
                    'provider_cost_minor' => (int) $row->provider_cost,
                    'gross_profit_minor' => $profit,
                    'gross_margin_bps' => $revenue > 0 ? (int) round($profit * 10000 / $revenue) : 0,
                    'formatted_revenue' => Money::fromMinor($revenue)->format(),
                    'formatted_provider_cost' => Money::fromMinor((int) $row->provider_cost)->format(),
                    'formatted_gross_profit' => Money::fromMinor($profit)->format(),
                    'formatted_gross_margin' => number_format(($revenue > 0 ? $profit * 10000 / $revenue : 0) / 100, 2) . '%',
                ];
            })
            ->all();
    }

    /**
     * Daily realized profit, for the dashboard chart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function daily(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return ProfitRecord::query()
            ->realized()
            ->whereBetween('realized_at', [$from, $to])
            ->selectRaw('DATE(realized_at) AS day')
            ->selectRaw('COUNT(*) AS transactions')
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(provider_cost_minor), 0) AS provider_cost')
            ->selectRaw('COALESCE(SUM(gross_profit_minor), 0) AS gross_profit')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'transactions' => (int) $row->transactions,
                'revenue_minor' => (int) $row->revenue,
                'provider_cost_minor' => (int) $row->provider_cost,
                'gross_profit_minor' => (int) $row->gross_profit,
            ])
            ->all();
    }

    /**
     * The refund rate over a period.
     *
     * Used by the high-refund-rate alert. Counted from `service_orders` rather
     * than `profit_records` because a refunded order is deliberately no longer
     * realized, and a rate computed from realized rows alone would always be
     * zero.
     *
     * @return array{total:int,refunded:int,rate_bps:int}
     */
    public function refundRate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $total = ServiceOrder::whereBetween('created_at', [$from, $to])->count();

        $refunded = ServiceOrder::whereBetween('created_at', [$from, $to])
            ->where(function ($q) {
                $q->where('status', ServiceOrder::STATUS_REFUNDED)
                    ->orWhereNotNull('refunded_at');
            })
            ->count();

        return [
            'total' => $total,
            'refunded' => $refunded,
            'rate_bps' => $total > 0 ? (int) round($refunded * 10000 / $total) : 0,
        ];
    }
}
