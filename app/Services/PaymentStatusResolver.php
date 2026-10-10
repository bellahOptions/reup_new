<?php

namespace App\Services;

use App\Models\Transactions;
use App\Support\Money;
use App\Support\RefreshStatusResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decide, on demand, what a transaction's final status is.
 *
 * This is the single implementation behind the admin console's "Refresh status"
 * action. It exists because a transaction can be left mid-flight by something
 * that never reached us — the customer closed the tab, their connection died,
 * the app was backgrounded on a phone — and the row then sits `pending`
 * forever with nobody able to say whether money moved.
 *
 * The decision has two branches, and the order matters:
 *
 *   1. **Did it ever reach the gateway?** Answered from our own record of the
 *      gateway's response — the Paystack access code, or the Bachs checkout id.
 *      Its absence is proof the customer was never handed to a payment page, so
 *      no charge can exist and the row is failed without a single network call.
 *   2. **If it did reach the gateway, ask the gateway.** The reference we gave
 *      it (the UUID, not the display reference) is the only authoritative
 *      handle. Card funding settles from the reply; the gateway's own words are
 *      stored on the row.
 *
 * ## What it will not do
 *
 * It never credits on a weaker signal than `PaystackService::settle()`, which
 * re-validates the amount, the currency and the reference before moving money.
 * A gateway that cannot be reached resolves nothing rather than guessing —
 * `unreachable` is a real outcome here, and the one case where the row is
 * deliberately left alone.
 *
 * Bill purchases (airtime, data, electricity) are answered by the upstream
 * vending provider instead of Paystack, because Paystack has never heard of
 * them. That path delegates to BillPaymentService and keeps its asymmetry: a
 * provider that cannot answer never triggers a refund.
 */
