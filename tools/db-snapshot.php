<?php

/**
 * Print a small, read-only snapshot of the live database.
 *
 * Written for the "does this deployment already have the columns the new code
 * needs?" question, which is otherwise answered by opening a MySQL client and
 * guessing at credentials. Read-only on purpose — it must be safe to run against
 * production to check a deploy.
 *
 * Usage: php tools/db-snapshot.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Transactions;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

$report = [];

foreach ([
    'users' => ['name', 'first_name', 'last_name', 'phone', 'paystack_customer_code', 'dva_account_number', 'dva_bank_name', 'dva_account_name', 'dva_created_at', 'dva_account_id', 'dva_bank_slug', 'paystack_details_synced_at'],
    'transactions' => ['status', 'service_type', 'payment_method', 'meta', 'completed_at'],
] as $table => $columns) {
    foreach ($columns as $column) {
        $report[$table . '.' . $column] = Schema::hasColumn($table, $column) ? 'yes' : 'NO';
    }
}

$report['notifications table'] = Schema::hasTable('notifications') ? 'yes' : 'NO';
$report['users'] = (string) User::count();
$report['transactions'] = (string) Transactions::count();
$report['funding rows settled'] = (string) Transactions::where('service_type', 'funding')->where('status', 'success')->count();
$report['receipts already stamped'] = (string) Transactions::whereNotNull('meta->receipt_sent_at')->count();

$width = max(array_map('strlen', array_keys($report)));

foreach ($report as $key => $value) {
    printf("%-{$width}s  %s\n", $key, $value);
}
