<?php

/**
 * Print the settings that decide how mail, queue and cache behave.
 *
 * Read-only, and deliberately narrow: this is the handful of values that explain
 * why a notification was sent twice, not at all, or only recorded on one channel.
 *
 * Usage: php tools/env-report.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$report = [
    'APP_ENV' => app()->environment(),
    'APP_URL' => config('app.url'),
    'queue.default' => config('queue.default'),
    'queue.connections.sync.after_commit' => config('queue.connections.sync.after_commit'),
    'queue.connections.database.after_commit' => config('queue.connections.database.after_commit'),
    'mail.default' => config('mail.default'),
    'mail.mailers.smtp.host' => config('mail.mailers.smtp.host'),
    'mail.mailers.smtp.port' => config('mail.mailers.smtp.port'),
    'mail.from.address' => config('mail.from.address'),
    'cache.default' => config('cache.default'),
    'paystack.key mode' => str_starts_with((string) config('services.paystack.secret_key'), 'sk_test_')
        ? 'TEST'
        : (str_starts_with((string) config('services.paystack.secret_key'), 'sk_live_') ? 'LIVE' : 'unset'),
    'paystack.dva_bank' => config('services.paystack.dva_bank'),
    'paystack.assume_live_dva' => config('services.paystack.assume_live_dva') ? 'true' : 'false',
    'wallet.funding_fee' => json_encode(config('wallet.funding_fee')),
    'scheduler heartbeat' => optional(\Illuminate\Support\Facades\Cache::get('scheduler:last_run'))->toDateTimeString() ?? 'never',
];

$width = max(array_map('strlen', array_keys($report)));

foreach ($report as $key => $value) {
    printf("%-{$width}s  %s\n", $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
}
