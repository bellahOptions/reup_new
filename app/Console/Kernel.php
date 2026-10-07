<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

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
         * from cron/Task Scheduler, or `php artisan schedule:work` in
         * development. Without one this never executes and pending payments
         * stay pending — see docs/RUNNING.md.
         */
        $schedule->command('payments:reconcile')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();
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
