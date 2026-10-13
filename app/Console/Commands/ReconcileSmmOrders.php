<?php

namespace App\Console\Commands;

use App\Models\ServiceOrder;
use App\Smm\SmmOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolve Social Boost orders whose provider outcome is not yet known.
 *
 * ## What Nitro makes unavoidable
 *
 * `add` returns an order id and nothing else. Nitro's own statuses are the only
 * place an SMM order's outcome exists, and they span `Pending`, `In progress`,
 * `Completed`, `Partial`, `Canceled` and `Refunded`. Nothing settles at
 * submission — the adapter maps a fresh order to PENDING by design and
 * `ServiceOrderService` therefore never marks an order complete merely because
 * the provider accepted it.
 *
 * So this sweep is not an optimisation; without it every social-boost order
 * would sit in PENDING forever, holding the customer's money, and `Refunded`
 * orders would never be refunded to the customer's wallet.
 *
 * ## What it will not do
 *
 *   * **It does not re-submit.** `SmmOrders::reconcileAndSettle()` asks the
 *     provider what happened; it never creates a second order. A timeout is not
 *     evidence that the first request failed, and re-sending it is how a customer
 *     is charged twice for one purchase.
 *   * **It does not refund on an unknown outcome.** Only a terminal status the
 *     provider itself reported leads to a refund, and the refund is idempotent by
 *     the transaction's own `payment_status`.
 *   * **It does not mark anything complete without the provider saying so.**
 *
 * ## Why the batch is bounded
 *
 * Nitro documents 300 requests per minute and this sweep makes one request per
 * order, so the batch is the only thing standing between a backlog and the
 * allowance that live purchases need. The batch is configurable and small, and
 * `withoutOverlapping` plus a cache lock keep two ticks off the same orders.
 */
class ReconcileSmmOrders extends Command
{
    protected $signature = 'smm:reconcile-orders
                            {--limit= : How many orders to poll in this pass}
                            {--grace=1 : Skip orders submitted fewer than this many minutes ago}
                            {--order= : Reconcile one order by ReUp reference}
                            {--dry-run : Report what would be polled without calling the provider}
                            {--json : Emit machine-readable output}';

    protected $description = 'Resolve Social Boost orders whose provider outcome is not yet known';

    public function handle(SmmOrders $orders): int
    {
        if ($reference = $this->option('order')) {
            return $this->reconcileOne($orders, (string) $reference);
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('pricing.smm.reconcile_batch', 50)));
        $grace = max(0, (int) $this->option('grace'));

        /*
         * Scoped to social-boost orders by their *transaction's* service type.
         *
         * Nothing on `service_orders` distinguishes one capability from another —
         * the table is capability-agnostic by design — so the join is the only
         * correct way to ask. Without it this sweep would poll every unresolved
         * order in the application, including orders belonging to other providers'
         * adapters that `SmmOrders` cannot address, and would spend Nitro's rate
         * limit on them.
         */
        $candidates = ServiceOrder::query()
            ->whereHas('transaction', fn ($q) => $q->where('service_type', SmmOrders::SERVICE_TYPE))
            ->unresolved()
            ->where('created_at', '<=', now()->subMinutes($grace))
            ->where(function ($q) {
                $q->whereNull('next_reconcile_at')->orWhere('next_reconcile_at', '<=', now());
            })
            ->orderBy('next_reconcile_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No Social Boost orders are awaiting reconciliation.');

            if ($this->option('json')) {
                $this->line(json_encode(['polled' => 0, 'resolved' => 0, 'refunded' => 0, 'still_open' => 0]));
            }

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Reference', 'Status', 'Provider reference', 'Next attempt'],
                $candidates->map(fn (ServiceOrder $o) => [
                    $o->uuid,
                    $o->status,
                    $o->provider_reference ?? '—',
                    $o->next_reconcile_at?->toDateTimeString() ?? '—',
                ])->all(),
            );

            if ($this->option('json')) {
                $this->line(json_encode([
                    'dry_run' => true,
                    'would_poll' => $candidates->count(),
                ], JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        }

        $lock = Cache::lock('smm:reconcile', 300);

        if (! $lock->get()) {
            $this->warn('A Social Boost reconciliation is already running.');

            return self::SUCCESS;
        }

        $polled = 0;
        $resolved = 0;
        $refunded = 0;
        $stillOpen = 0;

        try {
            foreach ($candidates as $order) {
                $before = $order->status;

                try {
                    $orders->reconcileAndSettle($order);
                } catch (\Throwable $e) {
                    /*
                     * One order failing must not stop the sweep. A provider that
                     * returns something unreadable for one id would otherwise
                     * block every other customer's order behind it, which is
                     * exactly backwards during an incident.
                     */
                    Log::warning('Social Boost reconciliation threw for one order', [
                        'order_id' => $order->getKey(),
                        'reference' => $order->uuid,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $polled++;
                $after = $order->fresh();

                if ($after->outcomeIsKnown()) {
                    $resolved++;
                } else {
                    $stillOpen++;
                }

                if ($after->status === ServiceOrder::STATUS_REFUNDED && $before !== ServiceOrder::STATUS_REFUNDED) {
                    $refunded++;
                }
            }
        } finally {
            $lock->release();
        }

        $summary = [
            'polled' => $polled,
            'resolved' => $resolved,
            'refunded' => $refunded,
            'still_open' => $stillOpen,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Polled', 'Resolved', 'Refunded', 'Still open'],
            [[$polled, $resolved, $refunded, $stillOpen]],
        );

        return self::SUCCESS;
    }

    /**
     * Reconcile a single order, by ReUp reference.
     *
     * Exists for the admin console's "check this order now" action, so an operator
     * does not have to wait for a sweep to answer a customer.
     */
    private function reconcileOne(SmmOrders $orders, string $reference): int
    {
        $order = ServiceOrder::where('uuid', $reference)->first();

        if (! $order) {
            $this->error("No order has the reference [{$reference}].");

            return self::FAILURE;
        }

        if ($order->isFinal() && $order->status !== ServiceOrder::STATUS_REFUNDED) {
            $this->info("Order [{$reference}] is already resolved as {$order->status}.");

            return self::SUCCESS;
        }

        try {
            $result = $orders->reconcileAndSettle($order);
        } catch (\Throwable $e) {
            $this->error('Reconciliation failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $order->refresh();

        $this->table(
            ['Reference', 'ReUp status', 'Provider status', 'Provider reference'],
            [[
                $order->uuid,
                $order->status,
                $order->provider_status ?? '—',
                $order->provider_reference ?? '—',
            ]],
        );

        if ($result->status === \App\Providers\Support\ProviderStatus::UNKNOWN) {
            $this->warn('The provider did not give a usable answer. The order stays held and will be retried.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
