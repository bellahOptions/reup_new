<?php

/**
 * Show the newest funding rows with their receipt state.
 *
 * The receipt sweep claims each row by stamping `meta.receipt_sent_at`, so this is
 * the other half of tools/show-notifications.php: that one answers "what did we
 * tell the customer?", this one answers "which funding rows have been told
 * about, and which are still waiting?".
 *
 * Read-only. Usage: php tools/show-funding.php [limit]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Console\Commands\SendFundingReceipts;
use App\Models\Transactions;

$limit = (int) ($argv[1] ?? 10);

$rows = Transactions::with('user')
    ->where('service_type', 'funding')
    ->orderByDesc('created_at')
    ->limit($limit)
    ->get();

printf("%-22s  %-10s  %-10s  %-12s  %s\n", 'reference', 'status', 'amount', 'receipted', 'description');
printf("%s\n", str_repeat('-', 110));

foreach ($rows as $row) {
    $stamp = ($row->meta ?? [])[SendFundingReceipts::META_KEY] ?? null;

    printf(
        "%-22s  %-10s  %-10s  %-12s  %s\n",
        (string) $row->reference,
        (string) $row->status,
        number_format((float) $row->total_amount, 2),
        $stamp ? 'yes' : 'no',
        (string) $row->description
    );
}

echo "\nFunding rows: " . Transactions::where('service_type', 'funding')->count()
    . ', settled: ' . Transactions::where('service_type', 'funding')->where('status', 'success')->count()
    . ', receipted: ' . Transactions::whereNotNull('meta->' . SendFundingReceipts::META_KEY)->count()
    . "\n";
