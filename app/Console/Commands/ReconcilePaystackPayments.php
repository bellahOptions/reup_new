<?php

namespace App\Console\Commands;

use App\Models\Transactions;
use App\Services\PaystackService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolve pending Paystack payments by asking Paystack directly.
 *
 * ## Why this exists
 *
 * This application is webhook-driven, and webhooks fail: Paystack gives up
 * retrying, an endpoint has a brief outage, a signature check rejects a valid
 * payload during a key rotation. When that happens the customer has paid but
 * the transaction sits `pending` forever and the wallet is never credited —
 * and because nothing polls, nothing ever notices. This is the safety net.
 *
 * ## What it does not do
 *
 * It only touches transactions where **Paystack is the authority**: card /
 * bank / USSD funding. It cannot reconcile bill purchases (airtime, data, cable,
 * electricity), because those are settled by ClubKonnect/Pairgate and Paystack
 * has never heard of them. Querying Paystack for a bill reference would return
 * "not found" and, if that were treated as a failure, would wrongly mark
 * successfully-delivered purchases as failed. That is why the query is scoped by
 * `payment_method = paystack` and not by status alone.
 *
 * ## Safety
 *
 * - Never writes `success` itself; it hands the verified payload to
 *   `PaystackService::settle()`, which is idempotent, locks the row and checks
 *   the amount. This command is therefore safe to run concurrently with the
 *   webhook.
 * - `--dry-run` reports without writing.
 * - Rows older than the lookback window are left alone and logged, rather than
 *   polled forever.
 */
class ReconcilePaystackPayments extends Command
{
    protected $signature = 'payments:reconcile
                            {--minutes=10 : Age in minutes after which a pending payment is polled}
                            {--lookback=1440 : Give up on payments older than this many minutes}
                            {--limit=50 : Maximum transactions to poll per run}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Resolve pending Paystack payments by verifying them against Paystack';

    public function handle(PaystackService $paystack): int
    {
        if (! $paystack->isConfigured()) {
            $this->warn('Paystack is not configured (PAYSTACK_SECRET_KEY is empty) — nothing to reconcile.');

            return self::SUCCESS;
        }

        $minAge = max(1, (int) $this->option('minutes'));
        $lookback = max($minAge + 1, (int) $this->option('lookback'));
        $dryRun = (bool) $this->option('dry-run');

        $counts = ['credited' => 0, 'failed' => 0, 'unchanged' => 0, 'unreachable' => 0];

        // Declared up front so the summary below cannot reference an undefined
        // variable when a branch is skipped.
        $candidates = collect();
        $stale = collect();

        /*
         * Age out anything still pending beyond the lookback window. Without
         * this, a payment that Paystack will never confirm — the customer closed
         * the checkout tab and no charge was ever attempted — stays "pending"
         * on the dashboard forever, which is worse than an honest failure: it
         * makes the customer think money is in flight.
         *
         * This is checked FIRST, and any row it claims is removed from the poll
         * set, so every pending row is covered by exactly one of the two
         * branches. Checking them as separate ranges previously left a gap: a
         * row older than the poll window but newer than the expiry threshold
         * matched neither condition and was silently ignored forever.
         *
         * Only rows the gateway has never heard of are expired — which is what
         * the lookback window buys: by default a payment gets a full day of
         * polling before it is written off, and a row the gateway knows about is
         * normally settled by the branch below long before it reaches here. The
         * status is therefore re-read under a lock and only a still-pending row
         * is expired, so a webhook arriving mid-run always wins.
         */
        $cutoff = now()->subMinutes($lookback);

        $stale = Transactions::query()
            ->where('payment_method', 'paystack')
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $transaction) {
            if ($dryRun) {
                $counts['failed']++;
                $this->line("  <fg=red>x</> {$transaction->reference} — would expire (older than {$lookback}m)");
                continue;
            }

            /*
             * Two guards, both inside a locked transaction:
             *   * the row must still be pending, so a webhook that arrived
             *     between the SELECT above and here wins;
             *   * `payment_status` must still be pending, so a settlement that
             *     moved it cannot be overwritten.
             */
            $expired = DB::transaction(function () use ($transaction) {
                $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->first();

                if (! $locked || $locked->status !== 'pending' || $locked->payment_status !== 'pending') {
                    return false;
                }

                $locked->forceFill([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                    'status_message' => 'Expired: the payment was never completed. No money left your account.',
                    'completed_at' => now(),
                ])->save();

                return true;
            });

            if ($expired) {
                $counts['failed']++;
                $this->line("  <fg=red>x</> {$transaction->reference} — expired (older than {$lookback}m)");
            } else {
                $counts['unchanged']++;
                $this->line("  <fg=gray>-</> {$transaction->reference} — settled while expiring; left alone");
            }
        }

