<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackController extends Controller
{
    public function __construct(
        private readonly PaystackService $paystack,
    ) {
    }

    /**
     * Paystack server-to-server webhook.
     *
     * This is the authoritative settlement path: the browser callback can be
     * abandoned or replayed, whereas Paystack signs and retries this request
     * until it receives a 2xx.
     *
     * Hardening applied here:
     *   - signature is verified over the raw body before the payload is parsed;
     *   - the route sits outside the `auth` middleware (Paystack has no
     *     session) and is exempt from CSRF, which the previous wiring got
     *     wrong in both directions;
     *   - settlement is idempotent and amount-checked in PaystackService;
     *   - replies 200 for events we do not act on so Paystack stops retrying.
     */
    public function webhook(Request $request)
    {
        $secret = config('services.paystack.secret_key');

        if (empty($secret)) {
            Log::critical('Paystack webhook received but PAYSTACK_SECRET_KEY is not configured.');
            abort(503, 'Payment gateway not configured.');
        }

        $signature = (string) $request->header('x-paystack-signature', '');
        $payload = $request->getContent();

        if ($signature === '') {
            Log::warning('Paystack webhook rejected: missing signature header.');
            abort(400, 'Missing signature.');
        }

        $expected = hash_hmac('sha512', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Paystack webhook rejected: signature mismatch.', [
                'ip' => $request->ip(),
            ]);
            abort(401, 'Invalid signature.');
        }

        $event = $request->input('event');
        $data = $request->input('data', []);

        Log::info('Paystack webhook received', ['event' => $event]);

        if ($event !== 'charge.success') {
            return response()->json(['status' => 'ignored']);
        }

        /*
         * A dedicated virtual account transfer has no reference to match on —
         * the payer types the account number into their own bank. Paystack
         * marks it with `channel = dedicated_nuban` and names the receiving
         * account, which is what identifies the customer.
         *
         * This MUST be checked before the reference guard below: a DVA payload
         * carries no `reference` at all, so gating on one first would silently
         * drop every real bank transfer.
         */
        if (data_get($data, 'authorization.channel') === 'dedicated_nuban') {
            $result = $this->paystack->creditDedicatedAccountTransfer($data);

            // Always 200: a retry cannot help for an unknown or duplicate
            // transfer, and retries for a credited one would be noise.
            return response()->json(['status' => $result['status']]);
        }

        $reference = $data['reference'] ?? null;

        if (! $reference) {
            return response()->json(['status' => 'ignored', 'reason' => 'no reference']);
        }

        $transaction = Transactions::where('payment_method', 'paystack')
            ->whereReference($reference)
            ->first();

        if (! $transaction) {
            Log::warning('Paystack webhook for unknown reference', ['reference' => $reference]);

            // 200: nothing to retry, the reference is simply not ours.
            return response()->json(['status' => 'unknown_reference']);
        }

        try {
            $result = $this->paystack->settle($transaction, $data);

            return response()->json(['status' => $result['status']]);
        } catch (Throwable $e) {
            Log::error('Paystack webhook settlement failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            // 500 so Paystack retries — the charge is real and unsettled.
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Live gateway balance, for the admin console.
     * Cached briefly; the previous implementation also logged a fragment of
     * the secret key on every construction.
     */
    public function getBalance()
    {
        if (! $this->paystack->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Paystack is not configured.'], 503);
        }

        return response()->json($this->paystack->balance());
    }

    public function refreshBalance()
    {
        $this->paystack->forgetBalanceCache();

        return $this->getBalance();
    }

    public function getTransactionStats(string $period = 'today')
    {
        $period = in_array($period, ['today', 'month'], true) ? $period : 'today';

        return response()->json($this->paystack->transactionTotals($period));
    }
}
