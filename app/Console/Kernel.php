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
         * Resolve every other pending transaction.
         *
         * `payments:reconcile` above is Paystack's sweep, and it is deliberately
         * narrow: it may only touch rows where Paystack is the authority, because
         * asking Paystack about a bill purchase returns "not found" and reading
         * that as a verdict would close out a delivered order. So nothing swept
         * the whole set — an airtime or electricity row could sit at `pending`
         * or `unknown` indefinitely, and the customer sees a charge with no
         * answer.
         *
         * This command uses the same decision the console's "Refresh status"
         * button uses (`App\Services\PaymentStatusResolver`), applied to every
         * open row: Paystack and Bachs funding, and provider-backed purchases
         * whose outcome is `unknown`.
         *
         * ## Why every two minutes, and not every minute
         *
         * Every row here costs a request to a third party, and a bill purchase
         * costs one to the vending provider, whose rate limit is shared with
         * real sales. Two minutes is frequent enough that a customer waiting on a
         * pending purchase sees it resolve while they are still on the page, and
         * half the traffic of a per-minute sweep.
         *
         * The command's `--grace` (default one minute) is what keeps this from
         * racing the webhook: a row younger than that is skipped entirely, so the
         * primary settlement path gets its chance first. This is still the
         * fallback, not the path a payment is expected to take.
         *
         * `withoutOverlapping` matters more here than anywhere else in this
         * schedule — two concurrent sweeps would both query every provider, and
         * a provider that hangs would be probed twice as hard exactly when it is
         * least able to answer.
         *
         * Runs in the background so a slow provider cannot make the scheduler
         * tick late and delay everything after it.
         */
        $schedule->command('payments:auto-resolve')
            ->everyTwoMinutes()
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
         * Bill reminders.
         *
         * A reminder notifies; it never debits. `reminders:dispatch` writes a
         * notification row and nothing else — AutoPay is a separate future feature
         * that will need its own customer-consent record and its own command.
         *
         * Every five minutes rather than every minute: a reminder is a courtesy,
         * not a settlement, and a five-minute window is imperceptible to a
         * customer while being five times less work.
         *
         * Duplicate prevention does not depend on `withoutOverlapping` — the
         * command claims each reminder for its due date before writing — so a
         * missed or overlapping run cannot produce two notifications.
         */
        $schedule->command('reminders:dispatch')
            ->everyFiveMinutes()
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

        /*
         * Provider health and float.
         *
         * Every five minutes. This is the control that makes "we never charge a
         * customer for a vend the upstream cannot fund" checkable before the fact
         * rather than discovered afterwards — and five minutes is a compromise:
         * frequent enough to notice a wallet running dry during a busy morning,
         * infrequent enough not to spend the provider's rate limit on monitoring.
         *
         * `withoutOverlapping` matters here because a provider that hangs would
         * otherwise have two probes in flight, and a slow upstream would be probed
         * twice as hard exactly when it is least able to answer.
         */
        $schedule->command('providers:check-balances')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        /*
         * Catalogue and cost refresh.
         *
         * Hourly. Costs move on the provider's schedule, not ours, and an hour of
         * staleness is a bounded exposure: the pricing engine records the cost it
         * quoted with, so a stale cost produces a smaller margin on orders placed
         * in that hour rather than an unexplained discrepancy later.
         *
         * Not every minute: a full sync is dozens to hundreds of upstream reads and
         * would be the single largest consumer of every provider's rate limit.
         */
        $schedule->command('providers:sync-catalogues')
            ->hourly()
            ->withoutOverlapping();

        /*
         * International catalogue.
         *
         * Every six hours rather than hourly, because it is the expensive one — a
         * country → product type → operator → variation walk against a rate-limited
         * API — and because international rates move far more slowly than the
         * catalogue around them. An order is priced from a live FX preview at
         * checkout, so a six-hour-old catalogue row never becomes the rate a
         * customer is charged.
         */
        $schedule->command('providers:sync-international-products')
            ->everySixHours()
            ->withoutOverlapping();

        /*
         * Gift cards daily.
         *
         * A gift card catalogue changes when a vendor changes its discount, which is
         * a deliberate act rather than a market movement — and every synced variant
         * needs an operator to map it before it can be sold, so syncing more often
         * would only queue work faster than it can be done.
         */
        $schedule->command('providers:sync-gift-cards')
            ->daily()
            ->withoutOverlapping();

        /*
         * Retention.
         *
         * Daily, and never on a schedule that could overlap the probe that is
         * writing to the same table. The deletion is chunked for that reason too.
         */
        $schedule->command('providers:prune-health')
            ->daily()
            ->withoutOverlapping();
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
