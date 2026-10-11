<?php

/**
 * Print the notifications that have been recorded, newest first.
 *
 * The funding receipt is delivered on two channels — mail, which the customer
 * keeps, and `database`, which is the record that we sent one. The `notifications`
 * table is what makes "was this customer told?" answerable from the database
 * weeks later, without trawling a mail log, so this is the check that the record
 * is actually being written.
 *
 * Read-only. Usage: php tools/show-notifications.php [limit]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$limit = (int) ($argv[1] ?? 10);

$rows = DB::table('notifications')->orderByDesc('created_at')->limit($limit)->get();

printf("%d notification(s) recorded (showing up to %d).\n\n", DB::table('notifications')->count(), $limit);

if ($rows->isEmpty()) {
    echo "None. If funding has settled and receipts were dispatched, the database\n";
    echo "channel is not being selected — check the notification's via().\n";

    exit(0);
}

foreach ($rows as $row) {
    $data = json_decode((string) $row->data, true) ?: [];

    printf(
        "%-30s  %-10s  %s\n",
        class_basename((string) $row->type),
        $row->created_at,
        $data['reference'] ?? ($data['transaction_id'] ?? '—')
    );

    if (isset($data['message'])) {
        echo '    ' . $data['message'] . "\n";
    }

    if (isset($data['url'])) {
        echo '    ' . $data['url'] . "\n";
    }

    echo '    read: ' . ($row->read_at ?: 'no') . "\n\n";
}
