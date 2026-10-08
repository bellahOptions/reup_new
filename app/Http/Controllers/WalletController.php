<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Models\User;
use App\Services\PaystackService;
use App\Services\SecurityService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly PaystackService $paystack,
        private readonly SecurityService $security,
    ) {
    }

    /* =====================================================================
     | Views
     |=================================================================== */

    public function index()
    {
        $user = Auth::user();
        $wallet = $this->wallets->forUser($user);

        $recentTransactions = Transactions::where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        $monthStart = now()->startOfMonth();

        /*
         * Summed as integer kobo through `Money` rather than cast to float. A
         * float cast of a large decimal sum is how a dashboard total ends up
         * one kobo away from the sum of the rows beneath it.
         */
        $monthlyStats = [
            'spent' => Money::fromDatabase(Transactions::where('user_id', $user->id)
                ->where('type', 'debit')->where('status', 'success')
                ->where('created_at', '>=', $monthStart)->sum('amount'))->toFloat(),
            'funded' => Money::fromDatabase(Transactions::where('user_id', $user->id)
                ->where('type', 'credit')->whereIn('service_type', ['funding', 'wallet_funding'])
                ->where('status', 'success')
                ->where('created_at', '>=', $monthStart)->sum('amount'))->toFloat(),
            'transactions' => Transactions::where('user_id', $user->id)
                ->where('status', 'success')
                ->where('created_at', '>=', $monthStart)->count(),
            'today_volume' => Money::fromDatabase(Transactions::where('user_id', $user->id)
                ->where('status', 'success')->whereDate('created_at', today())->sum('amount'))->toFloat(),
        ];

        return view('wallet.index', compact('wallet', 'recentTransactions', 'monthlyStats'));
    }

    public function history(Request $request)
    {
        $user = Auth::user();
        $wallet = $this->wallets->forUser($user);

        $filters = $request->validate([
            'type' => 'nullable|in:credit,debit',
            'status' => 'nullable|in:pending,processing,success,failed,cancelled,verifying',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'search' => 'nullable|string|max:100',
        ]);

        $query = Transactions::where('user_id', $user->id);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', '%' . $term . '%')
                    ->orWhere('description', 'like', '%' . $term . '%')
                    ->orWhere('uuid', 'like', '%' . $term . '%')
                    ->orWhere('api_reference', 'like', '%' . $term . '%');
            });
        }

        $transactions = $query->latest()->paginate(20)->withQueryString();

        return view('wallet.history', compact('wallet', 'transactions'));
    }

    public function fund()
    {
        $user = Auth::user();
        $wallet = $this->wallets->forUser($user);

        $recentFunding = Transactions::where('user_id', $user->id)
            ->where('service_type', 'funding')
            ->whereIn('status', ['success', 'pending', 'verifying'])
            ->latest()
            ->limit(5)
            ->get();

        return view('wallet.fund', [
            'wallet' => $wallet,
            'bank_details' => config('wallet.bank'),
            'paystack_fee' => config('wallet.fees.paystack'),
            'bank_fee' => config('wallet.fees.bank_transfer'),
            'min_amount' => config('wallet.minimum_funding', 100),
            'max_amount' => config('wallet.maximum_funding', 1000000),
            'recent_funding' => $recentFunding,
            'paystack_enabled' => $this->paystack->isConfigured(),
        ]);
    }

    /* =====================================================================
     | Funding
     |=================================================================== */

    public function processFunding(Request $request)
    {
        $validated = $request->validate([
            'amount' => [
                'required',
                'numeric',
                'min:' . config('wallet.minimum_funding', 100),
                'max:' . config('wallet.maximum_funding', 1000000),
            ],
            'payment_method' => 'required|in:paystack,bank_transfer',
            // Optional: the funding form supplies one so a double submit cannot
            // create two funding rows pointing at the same intent.
            'idempotency_key' => 'nullable|string|min:8|max:64|regex:/^[A-Za-z0-9\-_]+$/',
        ]);

        $user = Auth::user();

        /*
         * The amount is parsed once, exactly, into integer kobo. `round()` on a
         * float is what let a ₦1,000.005 request become an unrepresentable
         * amount, and the fee was computed from that same float.
         */
        $amount = Money::fromNaira($validated['amount']);
        $fee = $this->calculateFee($amount, $validated['payment_method']);
        $total = $amount->plus($fee);

        /*
         * Replay protection for the funding intent itself. The key is optional
         * (older forms do not send one), but when it is present a repeat submit
         * returns the original funding row rather than creating a second one.
         */
        $idempotencyKey = $validated['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $reservation = $this->security->reserve(
                $idempotencyKey,
                'wallet_funding',
                $user,
                $this->security->requestHash($user, [
                    'amount' => $amount->toDecimalString(),
                    'method' => $validated['payment_method'],
                ])
            );

            if ($reservation['replay'] && $reservation['transaction']) {
                $original = $reservation['transaction'];

                return $original->payment_method === 'bank_transfer'
                    ? redirect()->route('wallet.bank-transfer.details', ['ref' => $original->reference])
                    : redirect()->route('wallet.payment.status', ['reference' => $original->reference]);
            }
        }

        try {
            $transaction = DB::transaction(function () use ($user, $amount, $fee, $total, $validated, $idempotencyKey) {
                return Transactions::create([
                    'user_id' => $user->id,
                    'reference' => $this->wallets->generateReference('FND'),
                    'type' => 'credit',
                    'service_type' => 'funding',
                    'description' => 'Wallet funding — ' . ($validated['payment_method'] === 'paystack' ? 'Card' : 'Bank transfer'),
                    'amount' => $amount->toDecimalString(),
                    'service_fee' => $fee->toDecimalString(),
                    'total_amount' => $total->toDecimalString(),
                    'recipient' => $user->email,
                    'provider' => $validated['payment_method'],
                    'payment_method' => $validated['payment_method'],
                    'payment_status' => 'pending',
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                    'meta' => [
                        'funding_amount' => $amount->toDecimalString(),
                        'processing_fee' => $fee->toDecimalString(),
                        'total_payable' => $total->toDecimalString(),
                        'initiated_at' => now()->toDateTimeString(),
                    ],
                ]);
            });
        } catch (Throwable $e) {
            if (isset($reservation)) {
                $this->security->release($reservation['record'], $e->getMessage());
            }

            throw $e;
        }

        if (isset($reservation)) {
            $this->security->complete($reservation['record'], $transaction, ['outcome' => 'initiated']);
        }

        return $validated['payment_method'] === 'bank_transfer'
            ? $this->initiateBankTransfer($transaction)
            : $this->initiatePaystackPayment($transaction);
    }

    /**
     * Bank transfer funding via a Paystack dedicated virtual account.
     *
     * A DVA is permanent per customer, so it is generated once and reused. The
     * account number is what identifies the incoming transfer, which is why it
     * is stored on the user and matched by the webhook rather than by a
     * reference: the payer types the number into their own bank, so there is
     * nothing else to correlate on.
     *
     * Any date, time or amount discrepancy is reconciled by Paystack's webhook,
     * which is the only authoritative signal that money actually arrived.
     */
    private function initiateBankTransfer(Transactions $transaction)
    {
        $user = $transaction->user;

        $narration = 'REUP-' . strtoupper(substr($transaction->reference, -8));

        $account = null;
        $virtualAccount = null;
        $dvaError = null;

        try {
            $account = $this->paystack->dedicatedAccount($user);
        } catch (Throwable $e) {
            Log::error('Dedicated virtual account unavailable', [
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $dvaError = $this->dvaFallbackMessage($e->getMessage());

            // Fall back to the static account rather than dead-ending the
            // customer: a manual transfer can still be verified by an admin.
            // The stored value is the sanitised message, never the raw provider
            // text — meta is rendered back to the customer on page reload.
            $transaction->forceFill([
                'meta' => array_merge($transaction->meta ?? [], [
                    'narration' => $narration,
                    'dva_error' => $dvaError,
                    'bank_details_shown_at' => now()->toDateTimeString(),
                ]),
            ])->save();
        }

        if ($account !== null) {
            $transaction->forceFill([
                'meta' => array_merge($transaction->meta ?? [], [
                    'narration' => $narration,
                    'virtual_account' => $account,
                    'bank_details_shown_at' => now()->toDateTimeString(),
                ]),
            ])->save();
        }

        return view('wallet.bank-transfer', [
            'transaction' => $transaction,
            'bank_details' => config('wallet.bank'),
            'narration' => $narration,
            'amount' => $transaction->total_amount,
            'virtual_account' => $account,
            'dva_error' => $dvaError,
        ]);
    }

    /**
     * Turn a provider-side DVA failure into something a customer can act on.
     *
     * The raw message ("wema-bank is not available in test mode", "invalid key")
     * describes our own configuration and is never shown verbatim; the full text
     * is already in the log via the caller.
     */
    private function dvaFallbackMessage(string $providerMessage): string
    {
        $fallback = 'Transfer to the account below and we will credit you automatically.';

        if (stripos($providerMessage, 'test mode') !== false) {
            return 'Personal account numbers are not available on this account yet. ' . $fallback;
        }

        return 'We could not issue a personal account number just now. ' . $fallback;
    }

    /**
     * The stored dedicated virtual account for a user, in the shape the
     * bank-transfer view expects, or null when none was ever issued.
     *
     * @return array{account_number:string,bank_name:?string,account_name:?string}|null
     */
    private function dvaFromUser(?User $user): ?array
    {
        if (! $user || ! $user->dva_account_number) {
            return null;
        }

        return [
            'account_number' => $user->dva_account_number,
            'bank_name' => $user->dva_bank_name,
            'account_name' => $user->dva_account_name,
        ];
    }

    private function initiatePaystackPayment(Transactions $transaction)
    {
        try {
            $url = $this->paystack->initialize($transaction, $transaction->user);

            return redirect()->away($url);
        } catch (Throwable $e) {
            Log::error('Paystack initialisation failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            $transaction->forceFill([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => 'Could not start payment session.',
                'completed_at' => now(),
            ])->save();

            return redirect()->route('wallet.fund')
                ->with('error', 'We could not start the card payment session. Please try again or use bank transfer.');
        }
    }

    /**
     * The processing fee for a funding method, in exact kobo.
     *
     * `Money::percentage()` rounds half-up to the nearest kobo, which is what
     * the previous `round($amount * $percentage / 100 + $additional, 2)` did —
     * so no historical fee changes value, but the arithmetic no longer depends
     * on binary floating point.
     */
    private function calculateFee(Money $amount, string $method): Money
    {
        if ($method !== 'paystack') {
            return Money::fromDatabase(config('wallet.fees.bank_transfer.fixed', 0));
        }

        $config = config('wallet.fees.paystack', ['percentage' => 1.5, 'additional' => 100, 'cap' => 2000]);

        $fee = $amount->percentage($config['percentage'] ?? 0)
            ->plus(Money::fromDatabase($config['additional'] ?? 0));

        if (isset($config['cap'])) {
            $fee = $fee->min(Money::fromDatabase($config['cap']));
        }

        return $fee;
    }

    /* =====================================================================
     | Bank transfer proof
     |=================================================================== */

    public function submitBankTransferProof(Request $request)
    {
        $validated = $request->validate([
            /*
             * Deliberately NOT `exists:transactions,reference`.
             *
             * That rule queries the whole table, so a wrong reference produced a
             * different validation message for "exists but not yours" than for
             * "does not exist" — an oracle for confirming that a reference is
             * real. The ownership-scoped lookup below is the actual check, and
             * it fails identically either way.
             */
            'transaction_reference' => 'required|string|max:120',
            /*
             * `mimes` compares the extension guessed from the content;
             * `mimetypes` compares the detected MIME itself. Both are asserted
             * because either alone can be satisfied by a polyglot, and this is a
             * document an administrator later opens. The bytes go to the private
             * disk under a framework-generated name, so nothing here is
             * web-served regardless — this is the second lock, not the first.
             */
            'proof' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:5120',
            ],
            'remarks' => 'nullable|string|max:500',
        ]);

        // Scoped to the authenticated user: the previous lookup matched on
        // reference alone, so any user could attach proof to another user's
        // pending funding row.
        $transaction = Transactions::where('reference', $validated['transaction_reference'])
            ->where('user_id', Auth::id())
            ->where('service_type', 'funding')
            ->where('payment_method', 'bank_transfer')
            ->where('status', 'pending')
            ->first();

        if (! $transaction) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'We could not find a pending bank transfer with that reference.',
            ]);
        }

        // Private disk: proofs contain bank account details and must not be
        // reachable at a guessable public URL. The filename is generated by the
        // framework from a random hash, so the client-supplied name is never
        // part of the path and cannot traverse out of the directory.
        $path = $request->file('proof')->store('payment-proofs/' . date('Y/m'), 'local');

        $transaction->forceFill([
            'status' => 'verifying',
            'payment_status' => 'verifying',
            'meta' => array_merge($transaction->meta ?? [], [
                'proof_path' => $path,
                'proof_remarks' => $validated['remarks'] ?? null,
                'proof_submitted_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        Log::info('Bank transfer proof submitted', [
            'transaction_id' => $transaction->id,
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('wallet.history')
            ->with('success', 'Proof received. We will verify it and credit your wallet shortly.');
    }

    public function showBankTransferDetails(Request $request)
    {
        $reference = $request->query('ref');

        $transaction = Transactions::where('reference', $reference)
            ->where('user_id', Auth::id())
            ->where('payment_method', 'bank_transfer')
            ->whereIn('status', ['pending', 'verifying'])
            ->firstOrFail();

        return view('wallet.bank-transfer', [
            'transaction' => $transaction,
            'bank_details' => config('wallet.bank'),
            'narration' => $transaction->meta['narration'] ?? ('REUP-' . strtoupper(substr($transaction->reference, -8))),
            'amount' => $transaction->total_amount,
            // Reloading the page reuses the account generated at initiation
            // time; when that call failed, fall back to the shared account.
            'virtual_account' => $transaction->meta['virtual_account'] ?? $this->dvaFromUser($transaction->user),
            // Re-sanitised on read: meta is long-lived storage, so a raw provider
            // message written by an older revision must not reach the browser.
            'dva_error' => isset($transaction->meta['dva_error'])
                ? $this->dvaFallbackMessage((string) $transaction->meta['dva_error'])
                : null,
        ]);
    }

    /* =====================================================================
     | Paystack callback + webhook
     |=================================================================== */

    /**
     * Browser redirect target after a hosted checkout.
     *
     * Ownership is asserted before anything is settled. Previously this looked
     * the transaction up by reference alone, so an authenticated attacker
     * could replay another user's reference from the callback URL and have
     * that user's funding credited while `wallet.history` had already leaked
     * the reference.
     */
    public function handlePaystackCallback(Request $request)
    {
        $reference = $request->query('reference');

        if (! $reference || ! is_string($reference)) {
            return redirect()->route('wallet.fund')
                ->with('error', 'That payment link is missing its reference.');
        }

        $transaction = Transactions::where('user_id', Auth::id())
            ->where('payment_method', 'paystack')
            ->whereReference($reference)
            ->first();

        if (! $transaction) {
            Log::warning('Paystack callback for unknown or foreign transaction', [
                'reference' => $reference,
                'user_id' => Auth::id(),
            ]);

            abort(404);
        }

        try {
            $gatewayData = $this->paystack->verify($reference);
        } catch (Throwable $e) {
            Log::error('Paystack verification error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('wallet.index')
                ->with('error', 'We could not confirm that payment. If you were debited, contact support with your reference.');
        }

        if ($gatewayData === null) {
            return redirect()->route('wallet.index')
                ->with('error', 'That payment could not be verified with the gateway.');
        }

        /*
         * The browser plays no part in deciding success: the gateway is queried
         * server-to-server above, and `settle()` validates the reference, the
         * amount, the currency and the status before any money moves. A
         * `?reference=` in the URL is a hint about what to verify, never an
         * assertion that anything was paid.
         */
        $result = $this->paystack->settle($transaction, $gatewayData);

        return match ($result['status']) {
            'settled', 'already_settled' => redirect()->route('wallet.index')->with(
                'success',
                'Payment confirmed. ' . Money::fromDatabase($result['transaction']->amount)->format() . ' has been added to your wallet.'
            ),
            'amount_mismatch' => redirect()->route('wallet.index')->with(
                'error',
                'The amount paid did not match this transaction, so it was not credited. Support has been notified.'
            ),
            'currency_mismatch' => redirect()->route('wallet.index')->with(
                'error',
                'That payment was made in an unsupported currency, so it was not credited. Support has been notified.'
            ),
            'reference_mismatch' => redirect()->route('wallet.index')->with(
                'error',
                'We could not match that payment to this transaction. Support has been notified.'
            ),
            default => redirect()->route('wallet.index')->with(
                'error',
                'That payment was not successful: ' . ($gatewayData['gateway_response'] ?? 'declined by the gateway') . '.'
            ),
        };
    }

    public function paymentStatus(Request $request)
    {
        $reference = $request->query('reference');

        if (! $reference) {
            return redirect()->route('wallet.fund')->with('error', 'No payment reference was supplied.');
        }

        $transaction = Transactions::where('user_id', Auth::id())
            ->whereReference($reference)
            ->first();

        return view('wallet.payment-status', [
            'reference' => $reference,
            'transaction' => $transaction,
        ]);
    }

    public function checkPaymentStatus(Request $request)
    {
        $validated = $request->validate(['reference' => 'required|string|max:120']);

        $transaction = Transactions::where('user_id', Auth::id())
            ->whereReference($validated['reference'])
            ->first();

        if (! $transaction) {
            return response()->json(['status' => 'not_found', 'message' => 'Transaction not found.'], 404);
        }

        return response()->json([
            'status' => $transaction->status,
            'payment_status' => $transaction->payment_status,
            'amount' => Money::fromDatabase($transaction->amount)->toFloat(),
            'amount_minor' => Money::fromDatabase($transaction->amount)->minor(),
            'currency' => 'NGN',
            'message' => $this->statusMessage($transaction->status),
        ]);
    }

    /**
     * The authenticated user's wallet balance, for the header widget.
     *
     * Read from the wallet service, so it is the same value every other part of
     * the application uses — the previous inline route closure read
     * `Auth::user()->wallet` directly, which returned null for a user whose
     * wallet row had not been created and quietly reported a zero balance.
     */
    public function balance()
    {
        $balance = $this->wallets->balanceFor(Auth::user());

        return response()->json([
            'balance' => $balance->toFloat(),
            'balance_minor' => $balance->minor(),
            'currency' => Money::CURRENCY,
            'formatted' => $balance->format(),
        ]);
    }

    private function statusMessage(?string $status): string
    {
        return match ($status) {
            'success' => 'Payment successful — your wallet has been credited.',
            'pending' => 'Payment is still being processed.',
            'processing' => 'Payment is being processed by the gateway.',
            'verifying' => 'Your proof is being verified.',
            'failed' => 'Payment failed. No funds were taken.',
            'cancelled' => 'Payment was cancelled.',
            default => 'Unknown payment status.',
        };
    }
}
