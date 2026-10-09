<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the mobile network and the cost provenance on a transaction.
 *
 * ## Why the network needs its own column
 *
 * Before this, the only network-ish value on a transaction was `provider`, and
 * the payment pipeline overwrote it with the *provider adapter's* label. So a
 * customer's receipt read "Network: ClubKonnect" — the upstream API, not their
 * mobile operator — and the real network survived only inside the `meta` JSON,
 * where no receipt template read it.
 *
 * A dedicated pair of columns fixes that at the root:
 *
 *   * `network_code` is the canonical operator key ('mtn'), which is what a
 *     pricing rule keys on and what makes per-network reporting possible;
 *   * `network_name` is the resolved, customer-facing label, stored so a receipt
 *     printed two years from now shows what the network was *then*.
 *
 * Storing the name as well as the key is deliberate. Re-resolving a historical
 * transaction against today's configuration would let a later rename — or a
 * provider catalogue change — silently rewrite what a past receipt says, and a
 * receipt that changes is not evidence of anything.
 *
 * `provider` keeps its meaning: the upstream that served the request. It stays
 * available for administration, reconciliation and support, and is no longer
 * shown to customers as their network.
 *
 * ## Additive and nullable
 *
 * Both columns are nullable with no backfill, because a NULL is the honest state
 * for a historical row whose network was never captured. The model's accessor
 * falls back to `meta` for those rows and then to a neutral label — it never
 * falls back to the provider name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('transactions', 'network_code')) {
                    $table->string('network_code', 32)->nullable()->after('provider');
                }

                if (! Schema::hasColumn('transactions', 'network_name')) {
                    $table->string('network_name', 64)->nullable()->after('network_code');
                }
            });

            $this->addIndexIfMissing('transactions', ['service_type', 'network_code'], 'transactions_service_network_index');
        }

        /*
         * The pricing snapshot gains the cost-provenance columns the brief asks for:
         * where the provider cost came from, when it was verified, and whether it is
         * an assumption. They live on the snapshot because the snapshot is the
         * immutable record of the pricing decision — a provenance field on the
         * mutable transaction could be edited after the fact, which defeats the point.
         */
        if (Schema::hasTable('pricing_snapshots')) {
            Schema::table('pricing_snapshots', function (Blueprint $table) {
                if (! Schema::hasColumn('pricing_snapshots', 'cost_source')) {
                    $table->string('cost_source', 32)->nullable()->after('provider_cost_minor');
                }

                if (! Schema::hasColumn('pricing_snapshots', 'cost_verified_at')) {
                    $table->timestamp('cost_verified_at')->nullable()->after('cost_source');
                }

                if (! Schema::hasColumn('pricing_snapshots', 'cost_is_estimated')) {
                    $table->boolean('cost_is_estimated')->default(false)->after('cost_verified_at');
                }

                /*
                 * The face value the customer asked for. For airtime this equals the
                 * price; for a cost-plus product it is null. Stored so a snapshot
                 * proves why a face-value price was what it was rather than leaving a
                 * reader to infer it from cost.
                 */
                if (! Schema::hasColumn('pricing_snapshots', 'price_basis_minor')) {
                    $table->unsignedBigInteger('price_basis_minor')->nullable()->after('gross_profit_minor');
                }

                /** The network this snapshot was priced for, denormalised for reporting. */
                if (! Schema::hasColumn('pricing_snapshots', 'network')) {
                    $table->string('network', 32)->nullable()->after('price_basis_minor');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transactions')) {
            $this->dropIndexIfPresent('transactions', 'transactions_service_network_index');

            Schema::table('transactions', function (Blueprint $table) {
                $columns = array_values(array_filter(
                    ['network_code', 'network_name'],
                    fn ($column) => Schema::hasColumn('transactions', $column)
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('pricing_snapshots')) {
            Schema::table('pricing_snapshots', function (Blueprint $table) {
                $columns = array_values(array_filter(
                    ['cost_source', 'cost_verified_at', 'cost_is_estimated', 'price_basis_minor', 'network'],
                    fn ($column) => Schema::hasColumn('pricing_snapshots', $column)
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    /**
     * Add a composite index only when it is not already there.
     *
     * `Schema::hasIndex`/`getIndexes` do not exist in Laravel 8, so the index list
     * is read from the connection's schema manager.
     */
    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (in_array($name, $this->indexNames($table), true)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfPresent(string $table, string $name): void
    {
        if (! in_array($name, $this->indexNames($table), true)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }

    /**
     * The index names on a table.
     *
     * `listTableIndexes()` is the Laravel 8 path (it delegates to doctrine/dbal,
     * which is installed). Anything else — including a driver that cannot report
     * indexes at all — yields an empty list, which makes the callers skip rather
     * than fail: on a fresh `migrate` the index is created explicity, and a missing
     * "already exists" check is only a risk on a re-run.
     *
     * @return array<int,string>
     */
    private function indexNames(string $table): array
    {
        try {
            $manager = Schema::getConnection()->getDoctrineSchemaManager();

            return array_keys($manager->listTableIndexes($table));
        } catch (\Throwable $e) {
            return [];
        }
    }
};
