<?php

/**
 * Print a table's indexes.
 *
 * Which columns are unique decides what can be relied on for idempotency, and an
 * idempotency guard that is not backed by a unique index is a check that two
 * concurrent callers can both pass. That distinction is worth seeing rather than
 * inferring from a model's docblock.
 *
 * Usage: php tools/show-indexes.php table [table ...]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = array_slice($argv, 1);

if ($tables === []) {
    fwrite(STDERR, "Usage: php tools/show-indexes.php <table> [table ...]\n");
    exit(1);
}

foreach ($tables as $table) {
    echo "== {$table}\n";

    $indexes = [];

    foreach (DB::select('SHOW INDEX FROM `' . str_replace('`', '', $table) . '`') as $row) {
        $indexes[$row->Key_name]['unique'] = ! $row->Non_unique;
        $indexes[$row->Key_name]['columns'][(int) $row->Seq_in_index] = $row->Column_name;
    }

    foreach ($indexes as $name => $meta) {
        ksort($meta['columns']);

        printf(
            "  %-40s %-7s (%s)\n",
            $name,
            $meta['unique'] ? 'UNIQUE' : '',
            implode(', ', $meta['columns'])
        );
    }

    echo "\n";
}
