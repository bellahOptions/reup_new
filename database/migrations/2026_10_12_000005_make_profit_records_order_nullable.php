<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `profit_records.service_order_id` becomes nullable.
 *
 * ## Why
 *
 * The table was built for the `ServiceOrder` pipeline — one profit row per order,
 * keyed by `service_order_id` with a UNIQUE index — and the column was declared
 * `NOT NULL`. That is correct for an order and impossible for everything else: the
 * platform's actual sales (airtime, data, cable, electricity, exam PINs) go through
 * `BillPaymentService` and have no `ServiceOrder` at all, which is why the revenue
 * dashboard has been reporting zero while the platform sold.
 *
 * A row can now be keyed by either: `service_order_id` for an order, or
 * `transaction_id` for a live purchase. Both carry their own UNIQUE index, so
 * "at most one profit row per sale" holds on both paths and neither can be
 * double-counted.
 *
 * ## Why this is a separate migration
 *
 * The migration beside it adds the columns the live path needs. This is a change to
 * an existing column's nullability, and keeping it separate means the two can be
 * reasoned about — and rolled back — independently. `doctrine/dbal` is not
 * installed, so the change is raw DDL; SQLite already permits null on this column
 * because the foreign key was created without an explicit `NOT NULL`, so the
 * statement is guarded by driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();
        $table = $connection->getTablePrefix() . 'profit_records';

        if ($connection->getDriverName() === 'mysql') {
            $connection->statement("ALTER TABLE `{$table}` MODIFY `service_order_id` BIGINT UNSIGNED NULL");
        }
    }

    public function down(): void
    {
        $connection = Schema::getConnection();
        $table = $connection->getTablePrefix() . 'profit_records';

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        /*
         * Rows keyed only by a transaction cannot satisfy the restored NOT NULL, so
         * rolling back would fail on them. They are deleted rather than given a
         * fabricated order id: a profit row pointing at an order that does not exist
         * is worse than a missing row, because the dashboard would report it as a
         * sale that never happened.
         */
        $removed = $connection->table('profit_records')->whereNull('service_order_id')->delete();

        if ($removed > 0) {
            \Illuminate\Support\Facades\Log::warning('Removed transaction-keyed profit records to restore the NOT NULL constraint', [
                'removed' => $removed,
            ]);
        }

        $connection->statement("ALTER TABLE `{$table}` MODIFY `service_order_id` BIGINT UNSIGNED NOT NULL");
    }
};
