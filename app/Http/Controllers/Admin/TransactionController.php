<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Transactions;
use App\Models\User;
use App\Models\AdminLog;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
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

        // Create a new transaction based on the failed one
        $newTransaction = $transaction->replicate();
        $newTransaction->reference = 'RETRY-' . $transaction->reference;
        $newTransaction->status = 'pending';
        $newTransaction->status_message = 'Retry of failed transaction';
        $newTransaction->api_reference = null;
        $newTransaction->api_response = null;
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

    // Refund transaction
    public function refund(Request $request, $id)
    {
        $transaction = Transactions::findOrFail($id);

        if ($transaction->type !== 'debit') {
            return back()->withErrors(['error' => 'Only debit transactions can be refunded.']);
        }

        if ($transaction->status !== 'success') {
            return back()->withErrors(['error' => 'Only successful transactions can be refunded.']);
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        // Create refund transaction
        $refundTransaction = Transactions::create([
            'user_id' => $transaction->user_id,
            'reference' => 'REFUND-' . $transaction->reference,
            'type' => 'credit',
            'service_type' => 'refund',
            'description' => 'Refund for transaction ' . $transaction->reference . ': ' . $request->reason,
            'amount' => $transaction->amount,
            'service_fee' => 0,
            'total_amount' => $transaction->amount,
            'balance_before' => $transaction->user->wallet->balance,
            'balance_after' => $transaction->user->wallet->balance + $transaction->amount,
            'recipient' => $transaction->user->email,
            'provider' => 'manual',
            'payment_method' => 'refund',
            'payment_status' => 'success',
            'status' => 'success',
            'status_message' => 'Refund processed: ' . $request->reason,
            'api_reference' => null,
            'meta' => json_encode([
                'original_transaction_id' => $transaction->id,
                'original_reference' => $transaction->reference,
                'refund_reason' => $request->reason,
                'admin_id' => Auth::id()
            ]),
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        // Update user wallet
        $user = $transaction->user;
        if ($user && $user->wallet) {
            $user->wallet->increment('balance', $transaction->amount);
        }

        // Update original transaction
        $transaction->update([
            'status_message' => 'Refunded: ' . $request->reason,
            'meta' => json_encode(array_merge(
                json_decode($transaction->meta, true) ?? [],
                ['refunded' => true, 'refund_transaction_id' => $refundTransaction->id]
            ))
        ]);

        AdminLog::log(Auth::id(), 'refund_transaction', [
            'transaction_id' => $transaction->id,
            'refund_transaction_id' => $refundTransaction->id,
            'amount' => $transaction->amount,
            'reason' => $request->reason
        ]);

        return back()->with('success', 'Transaction refunded successfully. Refund ID: ' . $refundTransaction->id);
    }

    // Export transactions
    public function export(Request $request)
    {
        $query = Transactions::with('user');

        // Apply filters
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->get();

        $csv = \League\Csv\Writer::createFromString('');
        $csv->insertOne([
            'ID', 'Reference', 'User', 'Email', 'Type', 'Service Type', 'Description',
            'Amount', 'Fee', 'Total', 'Status', 'Payment Method', 'Recipient',
            'Created At', 'Completed At'
        ]);

        foreach ($transactions as $transaction) {
            $csv->insertOne([
                $transaction->id,
                $transaction->reference,
                $transaction->user->name ?? 'N/A',
                $transaction->user->email ?? 'N/A',
                $transaction->type,
                $transaction->service_type,
                $transaction->description,
                number_format($transaction->amount, 2),
                number_format($transaction->service_fee, 2),
                number_format($transaction->total_amount, 2),
                $transaction->status,
                $transaction->payment_method,
                $transaction->recipient,
                $transaction->created_at->format('Y-m-d H:i:s'),
                $transaction->completed_at ? $transaction->completed_at->format('Y-m-d H:i:s') : 'N/A'
            ]);
        }

        return response((string) $csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="transactions_' . date('Y-m-d') . '.csv"',
        ]);
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
    
    DB::beginTransaction();
    try {
        $transaction->update([
            'status' => 'success',
            'payment_status' => 'success',
            'status_message' => 'Forcefully marked as successful by admin',
            'completed_at' => now(),
        ]);
        
        // If it's a funding transaction, credit user wallet
        if ($transaction->service_type === 'funding' && $transaction->user) {
            $user = $transaction->user;
            if ($user->wallet) {
                $user->wallet->increment('balance', $transaction->amount);
                $user->wallet->increment('total_funded', $transaction->amount);
                
                $transaction->update([
                    'balance_after' => $user->wallet->fresh()->balance
                ]);
            }
        }
        
        DB::commit();
        return response()->json(['success' => true, 'message' => 'Transaction marked as successful']);
        
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
    }
}

public function forceFailed($id)
{
    $transaction = Transactions::findOrFail($id);
    
    $transaction->update([
        'status' => 'failed',
        'status_message' => 'Forcefully marked as failed by admin',
    ]);
    
    return response()->json(['success' => true, 'message' => 'Transaction marked as failed']);
}

public function cancel($id)
{
    $transaction = Transactions::findOrFail($id);
    
    DB::beginTransaction();
    try {
        $transaction->update([
            'status' => 'cancelled',
            'status_message' => 'Cancelled by admin',
        ]);
        
        // If payment was successful, refund user
        if ($transaction->payment_status === 'success' && $transaction->user) {
            $user = $transaction->user;
            if ($user->wallet) {
                $user->wallet->increment('balance', $transaction->amount);
                
                // Log refund transaction
                Transactions::create([
                    'user_id' => $user->id,
                    'reference' => Transactions::generateReference('RFND'),
                    'type' => 'credit',
                    'service_type' => 'refund',
                    'description' => 'Refund for cancelled transaction: ' . $transaction->reference,
                    'amount' => $transaction->amount,
                    'balance_before' => $user->wallet->balance - $transaction->amount,
                    'balance_after' => $user->wallet->balance,
                    'status' => 'success',
                    'payment_status' => 'success',
                    'completed_at' => now(),
                    'meta' => json_encode(['original_transaction_id' => $transaction->id])
                ]);
            }
        }
        
        DB::commit();
        return response()->json(['success' => true, 'message' => 'Transaction cancelled successfully']);
        
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
    }
}

public function updateStatus(Request $request, Transactions $transaction)
{
    $request->validate([
        'status' => 'required|in:pending,processing,success,failed,cancelled',
        'status_message' => 'nullable|string|max:500',
    ]);

    $oldStatus = $transaction->status;
    
    $transaction->update([
        'status' => $request->status,
        'status_message' => $request->status_message,
        'completed_at' => $request->status === 'success' ? now() : null,
    ]);

    // If transaction is now successful and it's a credit transaction, update user wallet
    if ($request->status === 'success' && $transaction->type === 'credit' && $transaction->service_type === 'funding') {
        $user = $transaction->user;
        if ($user && $user->wallet) {
            $user->wallet->increment('balance', $transaction->amount);
            $user->wallet->increment('total_funded', $transaction->amount);
        }
    }

    return response()->json([
        'success' => true,
        'message' => 'Transaction status updated successfully'
    ]);
}
}