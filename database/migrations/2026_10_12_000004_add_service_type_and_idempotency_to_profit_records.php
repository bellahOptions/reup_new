<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `profit_records` usable by the purchases the platform actually sells.
 *
 * ## The gap this closes
 *
 * `ProfitRecorder` was only ever called from `ServiceOrderService::finalize()`, and
 * no live route creates a `ServiceOrder` — airtime and data go through
 * `BillPaymentService`, which writes a `pricing_snapshots` row and stops there. So
 * the Revenue & Profit dashboard was reading a table nothing populated, and every
 * figure on it was zero while the platform was selling.
 *
 * Two things were missing for the live path to be recordable:
 *
 *   1. **a service dimension.** The dashboard could group by product, provider and
 *      category, but not by *service* — and "how much do we make on airtime versus
 *      data versus electricity" is the question an operator asks first. The service
 *      type lives on `transactions.service_type`, and a breakdown that has to join
 *      to `transactions` on every group-by is both slower and easy to get wrong, so
 *      it is copied onto the record.
 *
 *   2. **an idempotency key that fits the live path.** `service_order_id` is UNIQUE,
 *      which is what makes `ProfitRecorder::record()` safe to call twice. Live
 *      purchases have no order, so `transaction_id` is the natural key — and it was
 *      only a foreign key with a plain index, which means a webhook plus the
 *      auto-resolve sweep could both insert a profit row for one sale and the
 *      dashboard would report it twice. That is the defect this migration exists to
 *      prevent, so the unique index is added rather than assumed.
 *
 * ## Why the dedupe runs first
 *
 * Adding a unique index to a table that already violates it fails, and it would
 * fail on a deployment that has been running the old code. The duplicates are
 * removed by keeping the lowest id per transaction — the first recording of a sale
 * — before the index is created. On a database where the constraint already holds,
 * the delete matches nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('profit_records', 'service_type')) {
            Schema::table('profit_records', function (Blueprint $table) {
                /*
                 * Nullable, and it stays null for a row that cannot be attributed.
                 * A wrong service label is worse than an absent one: "Airtime" on a
                 * data sale would make the per-service report disagree with the
                 * transactions it claims to summarise.
                 */
                $table->string('service_type', 32)->nullable()->after('transaction_id');

                // The per-service report groups on this column over a date range.
                $table->index(['service_type', 'is_realized', 'realized_at'], 'profit_records_service_realized_index');
            });
        }

        if (! Schema::hasColumn('profit_records', 'cost_is_estimated')) {
            Schema::table('profit_records', function (Blueprint $table) {
                /*
                 * Defaults to *estimated*, which is the safe direction: a row whose
                 * basis is unknown must not be counted as confirmed profit. Existing
                 * rows are run through the checks below to set it properly.
                 */
                $table->boolean('cost_is_estimated')->default(true)->after('currency');

                $table->index(['cost_is_estimated', 'is_realized'], 'profit_records_estimate_realized_index');
            });
        }

        $this->backfillEstimateFlag();
        $this->backfillServiceType();
        $this->deduplicateTransactions();
        $this->addTransactionUniqueIndex();
    }

    /**
     * Copy the cost basis of each existing record from its pricing snapshot.
     *
     * The snapshot is the authority on whether the cost was confirmed, and it is
     * immutable, so this is a fact being read rather than a guess being made. A row
     * with no snapshot keeps the default of `true` — "estimated" — because there is
     * nothing that says the cost was confirmed.
     */
    private function backfillEstimateFlag(): void
    {
        if (! Schema::hasColumn('profit_records', 'cost_is_estimated')) {
            return;
        }

        DB::statement('
            UPDATE profit_records pr
            SET pr.cost_is_estimated = COALESCE((
                SELECT ps.cost_is_estimated
                FROM pricing_snapshots ps
                WHERE ps.transaction_id = pr.transaction_id
                   OR ps.service_order_id = pr.service_order_id
                LIMIT 1
            ), 1)
        ');
    }

    /**
     * Attribute existing rows to a service, from the transaction they settle.
     *
     * A correlated subquery rather than a join, because `profit_records` rows with
     * no transaction must be left alone rather than dropped by an inner join.
     */
    private function backfillServiceType(): void
    {
        if (! Schema::hasColumn('profit_records', 'service_type')) {
            return;
        }

        DB::statement('
            UPDATE profit_records pr
            SET pr.service_type = (
                SELECT t.service_type FROM transactions t WHERE t.id = pr.transaction_id
            )
            WHERE pr.service_type IS NULL AND pr.transaction_id IS NOT NULL
        ');
    }

    /**
     * Remove any duplicate profit row for one transaction, keeping the first.
     *
     * Done in PHP rather than as one `DELETE ... WHERE id NOT IN (SELECT ...)`,
     * which MySQL refuses outright: "You can't specify target table
     * 'profit_records' for update in FROM clause". Wrapping the subquery in a
     * derived table works on MySQL and is not portable, so the ids are collected
     * first and deleted in chunks — which also keeps the statement size bounded on
     * a table that has been accumulating for a while.
     */
    private function deduplicateTransactions(): void
    {
        $duplicateIds = DB::table('profit_records')
            ->select('id')
            ->whereNotNull('transaction_id')
            ->whereNotIn('id', function ($query) {
                $query->selectRaw('MIN(id)')
                    ->from('profit_records')
                    ->whereNotNull('transaction_id')
                    ->groupBy('transaction_id');
            })
            ->pluck('id');

        if ($duplicateIds->isEmpty()) {
            return;
        }

        foreach ($duplicateIds->chunk(500) as $chunk) {
            DB::table('profit_records')->whereIn('id', $chunk->all())->delete();
        }

        // Reported rather than silent: on a deployment that reached this migration,
        // this number is how much profit had been double-counted.
        \Illuminate\Support\Facades\Log::warning('Removed duplicate profit records before adding the unique index', [
            'removed' => $duplicateIds->count(),
        ]);
    }

    private function addTransactionUniqueIndex(): void
    {
        $existing = collect(DB::select('SHOW INDEX FROM profit_records'))
            ->firstWhere('Key_name', 'profit_records_transaction_id_unique');

        if ($existing) {
            return;
        }

        Schema::table('profit_records', function (Blueprint $table) {
            /*
             * Not a foreign-key replacement: the existing index is kept for the
             * foreign key, and this one is the constraint. MySQL allows both.
             *
             * Nullable, so many rows may have a null `transaction_id` — a profit
             * row that predates the transaction, or an order with no transaction —
             * while a *given* transaction may have at most one.
             */
            $table->unique('transaction_id', 'profit_records_transaction_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('profit_records', function (Blueprint $table) {
            foreach ([
                'profit_records_transaction_id_unique',
                'profit_records_service_realized_index',
                'profit_records_estimate_realized_index',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable $e) {
                    // Already absent.
                }
            }

            foreach (['service_type', 'cost_is_estimated'] as $column) {
                if (Schema::hasColumn('profit_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
