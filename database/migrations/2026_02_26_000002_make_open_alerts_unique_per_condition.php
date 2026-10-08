<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make "one open alert per condition" the database's rule rather than a hope.
 *
 * ## The bug this fixes
 *
 * `product_alerts.fingerprint` was declared globally UNIQUE. The intent, as written
 * in `ProductAlert`'s own docblock, was "one condition produces one open alert" —
 * but a global unique index enforces something stronger and worse: *one alert per
 * condition, ever*.
 *
 * That makes resolution permanent. A provider runs low on float, an operator tops it
 * up and resolves the alert, and the next time the same provider runs low the insert
 * fails against the unique index — so the condition that was worth alerting about
 * once can never be alerted about again. The failure is silent, which is the worst
 * possible property for a monitor: the dashboard shows no open alerts and looks
 * perfectly healthy.
 *
 * The code path in `ProviderMonitor::alert()` had the right check — look for an
 * existing *open* alert, update it, otherwise create — but the index contradicted it
 * and turned the create into an exception.
 *
 * ## The fix
 *
 * A generated column that holds the fingerprint only while the alert is open, with a
 * unique index on it:
 *
 *     open_fingerprint = IF(resolved_at IS NULL, fingerprint, NULL)
 *
 * MySQL allows any number of NULLs in a unique index, which is what makes this work
 * as a partial unique index — a feature MySQL does not otherwise have. The result is
 * exactly the intended invariant, enforced at the storage layer:
 *
 *   * one open alert per condition, guaranteed even if two probes race;
 *   * any number of resolved alerts per condition, because resolution must not
 *     permanently disarm the monitor.
 *
 * The `fingerprint` column keeps its own non-unique index so the existing lookups
 * (including `resolveAlerts()`, which queries by fingerprint) stay fast.
 *
 * `STORED` rather than `VIRTUAL` because a virtual generated column cannot be indexed
 * in MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_alerts') || ! $this->isMySql()) {
            return;
        }

        if ($this->indexExists('product_alerts', 'product_alerts_fingerprint_unique')) {
            /*
             * Dropped by name because the index was created by the table's own
             * migration; a `Schema::table` drop would need the name anyway and this
             * keeps the operation explicit about which index is being replaced.
             */
            DB::statement('ALTER TABLE `product_alerts` DROP INDEX `product_alerts_fingerprint_unique`');
        }

        if (! $this->indexExists('product_alerts', 'product_alerts_fingerprint_index')) {
            Schema::table('product_alerts', function (Blueprint $table) {
                $table->index('fingerprint', 'product_alerts_fingerprint_index');
            });
        }

        if (! $this->columnExists('product_alerts', 'open_fingerprint')) {
            DB::statement(
                'ALTER TABLE `product_alerts`
                 ADD COLUMN `open_fingerprint` VARCHAR(64)
                 GENERATED ALWAYS AS (IF(`resolved_at` IS NULL, `fingerprint`, NULL)) STORED'
            );
        }

        if (! $this->indexExists('product_alerts', 'product_alerts_open_fingerprint_unique')) {
            DB::statement(
                'ALTER TABLE `product_alerts`
                 ADD UNIQUE INDEX `product_alerts_open_fingerprint_unique` (`open_fingerprint`)'
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_alerts') || ! $this->isMySql()) {
            return;
        }

        if ($this->indexExists('product_alerts', 'product_alerts_open_fingerprint_unique')) {
            DB::statement('ALTER TABLE `product_alerts` DROP INDEX `product_alerts_open_fingerprint_unique`');
        }

        if ($this->columnExists('product_alerts', 'open_fingerprint')) {
            DB::statement('ALTER TABLE `product_alerts` DROP COLUMN `open_fingerprint`');
        }

        if (! $this->indexExists('product_alerts', 'product_alerts_fingerprint_unique')) {
            DB::statement('ALTER TABLE `product_alerts` ADD UNIQUE INDEX `product_alerts_fingerprint_unique` (`fingerprint`)');
        }
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
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

    private function columnExists(string $table, string $column): bool
    {
        $connection = Schema::getConnection();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$connection->getDatabaseName(), $table, $column]
        );

        return $result !== null && (int) $result->total > 0;
    }
};
