<?php

namespace App\Console\Commands;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Report purchases whose provider outcome is still unknown.
 *
 * ## Why this command has to exist
 *
 * When a provider call times out, ReUp genuinely does not know whether the
 * customer was vended. `BillPaymentService` therefore marks the row `unknown`,
 * keeps the money debited, and does **not** retry, fail over or refund — because
 * every one of those actions is wrong if the order did in fact vend.
 *
 * That is the correct decision, and it leaves a row that is nobody's job to
 * resolve unless something says so. This command is that something: it lists
 * every purchase still `unknown`, and asks the provider directly (when the
 * adapter supports a status query) whether the order completed.
 *
 * ## What it does NOT do
 *
 * It never marks an unconfirmed purchase as failed on the strength of a failed
 * query, and it never refunds one. A refund only happens when the provider
 * affirmatively says the order did not complete. Anything else is reported for a
 * human, because the alternative is giving away goods.
 *
 * ## Usage
 *
 * ```
 * php artisan payments:review-unconfirmed            # query providers, resolve what we can
 * php artisan payments:review-unconfirmed --dry-run  # report only
 * php artisan payments:review-unconfirmed --list     # list without querying providers
 * ```
 */
class ReviewUnconfirmedPurchases extends Command
{
    protected $signature = 'payments:review-unconfirmed
                            {--dry-run : Report what would change without writing}
                            {--list : List unconfirmed purchases without querying any provider}
                            {--age=15 : Only consider purchases older than this many minutes}
                            {--limit=100 : Maximum purchases to inspect}';

    protected $description = 'Resolve, or report, purchases whose provider outcome is unknown';

    public function handle(BillPaymentService $bills): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $listOnly = (bool) $this->option('list');
        $age = max(0, (int) $this->option('age'));

        $unconfirmed = Transactions::query()
            ->where('status', 'unknown')
            ->where('created_at', '<=', now()->subMinutes($age))
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($unconfirmed->isEmpty()) {
            $this->info('No unconfirmed purchases.');

            return self::SUCCESS;
        }

        $this->warn($unconfirmed->count() . ' purchase(s) with an unknown provider outcome:');
        $this->newLine();

        $resolved = 0;
        $stillUnknown = 0;

        foreach ($unconfirmed as $transaction) {
            $amount = Money::fromDatabase($transaction->total_amount);

            $this->line(sprintf(
                '  %s  %s  %s  %s  (%s)',
                $transaction->reference,
                $amount->format(),
                $transaction->service_type,
                $transaction->provider ?: 'unknown provider',
                $transaction->created_at->diffForHumans(),
            ));

            if ($listOnly || $dryRun) {
                $stillUnknown++;
                continue;
            }

            /*
             * A provider that vended is settled and the customer gets a receipt;
             * one that says it did not is refunded. A provider that cannot say
             * leaves the row exactly where it is.
             */
            $outcome = $bills->resolveUnknown($transaction, 0, confirmSuccess: false);

            if ($outcome['resolved']) {
                $resolved++;
                $this->info("    -> resolved as {$outcome['verdict']}");
            } else {
                $stillUnknown++;
                $this->line("    -> still unconfirmed ({$outcome['verdict']})");
            }
        }

        $this->newLine();
        $this->table(['Resolved', 'Still unconfirmed'], [[$resolved, $stillUnknown]]);

        if ($stillUnknown > 0) {
            /*
             * Logged at `error` because each of these is money in limbo. It is
             * the signal for the "unconfirmed purchases" review in the admin
             * console, and for whoever is on call.
             */
            Log::error('Purchases remain unconfirmed and need manual review', [
                'event' => 'unconfirmed_purchases_remain',
                'count' => $stillUnknown,
                'references' => $unconfirmed->take(50)->pluck('reference')->all(),
            ]);

            $this->warn('These need a human: check the provider dashboard for each reference, then resolve it in the admin console.');
        }

        return self::SUCCESS;
    }
}
