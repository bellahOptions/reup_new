<?php

namespace App\Console\Commands;

use App\Models\Transactions;
use App\Services\PaymentStatusResolver;
use App\Support\Money;
use App\Support\RefreshStatusResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolve every pending transaction by asking whoever settles it.
 *
 * ## Why this exists alongside `payments:reconcile`
 *
 * `payments:reconcile` is Paystack's sweep: it polls card funding and ages out
 * an attempt that never reached checkout. It cannot touch a bill purchase,
 * because Paystack has never heard of one — and it deliberately excludes them so
 * a "not found" from the wrong authority can never be read as a failure.
 *
 * The consequence was that nothing swept the *whole* set. Airtime, data, cable
 * and electricity rows sat at `pending`/`processing`/`unknown` until an hourly
 * review command looked at the `unknown` ones, and an operator had to press
 * "Refresh status" one row at a time for anything else. Meanwhile the customer
 * sees a pending charge and no answer.
 *
 * This command closes that gap by using the same decision the admin console's
 * refresh button uses — {@see PaymentStatusResolver} — across every open row, so
 * "what is actually happening with this transaction?" has one implementation and
 * one answer, whether it is asked by a human about one row or by a schedule
 * about all of them.
 *
 * ## The rules it inherits, and why they are not negotiable here
 *
 * Each of these is enforced inside the resolver and the services it delegates
 * to, not in this command. They are listed because a sweep that broke one of
 * them would be a money-losing bug that no status code would reveal:
 *
 *   * **An unreachable gateway is not a verdict.** Nothing is written. The row
 *     is retried on the next tick. Writing off a live payment because a provider
 *     was briefly down is the single worst thing this command could do.
 *   * **A bill purchase is only refunded on the provider's word.** `pending` or
 *     "cannot say" leaves the money debited and the row open, because the order
 *     may have been vended.
 *   * **Settlement goes through one path.** `PaystackService::settle()` and
 *     `BillPaymentService::markSuccess()` are idempotent and amount-checked, so
 *     running this every two minutes, or concurrently with a webhook, cannot
 *     double-credit.
 *   * **A bank transfer is never guessed at.** A dedicated virtual account has
 *     no per-transaction lookup; the inbound transfer is matched by the
 *     gateway's webhook, so these rows are reported as not queryable and left
 *     alone.
 *
 * ## Safety
 *
 *   * `--dry-run` asks every question the real run would ask and writes nothing.
 *     It re-reads each row afterwards and shouts if the database disagrees with
 *     that promise, so the guarantee is checked rather than asserted.
 *   * `--grace` (default 1 minute) leaves a brand-new attempt to its own webhook
 *     before the sweep starts second-guessing it.
 *   * `--limit` bounds one run's API traffic.
 *   * A row that throws is logged and skipped; the exit code reports it. One
 *     malformed row cannot stop the sweep from helping everyone else.
 */
class AutoResolvePendingTransactions extends Command
{
    protected $signature = 'payments:auto-resolve
                            {--limit=100 : Maximum transactions to examine in one run}
                            {--grace=1 : Skip transactions younger than this many minutes, so a webhook can win first}
                            {--type=* : Restrict to these service types (e.g. --type=funding --type=airtime)}
                            {--dry-run : Ask the same questions but write nothing}';

    protected $description = 'Resolve all pending transactions by querying the gateway or provider that settles them';

    /**
     * Statuses that are still open.
     *
     * `unknown` is included and is the most important member of the set: it is
     * the state a bill purchase lands in when the provider call timed out, and
     * it is the one a customer cannot distinguish from a charge that vanished.
     * `verifying` is excluded on purpose — that is a bank-transfer proof waiting
     * for an administrator, and no gateway has an opinion about it.
     */
    private const OPEN_STATUSES = ['pending', 'processing', 'unknown'];

