<?php

namespace App\Console\Commands;

use App\Models\Transactions;
use App\Notifications\FundingReceiptNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send a receipt for every funding that succeeded and has not been receipted.
 *
 * ## Why a sweep, and not a call at settlement
 *
 * The credit itself happens inside `WalletService`, under a row lock, on three
 * different paths — the browser callback, the gateway webhook, and the
 * two-minute `payments:auto-resolve` fallback. Every one of them is a place a
 * "send the receipt" line could be added, and every one of them is a place where
 * a mail server that is down for thirty seconds could take the *credit* down with
 * it. Worse, a fourth settlement path added later would silently not send
 * receipts, and nothing would notice.
 *
 * A sweep separates the two concerns completely: settlement stays exactly as it
 * is, and "did the customer get a receipt?" becomes a question about rows that
 * can be asked as often as anyone likes — after a deploy, after a mail outage,
 * after a queue backlog. It also makes the answer checkable before the fact with
 * `--dry-run`.
 *
 * ## Idempotency
 *
 * `transactions.meta.receipt_sent_at` is the claim. A row is only receipted if
 * it is not already stamped, and the stamp is written **after** the notification
 * is dispatched. That ordering matters: dispatching is the thing that can fail,
 * so stamping first would record a receipt that never went out and remove the row
 * from every future sweep — the customer's one chance, silently spent.
 *
 * The reverse ordering is not perfectly atomic: a crash between the two would
 * send one duplicate on the next run. That is the correct side to fail on. Two
 * receipts is a mild annoyance for one customer; no receipt, permanently, for a
 * customer who has no way to know it was supposed to arrive, is the failure that
 * generates the support ticket.
 *
 * ## The look-back window
 *
 * `--days` (default 7) bounds what the sweep examines, and it is not only about
 * cost. This command is deployed onto an existing database with settled funding
 * rows that were never receipted, because the feature did not exist. Without a
 * window, the first run after a deploy would send a receipt for every piece of
 * funding in the history — a customer who funded in March getting a receipt in
 * October, which reads as either fraud or a system that has lost track of time.
 * Seven days is long enough to cover a weekend outage and short enough to be
 * unremarkable. Raise it deliberately when back-filling.
 */
class SendFundingReceipts extends Command
{
    protected $signature = 'payments:send-funding-receipts
                            {--limit=100 : Maximum receipts to send in one run}
                            {--days=7 : Only consider funding settled in the last this many days}
                            {--grace=1 : Skip funding younger than this many minutes, so the credit can settle first}
                            {--dry-run : List what would be sent and write nothing}';

    protected $description = 'Send a receipt (email and in-app notification) for every successful wallet funding that has not had one';

    /** The `meta` key that marks a funding row as receipted. */
    public const META_KEY = 'receipt_sent_at';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));
        $grace = max(0, (int) $this->option('grace'));

        $rows = Transactions::query()
            ->with('user')
            ->where('service_type', 'funding')
            ->where('status', 'success')
            ->where('type', 'credit')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($grace > 0, fn ($query) => $query->where('created_at', '<=', now()->subMinutes($grace)))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        /*
         * The "already receipted" test is done in PHP on the decoded `meta`
         * column rather than as a `whereJsonDoesntContain` clause in SQL. Two
         * reasons, and the second is the important one: the JSON path syntax
         * differs between MySQL and SQLite, and a query that silently matches
         * nothing on one of them would mark the whole backlog as unreceipted and
         * mail every customer at once.
         */
        $pending = $rows->filter(fn (Transactions $row) => ! $this->alreadyReceipted($row));

        $skipped = $rows->count() - $pending->count();

        if ($pending->isEmpty()) {
            $this->info(
                ($dryRun ? '[dry run] ' : '')
                . 'No funding to receipt'
                . ($skipped > 0 ? " ({$skipped} already receipted)." : '.')
            );

            return self::SUCCESS;
        }

        $this->info(
            ($dryRun ? '[dry run] ' : '')
            . 'Receipting ' . $pending->count() . ' funding transaction(s)'
            . ($skipped > 0 ? ", {$skipped} already receipted" : '')
            . '...'
        );

        $sent = 0;
        $errors = 0;

        foreach ($pending as $transaction) {
            if ($dryRun) {
                $this->line(sprintf(
                    '  <fg=gray>would send</> %s — %s to %s',
                    $transaction->reference,
                    $this->naira($transaction),
                    $transaction->user?->email ?? '(no customer)'
                ));

                continue;
            }

            try {
                $this->receipt($transaction);
                $sent++;
            } catch (Throwable $e) {
                $errors++;

                /*
                 * One bad row must not stop the sweep. The customer concerned is
                 * recorded with the error, the row is left unstamped so the next
                 * run tries again, and the exit code reports that something was
                 * wrong.
                 */
                Log::error('Funding receipt could not be sent', [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'user_id' => $transaction->user_id,
                    'error' => $e->getMessage(),
                ]);

                $this->line("  <fg=red>!</> {$transaction->reference} — {$e->getMessage()}");

                continue;
            }

            $this->line(sprintf(
                '  <fg=green>+</> %s — %s to %s',
                $transaction->reference,
                $this->naira($transaction),
                $transaction->user?->email ?? '(no customer)'
            ));
        }

        $this->newLine();

        $this->table(
            ['Would send', 'Sent', 'Errors', 'Already receipted'],
            [[$dryRun ? $pending->count() : 0, $sent, $errors, $skipped]]
        );

        /*
         * Logged only when something actually happened. A sweep that runs every
         * five minutes and logs "nothing to do" 288 times a day buries the runs
         * that did something, and this log is how an operator answers "did that
         * customer get their receipt?" without opening the database.
         */
        if (! $dryRun && $sent > 0) {
            Log::info('Funding receipts sent', [
                'sent' => $sent,
                'errors' => $errors,
                'already_receipted' => $skipped,
            ]);
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Dispatch the receipt and stamp the row.
     *
     * The stamp is written last and with `forceFill`, because `meta` is a JSON
     * column the model casts to an array and the funding path has already written
     * other keys into it (`channel`, `gateway`, `funding_fee`, …). Merging rather
     * than replacing is what keeps those: a `update(['meta' => [...]])` here would
     * silently drop the fee audit trail.
     */
    private function receipt(Transactions $transaction): void
    {
        $user = $transaction->user;

        if (! $user) {
            throw new \RuntimeException('Funding transaction ' . $transaction->id . ' has no customer to receipt.');
        }

        $user->notify(new FundingReceiptNotification($transaction));

        $transaction->forceFill([
            'meta' => array_merge($transaction->meta ?? [], [
                self::META_KEY => now()->toDateTimeString(),
            ]),
        ])->save();
    }

    /** Has this funding row already been receipted? */
    private function alreadyReceipted(Transactions $transaction): bool
    {
        return filled(($transaction->meta ?? [])[self::META_KEY] ?? null);
    }

    /**
     * The amount, for the console.
     *
     * The currency symbol is written as an escape rather than as a literal naira
     * sign. It is two bytes of UTF-8, and a file that passes through an editor or
     * a shell redirect that is not configured for UTF-8 turns it into `â‚¦` — in
     * the one string an operator reads to confirm which customer was mailed.
     * `\u{20A6}` names the character and cannot be mangled by an encoding
     * setting.
     */
    private function naira(Transactions $transaction): string
    {
        return "\u{20A6}" . number_format((float) $transaction->total_amount, 2);
    }
}
