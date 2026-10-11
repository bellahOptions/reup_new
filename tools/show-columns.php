<?php

/**
 * Print a table's columns and their types.
 *
 * Read-only. The column list is what decides whether a change needs a migration
 * and whether a "flag this for review" feature has somewhere to live.
 *
 * Usage: php tools/show-columns.php [table]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$table = $argv[1] ?? 'users';

if (! Schema::hasTable($table)) {
    fwrite(STDERR, "No table named [{$table}].\n");
    exit(1);
}

$columns = DB::select('SHOW FULL COLUMNS FROM `' . str_replace('`', '', $table) . '`');

printf("%-32s %-28s %-8s %-8s %s\n", 'column', 'type', 'null', 'default', 'comment');

foreach ($columns as $column) {
    printf(
        "%-32s %-28s %-8s %-8s %s\n",
        $column->Field,
        $column->Type,
        $column->Null,
        $column->Default === null ? '—' : (string) $column->Default,
        (string) ($column->Comment ?? '')
    );
}
