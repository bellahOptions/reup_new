<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes and columns the ledger and the idempotency table need on
 * `transactions`, which cannot be added in the same migration that creates them
 * without an ordering dependency on the foreign keys.
 *
 * Also the indexes for two queries that currently scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            /*
             * The idempotency key that produced this row, when the caller
             * supplied one. Stored on the transaction as well as in
             * `idempotency_keys` so the provenance of a purchase is visible from
             * the transaction alone — which is what an operator investigating a
             * double charge actually looks at.
             */
            if (! Schema::hasColumn('transactions', 'idempotency_key')) {
                $table->string('idempotency_key', 191)->nullable()->after('payment_reference');
                $table->index('idempotency_key', 'txn_idempotency_key_index');
            }

            /*
             * The daily and monthly spend-limit checks in SecurityService sum
             * `total_amount` filtered by (user_id, type, status, created_at).
             * Without an index that is a per-user range scan of the whole table
             * on every purchase.
             */
            if (! $this->indexExists('transactions', 'txn_user_type_status_created_index')) {
                $table->index(['user_id', 'type', 'status', 'created_at'], 'txn_user_type_status_created_index');
            }

            /*
             * The dashboard and history pages read a user's transactions newest
             * first, filtered by service type.
             */
            if (! $this->indexExists('transactions', 'txn_user_service_created_index')) {
                $table->index(['user_id', 'service_type', 'created_at'], 'txn_user_service_created_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if ($this->indexExists('transactions', 'txn_user_service_created_index')) {
                $table->dropIndex('txn_user_service_created_index');
            }

            if ($this->indexExists('transactions', 'txn_user_type_status_created_index')) {
                $table->dropIndex('txn_user_type_status_created_index');
            }

            if (Schema::hasColumn('transactions', 'idempotency_key')) {
                $table->dropIndex('txn_idempotency_key_index');
                $table->dropColumn('idempotency_key');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$connection->getDatabaseName(), $table, $indexName]
        );

        return $result !== null && (int) $result->total > 0;
    }
};
