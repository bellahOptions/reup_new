<?php

/**
 * Print the name fields of the most recent accounts.
 *
 * The split-name change writes two new columns and derives a third, and the only
 * convincing check is to look at real rows: a backfill that silently produced
 * empty last names, or an accessor that dropped the fallback, looks identical in
 * the code and completely different in the table.
 *
 * Read-only. Usage: php tools/show-names.php [limit]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

$limit = (int) ($argv[1] ?? 10);

printf("%-4s  %-26s  %-14s  %-16s  %s\n", 'id', 'name', 'first_name', 'last_name', 'dva');
printf("%s\n", str_repeat('-', 92));

foreach (User::orderByDesc('id')->limit($limit)->get() as $user) {
    printf(
        "%-4d  %-26s  %-14s  %-16s  %s\n",
        $user->id,
        mb_strimwidth((string) $user->name, 0, 26),
        mb_strimwidth((string) $user->first_name, 0, 14),
        mb_strimwidth((string) $user->last_name, 0, 16),
        $user->dva_account_number ?: '—'
    );
}