    public function handle(PaymentStatusResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $grace = max(0, (int) $this->option('grace'));

        /** @var array<int,string> $types */
        $types = array_values(array_filter((array) $this->option('type'), fn ($type) => is_string($type) && $type !== ''));

        $candidates = $this->candidates($limit, $grace, $types);

        if ($candidates->isEmpty()) {
            $this->info(($dryRun ? '[dry run] ' : '') . 'No pending transactions to resolve.');

            return self::SUCCESS;
        }

        $this->info(
            ($dryRun ? '[dry run] ' : '')
            . 'Examining ' . $candidates->count() . ' pending transaction(s)'
            . ($types ? ' of type ' . implode(', ', $types) : '')
            . ($grace > 0 ? ", ignoring anything younger than {$grace} minute(s)" : '')
            . '...'
        );

        $counts = [
            RefreshStatusResult::OUTCOME_SETTLED => 0,
            RefreshStatusResult::OUTCOME_FAILED => 0,
            RefreshStatusResult::OUTCOME_PENDING => 0,
            RefreshStatusResult::OUTCOME_FINAL => 0,
            RefreshStatusResult::OUTCOME_UNREACHABLE => 0,
            RefreshStatusResult::OUTCOME_CONFIG_ERROR => 0,
        ];

        $refunded = 0;
        $failed = 0;

        /*
         * How many rows this run actually wrote to. `settled` and `failed` are
         * the only two outcomes that change a row; everything else is a real
         * answer of "leave it alone", and counting those as resolved would make
         * the summary claim work that did not happen.
         */
        $written = 0;

        foreach ($candidates as $transaction) {
            $before = $this->fingerprint($transaction);

            try {
                $result = $dryRun
                    ? $resolver->preview($transaction)
                    : $resolver->refresh($transaction, 0);
            } catch (Throwable $e) {
                $failed++;

                Log::error('Auto-resolve could not process a transaction', [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'service_type' => $transaction->service_type,
                    'status' => $transaction->status,
                    'error' => $e->getMessage(),
                ]);

                $this->line("  <fg=red>!</> {$transaction->reference} — could not be resolved: {$e->getMessage()}");

                continue;
            }

            $counts[$result->outcome] = ($counts[$result->outcome] ?? 0) + 1;

            if ($result->changed()) {
                $written++;
            }

            /*
             * One thing the outcome vocabulary cannot express on its own, and
             * that an operator scanning the log needs: a resolved *bill* purchase
             * that failed has had the customer's money put back, so the refund is
             * the event, not the failure. A failed *funding* attempt moved no
             * money at all, and reporting it as a refund would be alarming and
             * wrong.
             */
            if ($result->outcome === RefreshStatusResult::OUTCOME_FAILED
                && $transaction->service_type !== 'funding'
                && $result->paymentStatus === 'refunded') {
                $refunded++;
            }

            if ($dryRun) {
                $drift = $this->fingerprint($transaction->fresh() ?? $transaction) !== $before;

                if ($drift) {
                    // This should be impossible. If it ever happens, the promise
                    // that `--dry-run` changes nothing is broken and somebody
                    // must know immediately.
                    $failed++;

                    Log::critical('SECURITY/FINANCE: a dry-run sweep modified a transaction', [
                        'event' => 'dry_run_mutation',
                        'transaction_id' => $transaction->id,
                        'reference' => $transaction->reference,
                        'before' => $before,
                        'after' => $this->fingerprint($transaction->fresh() ?? $transaction),
                    ]);

                    $this->line("  <fg=red>!</> {$transaction->reference} — CHANGED during a dry run; see the log");
                    continue;
                }
            }

            $this->report($transaction, $result, $dryRun);
        }

        $this->newLine();

        $this->table(
            ['Settled', 'Failed', 'Still pending', 'Not queryable', 'Unreachable', 'Config error', 'Errors'],
            [[
                $counts[RefreshStatusResult::OUTCOME_SETTLED],
                $counts[RefreshStatusResult::OUTCOME_FAILED],
                $counts[RefreshStatusResult::OUTCOME_PENDING],
                $counts[RefreshStatusResult::OUTCOME_FINAL],
                $counts[RefreshStatusResult::OUTCOME_UNREACHABLE],
                $counts[RefreshStatusResult::OUTCOME_CONFIG_ERROR],
                $failed,
            ]]
        );

        $this->line(sprintf(
            '  %s %d of %d row(s).',
            $dryRun ? 'Would change' : 'Changed',
            $written,
            $candidates->count()
        ));

        if ($refunded > 0) {
            $this->warn("  {$refunded} failed purchase(s) were refunded to the customer.");
        }

        /*
         * Only a real change is logged at `info`. A sweep that logs "nothing
         * happened" every two minutes buries the runs that did something, and
         * this log is how an operator reconstructs why a wallet moved.
         */
        if (! $dryRun && ($counts[RefreshStatusResult::OUTCOME_SETTLED] > 0 || $counts[RefreshStatusResult::OUTCOME_FAILED] > 0)) {
            Log::info('Auto-resolve sweep resolved pending transactions', [
                'examined' => $candidates->count(),
                'settled' => $counts[RefreshStatusResult::OUTCOME_SETTLED],
                'failed' => $counts[RefreshStatusResult::OUTCOME_FAILED],
                'refunded' => $refunded,
                'still_pending' => $counts[RefreshStatusResult::OUTCOME_PENDING],
                'unreachable' => $counts[RefreshStatusResult::OUTCOME_UNREACHABLE],
            ]);
        }

        /*
         * A non-zero exit for a row this command could not process at all, so a
         * monitoring probe or a deploy check can see it. "Nothing to resolve" and
         * "an unreachable provider" are both success: neither is a fault in this
         * command, and treating them as one would make the exit code useless.
         */
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every open transaction, oldest first.
     *
     * Oldest first matters when `--limit` truncates: the rows that have been
     * waiting longest are the ones a customer has been staring at, and they are
     * also the ones nearest the point where a provider's status query stops being
     * able to answer at all.
     */
    private function candidates(int $limit, int $grace, array $types)
    {
        $query = Transactions::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('created_at');

        if ($grace > 0) {
            $query->where('created_at', '<=', now()->subMinutes($grace));
        }

        if ($types !== []) {
            $query->whereIn('service_type', $types);
        }

        return $query->limit($limit)->get();
    }

    /**
     * The parts of a row this command may change.
     *
     * Used only by `--dry-run`, to prove after the fact that nothing moved. The
     * balance columns are included because a settlement writes them, and
     * `payment_status` is separate from `status` because a refund moves one
     * without the other.
     */
    private function fingerprint(Transactions $transaction): string
    {
        return implode('|', [
            (string) $transaction->status,
            (string) $transaction->payment_status,
            (string) $transaction->balance_before,
            (string) $transaction->balance_after,
            (string) ($transaction->completed_at?->toDateTimeString() ?? ''),
        ]);
    }

    private function report(Transactions $transaction, RefreshStatusResult $result, bool $dryRun): void
    {
        $colour = match ($result->outcome) {
            RefreshStatusResult::OUTCOME_SETTLED => 'green',
            RefreshStatusResult::OUTCOME_FAILED => 'red',
            RefreshStatusResult::OUTCOME_UNREACHABLE, RefreshStatusResult::OUTCOME_CONFIG_ERROR => 'yellow',
            default => 'gray',
        };

        $glyph = match ($result->outcome) {
            RefreshStatusResult::OUTCOME_SETTLED => '+',
            RefreshStatusResult::OUTCOME_FAILED => 'x',
            RefreshStatusResult::OUTCOME_UNREACHABLE, RefreshStatusResult::OUTCOME_CONFIG_ERROR => '?',
            default => '-',
        };

        $this->line(sprintf(
            '  <fg=%s>%s</> %s — %s',
            $colour,
            $glyph,
            $transaction->reference,
            $result->message
        ));

        /*
         * In a dry run the amount is printed separately, because the message
         * says what *would* happen and an operator reconciling against a bank
         * statement wants the figure next to the reference rather than parsed
         * out of prose.
         */
        if ($dryRun && $result->outcome === RefreshStatusResult::OUTCOME_SETTLED) {
            $this->line('      amount: ' . Money::fromDatabase($transaction->amount)->format()
                . ' · customer #' . $transaction->user_id);
        }
    }
}