class PaymentStatusResolver
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly BachsService $bachs,
        private readonly BillPaymentService $bills,
    ) {
    }

    /**
     * The gateway that took (or would have taken) the money for this row.
     */
    public function gateway(Transactions $transaction): string
    {
        $meta = is_array($transaction->meta) ? $transaction->meta : [];

        if (($meta['gateway'] ?? null) === BachsService::GATEWAY) {
            return BachsService::GATEWAY;
        }

        return 'paystack';
    }

    /**
     * Did this attempt ever get handed to a payment gateway's checkout?
     *
     * An access code or checkout id is only ever written from a successful
     * initialise response, so its presence proves the customer reached a
     * payment page. `initiated_at` is deliberately not used: it is stamped when
     * the funding row is created, before any gateway is called.
     */
    public function reachedGateway(Transactions $transaction): bool
    {
        $meta = is_array($transaction->meta) ? $transaction->meta : [];

        foreach ([
            'paystack_access_code',
            'paystack_transaction_id',
            'paystack_initialized_at',
            'bachs_checkout_id',
            'bachs_checkout_url',
        ] as $key) {
            if (! empty($meta[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refresh one transaction and report what it now is.
     *
     * @param  int  $actorId  the administrator doing this, for the audit trail.
     */
    public function refresh(Transactions $transaction, int $actorId = 0): RefreshStatusResult
    {
        $transaction = $transaction->fresh() ?? $transaction;

        if ($this->isFinal($transaction)) {
            return RefreshStatusResult::final_($transaction, sprintf(
                'This transaction is already %s; nothing to look up.',
                $transaction->status
            ));
        }

        /*
         * A bill purchase is settled by the vending provider, not by a payment
         * gateway, so it takes a different branch entirely.
         */
        if (! $this->isCardFunding($transaction)) {
            /*
             * Bank transfer is the other thing a payment gateway settles here,
             * but not by a query we can make: a dedicated virtual account has no
             * per-transaction lookup, and the inbound transfer is matched by
             * Paystack's webhook. Saying so is the honest answer — drifting into
             * the vending-provider branch would ask ClubKonnect about a bank
             * transfer it has never heard of.
             */
            if ($this->isBankTransferFunding($transaction)) {
                return RefreshStatusResult::final_(
                    $transaction,
                    'Bank transfers are confirmed by the gateway\'s own notification, so there is nothing to query. '
                    . 'This one is still pending; it will be credited automatically when the transfer lands.'
                );
            }

            return $this->refreshBillPurchase($transaction, $actorId);
        }

        if (! $this->reachedGateway($transaction)) {
            // No access code: the customer was never handed to a payment page.
            // There is nothing to ask about and no charge that could exist.
            $this->failFundingAttempt(
                $transaction,
                'Failed: this attempt never reached the payment page. No money left your account.'
            );

            Log::info('Admin refresh resolved a funding attempt that never reached the gateway', [
                'transaction_id' => $transaction->id,
                'gateway' => $this->gateway($transaction),
                'actor_id' => $actorId ?: null,
            ]);

            return RefreshStatusResult::failed(
                $transaction->fresh(),
                'This attempt never reached the payment gateway, so it was marked as failed. Nothing was charged.'
            );
        }

        return $this->gateway($transaction) === BachsService::GATEWAY
            ? $this->refreshWithBachs($transaction)
            : $this->refreshWithPaystack($transaction);
    }

    /**
     * Answer the same question as {@see refresh()} and write nothing.
     *
     * ## Why this exists
     *
     * The scheduled sweep runs unattended and can move money — it credits a
     * settled payment and refunds a failed purchase. An operator therefore needs
     * to be able to ask "what would this run actually do?" *before* letting it
     * loose, and the only trustworthy answer is the one produced by the same
     * question-asking: the same gateways, the same provider status queries, the
     * same eligibility rules.
     *
     * ## Why it duplicates the decision tree instead of sharing it
     *
     * The two differ in one dimension that cannot be parameterised away:
     * `refresh()` reads the row fresh, locks it, and writes through the single
     * settlement path. Threading a `$dryRun` flag through all of that would put
     * a write-vs-not branch inside every arrow of the tree — and the failure mode
     * of getting one wrong is money moved during a supposed dry run, which is
     * unrecoverable and silent.
     *
     * So the *network* and *decision* halves are shared and only the *writes* are
     * absent: `isFinal()`, `isCardFunding()`, `isBankTransferFunding()`,
     * `reachedGateway()` and `gateway()` are the same methods both use, so
     * eligibility cannot drift; the gateway clients are the same ones, so the
     * question asked upstream is identical; `BillPaymentService::resolveUnknown`
     * takes its own `$dryRun` and stops before it settles or refunds anything.
     *
     * ## What it does not do
     *
     * It never reports `settled` or `failed` as *done*. A settlement the gateway
     * confirms comes back as `OUTCOME_SETTLED` — the same outcome the real run
     * would produce — because the caller needs to count it; the difference is
     * that this transaction is still `pending` in the database afterwards. The
     * messages say so.
     *
     * @param  bool  $allowPending  for a bill purchase, also consider a row that
     *                              is still `pending`/`processing`. Passed
     *                              through to `resolveUnknown` and off by
     *                              default, so an unattended sweep and an
     *                              operator asking about one row can differ —
     *                              which they must. See `previewBillPurchase`.
     */
    public function preview(Transactions $transaction, int $actorId = 0, bool $allowPending = false): RefreshStatusResult
    {
        $transaction = $transaction->fresh() ?? $transaction;

        if ($this->isFinal($transaction)) {
            return RefreshStatusResult::final_($transaction, sprintf(
                'Already %s; the sweep would skip it.',
                $transaction->status
            ));
        }

        /*
         * Bill purchases first, exactly as `refresh()` orders it: they are
         * answered by the vending provider, so they must never be sent to a
         * payment gateway that has never heard of them.
         */
        if (! $this->isCardFunding($transaction)) {
            if ($this->isBankTransferFunding($transaction)) {
                return RefreshStatusResult::final_(
                    $transaction,
                    'A bank transfer is confirmed by the gateway\'s own notification, so there is nothing to query. '
                    . 'The sweep would leave it alone.'
                );
            }

            return $this->previewBillPurchase($transaction, $actorId, $allowPending);
        }

        if (! $this->reachedGateway($transaction)) {
            $stillPending = $transaction->status === 'pending' || $transaction->status === 'processing';

            return RefreshStatusResult::failed(
                $transaction,
                $stillPending
                    ? 'No access code is recorded, so this attempt never reached a payment page. '
                        . 'The sweep would mark it failed without asking the gateway — nothing was charged.'
                    : 'This attempt never reached a payment page, so the sweep would mark it failed.'
            );
        }

        return $this->gateway($transaction) === BachsService::GATEWAY
            ? $this->previewWithBachs($transaction)
            : $this->previewWithPaystack($transaction);
    }

    private function previewWithPaystack(Transactions $transaction): RefreshStatusResult
    {
        if (! $this->paystack->isConfigured()) {
            return RefreshStatusResult::configError(
                $transaction,
                'Paystack is not configured on this environment, so the sweep could not check this row.'
            );
        }

        $reference = $transaction->gatewayReference();

        try {
            $gatewayData = $this->paystack->verify($reference);
        } catch (Throwable $e) {
            Log::warning('Sweep preview could not reach Paystack', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return RefreshStatusResult::unreachable(
                $transaction,
                'Paystack could not be reached just now, so the sweep would leave this row alone.'
            );
        }

        if ($gatewayData === null) {
            return RefreshStatusResult::unreachable(
                $transaction,
                'Paystack does not recognise reference ' . $reference . ', so the sweep would change nothing.'
            );
        }

        $status = strtolower((string) ($gatewayData['status'] ?? 'unknown'));

        if ($status === 'success') {
            return RefreshStatusResult::settled(
                $transaction,
                'Paystack confirms payment. The sweep would credit '
                . Money::fromDatabase($transaction->amount)->format()
                . ' to the customer and mark the row successful.'
            );
        }

        if (in_array($status, ['failed', 'reversed', 'abandoned'], true)) {
            return RefreshStatusResult::failed(
                $transaction,
                'Paystack reports this payment as ' . $status . '. The sweep would mark the row failed.'
            );
        }

        return RefreshStatusResult::stillPending(
            $transaction,
            'Paystack still reports this payment as ' . $status . ', so the sweep would leave it pending.'
        );
    }

    private function previewWithBachs(Transactions $transaction): RefreshStatusResult
    {
        if (! $this->bachs->isConfigured()) {
            return RefreshStatusResult::configError(
                $transaction,
                'Bachs is not configured on this environment, so the sweep could not check this row.'
            );
        }

        $checkoutId = (string) (((is_array($transaction->meta) ? $transaction->meta : [])['bachs_checkout_id']) ?? '');

        if ($checkoutId === '') {
            return RefreshStatusResult::configError(
                $transaction,
                'This transaction has no Bachs checkout id recorded, so the sweep could not check it.'
            );
        }

        try {
            $charge = $this->bachs->verify($checkoutId);
        } catch (Throwable $e) {
            Log::warning('Sweep preview could not reach Bachs', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return RefreshStatusResult::unreachable(
                $transaction,
                'Bachs could not be reached just now, so the sweep would leave this row alone.'
            );
        }

        if ($charge === null) {
            return RefreshStatusResult::stillPending(
                $transaction,
                'Bachs has no payment recorded for this checkout yet, so the sweep would leave it pending.'
            );
        }

        $status = strtolower((string) ($charge['status'] ?? 'unknown'));

        if (in_array($status, ['succeeded', 'accepted'], true)) {
            return RefreshStatusResult::settled(
                $transaction,
                'Bachs confirms payment. The sweep would credit the customer and mark the row successful.'
            );
        }

        if (in_array($status, ['failed', 'refunded', 'partially_refunded', 'auto_refunded'], true)) {
            return RefreshStatusResult::failed(
                $transaction,
                'Bachs reports this payment as ' . $status . '. The sweep would mark the row failed.'
            );
        }

        return RefreshStatusResult::stillPending(
            $transaction,
            'Bachs still reports this payment as ' . $status . ', so the sweep would leave it pending.'
        );
    }

    /**
     * A provider-backed purchase, asked without a write.
     *
     * ## `$allowPending` is passed straight through, and the default is narrow
     *
     * The scheduled sweep keeps the narrow default — only a row whose outcome is
     * genuinely `unknown` is queried. Widening it to `pending`/`processing` is
     * right for an operator looking at one row they have reason to doubt, and
     * wrong for an unattended sweep: a purchase the provider is still working on
     * can legitimately answer "received/pending" one minute and "failed" the
     * next, and a sweep that has decided to query it will act on whichever
     * answer it happens to catch. Refunding a vend that then completes is the
     * failure mode, and it is not recoverable.
     *
     * A provider that cannot answer is reported as unreachable — never as a
     * failure — because a refund on a non-answer is how a vended order is given
     * away.
     */
    private function previewBillPurchase(Transactions $transaction, int $actorId, bool $allowPending = false): RefreshStatusResult
    {
        $result = $this->bills->resolveUnknown(
            $transaction,
            $actorId,
            allowPending: $allowPending,
            dryRun: true,
        );

        return match ($result['verdict']) {
            'success' => RefreshStatusResult::settled(
                $transaction,
                'The provider confirms this purchase succeeded. The sweep would settle it and send the receipt.'
            ),
            'failed' => RefreshStatusResult::failed(
                $transaction,
                'The provider reports this purchase failed. The sweep would refund the customer.'
            ),
            'refund_failed' => RefreshStatusResult::unreachable(
                $transaction,
                'The provider reports this purchase failed. The sweep would attempt a refund — '
                . 'check the wallet before trusting that it lands.'
            ),
            'pending' => RefreshStatusResult::stillPending(
                $transaction,
                'The provider reports this purchase as still pending, so the sweep would leave it alone.'
            ),
            'not_unknown' => RefreshStatusResult::stillPending(
                $transaction,
                'This purchase has no provider verdict yet, so the sweep would leave it to the provider and its webhook.'
            ),
            default => RefreshStatusResult::unreachable(
                $transaction,
                'The provider could not confirm the outcome, so the sweep would change nothing.'
            ),
        };
    }

    private function refreshWithPaystack(Transactions $transaction): RefreshStatusResult
    {
        if (! $this->paystack->isConfigured()) {
            return RefreshStatusResult::configError(
                $transaction,
                'Paystack is not configured on this environment, so the status cannot be checked.'
            );
        }

        $reference = $transaction->gatewayReference();

        try {
            $gatewayData = $this->paystack->verify($reference);
        } catch (Throwable $e) {
            Log::warning('Admin refresh could not reach Paystack', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return RefreshStatusResult::unreachable(
                $transaction,
                'Paystack could not be reached, so the status is unchanged. Try again shortly.'
            );
        }

        if ($gatewayData === null) {
            return RefreshStatusResult::unreachable(
                $transaction,
                'Paystack does not recognise reference ' . $reference . '. Nothing was settled; the transaction is unchanged.'
            );
        }

        $status = strtolower((string) ($gatewayData['status'] ?? 'unknown'));

        if ($status === 'success') {
            $result = $this->paystack->settle($transaction, $gatewayData);

            return $this->fromSettlement($transaction, $result);
        }

        if (in_array($status, ['failed', 'reversed'], true)) {
            $this->failFundingAttempt(
                $transaction,
                (string) ($gatewayData['gateway_response'] ?? 'The payment failed at the gateway.'),
                $gatewayData
            );

            return RefreshStatusResult::failed(
                $transaction->fresh(),
                'Paystack reports this payment as ' . $status . '. It has been marked failed.'
            );
        }

        if ($status === 'abandoned') {
            $this->failFundingAttempt(
                $transaction,
                'The payment page was closed before payment was made. No money left your account.',
                $gatewayData
            );

            return RefreshStatusResult::failed(
                $transaction->fresh(),
                'The customer opened the payment page but never paid. It has been marked failed.'
            );
        }

        // `pending`, `ongoing`, `processing`, or a status we do not know:
        // Paystack is still treating it as live, so nothing is written.
        return RefreshStatusResult::stillPending(
            $transaction,
            'Paystack still reports this payment as ' . $status . '. It has been left pending.'
        );
    }

    private function refreshWithBachs(Transactions $transaction): RefreshStatusResult
    {
        if (! $this->bachs->isConfigured()) {
            return RefreshStatusResult::configError(
                $transaction,
                'Bachs is not configured on this environment, so the status cannot be checked.'
            );
        }

        $checkoutId = (string) (((is_array($transaction->meta) ? $transaction->meta : [])['bachs_checkout_id']) ?? '');

        if ($checkoutId === '') {
            return RefreshStatusResult::configError(
                $transaction,
                'This transaction has no Bachs checkout id recorded, so its status cannot be checked.'
            );
        }

        try {
            $charge = $this->bachs->verify($checkoutId);
        } catch (Throwable $e) {
            Log::warning('Admin refresh could not reach Bachs', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return RefreshStatusResult::unreachable(
                $transaction,
                'Bachs could not be reached, so the status is unchanged. Try again shortly.'
            );
        }

        if ($charge === null) {
            return RefreshStatusResult::stillPending(
                $transaction,
                'Bachs has no payment recorded for this checkout yet. It has been left pending.'
            );
        }

        $status = strtolower((string) ($charge['status'] ?? 'unknown'));

        if (in_array($status, ['succeeded', 'accepted'], true)) {
            $result = $this->bachs->settle($transaction, $charge);

            return $this->fromSettlement($transaction, $result);
        }

        if (in_array($status, ['failed', 'refunded', 'partially_refunded', 'auto_refunded'], true)) {
            $this->failFundingAttempt(
                $transaction,
                (string) ($charge['failure_reason'] ?? 'The payment was not successful.'),
                $charge['raw'] ?? null
            );

            return RefreshStatusResult::failed(
                $transaction->fresh(),
                'Bachs reports this payment as ' . $status . '. It has been marked failed.'
            );
        }

        return RefreshStatusResult::stillPending(
            $transaction,
            'Bachs still reports this payment as ' . $status . '. It has been left pending.'
        );
    }

    /**
     * A provider-backed purchase (airtime, data, cable, electricity, exam PIN).
     *
     * These are settled by the vending provider, so the answer comes from
     * BillPaymentService, whose rules are deliberately asymmetric: success
     * settles, failed refunds, and anything else changes nothing at all.
     */
    private function refreshBillPurchase(Transactions $transaction, int $actorId): RefreshStatusResult
    {
        if (! in_array($transaction->status, ['unknown', 'pending', 'processing'], true)) {
            return RefreshStatusResult::final_(
                $transaction,
                'This transaction is ' . $transaction->status . '; there is nothing to look up.'
            );
        }

        $result = $this->bills->resolveUnknown($transaction, $actorId, allowPending: true);
        $fresh = $transaction->fresh() ?? $transaction;

        return match ($result['verdict']) {
            'success' => RefreshStatusResult::settled($fresh, 'The provider confirms this purchase succeeded.'),
            'failed' => RefreshStatusResult::failed($fresh, 'The provider reports this purchase failed, and the customer has been refunded.'),
            'refund_failed' => RefreshStatusResult::unreachable($fresh, 'The provider reports failure but the refund could not be issued. Please handle this transaction by hand.'),
            'pending' => RefreshStatusResult::stillPending($fresh, 'The provider reports this purchase as still pending.'),
            default => RefreshStatusResult::unreachable($fresh, 'The provider could not confirm the outcome, so nothing was changed.'),
        };
    }

    /**
     * @param  array{status:string,transaction:Transactions}  $result
     */
    private function fromSettlement(Transactions $transaction, array $result): RefreshStatusResult
    {
        $fresh = $result['transaction'];

        return match ($result['status']) {
            'settled' => RefreshStatusResult::settled(
                $fresh,
                'Payment confirmed. ' . Money::fromDatabase($fresh->amount)->format() . ' has been credited to the customer.'
            ),
            'already_settled' => RefreshStatusResult::final_($fresh, 'This transaction was already settled.'),
            'amount_mismatch' => RefreshStatusResult::failed($fresh, 'The amount paid did not match this transaction, so it was not credited.'),
            'currency_mismatch' => RefreshStatusResult::failed($fresh, 'The payment was made in an unsupported currency, so it was not credited.'),
            'reference_mismatch' => RefreshStatusResult::failed($fresh, 'The gateway reference does not belong to this transaction, so it was not credited.'),
            default => RefreshStatusResult::failed($fresh, 'The gateway did not confirm this payment. It has been marked failed.'),
        };
    }

    private function isFinal(Transactions $transaction): bool
    {
        return in_array($transaction->status, ['success', 'failed', 'cancelled'], true);
    }

    /**
     * Card funding is the only thing a *payment gateway* can answer for.
     *
     * Bank transfer is excluded: Paystack's dedicated virtual account has no
     * per-transaction query we can make, and the inbound transfer is matched by
     * the webhook instead.
     */
    private function isCardFunding(Transactions $transaction): bool
    {
        return $transaction->service_type === 'funding'
            && $transaction->type === 'credit'
            && ! in_array($transaction->payment_method, ['bank_transfer'], true);
    }

    private function isBankTransferFunding(Transactions $transaction): bool
    {
        return $transaction->service_type === 'funding'
            && $transaction->type === 'credit'
            && $transaction->payment_method === 'bank_transfer';
    }

    private function failFundingAttempt(Transactions $transaction, string $message, ?array $apiResponse = null): void
    {
        $transaction->forceFill(array_filter([
            'status' => 'failed',
            'payment_status' => 'failed',
            'status_message' => $message,
            'api_response' => $apiResponse,
            'completed_at' => now(),
        ], fn ($value) => $value !== null))->save();
    }
}
