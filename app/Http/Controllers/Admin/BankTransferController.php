<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\Transactions;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankTransferController extends Controller
{
    /** Columns the transfer list may be sorted by. */
    private const SORTABLE = ['id', 'created_at', 'amount', 'status', 'reference'];

    /** Statuses that mean "a human has not looked at this yet". */
    private const REVIEWABLE = ['pending', 'verifying'];

    public function __construct(private readonly WalletService $wallets)
    {
    }

    /**
     * Whitelist the requested sort column and direction.
     *
     * Replaces `$query->orderBy($request->input('sort'), $request->input('order'))`,
     * which interpolated an unvalidated identifier into the SQL.
     */
    private function resolveSort(Request $request): array
    {
        $column = (string) $request->query('sort', 'created_at');

        if (! in_array($column, self::SORTABLE, true)) {
            $column = 'created_at';
        }

        $direction = strtolower((string) $request->query('order', 'desc'));

        return [$column, $direction === 'asc' ? 'asc' : 'desc'];
    }

    /** Base query for funding transactions paid by bank transfer. */
    private function baseQuery()
    {
        return Transactions::where('service_type', 'funding')
            ->where('payment_method', 'bank_transfer');
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:pending,verifying,success,failed,rejected,fraudulent',
            'search' => 'nullable|string|max:120',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'sort' => 'nullable|string|max:40',
            'order' => 'nullable|in:asc,desc',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
        ]);

        $query = $this->baseQuery()->with('user');

        // The old implementation inferred "fraudulent" by LIKE-matching the
        // free-text status_message, which broke the moment an admin typed a
        // message containing "fraud" for an unrelated reason. There is now a
        // real column.
        match ((string) $request->input('status', '')) {
            'fraudulent' => $query->where('is_fraudulent', true),
            'rejected' => $query->where('status', 'failed')->where('is_fraudulent', false),
            'pending' => $query->whereIn('status', self::REVIEWABLE),
            default => $request->filled('status')
                ? $query->where('status', (string) $request->input('status', ''))
                : null,
        };

        if ($request->filled('search')) {
            $search = (string) $request->input('search', '');
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }
        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', (float) $request->input('min_amount', 0));
        }
        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', (float) $request->input('max_amount', 0));
        }

        [$sort, $direction] = $this->resolveSort($request);
        $transfers = $query->orderBy($sort, $direction)->paginate(20)->withQueryString();

        $stats = [
            'pending' => $this->baseQuery()->whereIn('status', self::REVIEWABLE)->count(),
            'pending_value' => (float) $this->baseQuery()->whereIn('status', self::REVIEWABLE)->sum('amount'),
            'approved' => $this->baseQuery()->where('status', 'success')->count(),
            'rejected' => $this->baseQuery()->where('status', 'failed')->where('is_fraudulent', false)->count(),
            'fraudulent' => $this->baseQuery()->where('is_fraudulent', true)->count(),
        ];

        return view('admin.bank-transfers.index', compact('transfers', 'stats'));
    }

    public function show(Transactions $transfer)
    {
        $this->assertIsBankTransfer($transfer);

        $transfer->load('user');

        return view('admin.bank-transfers.show', ['transfer' => $transfer, 'meta' => $transfer->meta ?? []]);
    }

    /**
     * Approve a transfer and credit the wallet.
     *
     * Rewritten to run inside a single locked transaction and to reuse
     * WalletService. Previously it incremented `balance` AND decremented
     * `pending_balance`, but nothing ever *added* to `pending_balance`, so the
     * decrement drove the column negative.
     */
    public function approve(Request $request, Transactions $transfer)
    {
        $this->assertIsBankTransfer($transfer);

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:500',
        ]);

        $outcome = DB::transaction(function () use ($transfer, $validated) {
            $locked = Transactions::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::REVIEWABLE, true)) {
                return ['status' => 'not_reviewable', 'transaction' => $locked];
            }

            if (! $locked->user) {
                throw new \RuntimeException('Transfer has no associated customer.');
            }

            $movement = $this->wallets->credit($locked->user, (float) $locked->amount);

            $locked->forceFill([
                'status' => 'success',
                'payment_status' => 'success',
                'status_message' => 'Approved by admin' . ($validated['remarks'] ? ': ' . $validated['remarks'] : '.'),
                'balance_after' => $movement['balance_after'],
                'completed_at' => now(),
                'meta' => array_merge($locked->meta ?? [], [
                    'approved_by' => Auth::id(),
                    'approved_at' => now()->toDateTimeString(),
                    'approval_remarks' => $validated['remarks'] ?? null,
                ]),
            ])->save();

            AdminLog::log(Auth::id(), 'approve_bank_transfer', [
                'transaction_id' => $locked->id,
                'user_id' => $locked->user_id,
                'amount' => (float) $locked->amount,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return ['status' => 'credited', 'transaction' => $locked];
        });

        if ($outcome['status'] === 'not_reviewable') {
            return back()->with('error', 'That transfer has already been actioned.');
        }

        return redirect()->route('admin.bank-transfers.index')->with(
            'success',
            'Transfer approved. ₦' . number_format((float) $outcome['transaction']->amount, 2) . ' credited.'
        );
    }

    public function reject(Request $request, Transactions $transfer)
    {
        $this->assertIsBankTransfer($transfer);

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $outcome = DB::transaction(function () use ($transfer, $validated) {
            $locked = Transactions::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::REVIEWABLE, true)) {
                return ['status' => 'not_reviewable', 'transaction' => $locked];
            }

            $locked->forceFill([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => 'Rejected by admin: ' . $validated['reason'],
                'completed_at' => now(),
                'meta' => array_merge($locked->meta ?? [], [
                    'rejected_by' => Auth::id(),
                    'rejected_at' => now()->toDateTimeString(),
                    'rejection_reason' => $validated['reason'],
                ]),
            ])->save();

            AdminLog::log(Auth::id(), 'reject_bank_transfer', [
                'transaction_id' => $locked->id,
                'user_id' => $locked->user_id,
                'amount' => (float) $locked->amount,
                'reason' => $validated['reason'],
            ]);

            return ['status' => 'rejected', 'transaction' => $locked];
        });

        if ($outcome['status'] === 'not_reviewable') {
            return back()->with('error', 'That transfer has already been actioned.');
        }

        return redirect()->route('admin.bank-transfers.index')->with('success', 'Transfer rejected.');
    }

    public function markFraudulent(Request $request, Transactions $transfer)
    {
        $this->assertIsBankTransfer($transfer);

        $validated = $request->validate([
            'fraud_reason' => 'required|string|min:5|max:500',
            'suspend_user' => 'boolean',
        ]);

        DB::transaction(function () use ($transfer, $validated) {
            $locked = Transactions::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => 'failed',
                'payment_status' => 'failed',
                'is_fraudulent' => true,
                'status_message' => 'Marked fraudulent: ' . $validated['fraud_reason'],
                'completed_at' => now(),
                'meta' => array_merge($locked->meta ?? [], [
                    'fraud_marked_by' => Auth::id(),
                    'fraud_marked_at' => now()->toDateTimeString(),
                    'fraud_reason' => $validated['fraud_reason'],
                ]),
            ])->save();

            if ($request->boolean('suspend_user') && $locked->user) {
                $locked->user->forceFill(['status' => 'suspended'])->save();
            }

            AdminLog::log(Auth::id(), 'mark_bank_transfer_fraud', [
                'transaction_id' => $locked->id,
                'user_id' => $locked->user_id,
                'amount' => (float) $locked->amount,
                'fraud_reason' => $validated['fraud_reason'],
                'suspended_user' => $request->boolean('suspend_user'),
            ]);
        });

        return redirect()->route('admin.bank-transfers.index')
            ->with('success', 'Transfer flagged as fraudulent.');
    }

    /**
     * Stream a proof of payment to an authorised admin.
     *
     * The file lives on the private disk now, so this is the only way to read
     * it. Previously `proof` was written to the `public` disk and served from
     * a predictable URL with no authentication at all, and the admin viewer
     * referenced a class that does not exist (`Transaction`).
     */
    public function viewProof(Transactions $transfer): StreamedResponse
    {
        return $this->streamProof($transfer, inline: true);
    }

    public function downloadProof(Transactions $transfer): StreamedResponse
    {
        return $this->streamProof($transfer, inline: false);
    }

    private function streamProof(Transactions $transfer, bool $inline): StreamedResponse
    {
        $this->assertIsBankTransfer($transfer);

        $path = $transfer->meta['proof_path'] ?? null;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404, 'No proof of payment has been uploaded for this transfer.');
        }

        if (! $inline) {
            AdminLog::log(Auth::id(), 'download_bank_proof', ['transaction_id' => $transfer->id]);
        }

        $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
        $name = 'proof-' . $transfer->reference . '.' . $extension;

        return response()->streamDownload(
            fn () => fpassthru(Storage::disk('local')->readStream($path)),
            $name,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
                // Never let a browser sniff an uploaded file into something executable.
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function assertIsBankTransfer(Transactions $transfer): void
    {
        if ($transfer->service_type !== 'funding' || $transfer->payment_method !== 'bank_transfer') {
            throw ValidationException::withMessages([
                'transfer' => 'That record is not a bank transfer funding transaction.',
            ]);
        }
    }
}
