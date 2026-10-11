<?php

/**
 * Show where a Paystack-funded purchase stands.
 *
 * A card purchase is two steps across two requests — the payment settles, then the
 * product is vended — so "did the customer get what they paid for?" is a question
 * about the funding row's `meta.funded_purchase` state, not about the payment. This
 * prints that state for the most recent ones, which is the first thing to look at
 * when somebody says they were charged and received nothing.
 *
 * Read-only. Usage: php tools/show-funded-purchases.php [limit]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Transactions;
use App\Orders\FundedPurchase;
use App\Support\Money;

$limit = (int) ($argv[1] ?? 15);

$rows = Transactions::query()
    ->with('user')
    ->where('service_type', 'funding')
    ->whereNotNull('meta->' . FundedPurchase::META_KEY)
    ->orderByDesc('id')
    ->limit($limit)
    ->get();

if ($rows->isEmpty()) {
    echo "No card purchases recorded.\n";
    echo "A card purchase creates a `funding` row carrying a `" . FundedPurchase::META_KEY . "` intent.\n";

    exit(0);
}

printf(
    "%-22s  %-11s  %-10s  %-9s  %-8s  %s\n",
    'funding reference',
    'payment',
    'product',
    'state',
    'amount',
    'detail'
);
printf("%s\n", str_repeat('-', 108));

foreach ($rows as $row) {
    $intent = ($row->meta ?? [])[FundedPurchase::META_KEY] ?? [];
    $state = (string) ($intent['state'] ?? 'unknown');

    printf(
        "%-22s  %-11s  %-10s  %-9s  %-8s  %s\n",
        (string) $row->reference,
        (string) $row->status,
        ucfirst((string) ($intent['product'] ?? '—')),
        $state,
        Money::fromDatabase($row->total_amount)->format(),
        $state === FundedPurchase::STATE_VENDED
            ? 'delivered as ' . ($intent['product_reference'] ?? '?')
            : (string) ($intent['message'] ?? '')
    );
}

if ($rows->isNotEmpty()) {
    $first = $rows->first();
    $intent = ($first->meta ?? [])[FundedPurchase::META_KEY] ?? [];

    echo "\nMost recent intent, in full:\n";
    echo json_encode($intent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
