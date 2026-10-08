<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pairgate server-to-server webhook.
 *
 * The Pairgate API only *acknowledges* a purchase. Two things are learned here
 * and nowhere else:
 *
 *   1. the electricity token / exam PIN (`pin`) — the acknowledgement carries
 *      none, so without this endpoint those products are sold but never
 *      delivered;
 *   2. that an order Pairgate accepted has since failed, which means the
 *      customer's debit has to be reversed.
 *
 * Hardening, mirroring PaystackController:
 *   - the route sits outside `auth` (Pairgate holds no session) and is exempt
 *     from CSRF, which it cannot satisfy;
 *   - the HMAC signature is verified over the raw body before it is parsed, and
 *     an unconfigured secret is a 503 rather than an open door — an unauthenticated
 *     request that can trigger a refund is a way to steal: get the goods, then
 *     ask for the money back;
 *   - a signed body is honoured for five minutes only, so a captured delivery
 *     cannot be replayed later;
 *   - every state change is idempotent and taken under a row lock, because
 *     Pairgate retries anything not answered 2xx within ten seconds;
 *   - a failure is amount-checked against the row before money moves;
 *   - events we do not act on still return 200, so the retry queue drains.
 */
class PairgateWebhookController extends Controller
{
    /** Documented replay window for `X-Pairgate-Timestamp`, in seconds. */
    private const MAX_SKEW = 300;

    public function __construct(
        private readonly BillPaymentService $bills,
    ) {
    }

    public function handle(Request $request)
    {
        $secret = (string) config('services.pairgate.webhook_secret');

        if ($secret === '') {
            Log::critical('Pairgate webhook received but PAIRGATE_WEBHOOK_SECRET is not configured.');

            abort(503, 'Webhook secret not configured.');
        }

        $payload = $request->getContent();
        $timestamp = (string) $request->header('X-Pairgate-Timestamp', '');
        $signature = (string) $request->header('X-Pairgate-Signature', '');

        if ($timestamp === '' || $signature === '') {
            Log::warning('Pairgate webhook rejected: missing signature headers.', ['ip' => $request->ip()]);

            abort(400, 'Missing signature.');
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SKEW) {
            Log::warning('Pairgate webhook rejected: timestamp outside the replay window.', [
                'timestamp' => $timestamp,
                'ip' => $request->ip(),
            ]);

            abort(401, 'Stale signature.');
        }

        // Documented scheme: hex HMAC-SHA256 over "<timestamp>.<raw body>".
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Pairgate webhook rejected: signature mismatch.', ['ip' => $request->ip()]);

            abort(401, 'Invalid signature.');
        }

        $status = strtolower((string) $request->input('status', ''));

        Log::info('Pairgate webhook received', [
            'event' => $request->input('event'),
            'status' => $status,
            'reference_code' => $request->input('reference_code'),
        ]);

        $transaction = $this->findTransaction(
            (string) $request->input('reference_code', ''),
            (string) $request->input('reference', ''),
        );

        if (! $transaction) {
            Log::warning('Pairgate webhook for unknown reference', [
                'reference_code' => $request->input('reference_code'),
                'reference' => $request->input('reference'),
            ]);

            // 200: nothing to retry — the reference is simply not ours.
            return response()->json(['status' => 'unknown_reference']);
        }

