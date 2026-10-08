<?php

/**
 * PRE-DEPLOYMENT SAFETY CHECK — read-only.
 *
 * The migrations that add UNIQUE indexes to `transactions.api_reference`,
 * `transactions.payment_reference` and `wallets.user_id` will FAIL on a database
 * that already contains duplicates. That is intentional: de-duplicating real
 * financial rows automatically would change historical record, so the migration
 * refuses and reports instead.
 *
 * This script performs the same checks WITHOUT modifying anything, so the
 * duplicates can be reviewed before a deploy window. It exits non-zero if any
 * blocker is found.
 *
 * Usage (point it at the target database with the normal DB_* environment):
 *
 *     php tools/preflight-financial-integrity.php
 *
 * On production, copy the database to a staging instance and run it there
 * first; the queries below are also safe to paste into a read-only console.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = DB::connection()->getDatabaseName();
$driver = DB::connection()->getDriverName();

echo "Pre-flight financial integrity check\n";
echo "  connection : {$driver}\n";
echo "  database   : {$database}\n";
echo str_repeat('-', 68) . "\n";

$blockers = 0;
$warnings = 0;

/** Report duplicates in a column that is about to receive a UNIQUE index. */
function checkUnique(string $table, string $column, int &$blockers): void
{
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
        echo "  SKIP  {$table}.{$column} — column not present\n";

        return;
    }

    $duplicates = DB::table($table)
        ->select($column, DB::raw('COUNT(*) as occurrences'))
        ->whereNotNull($column)
        ->where($column, '!=', '')
        ->groupBy($column)
        ->havingRaw('COUNT(*) > 1')
        ->orderByDesc('occurrences')
        ->limit(10)
        ->get();

    if ($duplicates->isEmpty()) {
        echo "  OK    {$table}.{$column} — no duplicates, UNIQUE index can be added\n";

        return;
    }

    $blockers++;
    $total = $duplicates->sum('occurrences');

    echo "  BLOCK {$table}.{$column} — {$duplicates->count()} duplicated value(s), {$total} rows\n";

    foreach ($duplicates as $row) {
        $value = (string) $row->{$column};
        $ids = DB::table($table)->where($column, $row->{$column})->limit(10)->pluck('id')->implode(', ');

        echo "          {$value}  x{$row->occurrences}  ids: {$ids}\n";
    }
}

/** A wallet balance that disagrees with the sum of its transactions. */
function checkWalletConsistency(int &$warnings): void
{
    if (! Schema::hasTable('wallets') || ! Schema::hasTable('transactions')) {
        echo "  SKIP  wallet consistency — tables not present\n";

        return;
    }

    $mismatches = DB::table('wallets')
        ->leftJoin('transactions', function ($join) {
            $join->on('transactions.user_id', '=', 'wallets.user_id')
                ->where('transactions.status', 'success');
        })
        ->groupBy('wallets.id', 'wallets.user_id', 'wallets.balance')
        ->select(
            'wallets.id',
            'wallets.user_id',
            'wallets.balance',
            DB::raw("COALESCE(SUM(CASE WHEN transactions.type = 'credit' THEN transactions.total_amount ELSE 0 END), 0) AS credits"),
            DB::raw("COALESCE(SUM(CASE WHEN transactions.type = 'debit' THEN transactions.total_amount ELSE 0 END), 0) AS debits")
        )
        ->havingRaw('ABS(wallets.balance - (COALESCE(SUM(CASE WHEN transactions.type = \'credit\' THEN transactions.total_amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN transactions.type = \'debit\' THEN transactions.total_amount ELSE 0 END), 0))) > 0.01')
        ->limit(20)
        ->get();

    if ($mismatches->isEmpty()) {
        echo "  OK    wallet balances agree with transaction history (to the kobo)\n";

        return;
    }

    /*
     * A warning, not a blocker. Historical rows predate the wallet service and
     * were written by several controllers that each maintained balances
     * differently, so some drift is expected and cannot be reconstructed. The
     * ledger is introduced with a per-wallet opening entry precisely so that
     * existing balances are recorded as a known starting point rather than
     * silently recalculated.
     */
    $warnings += $mismatches->count();

    echo "  WARN  {$mismatches->count()} wallet(s) disagree with their transaction history:\n";

    foreach ($mismatches as $row) {
        $expected = (float) $row->credits - (float) $row->debits;

        echo sprintf(
            "          wallet %d (user %d): stored %s, transactions imply %s\n",
            $row->id,
            $row->user_id,
            $row->balance,
            number_format($expected, 2, '.', '')
        );
    }

    echo "          The ledger backfill records each of these as an opening ADMIN_ADJUSTMENT\n";
    echo "          rather than recalculating it, so no historical row is rewritten.\n";
}

/** Rows that would break a foreign key or a required-column assumption. */
function checkOrphans(int &$warnings): void
{
    $checks = [
        ['wallets', 'user_id', 'users'],
        ['transactions', 'user_id', 'users'],
    ];

    foreach ($checks as [$table, $column, $parent]) {
        if (! Schema::hasTable($table) || ! Schema::hasTable($parent)) {
            continue;
        }

        $orphans = DB::table($table)
            ->leftJoin($parent, "{$parent}.id", '=', "{$table}.{$column}")
            ->whereNull("{$parent}.id")
            ->count();

        if ($orphans > 0) {
            $warnings++;
            echo "  WARN  {$table}.{$column}: {$orphans} row(s) reference a missing {$parent} row\n";
        }
    }
}

echo "\nUnique constraints the next migration will add\n";
checkUnique('transactions', 'api_reference', $blockers);
checkUnique('transactions', 'payment_reference', $blockers);
checkUnique('wallets', 'user_id', $blockers);

echo "\nExisting data integrity\n";
checkWalletConsistency($warnings);
checkOrphans($warnings);

echo "\n" . str_repeat('-', 68) . "\n";

if ($blockers > 0) {
    echo "RESULT: {$blockers} blocker(s). The migration WILL fail until these are resolved.\n";
    echo "        Review the duplicated references by hand. Do not de-duplicate financial\n";
    echo "        rows automatically — decide which row is authoritative for each, then fix.\n";
    exit(1);
}

echo "RESULT: no blockers. Safe to migrate." . ($warnings > 0 ? " ({$warnings} warning(s) above — informational)" : '') . "\n";
exit(0);
