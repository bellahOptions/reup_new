<?php

namespace App\Console\Commands;

use App\Lifecycle\LifecycleScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The lifecycle tick: turn what is due into queued jobs.
 *
 * Every minute, from the scheduler. It does no sending — see `LifecycleScheduler` for
 * why — and it is idempotent, so overlapping ticks and a tick after a crash cannot
 * double-send.
 *
 * ## Locking
 *
 * `withoutOverlapping()` on the schedule entry covers the normal case, but the lock
 * there is the scheduler's, and this command can also be run by hand or by a
 * monitoring system. The lock here is the one that actually protects the work, and it
 * is short — a tick that cannot acquire it returns immediately rather than queueing
 * behind the running one, because the next tick is only a minute away and a backlog of
 * waiting ticks would be worse than a skipped one.
 *
 * `Cache::lock` is used for the *tick*, which is a scheduling concern. It is
 * deliberately not used for message idempotency, which is a database constraint —
 * a cache lock expires, is per-store, and two application servers with separate caches
 * both acquire it.
 */
class ProcessLifecycle extends Command
{
    protected $signature = 'lifecycle:process
                            {--batch=200 : Maximum rows to move per stage in one tick}
                            {--dry-run : Report what is due and change nothing}';

    protected $description = 'Process due customer lifecycle messages and enqueue them for sending';

    public function handle(LifecycleScheduler $scheduler): int
    {
        $batch = max(1, (int) $this->option('batch'));

        if ($this->option('dry-run')) {
            $snapshot = $scheduler->snapshot();

            $this->info('[dry run] Lifecycle state:');

            foreach ($snapshot as $key => $value) {
                $this->line(sprintf('  %-22s %s', str_replace('_', ' ', $key), number_format($value)));
            }

            return self::SUCCESS;
        }

        $lock = Cache::lock('lifecycle:process', 120);

        if (! $lock->get()) {
            // Another tick is running. The next one is a minute away; waiting would
            // build a backlog of ticks competing for the same rows.
            return self::SUCCESS;
        }

        try {
            $result = $scheduler->run($batch);
        } finally {
            $lock->release();
        }

        $total = array_sum($result);

        // Silent when there is nothing to do, which is almost every tick. A command
        // that logs "nothing happened" 1,440 times a day buries the runs that matter.
        if ($total > 0) {
            Log::info('Lifecycle tick', $result);
        }

        if ($this->output->isVerbose()) {
            $this->table(
                ['Events processed', 'Queued', 'Released', 'Inactive events'],
                [[$result['events'], $result['queued'], $result['released'], $result['inactive_events']]]
            );
        }

        return self::SUCCESS;
    }
}
