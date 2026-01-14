<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\AdminLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class BankTransferController extends Controller
{
    // Display pending bank transfers
     public function index(Request $request)
    {
        $query = Transactions::where('service_type', 'funding')
            ->where('payment_method', 'bank_transfer')
            ->with('user');

        // Filter by status
        if ($request->filled('status')) {
            $status = $request->status;
            
            // Handle special cases for 'fraudulent'
            if ($status === 'fraudulent') {
                $query->where('status', 'failed')
                    ->where(function($q) {
                        $q->where('status_message', 'like', '%fraud%')
                          ->orWhere('status_message', 'like', '%fraudulent%')
                          ->orWhere('status_message', 'like', '%scam%');
                    });
            } else {
                $query->where('status', $status);
            }
        } else {
            // Default: show all except fraudulent (if you want to show all by default)
            $query->whereNotIn('status', []);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('user', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by date
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sort by created_at desc by default
        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        $transfers = $query->paginate(20)->withQueryString();

        $stats = [
            'pending' => Transactions::where('service_type', 'funding')
                ->where('payment_method', 'bank_transfer')
                ->where('status', 'pending')
                ->count(),
            'approved' => Transactions::where('service_type', 'funding')
                ->where('payment_method', 'bank_transfer')
                ->where('status', 'success')
                ->count(),
            'rejected' => Transactions::where('service_type', 'funding')
                ->where('payment_method', 'bank_transfer')
                ->where('status', 'failed')
                ->where(function($q) {
                    $q->where('status_message', 'not like', '%fraud%')
                      ->where('status_message', 'not like', '%fraudulent%')
                      ->where('status_message', 'not like', '%scam%');
                })
                ->count(),
            'fraudulent' => Transactions::where('service_type', 'funding')
                ->where('payment_method', 'bank_transfer')
                ->where('status', 'failed')
                ->where(function($q) {
                    $q->where('status_message', 'like', '%fraud%')
                      ->orWhere('status_message', 'like', '%fraudulent%')
                      ->orWhere('status_message', 'like', '%scam%');
                })
                ->count(),
        ];

        return view('admin.bank-transfers.index', compact('transfers', 'stats'));
    }

    // Show bank transfer details
// Show bank transfer details (for modal)
public function show($id)
{
    $transfer = Transactions::where('service_type', 'funding')
        ->where('payment_method', 'bank_transfer')
        ->with('user')
        ->findOrFail($id);

    $meta = json_decode($transfer->meta, true);
    
    // If AJAX request, return modal content only
    if (request()->ajax()) {
        return view('admin.bank-transfers.show', compact('transfer', 'meta'));
    }

    // For direct access, return full page (or redirect)
    return redirect()->route('admin.bank-transfers.index');
}

    // Approve bank transfer
    public function approve(Request $request, $id)
    {
        $transfer = Transactions::where('service_type', 'funding')
            ->where('payment_method', 'bank_transfer')
            ->where('status', 'pending')
            ->with('user')
            ->findOrFail($id);

        $request->validate([
            'remarks' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            // Update transaction
            $transfer->update([
                'status' => 'success',
                'payment_status' => 'success',
                'status_message' => 'Approved by admin: ' . ($request->remarks ?? 'No remarks'),
                'completed_at' => now(),
                'meta' => json_encode(array_merge(
                    json_decode($transfer->meta, true) ?? [],
                    [
                        'approved_by' => Auth::id(),
                        'approved_at' => now()->toDateTimeString(),
                        'approval_remarks' => $request->remarks,
                        'admin_action' => 'approved'
                    ]
                ))
            ]);

            // Update user wallet
            $user = $transfer->user;
            if ($user && $user->wallet) {
                // Deduct from pending balance and add to actual balance
                $user->wallet->decrement('pending_balance', $transfer->amount);
                $user->wallet->increment('balance', $transfer->amount);
                $user->wallet->increment('total_funded', $transfer->amount);
                $user->wallet->increment('transaction_count');

                // Update transaction with final balances
                $transfer->update([
                    'balance_after' => $user->wallet->fresh()->balance
                ]);
            }

            // Log the approval
            AdminLog::log(Auth::id(), 'approve_bank_transfer', [
                'transaction_id' => $transfer->id,
                'user_id' => $user->id,
                'amount' => $transfer->amount,
                'remarks' => $request->remarks
            ]);

            DB::commit();

            return redirect()->route('admin.bank-transfers.index')
                ->with('success', 'Bank transfer approved successfully. User wallet credited.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bank transfer approval failed: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Approval failed: ' . $e->getMessage()]);
        }
    }

    // Reject bank transfer
    public function reject(Request $request, $id)
    {
        $transfer = Transactions::where('service_type', 'funding')
            ->where('payment_method', 'bank_transfer')
            ->where('status', 'pending')
            ->with('user')
            ->findOrFail($id);

        $request->validate([
            'reason' => 'required|string|max:500',
            'refund_pending_balance' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            // Update transaction
            $transfer->update([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => 'Rejected by admin: ' . $request->reason,
                'meta' => json_encode(array_merge(
                    json_decode($transfer->meta, true) ?? [],
                    [
                        'rejected_by' => Auth::id(),
                        'rejected_at' => now()->toDateTimeString(),
                        'rejection_reason' => $request->reason,
                        'admin_action' => 'rejected'
                    ]
                ))
            ]);

            // If refund pending balance is requested
            if ($request->boolean('refund_pending_balance')) {
                $user = $transfer->user;
                if ($user && $user->wallet) {
                    // Deduct from pending balance (since transfer was rejected)
                    $user->wallet->decrement('pending_balance', $transfer->amount);
                }
            }

            // Log the rejection
            AdminLog::log(Auth::id(), 'reject_bank_transfer', [
                'transaction_id' => $transfer->id,
                'user_id' => $transfer->user_id,
                'amount' => $transfer->amount,
                'reason' => $request->reason,
                'refund_pending_balance' => $request->boolean('refund_pending_balance')
            ]);

            DB::commit();

            return redirect()->route('admin.bank-transfers.index')
                ->with('success', 'Bank transfer rejected successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bank transfer rejection failed: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Rejection failed: ' . $e->getMessage()]);
        }
    }

    // Mark as fraudulent
    public function markFraudulent(Request $request, $id)
    {
        $transfer = Transactions::where('service_type', 'funding')
            ->where('payment_method', 'bank_transfer')
            ->with('user')
            ->findOrFail($id);

        $request->validate([
            'fraud_reason' => 'required|string|max:500',
            'suspend_user' => 'boolean',
            'block_user' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            // Update transaction
            $transfer->update([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => 'Marked as fraudulent: ' . $request->fraud_reason,
                'meta' => json_encode(array_merge(
                    json_decode($transfer->meta, true) ?? [],
                    [
                        'fraud_marked_by' => Auth::id(),
                        'fraud_marked_at' => now()->toDateTimeString(),
                        'fraud_reason' => $request->fraud_reason,
                        'admin_action' => 'fraudulent'
                    ]
                ))
            ]);

            // Update user wallet (deduct pending balance)
            $user = $transfer->user;
            if ($user && $user->wallet) {
                $user->wallet->decrement('pending_balance', $transfer->amount);
            }

            // Take action against user if requested
            if ($request->boolean('suspend_user')) {
                $user->update(['status' => 'suspended']);
            }

            if ($request->boolean('block_user')) {
                // Add user to fraud list or block
                // You can create a separate fraud_users table or add a flag
                $user->update(['is_blocked' => true]);
            }

            // Log the fraud marking
            AdminLog::log(Auth::id(), 'mark_bank_transfer_fraud', [
                'transaction_id' => $transfer->id,
                'user_id' => $user->id,
                'amount' => $transfer->amount,
                'fraud_reason' => $request->fraud_reason,
                'actions_taken' => [
                    'suspend_user' => $request->boolean('suspend_user'),
                    'block_user' => $request->boolean('block_user')
                ]
            ]);

            DB::commit();

            return redirect()->route('admin.bank-transfers.index')
                ->with('success', 'Bank transfer marked as fraudulent. User account actions applied.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Mark fraud failed: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Operation failed: ' . $e->getMessage()]);
        }
    }

    // View proof image
    public function viewProof($id)
    {
        $transfer = Transaction::findOrFail($id);
        $meta = json_decode($transfer->meta, true);
        $proofPath = $meta['proof_path'] ?? null;

        if (!$proofPath || !Storage::exists($proofPath)) {
            abort(404, 'Proof file not found.');
        }

        $file = Storage::get($proofPath);
        $mimeType = Storage::mimeType($proofPath);

        return response($file, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="proof_' . $transfer->reference . '"'
        ]);
    }

    // Download proof
    public function downloadProof($id)
    {
        $transfer = Transaction::findOrFail($id);
        $meta = json_decode($transfer->meta, true);
        $proofPath = $meta['proof_path'] ?? null;

        if (!$proofPath || !Storage::exists($proofPath)) {
            abort(404, 'Proof file not found.');
        }

        return Storage::download($proofPath, 'proof_' . $transfer->reference . '.' . pathinfo($proofPath, PATHINFO_EXTENSION));
    }
}