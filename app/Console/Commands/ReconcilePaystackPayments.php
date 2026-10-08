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
                            {--unreached=30 : Age in minutes after which a payment that never reached checkout is failed}
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

        /*
         * A payment that never reached checkout gets a much shorter rope.
         *
         * The lookback window exists to give a customer who *opened* Paystack's
         * checkout a fair chance to finish, and webhook/callback a fair chance
         * to arrive. An attempt that never got an authorization URL back has no
         * such chance: the customer was never handed to the gateway, so there is
         * no session to complete, no charge in flight and nothing for a webhook
         * to report. Leaving it pending for a day is what makes a failed funding
         * attempt look like money in limbo.
         *
         * Floored at 3x the poll window so every one of these rows is polled
         * against Paystack at least twice before it is written off — if the
         * gateway does know the reference, it settles on the polling branch
         * below instead.
         */
        $unreached = max(3 * $minAge, (int) $this->option('unreached'));

        $dryRun = (bool) $this->option('dry-run');

        $counts = ['credited' => 0, 'failed' => 0, 'unchanged' => 0, 'unreachable' => 0];

        // Declared up front so the summary below cannot reference an undefined
        // variable when a branch is skipped.
        $candidates = collect();
        $stale = collect();

        /*
         * Age out anything still pending beyond its window. Without this, a
         * payment that Paystack will never confirm — the customer never reached
         * checkout, or closed it and walked away — stays "pending" on the
         * dashboard forever, which is worse than an honest failure: it makes the
         * customer think money is in flight.
         *
         * Two windows, because the two situations are not alike:
         *
         *   * never reached checkout -> `--unreached` (default 30m). The
         *     customer was never handed to the gateway, so there is no session
         *     to complete and no charge in flight. This is the common shape of
         *     "the funding attempt failed": the attempt is definitively dead the
         *     moment it happens, and only the webhook grace period is needed
         *     before saying so.
         *   * reached checkout -> the full `--lookback`, because a customer may
         *     still be typing their card details, and Paystack may still deliver
         *     a webhook for a payment that succeeded.
         *
         * This is checked FIRST, and every row it claims is removed from the
         * poll set, so each pending row is covered by exactly one of the two
         * branches. Checking them as separate ranges previously left a gap: a
         * row older than the poll window but newer than the expiry threshold
         * matched neither condition and was silently ignored forever.
         *
         * The status is re-read under a lock and only a still-pending row is
         * expired, so a webhook arriving mid-run always wins — and `settle()`
         * credits a row the gateway confirms even if this has already written it
         * off, so an early expiry can never cost a customer their money.
         */
        $cutoff = now()->subMinutes($lookback);
        $unreachedCutoff = now()->subMinutes($unreached);

        /*
         * One fetch, partitioned in PHP.
         *
         * Two age-bounded queries were the obvious shape and the wrong one: the
         * sets have to be exactly disjoint or a row is either counted twice or
         * missed at the boundary between them, and getting that right in SQL
         * couples the two windows together. Fetching everything pending and
         * past the *shorter* window, then splitting on the access code and the
         * row's age, has no boundary to get wrong.
         */
        $cutoff = now()->subMinutes($lookback);
        $unreachedCutoff = now()->subMinutes($unreached);

        $eligible = Transactions::query()
            ->where('payment_method', 'paystack')
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', $unreachedCutoff)
            ->get();

        $stale = $eligible
            ->filter(function (Transactions $transaction) use ($cutoff) {
                if (! $this->reachedCheckout($transaction)) {
                    // Never handed to the gateway: past the short window is enough.
                    return true;
                }

                // Checkout was opened, so it gets the full lookback window.
                return $transaction->created_at->lt($cutoff);
            })
            ->values();

        foreach ($stale as $transaction) {
            $reached = $this->reachedCheckout($transaction);

            $message = $reached
                ? 'Expired: the payment was never completed. No money left your account.'
                : 'Failed: this attempt never reached the payment page. No money left your account.';

            if ($dryRun) {
                $counts['failed']++;
                $this->line("  <fg=red>x</> {$transaction->reference} — would fail (" . ($reached ? "checkout opened, older than {$lookback}m" : "never reached checkout, older than {$unreached}m") . ')');
                continue;
            }

            /*
             * Two guards, both inside a locked transaction:
             *   * the row must still be pending, so a webhook that arrived
             *     between the SELECT above and here wins;
             *   * `payment_status` must still be pending, so a settlement that
             *     moved it cannot be overwritten.
             */
            $expired = DB::transaction(function () use ($transaction, $message) {
                $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->first();

                if (! $locked || $locked->status !== 'pending' || $locked->payment_status !== 'pending') {
                    return false;
                }

                $locked->forceFill([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                    'status_message' => $message,
                    'completed_at' => now(),
                ])->save();

                return true;
            });

            if ($expired) {
                $counts['failed']++;
                $this->line("  <fg=red>x</> {$transaction->reference} — failed (" . ($reached ? 'checkout abandoned' : 'never reached checkout') . ')');

                Log::info('Paystack funding attempt resolved as failed without reaching the gateway', [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'reached_checkout' => $reached,
                ]);
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

            /*
             * Terminal gateway verdicts close the row out immediately rather
             * than waiting for an expiry window:
             *
             *   * 'failed'     — the charge was attempted and refused.
             *   * 'abandoned'  — the customer opened checkout and walked away
             *                    without paying. Paystack will not collect on
             *                    that session, so this is settled business
             *                    rather than something to keep "pending".
             *   * 'reversed'   — the charge was returned, so no funds remain
             *                    with us to credit.
             *
             * 'ongoing' / 'pending' / 'processing' are still in flight and must
             * not be closed out. A 'success' is credited on the branch above.
             */
            if (in_array($status, ['failed', 'abandoned', 'reversed'], true)) {
                if (! $dryRun) {
                    $transaction->forceFill([
                        'status' => 'failed',
                        'payment_status' => 'failed',
                        'status_message' => $status === 'abandoned'
                            ? 'The payment page was closed before payment was made. No money left your account.'
                            : ($gatewayData['gateway_response'] ?? 'Payment failed at the gateway.'),
                        'api_response' => $gatewayData,
                        'completed_at' => now(),
                    ])->save();
                }

                $counts['failed']++;
                $this->line("  <fg=red>x</> {$transaction->reference} — {$status} at gateway");
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

    /**
     * Did this attempt ever get handed to Paystack's checkout?
     *
     * The access code is only ever written from a successful `initialize()`
     * response, so its presence is the durable record that the customer was
     * actually sent to the payment page. Its absence — including a row whose
     * metadata was never merged because initialisation threw — means the
     * attempt never reached the gateway.
     *
     * Note `initiated_at` is deliberately NOT used for this: it is stamped when
     * the funding row is created, before Paystack is called, so it says nothing
     * about whether checkout was reached.
     */
    private function reachedCheckout(Transactions $transaction): bool
    {
        $meta = $transaction->meta;

        if (! is_array($meta)) {
            return false;
        }

        return ! empty($meta['paystack_access_code'])
            || ! empty($meta['paystack_transaction_id'])
            || ! empty($meta['paystack_initialized_at']);
    }
}
