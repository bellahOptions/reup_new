<?php

namespace App\Http\Controllers;

use App\Exceptions\PhoneNumberRequiredException;
use App\Models\Transactions;
use App\Models\User;
use App\Services\BachsService;
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
    /**
     * The message shown when a personal account cannot be issued because the
     * customer has not given us a phone number.
     *
     * A constant, not an inline string, because it is compared against — the
     * POST handler and the view both need to recognise this outcome and offer
     * the profile form, and matching on a copy of the prose would break the
     * first time somebody reworded it. The sentence is deliberately about what
     * the customer must do, not about what the gateway refused.
     */
    private const WARNING_PHONE_REQUIRED =
        'We need a phone number on your account before we can create your personal account number. '
        . 'Paystack requires one for every account holder. Add yours and the account is created straight away.';

    /**
     * Machine-readable codes for the two kinds of "could not issue".
     *
     * The view branches on these, not on the prose. Matching a sentence to decide
     * whether to offer the profile form would break the first time somebody
     * reworded it, and rewording customer-facing copy is a thing that happens.
     */
    private const WARNING_CODE_PHONE_REQUIRED = 'phone_required';

    /** Anything else: a gateway outage, or our own configuration. */
    private const WARNING_CODE_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly PaystackService $paystack,
        private readonly BachsService $bachs,
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
            /*
             * Card is only offered when at least one card gateway can actually
             * serve it. Bachs alone is enough: it is the fallback for exactly
             * the case where Paystack is not configured or reachable.
             */
            'paystack_enabled' => $this->paystack->isConfigured() || $this->bachs->isConfigured(),
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

                /*
                 * A replay of an attempt that never went through must not trap
                 * the customer.
                 *
                 * The idempotency key exists to stop a *second* charge for work
                 * that already happened. A failed attempt did not happen: no
                 * money moved, so there is nothing to be idempotent about, and
                 * bouncing the customer to the status page of a dead attempt
                 * leaves them with a failure they cannot retry past. Sending
                 * them back to the form lets the retry proceed, and a new
                 * attempt creates its own row.
                 */
                if (in_array($original->status, ['failed', 'cancelled'], true)) {
                    return redirect()->route('wallet.fund')
                        ->with('error', 'Your previous funding attempt did not go through and no money was taken. Please try again.');
                }

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
            : $this->initiateCardPayment($request, $transaction);
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

    /**
     * Start a card payment, falling back to Bachs when Paystack cannot.
     *
     * Order matters. Paystack is the primary rail: it is the one that settles
     * to the account the rest of this application already reconciles against
     * (`payments:reconcile`, the webhook, the balance card in the admin
     * console), so it is always tried first and Bachs is only reached when
     * Paystack genuinely refuses to produce a checkout URL.
     *
     * This is not a customer-facing choice. The funding form still offers
     * "Card"; a second radio button would ask the customer to make a decision
     * they have no information to make, and the fee is identical either way.
     *
     * If both gateways fail the transaction is failed as before, so the
     * attempt resolves itself rather than sitting pending.
     */
    private function initiateCardPayment(Request $request, Transactions $transaction)
    {
        try {
            $url = $this->paystack->initialize($transaction, $transaction->user);

            return $this->sendToGateway($request, $url);
        } catch (Throwable $e) {
            report($e);

            Log::error('Paystack initialisation failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackToBachs($request, $transaction);
        }
    }

    /**
     * Try the fallback gateways, or fail the attempt.
     *
     * Everything a customer sees about the outcome happens here, so the funding
     * page never has to explain "Paystack" to anyone.
     */
    private function fallbackToBachs(Request $request, Transactions $transaction)
    {
        if (! $this->bachs->isConfigured()) {
            return $this->failCardAttempt(
                $request,
                $transaction,
                'Could not start payment session.',
                'We could not start the card payment session. Please try again or use bank transfer.'
            );
        }

        try {
            $url = $this->bachs->initialize($transaction, $transaction->user);

            /*
             * The gateway is recorded on the row because settlement has to know
             * which provider's vocabulary to validate against. Silence here was
             * the alternative — the customer would be charged by a merchant
             * name they do not recognise with nothing in the app explaining why,
             * and support would have no way to tell which rail took the money.
             */
            Log::info('Card funding fell back to Bachs after Paystack refused', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
            ]);

            return $this->sendToGateway($request, $url);
        } catch (Throwable $e) {
            report($e);

            Log::error('Bachs initialisation failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failCardAttempt(
                $request,
                $transaction,
                'Could not start payment session: ' . $e->getMessage(),
                'We could not start the card payment session. Please try again, or fund by bank transfer.'
            );
        }
    }

    /**
     * Hand the customer to the gateway, in whichever shape the browser asked.
     *
     * The funding form submits with `fetch()` so it can report the outcome
     * immediately (see resources/views/wallet/fund.blade.php). A full-page POST
     * leaves the old page — and its "Processing…" button — on screen for as long
     * as both gateway calls take, which is how a slow provider turns into an
     * apparently infinite spinner. The JSON branch answers in one round trip.
     *
     * A non-JSON request still gets the original redirect, so a no-JavaScript
     * browser is not broken by the faster path.
     */
    private function sendToGateway(Request $request, string $url)
    {
        if (! $request->wantsJson()) {
            return redirect()->away($url);
        }

        return response()->json([
            'status' => 'redirect',
            'redirect' => $url,
        ]);
    }

    /**
     * Mark a card attempt dead and report it.
     *
     * The row is failed rather than left pending: nothing reached a gateway, so
     * there is nothing to reconcile, and `payments:reconcile` would otherwise
     * have to age it out.
     */
    private function failCardAttempt(Request $request, Transactions $transaction, string $internal, string $customerMessage)
    {
        $transaction->forceFill([
            'status' => 'failed',
            'payment_status' => 'failed',
            'status_message' => $internal,
            'completed_at' => now(),
        ])->save();

        return $this->reportFailure($request, $customerMessage);
    }

    /**
     * The one place a funding failure is turned into a response.
     *
     * 502 rather than 500: the request was valid and it is an upstream gateway
     * that could not be used, which is what the status code is for.
     */
    private function reportFailure(Request $request, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'failed',
                'message' => $message,
            ], 502);
        }

        return redirect()->route('wallet.fund')->with('error', $message);
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
     | Dedicated virtual account (pay-in account)
     |====================================================================
     | A permanent NUBAN issued per customer by Paystack. Every inbound
     | transfer to it credits the wallet automatically: Paystack sends
     | `charge.success` with `channel = dedicated_nuban`, `PaystackController
     | ::webhook` recognises it, and `PaystackService::creditDedicatedAccount
     | Transfer` writes the funding row and the ledger entry.
     |
     | So there is nothing per-transfer for this controller to arrange, and no
     | amount is required up front — the customer transfers what they want.
     | These two methods exist purely so the account can be requested and read
     | without going through the "fund a specific amount by transfer" flow.
     */

    /**
     * The customer's dedicated account, issuing one on first visit.
     *
     * Issuing on a GET is deliberate and safe:
     *
     *   * it is **idempotent** — `dedicatedAccount()` returns the stored account
     *     without a network call once it exists, and Paystack rejects a second
     *     assignment for the same customer anyway;
     *   * it is **not a financial action** — no charge, no transaction row, no
     *     balance change. A NUBAN that is never used costs nothing.
     *
     * The alternative, a page whose only content is a button labelled
     * "Generate", asks the customer to make a decision they have no information
     * to make, and then shows them a spinner. Requesting the account on arrival
     * is what "generate my account" actually means.
     *
     * A failure to issue is *not* an error page. The account may be unavailable
     * because Paystack is down, or because personal accounts are not enabled on
     * this account yet — in which case the customer is told plainly and offered
     * the other funding routes, which still work.
     */
    public function virtualAccount()
    {
        $outcome = $this->issueVirtualAccount();

        return $this->renderVirtualAccount($outcome['message'] ?? null, $outcome['code'] ?? null);
    }

    /**
     * Issue and display the customer's dedicated account, or explain why not.
     *
     * The outcome is returned as a small shape rather than parallel values, and
     * that is not ceremony: the first version returned a bare message and the
     * caller passed it to `renderVirtualAccount(?string $warning)`, so the
     * `$phoneMissing` argument beside it silently took its default and the view's
     * phone prompt never rendered — the message said "add a phone number" while
     * the heading above it said "we could not issue your account". Returning one
     * value that carries both makes that class of mistake impossible to write.
     *
     * `with('warning')` rather than `with('error')`: nothing failed from the
     * customer's side, and an error toast on a page that is working correctly is
     * how a customer is taught to distrust the wallet.
     *
     * @return array{message:?string,code:?string}|null
     */
    private function issueVirtualAccount(): ?array
    {
        $user = Auth::user();

        if ($user->dva_account_number) {
            // Already issued. No network call, no message — the page just shows
            // the account, which is what a returning visitor came for.
            return null;
        }

        if (! $this->paystack->isConfigured()) {
            Log::warning('Dedicated virtual account requested while Paystack is not configured', [
                'user_id' => $user->id,
            ]);

            return [
                'message' => 'Personal account numbers are not available on this deployment yet. '
                    . 'You can still fund with a card, or by transfer to the account on the funding page.',
                'code' => self::WARNING_CODE_UNAVAILABLE,
            ];
        }

        /*
         * Paystack will not attach a virtual account to a customer record with no
         * phone number, and our own phone column is optional — so this is asked
         * *before* anything is spent on the gateway, and reported as the
         * actionable condition it is rather than as a provider error.
         *
         * The rule is asked of the service that enforces it (`hasPhoneFor…`)
         * rather than re-tested here against the raw column: the accessor that
         * decides is the same one that builds the value sent upstream, so the
         * pre-check and the API call cannot drift apart.
         */
        if (! $this->paystack->hasPhoneForDedicatedAccount($user)) {
            Log::info('Dedicated virtual account deferred until a phone number is on file', [
                'user_id' => $user->id,
            ]);

            return ['message' => self::WARNING_PHONE_REQUIRED, 'code' => self::WARNING_CODE_PHONE_REQUIRED];
        }

        try {
            /*
             * Locked, and re-read inside the lock.
             *
             * Requesting an account is an upstream API call, and Paystack
             * rejects a second assignment for a customer that already has one —
             * so two concurrent requests (a double-tap, two open tabs) would
             * have one win and the other fail with a provider error shown to the
             * customer. The lock makes the second request wait, see the account
             * the first one stored, and do nothing.
             *
             * The row is re-fetched rather than trusting the instance loaded
             * above: that instance predates the lock, so its `dva_account_number`
             * may be stale — which is exactly the value being tested.
             */
            $account = DB::transaction(function () use ($user) {
                $locked = User::whereKey($user->getKey())->lockForUpdate()->first();

                if (! $locked || $locked->dva_account_number) {
                    return null;
                }

                return $this->paystack->dedicatedAccount($locked);
            });

            if ($account === null) {
                // The other request issued it while this one waited.
                return null;
            }

            Log::info('Dedicated virtual account issued to a customer', [
                'user_id' => $user->id,
                'bank' => $account['bank_name'] ?? null,
            ]);

            return null;
        } catch (Throwable $e) {
            /*
             * The pre-check above means this branch is not the normal way a
             * missing phone number is reported — it is the backstop for the
             * cases the check cannot see: a number that is present but
             * unusable, or a customer record left without one by an earlier
             * release that created it before the check existed.
             *
             * It is kept, rather than removed with the check, precisely because
             * those cases would otherwise fall through to the sanitiser below and
             * be reported as "we could not issue your personal account number" —
             * a dead end the customer cannot act on.
             */
            if ($e instanceof PhoneNumberRequiredException) {
                Log::info('Dedicated virtual account deferred until a phone number is on file', [
                    'user_id' => $user->id,
                    'stage' => 'provider',
                ]);

                return ['message' => self::WARNING_PHONE_REQUIRED, 'code' => self::WARNING_CODE_PHONE_REQUIRED];
            }

            /*
             * The raw provider message describes our configuration ("wema-bank
             * is not available in test mode", "invalid key") and is never shown
             * verbatim. `$dvaFallbackMessage()` is the same sanitiser the funding
             * flow uses, so the two paths cannot describe one failure two ways.
             *
             * The full text is in the log, which is where it belongs.
             */
            Log::error('Dedicated virtual account could not be issued', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['message' => $this->dvaFallbackMessage($e->getMessage()), 'code' => self::WARNING_CODE_UNAVAILABLE];
        }
    }

    private function renderVirtualAccount(?string $warning = null, ?string $warningCode = null)
    {
        $user = Auth::user()->fresh();

        $account = $this->dvaFromUser($user);

        return view('wallet.virtual-account', [
            'wallet' => $this->wallets->forUser($user),
            'virtual_account' => $account,
            'bank_details' => config('wallet.bank'),
            'paystack_enabled' => $this->paystack->isConfigured(),
            'dva_warning' => $warning ?? session('warning'),
            /*
             * A missing phone number is the one failure the customer can fix
             * themselves, so the page offers a form rather than an apology. The
             * flag is passed separately from the message because the view needs
             * to change the *action*, not just the wording — and because a
             * flashed message from a redirect loses any structured data.
             */
            'phone_missing' => $warningCode === self::WARNING_CODE_PHONE_REQUIRED
                || (session('warning_code') === self::WARNING_CODE_PHONE_REQUIRED),
            'profile_url' => route('profile.index'),
            /*
             * Recent transfers to the account, so the customer can see the credit
             * land without leaving the page. Matched on `payment_method`, which
             * is how both settlement paths record a bank transfer — the webhook
             * and the manual-proof fallback.
             */
            'recentTransfers' => Transactions::where('user_id', $user->id)
                ->where('type', 'credit')
                ->where('service_type', 'funding')
                ->where('payment_method', 'bank_transfer')
                ->where('status', 'success')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function storeVirtualAccount()
    {
        $outcome = $this->issueVirtualAccount();

        if ($outcome === null) {
            return redirect()
                ->route('wallet.virtual-account')
                ->with('success', 'Your account is ready.');
        }

        /*
         * Both the message and its code are flashed, so the page after the
         * redirect can offer the right action — not just the right sentence.
         */
        return redirect()
            ->route('wallet.virtual-account')
            ->with('warning', $outcome['message'])
            ->with('warning_code', $outcome['code']);
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
