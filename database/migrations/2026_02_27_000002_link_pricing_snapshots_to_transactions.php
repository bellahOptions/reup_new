<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a pricing snapshot belong to a wallet transaction as well as to a
 * `service_orders` row.
 *
 * ## Why
 *
 * `pricing_snapshots` was built for the Wave 1 catalogue pipeline, where every
 * priced sale creates a `service_orders` row and the snapshot hangs off it. Airtime
 * and data go through `BillPaymentService` instead — the pipeline that owns the
 * wallet debit, the idempotency reservation and the provider failover for the
 * original five products — so they have a `transactions` row and no
 * `service_orders` row.
 *
 * The brief requires an immutable pricing snapshot for those purchases too, and it
 * is explicit that this must reuse the existing snapshot architecture rather than a
 * parallel one. So the snapshot gains a second, optional owner.
 *
 * ## Non-destructive
 *
 * `service_order_id` becomes nullable and a nullable `transaction_id` is added.
 * Existing rows keep their `service_order_id` untouched, and the UNIQUE index on it
 * is preserved. MySQL (like SQLite and Postgres) treats NULLs as distinct in a
 * UNIQUE index, so the many transaction-owned snapshots that have no
 * `service_order_id` do not collide with one another.
 *
 * At most one of the two owners is set for any snapshot, which is what keeps "one
 * priced sale, one snapshot" true for both pipelines.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_snapshots')) {
            return;
        }

        if (! Schema::hasColumn('pricing_snapshots', 'transaction_id')) {
            Schema::table('pricing_snapshots', function (Blueprint $table) {
                $table->foreignId('transaction_id')
                    ->nullable()
                    ->after('service_order_id')
                    ->constrained('transactions')
                    ->nullOnDelete();
            });
        }

        /*
         * `service_order_id` must become nullable so a transaction-owned snapshot can
         * be inserted. Done with raw DDL because changing a column needs
         * doctrine/dbal through `Blueprint::change()`, and this has to behave on both
         * MySQL (the app) and SQLite (some unit runs).
         */
        if (Schema::hasColumn('pricing_snapshots', 'service_order_id')) {
            $connection = Schema::getConnection();

            if ($connection->getDriverName() === 'mysql') {
                $connection->statement(
                    'ALTER TABLE pricing_snapshots MODIFY service_order_id BIGINT UNSIGNED NULL'
                );
            }
            // SQLite columns are nullable unless declared NOT NULL, and the original
            // test schema does not constrain this, so no DDL is needed there.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pricing_snapshots')) {
            return;
        }

        if (Schema::hasColumn('pricing_snapshots', 'transaction_id')) {
            Schema::table('pricing_snapshots', function (Blueprint $table) {
                $table->dropConstrainedForeignId('transaction_id');
            });
        }
    }
};