        try {
            $result = $status === 'failed'
                ? $this->settleFailure($transaction, $request)
                : $this->settleSuccess($transaction, $request);

            return response()->json(['status' => $result]);
        } catch (Throwable $e) {
            Log::error('Pairgate webhook settlement failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            // 500 so Pairgate retries: the delivery is real and unsettled.
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Pairgate echoes our own `reference` and its `reference_code`; either can
     * identify the row. The model's scope covers `reference`, `uuid` and
     * `api_reference`, so a delivery that carries only one of them still lands.
     */
    private function findTransaction(string $referenceCode, string $reference): ?Transactions
    {
        foreach (array_unique(array_filter([$referenceCode, $reference])) as $identifier) {
            $transaction = Transactions::whereReference($identifier)->latest('id')->first();

            if ($transaction) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * A completed order.
     *
     * The purchase pipeline already marked it successful from the API
     * acknowledgement, so normally the only thing left to do is store the token
     * or PIN that arrives with this call.
     */
    private function settleSuccess(Transactions $transaction, Request $request): string
    {
        $token = $request->input('pin');
        $referenceCode = (string) $request->input('reference_code', '');

        /*
         * Note on amounts: unlike the failure path below, a success delivery is
         * NOT amount-checked. For exam PINs Pairgate sends one webhook per PIN
         * with the *per-PIN* price, not the order total, so comparing that
         * figure against the transaction would refuse every legitimate delivery.
         * The delivery is authenticated by HMAC and resolved by reference
         * before reaching here, which is what actually establishes that it
         * belongs to this transaction.
         */
        return DB::transaction(function () use ($transaction, $request, $token, $referenceCode) {
            $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            /*
             * A success arriving after we refunded is not something to act on
             * automatically: either the failure webhook was wrong or this one
             * is, and only a human can tell which. Touching the row here would
             * either hide a refund or pay for the same order twice.
             */
            if (in_array($locked->payment_status, ['refunded', 'reversed'], true)) {
                Log::critical('Pairgate reported success for a refunded transaction — needs review', [
                    'transaction_id' => $locked->id,
                    'reference' => $locked->reference,
                    'reference_code' => $referenceCode,
                ]);

                return 'refunded_needs_review';
            }

            $meta = $locked->meta ?? [];

            if (is_string($token) && $token !== '') {
                $meta['token'] = $token;
            }

            /*
             * Exam PINs are delivered one webhook per PIN, and `api_reference`
             * can only hold one code, so keep the whole set. That is what makes
             * a later status enquiry possible for every PIN in the order.
             */
            if ($referenceCode !== '') {
                $meta['provider_references'] = array_values(array_unique(array_merge(
                    (array) ($meta['provider_references'] ?? []),
                    [$referenceCode],
                )));
            }

            $meta['webhook'] = [
                'status' => 'successful',
                'received_at' => now()->toDateTimeString(),
            ];

            $locked->forceFill([
                'status' => 'success',
                'payment_status' => 'success',
                'api_reference' => $locked->api_reference ?: ($referenceCode ?: null),
                'api_response' => $request->all(),
                'completed_at' => $locked->completed_at ?: now(),
                'meta' => $meta,
            ])->save();

            return 'settled';
        });
    }

    /**
     * An order Pairgate accepted and then failed: the customer's money goes back.
     *
     * This is not theoretical. A vending request is marked successful on the API
     * acknowledgement, so a failure that only surfaces hours later arrives
     * against a row that already reads "success" — and the only safe thing to do
     * with a row that says success is to reverse it exactly once.
     */
    private function settleFailure(Transactions $transaction, Request $request): string
    {
        $reason = (string) ($request->input('message') ?: 'Pairgate reported the transaction as failed.');
        $amount = $request->input('amount');

        // Only refund the row this delivery is actually about. The charge the
        // customer paid is `amount`; our fee is not part of Pairgate's figure.
        // Compared exactly, in integer kobo — a one-kobo difference is a
        // different order, not a rounding artefact.
        if (is_numeric($amount)
            && ! Money::fromDatabase($transaction->amount)->equals(Money::fromNaira($amount))) {
            Log::critical('Pairgate webhook amount mismatch — refusing to refund automatically', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'expected' => (string) $transaction->amount,
                'reported' => (string) $amount,
            ]);

            // 200: a retry cannot resolve a mismatch. A human has to look.
            return 'amount_mismatch';
        }

        $refunded = DB::transaction(function () use ($transaction, $reason, $request) {
            $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->payment_status, ['refunded', 'reversed'], true)) {
                return false;
            }

            /*
             * `refund()` re-reads the row under its own lock and refuses a
             * second reversal, so this is idempotent even if Pairgate delivers
             * the same failure twice concurrently. It also writes the ledger
             * entry and marks the row refunded, so there is no second place for
             * the status to be set from.
             */
            if (! $this->bills->refund($locked, $reason)) {
                return false;
            }

            $locked->forceFill([
                'api_response' => $request->all(),
                'meta' => array_merge($locked->meta ?? [], [
                    'webhook' => [
                        'status' => 'failed',
                        'received_at' => now()->toDateTimeString(),
                    ],
                ]),
            ])->save();

            return true;
        });

        return $refunded ? 'refunded' : 'already_refunded';
    }
}
