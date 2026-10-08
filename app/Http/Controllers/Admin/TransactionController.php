<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Transactions;
use App\Models\User;
use App\Models\AdminLog;
use App\Models\WalletLedger;
use App\Services\BillPaymentService;
use App\Services\PaymentStatusResolver;
use App\Services\WalletService;
use App\Support\Money;
use App\Support\RefreshStatusResult;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly BillPaymentService $bills,
        private readonly PaymentStatusResolver $statusResolver,
    ) {
    }

    // Display all transactions
    public function index(Request $request)
    {
        $query = Transactions::with('user')->latest();

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('user', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('service_type')) {
            $query->where('service_type', $request->service_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->min_amount);
        }

        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->max_amount);
        }

        $transactions = $query->paginate(20)->withQueryString();

        // Get stats
        $stats = [
            'total' => Transactions::count(),
            'total_amount' => Transactions::where('status', 'success')->sum('amount'),
            'success' => Transactions::where('status', 'success')->count(),
            'pending' => Transactions::where('status', 'pending')->count(),
            'failed' => Transactions::where('status', 'failed')->count(),
        ];

        // Service type distribution
        $serviceTypes = [
            'airtime' => Transactions::where('service_type', 'airtime')->count(),
            'data' => Transactions::where('service_type', 'data')->count(),
            'funding' => Transactions::where('service_type', 'funding')->count(),
            'transfer' => Transactions::where('service_type', 'transfer')->count(),
            'cable-tv' => Transactions::where('service_type', 'cable-tv')->count(),
            'electricity' => Transactions::where('service_type', 'electricity')->count(),
            'exam' => Transactions::where('service_type', 'exam')->count(),
        ];

        return view('admin.transactions.index', compact('transactions', 'stats', 'serviceTypes'));
    }

  // Show transaction details
