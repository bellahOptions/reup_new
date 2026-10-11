<?php

/**
 * Rename a migration's entry in the `migrations` table.
 *
 * Renaming a migration file after it has been applied makes the runner think the
 * old one is missing and the new one is pending: `migrate:status` shows a
 * "Migration not found" line and `migrate` tries to run the renamed file again.
 * This is the one-line repair, and it is here as a tool rather than as a
 * hand-written SQL statement because the table name comes from configuration.
 *
 * Usage: php tools/rename-migration.php 2026_10_12_000003_old_name 2026_10_12_000002_new_name
 *        php tools/rename-migration.php --list
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$table = config('database.migrations', 'migrations');

if (! Schema::hasTable($table)) {
    fwrite(STDERR, "No [{$table}] table in this database.\n");
    exit(1);
}

if (($argv[1] ?? null) === '--list') {
    foreach (DB::table($table)->orderBy('id')->pluck('migration') as $name) {
        $exists = is_file(base_path('database/migrations/' . $name . '.php'));

        printf("%s  %s\n", $exists ? ' ' : '!', $name);
    }

    echo "\nRows marked ! have no file on disk.\n";
    exit(0);
}

$old = $argv[1] ?? null;
$new = $argv[2] ?? null;

if ($old === null || $new === null) {
    fwrite(STDERR, "Usage: php tools/rename-migration.php <old> <new>\n       php tools/rename-migration.php --list\n");
    exit(1);
}

$affected = DB::table($table)->where('migration', $old)->update(['migration' => $new]);

if ($affected === 0) {
    fwrite(STDERR, "No migration row named [{$old}]. Use --list to see what is recorded.\n");
    exit(1);
}

echo "Renamed {$old} -> {$new} ({$affected} row).\n";
