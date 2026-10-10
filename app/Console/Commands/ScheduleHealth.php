<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Prove that the scheduler is actually running.
 *
 * ## The problem this solves
 *
 * `payments:reconcile` is the safety net for payments the Paystack webhook never
 * confirmed. It is registered in the schedule — and that registration does
 * nothing at all unless something invokes `schedule:run` every minute (a cron
 * entry, `schedule:work` under a supervisor, or `schedule:work` as a container
 * entrypoint).
 *
 * Nothing about the application fails when that is missing. Payments stay
 * `pending`, no error is logged, no page breaks, and the first symptom is a
 * customer saying they paid and were not credited. That is a silent failure with
 * a financial consequence, so it needs an explicit check.
 *
 * `schedule:run` invokes this command every minute as a heartbeat. `--check`
 * then verifies the heartbeat is recent, and exits non-zero if it is not — which
 * is what makes this usable as a deploy gate, a monitoring probe, or a health
 * check endpoint.
 *
 * ## Usage
 *
 * ```
 * php artisan schedule:health            # report
 * php artisan schedule:health --check    # exit 1 when the scheduler is not running
 * ```
 */
class ScheduleHealth extends Command
{
    /**
     * The cache key `schedule:run` refreshes through the heartbeat entry.
     *
     * Deliberately the same key the scheduler itself writes, so this command
     * measures the real thing rather than a parallel counter that could drift.
     */
    private const HEARTBEAT_KEY = 'scheduler:last_run';

    /**
     * How long a heartbeat stays believable.
     *
     * Two missed minutes, so one slow run or one busy minute does not raise a
     * false alarm, but a scheduler that has genuinely stopped is caught inside
     * five minutes.
     */
    private const STALE_AFTER_SECONDS = 150;

    protected $signature = 'schedule:health
                            {--check : Exit non-zero when the scheduler is not running}';

    protected $description = 'Report whether the task scheduler is actually running (proves payments:reconcile executes)';

    public function handle(): int
    {
        $lastRun = Cache::get(self::HEARTBEAT_KEY);
        $age = $lastRun ? now()->diffInSeconds($lastRun, false) : null;

        $running = $age !== null && $age >= 0 && $age < self::STALE_AFTER_SECONDS;

        $this->line('Scheduler heartbeat: ' . ($lastRun ? $lastRun->toDateTimeString() : 'never'));

        if ($lastRun) {
            $this->line('Last run: ' . $age . ' second(s) ago');
        }

        if ($running) {
            $this->info(
                'The scheduler is running. payments:reconcile is executing every minute and '
                . 'payments:auto-resolve every two minutes.'
            );

            return self::SUCCESS;
        }

        $this->error(
            'The scheduler is NOT running. Pending payments will never be reconciled and no pending '
            . 'transaction will be resolved, because `schedule:run` is not being invoked every minute.'
        );

        $this->newLine();
        $this->line('Fix it with one of:');
        $this->line('  cron (Linux):         * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line('  supervisor (Linux):   command=php ' . base_path() . '/artisan schedule:work');
        $this->line('  Task Scheduler (Win): schtasks /create /tn "ReUp Scheduler" /sc minute /mo 1 '
            . '/tr "php ' . base_path() . '\\artisan schedule:run"');
        $this->line('  container:            php artisan schedule:work  (as the container command)');

        return $this->option('check') ? self::FAILURE : self::SUCCESS;
    }
}
