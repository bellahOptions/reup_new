<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transactions;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    // Display all transactions for the user
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = $user->transactions()->latest();
        
        // Apply filters
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->service_type);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('recipient', 'like', "%{$search}%");
            });
        }
        
        $transactions = $query->paginate(15);
        $filters = $request->only(['type', 'service_type', 'status', 'date', 'search']);
        
        // Get statistics
        $statistics = [
            'total_transactions' => $user->transactions()->count(),
            'total_spent' => $user->transactions()->where('type', 'debit')->sum('amount'),
            'total_funded' => $user->transactions()->where('type', 'credit')->sum('amount'),
            'pending_transactions' => $user->transactions()->where('status', 'pending')->count(),
        ];
        
        return view('transactions.index', compact('transactions', 'filters', 'statistics'));
    }
    
    // Show single transaction details
    public function show($reference)
    {
        $transaction = Auth::user()->transactions()
            ->where('reference', $reference)
            ->firstOrFail();
            
        return view('transactions.show', compact('transaction'));
    }
    
    // Export transactions (optional)
    public function export(Request $request)
    {
        $user = Auth::user();
        
        $transactions = $user->transactions()
            ->when($request->filled('start_date'), function($query) use ($request) {
                return $query->whereDate('created_at', '>=', $request->start_date);
            })
            ->when($request->filled('end_date'), function($query) use ($request) {
                return $query->whereDate('created_at', '<=', $request->end_date);
            })
            ->get();
            
        // Implement CSV or PDF export here
        return response()->json(['message' => 'Export feature coming soon']);
    }

    public function markAsProcessing(): void
{
    $this->update([
        'status' => 'processing',
        'updated_at' => now(),
    ]);
}

public function markAsSuccess(?string $apiReference = null, ?array $apiResponse = null): void
{
    $this->update([
        'status' => 'success',
        'payment_status' => 'success',
        'api_reference' => $apiReference,
        'api_response' => $apiResponse,
        'completed_at' => now(),
    ]);
}

public function markAsFailed(string $reason): void
{
    $this->update([
        'status' => 'failed',
        'payment_status' => 'failed',
        'failure_reason' => $reason,
        'completed_at' => now(),
    ]);
}
}