        if ($stale->isNotEmpty()) {
            $this->newLine();
        }

        $candidates = Transactions::query()
            ->where('payment_method', 'paystack')
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<=', now()->subMinutes($minAge))
            ->where('created_at', '>=', $cutoff)
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info($stale->isEmpty()
                ? 'No pending Paystack payments to reconcile.'
                : 'No payments within the reconciliation window.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '') . 'Polling ' . $candidates->count() . ' pending payment(s)...');

        foreach ($candidates as $transaction) {
            $reference = $transaction->gatewayReference();

            try {
                $gatewayData = $paystack->verify($reference);
            } catch (Throwable $e) {
                // A network blip is not a verdict on the payment. Leave it
                // pending; the next run retries.
                $counts['unreachable']++;
                $this->line("  <fg=yellow>?</> {$transaction->reference} — gateway unreachable: {$e->getMessage()}");
                continue;
            }

            if ($gatewayData === null) {
                // Paystack does not know this reference at all. It may simply not
                // have been initialised (the customer closed the tab before
                // paying). Nothing to settle; leave it for the age-out below.
                $counts['unchanged']++;
                $this->line("  <fg=gray>-</> {$transaction->reference} — not found at gateway");
                continue;
            }

            $status = $gatewayData['status'] ?? 'unknown';

            if ($status === 'success') {
                if ($dryRun) {
                    $counts['credited']++;
                    $this->line("  <fg=green>+</> {$transaction->reference} — would credit " . Money::fromDatabase($transaction->amount)->format());
                    continue;
                }

                /*
                 * `settle()` is the single settlement path and is idempotent:
                 * it re-reads the row under a row lock, returns untouched if the
                 * row is already successful, and validates the reference, amount
                 * and currency before crediting. Running this command twice, or
                 * concurrently with the webhook, therefore cannot double-credit.
                 */
                $result = $paystack->settle($transaction, $gatewayData);

                if (in_array($result['status'], ['settled', 'already_settled'], true)) {
                    $counts['credited']++;
                    $this->line("  <fg=green>+</> {$transaction->reference} — credited by reconciliation");
                    Log::info('Paystack payment reconciled to success', [
                        'transaction_id' => $transaction->id,
                        'reference' => $transaction->reference,
                        'uuid' => $transaction->uuid,
                        'result' => $result['status'],
                    ]);
                } else {
                    // settle() refused it (amount/currency/reference mismatch) —
                    // that path already marked the row failed and logged
                    // critically, so it is not silently dropped.
                    $counts['failed']++;
                    $this->line("  <fg=red>x</> {$transaction->reference} — settle refused: {$result['status']}");
                }

                continue;
            }

            // 'failed' is terminal at Paystack. 'abandoned' means the customer
            // opened checkout and walked away; 'ongoing'/'pending'/'processing'
            // are still in flight and must not be closed out.
            if ($status === 'failed') {
                if (! $dryRun) {
                    $transaction->forceFill([
                        'status' => 'failed',
                        'payment_status' => 'failed',
                        'status_message' => $gatewayData['gateway_response'] ?? 'Payment failed at the gateway.',
                        'api_response' => $gatewayData,
                        'completed_at' => now(),
                    ])->save();
                }

                $counts['failed']++;
                $this->line("  <fg=red>x</> {$transaction->reference} — failed at gateway");
                continue;
            }

            $counts['unchanged']++;
            $this->line("  <fg=gray>-</> {$transaction->reference} — still {$status}");
        }

        $this->newLine();
        $this->table(
            ['Credited', 'Failed', 'Unchanged', 'Unreachable'],
            [[$counts['credited'], $counts['failed'], $counts['unchanged'], $counts['unreachable']]]
        );

        return self::SUCCESS;
    }
}