public function show(Request $request, Transactions $transaction)
{
    try {
        // Load the relationship
        $transaction->load('user');
        
        // Check if it's an AJAX request or modal request
        if ($request->ajax() || $request->has('modal')) {
            return view('admin.transactions.show', compact('transaction'));
        }

        // For non-AJAX requests, redirect to index
        return redirect()->route('admin.transactions.index');
        
    } catch (\Exception $e) {
        // If it's an AJAX request, return a proper error
        if ($request->ajax() || $request->has('modal')) {
            return response()->json([
                'error' => 'Transaction not found',
                'message' => $e->getMessage()
            ], 404);
        }
        
        // For regular requests, redirect with error
        return redirect()->route('admin.transactions.index')
            ->with('error', 'Transaction not found');
    }
}

   

    // Retry failed transaction
    public function retry($id)
    {
        $transaction = Transactions::findOrFail($id);

        if ($transaction->status !== 'failed') {
            return back()->withErrors(['error' => 'Only failed transactions can be retried.']);
        }

        /*
         * A retry of a *refunded* transaction must not happen: the customer has
         * their money back, so re-running the purchase would either fail again
         * or vendor goods that have already been paid back.
         */
        if (in_array($transaction->payment_status, ['refunded', 'reversed'], true)) {
            return back()->withErrors(['error' => 'That transaction was refunded and cannot be retried.']);
        }

        // Create a new transaction based on the failed one
        $newTransaction = $transaction->replicate();
        $newTransaction->reference = 'RETRY-' . $transaction->reference;
        $newTransaction->status = 'pending';
        $newTransaction->status_message = 'Retry of failed transaction';
        $newTransaction->api_reference = null;
        $newTransaction->api_response = null;
        $newTransaction->payment_reference = null;
        $newTransaction->paid_at = null;
        $newTransaction->completed_at = null;
        $newTransaction->save();

        AdminLog::log(Auth::id(), 'retry_transaction', [
            'old_transaction_id' => $transaction->id,
            'new_transaction_id' => $newTransaction->id,
            'reference' => $transaction->reference
        ]);

        return back()->with('success', 'Transaction retry initiated. New transaction ID: ' . $newTransaction->id);
    }

    /**
     * Refund a settled debit.
     *
     * ## What was wrong before
     *
     * The previous implementation:
     *
     *   * incremented `wallets.balance` directly, with no row lock, no ledger
     *     entry, and no `total_spent` correction;
     *   * wrote `balance_before`/`balance_after` from an unlocked read, so those
     *     columns recorded a balance that may never have existed;
     *   * had **no idempotency guard at all** — pressing the button twice paid
     *     the customer twice, because the only check was the original
     *     transaction's status, which the refund does not change.
     *
     * Now the whole thing runs inside one transaction, the original row is
     * re-read under a lock, a transaction that is already refunded is refused,
     * and the refund carries a `payment_reference` whose unique index makes a
     * second refund impossible even under a race.
     */
    public function refund(Request $request, $id)
    {
        $transaction = Transactions::findOrFail($id);

        if ($transaction->type !== 'debit') {
            return back()->withErrors(['error' => 'Only debit transactions can be refunded.']);
        }

        if ($transaction->status !== 'success') {
            return back()->withErrors(['error' => 'Only successful transactions can be refunded.']);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $amount = Money::fromDatabase($transaction->total_amount);
        $actorId = (int) Auth::id();

        try {
            $result = DB::transaction(function () use ($transaction, $validated, $amount, $actorId) {
                $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

                if (in_array($locked->payment_status, ['refunded', 'reversed'], true)) {
                    return ['status' => 'already_refunded', 'transaction' => $locked];
                }

                if ($locked->status !== 'success' || $locked->type !== 'debit') {
                    return ['status' => 'not_refundable', 'transaction' => $locked];
                }

                $user = $locked->user;

                if (! $user) {
                    return ['status' => 'no_user', 'transaction' => $locked];
                }

                // The refund record first, so the immutable ledger entry can
                // reference it.
                $refundTransaction = Transactions::create([
                    'user_id' => $locked->user_id,
                    'reference' => 'REFUND-' . $locked->reference,
                    'type' => 'credit',
                    'service_type' => 'refund',
                    'description' => 'Refund for transaction ' . $locked->reference . ': ' . $validated['reason'],
                    'amount' => $amount->toDecimalString(),
                    'service_fee' => '0.00',
                    'total_amount' => $amount->toDecimalString(),
                    'recipient' => $user->email,
                    'provider' => 'manual',
                    'payment_method' => 'wallet',
                    'payment_status' => 'success',
                    'status' => 'success',
                    'status_message' => 'Refund processed: ' . $validated['reason'],
                    // Unique: a second refund of the same charge cannot insert.
                    'payment_reference' => 'ADMIN-REFUND-OF-' . $locked->id,
                    // Display reference is unique too, and this prefix is
                    // deterministic — a retry collides here as well.
                    'meta' => [
                        'original_transaction_id' => $locked->id,
                        'original_reference' => $locked->reference,
                        'refund_reason' => $validated['reason'],
                        'admin_id' => $actorId,
                    ],
                    'paid_at' => now(),
                    'completed_at' => now(),
                ]);

                $movement = $this->wallets->refund(
                    user: $user,
                    amount: $amount,
                    description: 'Refund — ' . $validated['reason'],
                    transaction: $refundTransaction,
                    metadata: [
                        'original_transaction_id' => $locked->id,
                        'admin_id' => $actorId,
                    ],
                    actorId: $actorId,
                );

                $refundTransaction->forceFill([
                    'balance_before' => $movement['balance_before'],
                    'balance_after' => $movement['balance_after'],
                ])->save();

                $locked->forceFill([
                    'status_message' => 'Refunded: ' . $validated['reason'],
                    'payment_status' => 'refunded',
                    'meta' => array_merge($locked->meta ?? [], [
                        'refunded' => true,
                        'refund_transaction_id' => $refundTransaction->id,
                        'refunded_by' => $actorId,
                        'refunded_at' => now()->toDateTimeString(),
                    ]),
                ])->save();

                AdminLog::log($actorId, 'refund_transaction', [
                    'transaction_id' => $locked->id,
                    'refund_transaction_id' => $refundTransaction->id,
                    'amount' => $amount->toDecimalString(),
                    'balance_before' => $movement['balance_before'],
                    'balance_after' => $movement['balance_after'],
                    'status_before' => $locked->status,
                    'status_after' => 'refunded',
                    'reason' => $validated['reason'],
                ]);

                return ['status' => 'refunded', 'transaction' => $refundTransaction];
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::critical('Admin refund failed', [
                'transaction_id' => $transaction->id,
                'admin_id' => $actorId,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'The refund could not be completed. No money moved.']);
        }

        return match ($result['status']) {
            'refunded' => back()->with('success', 'Transaction refunded successfully. Refund ID: ' . $result['transaction']->id),
            'already_refunded' => back()->withErrors(['error' => 'That transaction has already been refunded.']),
            'no_user' => back()->withErrors(['error' => 'That transaction has no customer attached.']),
            default => back()->withErrors(['error' => 'That transaction is not in a refundable state.']),
        };
    }

    /**
     * Stream a filtered transaction export as CSV.
     *
     * Rewritten because the previous implementation called
     * `\League\Csv\Writer::createFromString()`, but `league/csv` is not a
     * dependency of this project — so the export threw
     * "Class League\Csv\Writer not found" every time it was used. This uses
     * PHP's own fputcsv and streams the rows, so a large export never has to be
     * materialised in memory.
     */
    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|in:pending,processing,success,failed,cancelled,verifying',
            'service_type' => 'nullable|string|max:40',
        ]);

        $query = Transactions::with('user');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->input('service_type'));
        }

        $filename = 'transactions_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID', 'Reference', 'Customer', 'Email', 'Type', 'Service type', 'Description',
                'Amount', 'Fee', 'Total', 'Status', 'Payment method', 'Recipient',
                'Provider', 'Created at', 'Completed at',
            ]);

            $query->chunk(500, function ($transactions) use ($out) {
                foreach ($transactions as $transaction) {
                    fputcsv($out, [
                        $transaction->id,
                        $this->csvSafe($transaction->reference),
                        $this->csvSafe($transaction->user->name ?? null),
                        $this->csvSafe($transaction->user->email ?? null),
                        $transaction->type,
                        $transaction->service_type,
                        $this->csvSafe($transaction->description),
                        number_format((float) $transaction->amount, 2, '.', ''),
                        number_format((float) $transaction->service_fee, 2, '.', ''),
                        number_format((float) $transaction->total_amount, 2, '.', ''),
                        $transaction->status,
                        $transaction->payment_method,
                        $this->csvSafe($transaction->recipient),
                        $this->csvSafe($transaction->provider),
                        optional($transaction->created_at)->format('Y-m-d H:i:s'),
                        optional($transaction->completed_at)->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Neutralise spreadsheet formula injection in exported text.
     *
     * A description starting with "=", "+", "-" or "@" is executed as a formula
     * by Excel and Sheets when the file is opened, which turns a transaction
     * description into an attack vector against the administrator who exports.
     */
    private function csvSafe(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    // Get transaction statistics (API endpoint)
    public function statistics()
    {
        $today = now()->today();
        $yesterday = now()->yesterday();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        $stats = [
            'today' => [
                'count' => Transactions::whereDate('created_at', $today)->count(),
                'volume' => Transactions::whereDate('created_at', $today)->sum('amount'),
                'success' => Transactions::whereDate('created_at', $today)->where('status', 'success')->count(),
            ],
            'yesterday' => [
                'count' => Transactions::whereDate('created_at', $yesterday)->count(),
                'volume' => Transactions::whereDate('created_at', $yesterday)->sum('amount'),
                'success' => Transactions::whereDate('created_at', $yesterday)->where('status', 'success')->count(),
            ],
            'this_month' => [
                'count' => Transactions::where('created_at', '>=', $thisMonth)->count(),
                'volume' => Transactions::where('created_at', '>=', $thisMonth)->sum('amount'),
                'success' => Transactions::where('created_at', '>=', $thisMonth)->where('status', 'success')->count(),
            ],
            'last_month' => [
                'count' => Transactions::whereBetween('created_at', [$lastMonth, $thisMonth])->count(),
                'volume' => Transactions::whereBetween('created_at', [$lastMonth, $thisMonth])->sum('amount'),
                'success' => Transactions::whereBetween('created_at', [$lastMonth, $thisMonth])->where('status', 'success')->count(),
            ],
        ];

        return response()->json($stats);
    }

    public function forceSuccess($id)
    {
        $transaction = Transactions::findOrFail($id);

        $actorId = (int) Auth::id();
        $before = [
            'status' => $transaction->status,
            'payment_status' => $transaction->payment_status,
        ];

        DB::beginTransaction();

        try {
            $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            /*
             * A funding row that is already settled must not be credited again.
             * The previous version incremented the balance unconditionally, so
             * pressing the button twice minted the amount twice.
             */
            $alreadySettled = $locked->status === 'success';

            $movement = null;

            if ($locked->service_type === 'funding' && ! $alreadySettled) {
                $user = $locked->user;

                if (! $user) {
                    DB::rollBack();

                    return response()->json(['success' => false, 'message' => 'That transaction has no customer attached.'], 422);
                }

                $amount = Money::fromDatabase($locked->amount);

                $movement = $this->wallets->credit(
                    user: $user,
                    amount: $amount,
                    countsAsFunding: true,
                    entryType: WalletLedger::ENTRY_ADMIN_ADJUSTMENT,
                    description: 'Funding forced successful by administrator',
                    transaction: $locked,
                    metadata: ['admin_id' => $actorId],
                    actorId: $actorId,
                );
            }

            $locked->forceFill([
                'status' => 'success',
                'payment_status' => 'success',
                'status_message' => 'Forcefully marked as successful by admin',
                'completed_at' => now(),
                'payment_reference' => $movement
                    ? ($locked->payment_reference ?: ('ADMIN-FORCED-' . $locked->id))
                    : $locked->payment_reference,
                'balance_after' => $movement['balance_after'] ?? $locked->balance_after,
            ])->save();

            AdminLog::log($actorId, 'force_transaction_success', [
                'transaction_id' => $locked->id,
                'user_id' => $locked->user_id,
                'before' => $before,
                'after' => ['status' => 'success', 'payment_status' => 'success'],
                'already_settled' => $alreadySettled,
                'credited' => $movement !== null,
                'amount' => (string) $locked->amount,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $alreadySettled
                    ? 'Transaction was already successful; no further credit was made.'
                    : 'Transaction marked as successful',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            \Illuminate\Support\Facades\Log::error('Admin force-success failed', [
                'transaction_id' => $transaction->id,
                'admin_id' => $actorId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    public function forceFailed($id)
    {
        $transaction = Transactions::findOrFail($id);

        $before = ['status' => $transaction->status, 'payment_status' => $transaction->payment_status];

        /*
         * Forcing a settled debit to `failed` does NOT move money: the customer
         * keeps what they paid for until an administrator issues the refund
         * explicitly. Doing it implicitly here is how a wallet gets credited for
         * goods that were actually delivered.
         */
        $transaction->forceFill([
            'status' => 'failed',
            'status_message' => 'Forcefully marked as failed by admin',
        ])->save();

        AdminLog::log(Auth::id(), 'force_transaction_failed', [
            'transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'before' => $before,
            'after' => ['status' => 'failed'],
            'refund_issued' => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Transaction marked as failed']);
    }

    /**
     * Cancel a transaction, reversing a settled debit if there was one.
     *
     * The reversal goes through WalletService, so it is locked, ledgered and
     * idempotent. The previous implementation incremented the balance by hand
     * and wrote a refund row with `balance_before` computed as
     * `balance - amount` *after* the increment — which produced a before/after
     * pair that was wrong by exactly one amount.
     */
    public function cancel($id)
    {
        $transaction = Transactions::findOrFail($id);

        $actorId = (int) Auth::id();

        DB::beginTransaction();

        try {
            $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            $alreadyReversed = in_array($locked->payment_status, ['refunded', 'reversed'], true);
            $reversible = $locked->type === 'debit'
                && in_array($locked->status, ['success', 'processing', 'unknown'], true)
                && ! $alreadyReversed;

            $reason = 'Cancelled by admin';

            $locked->forceFill([
                'status' => 'cancelled',
                'status_message' => $reason,
            ])->save();

            if ($reversible && $locked->user) {
                $this->bills->refund(
                    transaction: $locked,
                    reason: $reason,
                    entryType: WalletLedger::ENTRY_REVERSAL,
                    actorId: $actorId,
                );
            }

            AdminLog::log($actorId, 'cancel_transaction', [
                'transaction_id' => $locked->id,
                'user_id' => $locked->user_id,
                'amount' => (string) $locked->amount,
                'reversed' => $reversible,
                'already_reversed' => $alreadyReversed,
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Transaction cancelled successfully']);
        } catch (\Throwable $e) {
            DB::rollBack();

            \Illuminate\Support\Facades\Log::error('Admin cancel failed', [
                'transaction_id' => $transaction->id,
                'admin_id' => $actorId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Re-check a transaction against the gateway and settle it.
     *
     * The admin console's "Refresh status" action. Everything about the
     * decision lives in PaymentStatusResolver; this only records who asked and
     * reports the answer.
     *
     * A refresh that changed nothing is a 200, not an error: "Paystack still
     * reports it as pending" and "we could not reach Paystack" are both real
     * answers an operator needs to see, and neither is a failed request. The
     * `outcome` field is what the UI branches on.
     */
    public function refreshStatus(Transactions $transaction)
    {
        $actorId = (int) Auth::id();
        $before = [
            'status' => $transaction->status,
            'payment_status' => $transaction->payment_status,
        ];

        try {
            $result = $this->statusResolver->refresh($transaction, $actorId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin transaction refresh failed', [
                'transaction_id' => $transaction->id,
                'admin_id' => $actorId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'outcome' => RefreshStatusResult::OUTCOME_UNREACHABLE,
                'message' => 'The status check failed: ' . $e->getMessage(),
            ], 500);
        }

        AdminLog::log($actorId, 'refresh_transaction_status', [
            'transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'before' => $before,
            'after' => ['status' => $result->status, 'payment_status' => $result->paymentStatus],
            'outcome' => $result->outcome,
            'message' => $result->message,
        ]);

        return response()->json([
            'success' => true,
            'changed' => $result->changed(),
        ] + $result->toArray());
    }

}
