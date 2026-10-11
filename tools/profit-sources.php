<?php

/**
 * Report where profit is actually recorded, and where it is not.
 *
 * The console has a Revenue & Profit dashboard that reads `profit_records`, and a
 * pricing engine that writes a pricing snapshot onto every quote. Those are two
 * different tables, and the question this answers is whether the sales the platform
 * actually makes end up in the one the dashboard reads.
 *
 * Read-only. Usage: php tools/profit-sources.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PricingSnapshot;
use App\Models\ProfitRecord;
use App\Models\ServiceOrder;
use App\Models\Transactions;
use Illuminate\Support\Facades\DB;

$report = [
    'profit_records (the dashboard reads these)' => ProfitRecord::count(),
    '  ...of which realized' => ProfitRecord::where('is_realized', true)->count(),
    'pricing_snapshots (every quote writes one)' => PricingSnapshot::count(),
    '  ...attached to a purchase (transaction_id)' => PricingSnapshot::whereNotNull('transaction_id')->count(),
    '  ...attached to an order (service_order_id)' => PricingSnapshot::whereNotNull('service_order_id')->count(),
    'service_orders' => ServiceOrder::count(),
    'transactions (all)' => Transactions::count(),
];

$width = max(array_map('strlen', array_keys($report)));

foreach ($report as $key => $value) {
    printf("%-{$width}s  %s\n", $key, $value);
}

echo "\nPurchases by service type, with and without a snapshot:\n\n";

$byType = Transactions::query()
    ->selectRaw('service_type')
    ->selectRaw('COUNT(*) AS total')
    ->selectRaw("SUM(status = 'success') AS succeeded")
    ->selectRaw("SUM(EXISTS(SELECT 1 FROM pricing_snapshots ps WHERE ps.transaction_id = transactions.id)) AS snapshotted")
    ->groupBy('service_type')
    ->orderByDesc('total')
    ->get();

printf("%-16s %8s %10s %13s\n", 'service_type', 'total', 'succeeded', 'snapshotted');

foreach ($byType as $row) {
    printf(
        "%-16s %8d %10d %13d\n",
        $row->service_type,
        (int) $row->total,
        (int) $row->succeeded,
        (int) $row->snapshotted
    );
}

echo "\nRealized profit, by the dimensions the dashboard can group by:\n\n";

foreach (['product_key', 'provider_slug', 'category_slug'] as $column) {
    $rows = ProfitRecord::query()
        ->realized()
        ->select($column)
        ->selectRaw('COUNT(*) AS n')
        ->selectRaw('COALESCE(SUM(gross_profit_minor), 0) AS profit')
        ->groupBy($column)
        ->orderByDesc('profit')
        ->limit(5)
        ->get();

    printf("%s: %d group(s)\n", $column, $rows->count());

    foreach ($rows as $row) {
        printf(
            "    %-24s %4d sale(s)  %s\n",
            (string) ($row->{$column} ?? '—'),
            (int) $row->n,
            App\Support\Money::fromMinor((int) $row->profit)->format()
        );
    }
}

echo "\nColumns available on transactions for grouping:\n";

$columns = array_map(
    fn ($c) => $c->Field,
    DB::select('SHOW COLUMNS FROM transactions')
);

echo '  ' . implode(', ', $columns) . "\n";
