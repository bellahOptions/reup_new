<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-backed financial idempotency.
 *
 * ## The problem
 *
 * Idempotency was enforced in `Illuminate\Support\Facades\Cache`. That is a
 * correctness bug, not a tuning choice:
 *
 *   * the cache is not transactionally consistent with the database, so a
 *     rolled-back purchase could leave a reserved key pointing at a row that
 *     does not exist — and the replay of that request would then be *allowed*
 *     and debit the customer a second time;
 *   * the default file store is per-server, so two application servers behind a
 *     load balancer each keep their own copy of "seen" and a double-submit is
 *     honoured by whichever one is not holding the key;
 *   * `Cache::put` has no compare-and-set, so two concurrent requests that both
 *     miss the key both proceed;
 *   * a cache flush silently resets every replay guard in the platform.
 *
 * ## What replaces it
 *
 * A real table with a UNIQUE index on the key. The unique index is the
 * authority: two concurrent inserts cannot both succeed, regardless of how many
 * application servers are running or what happened to the cache. The cache is
 * kept as a fast path only (see SecurityService).
 *
 * ## Related unique constraints
 *
 * The same reasoning applies to every column a payment can be replayed
 * against:
 *
 *   * `transactions.reference` — already UNIQUE from the create migration;
 *   * `transactions.uuid`      — already UNIQUE (2026_02_11_000001);
 *   * `transactions.api_reference` — the provider's own order id. Two rows
 *     sharing one means a webhook can settle the wrong row, or settle a row
 *     twice;
 *   * `transactions.payment_reference` — the gateway reference for funding rows.
 *     A duplicate means one payment credited two wallets.
 *
 * ## Production safety
 *
 * Adding a UNIQUE index to a table with existing duplicates fails, and it
 * should: silently de-duplicating financial rows would change historical
 * record. This migration therefore **checks first and refuses with a precise
 * report** of the offending rows, so the operator can decide what to do. The
 * check is a `GROUP BY ... HAVING COUNT(*) > 1`, which uses any existing index
 * on the column.
 *
 * Rows with a NULL reference are unaffected: MySQL treats NULLs as distinct in
 * a unique index, which is exactly right — most transactions never talk to
 * Paystack and have no gateway reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createIdempotencyTable();
        $this->addUniqueIndex('transactions', 'api_reference', 'txn_api_reference_unique');
        $this->addUniqueIndex('transactions', 'payment_reference', 'txn_payment_reference_unique');

        /*
         * One wallet per customer. `WalletService::lockForUser()` uses
         * `firstOrFail()` on a row lock, so two wallets for one user means the
         * balance the customer spends from depends on the order rows come back
         * in — a random overspend.
         */
        $this->addUniqueIndex('wallets', 'user_id', 'wallets_user_id_unique');
    }

    public function down(): void
    {
        $this->dropUniqueIndex('wallets', 'wallets_user_id_unique');
        $this->dropUniqueIndex('transactions', 'txn_payment_reference_unique');
        $this->dropUniqueIndex('transactions', 'txn_api_reference_unique');

        Schema::dropIfExists('idempotency_keys');
    }

    private function createIdempotencyTable(): void
    {
        if (Schema::hasTable('idempotency_keys')) {
            return;
        }

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            // The caller-supplied key. UNIQUE is the entire point of the table:
            // the second concurrent INSERT fails, which is how a double submit
            // is rejected without a lock and without a cache.
            $table->string('key', 191)->unique();

            // What the key was used for, so a key cannot be reused across
            // different operations by accident or by an attacker.
            $table->string('scope', 64)->default('bill_purchase');

            /*
             * Which upstream the key was issued against, where that matters.
             *
             * For webhook-driven providers the vendor's own reference is the
             * natural idempotency key, and the same reference string from two
             * different vendors must not be treated as the same event. Nullable
             * because a caller-supplied form key has no provider.
             *
             * Declared here in source order rather than with `->after('scope')`:
             * MySQL does not accept an `AFTER` clause inside `CREATE TABLE`, and
             * Laravel emits the modifier verbatim, so the DDL is rejected with
             * error 1064.
             */
            $table->string('provider', 64)->nullable();

            // Hash of the meaningful request parameters. A replayed key with a
            // *different* body is not a replay — it is either a client bug or an
            // attempt to reuse a processed key for a second purchase, and both
            // must be refused rather than answered with the first outcome.
            $table->string('request_hash', 64)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            // The transaction the key produced. `restrictOnDelete` so a key
            // cannot end up pointing at nothing and silently allowing a replay.
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();

            $table->enum('status', ['reserved', 'completed', 'failed'])->default('reserved');

            $table->unsignedInteger('response_code')->nullable();
            $table->json('response')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'created_at']);
            $table->index('expires_at');
        });
    }

    /**
     * Add a UNIQUE index, refusing loudly when existing rows would collide.
     */
    private function addUniqueIndex(string $table, string $column, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if ($this->indexExists($table, $indexName)) {
            return;
        }

        $duplicates = DB::table($table)
            ->select($column, DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $report = $duplicates
                ->map(fn ($row) => sprintf('%s (x%d)', $row->{$column}, $row->occurrences))
                ->implode(', ');

            throw new RuntimeException(
                "Cannot add a UNIQUE index on {$table}.{$column}: existing duplicate values [{$report}]. "
                . "Resolve these rows deliberately (they represent real historical financial records) and re-run "
                . "this migration. Do not de-duplicate them automatically."
            );
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName) {
            $blueprint->unique($column, $indexName);
        });
    }

    private function dropUniqueIndex(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropUnique($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return $result !== null && (int) $result->total > 0;
    }
};
