<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        /*
         * Resolve pending Paystack payments.
         *
         * Runs every minute so a payment is picked up within ~10 minutes of
         * being made (the command only polls rows older than 10 minutes, which
         * gives the webhook its chance first — this is the fallback, not the
         * primary path). Overlapping is prevented so a slow gateway cannot cause
         * two runs to poll the same rows.
         *
         * REQUIRES a scheduler runner: `php artisan schedule:run` every minute
         * from cron/Task Scheduler, or `php artisan schedule:work` under a
         * supervisor or as the container command. Without one this never
         * executes and pending payments stay pending — see docs/SCHEDULER.md.
         *
         * `schedule:health --check` exists to make that failure loud rather than
         * silent. Run it after every deploy.
         */
        $schedule->command('payments:reconcile')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        /*
         * Flag purchases whose provider outcome is still unknown.
         *
         * A purchase whose provider call timed out is deliberately left in the
         * `unknown` state rather than refunded, because the order may have been
         * vended. That is the right call, and it is also a state that will sit
         * there forever if nobody looks at it — so it is reported on a schedule.
         */
        $schedule->command('payments:review-unconfirmed')
            ->hourly()
            ->withoutOverlapping();

        /*
         * Heartbeat.
         *
         * Written on every scheduler tick. `schedule:health --check` reads it, so
         * "is the scheduler running" becomes a question with an answer instead of
         * an assumption. This is the entry that makes the whole schedule
         * verifiable, which is why it is listed even though it does no work.
         */
        $schedule->call(function () {
            Cache::put('scheduler:last_run', now(), now()->addHours(6));
        })->everyMinute()->name('scheduler-heartbeat');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
