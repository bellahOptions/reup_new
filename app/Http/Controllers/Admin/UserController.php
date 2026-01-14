<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transactions;
use App\Models\AdminLog;

class UserController extends Controller
{
     public function index(Request $request)
    {
        $query = User::regularUsers()->with('wallet');

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'verified':
                    $query->whereNotNull('email_verified_at');
                    break;
                case 'unverified':
                    $query->whereNull('email_verified_at');
                    break;
                case 'active':
                    $query->where('last_login_at', '>=', now()->subDays(30));
                    break;
                case 'inactive':
                    $query->where(function($q) {
                        $q->where('last_login_at', '<', now()->subDays(30))
                          ->orWhereNull('last_login_at');
                    });
                    break;
            }
        }

        // Filter by date
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sort
        $sort = $request->get('sort', 'created_at');
        $order = $request->get('order', 'desc');
        $query->orderBy($sort, $order);

        $users = $query->paginate(20);
        
        // Calculate stats for the stats cards
        $stats = [
            'total' => User::regularUsers()->count(),
            'active' => User::regularUsers()->where('last_login_at', '>=', now()->subDays(30))->count(),
            'verified' => User::regularUsers()->whereNotNull('email_verified_at')->count(),
            'new_users' => User::regularUsers()->where('created_at', '>=', now()->subDays(30))->count(),
            'total_today' => User::regularUsers()->whereDate('created_at', today())->count(),
            'total_this_week' => User::regularUsers()->where('created_at', '>=', now()->startOfWeek())->count(),
            'total_this_month' => User::regularUsers()->where('created_at', '>=', now()->startOfMonth())->count(),
            'online_now' => User::where('last_login_at', '>=', now()->subMinutes(5))->count(),
        ];

        // For backward compatibility
        $totalUsers = $stats['total'];
        $verifiedUsers = $stats['verified'];
        $activeUsers = $stats['active'];

        return view('admin.users.index', compact(
            'users', 
            'totalUsers', 
            'verifiedUsers', 
            'activeUsers',
            'stats' // Make sure to pass stats
        ));
    }


    // Show user details
    public function show($id)
    {
        $user = User::regularUsers()->with('wallet')->findOrFail($id);
        
        $transactions = Transactions::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $stats = [
            'total_transactions' => Transactions::where('user_id', $id)->count(),
            'successful_transactions' => Transactions::where('user_id', $id)->where('status', 'success')->count(),
            'total_spent' => Transactions::where('user_id', $id)->where('type', 'debit')->sum('amount'),
            'total_funded' => Transactions::where('user_id', $id)->where('type', 'credit')->sum('amount'),
            'last_transaction' => Transactions::where('user_id', $id)->latest()->first(),
        ];

        return view('admin.users.show', compact('user', 'transactions', 'stats'));
    }

    // Show edit user form
    public function edit($id)
    {
        $user = User::regularUsers()->findOrFail($id);
        return view('admin.users.edit', compact('user'));
    }

    // Update user
    public function update(Request $request, $id)
    {
        $user = User::regularUsers()->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'status' => 'nullable|in:active,suspended',
        ]);

        $oldData = $user->toArray();

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);

        // If email changed, reset verification
        if ($oldData['email'] !== $request->email) {
            $user->email_verified_at = null;
            $user->save();
        }

        // Log the update
        AdminLog::log(Auth::id(), 'update_user', [
            'user_id' => $user->id,
            'old_data' => $oldData,
            'new_data' => $user->toArray()
        ]);

        return redirect()->route('admin.users.show', $user->id)
            ->with('success', 'User updated successfully.');
    }

    // Update user wallet
    public function updateWallet(Request $request, $id)
    {
        $user = User::regularUsers()->with('wallet')->findOrFail($id);

        $request->validate([
            'action' => 'required|in:add,deduct,set',
            'amount' => 'required|numeric|min:0',
            'reason' => 'required|string|max:500',
        ]);

        $wallet = $user->wallet;
        $oldBalance = $wallet->balance;

        switch ($request->action) {
            case 'add':
                $newBalance = $wallet->balance + $request->amount;
                $wallet->balance = $newBalance;
                $wallet->total_funded = $wallet->total_funded + $request->amount;
                break;
            case 'deduct':
                $newBalance = $wallet->balance - $request->amount;
                if ($newBalance < 0) {
                    return back()->withErrors(['amount' => 'Insufficient balance.']);
                }
                $wallet->balance = $newBalance;
                $wallet->total_withdrawn = $wallet->total_withdrawn + $request->amount;
                break;
            case 'set':
                $newBalance = $request->amount;
                $wallet->balance = $newBalance;
                break;
        }

        $wallet->save();

        // Create transaction record for manual adjustment
        Transaction::create([
            'user_id' => $user->id,
            'reference' => 'ADJ-' . strtoupper(uniqid()),
            'type' => $request->action === 'add' ? 'credit' : 'debit',
            'service_type' => 'manual_adjustment',
            'description' => 'Manual balance adjustment: ' . $request->reason,
            'amount' => $request->amount,
            'service_fee' => 0,
            'total_amount' => $request->amount,
            'balance_before' => $oldBalance,
            'balance_after' => $newBalance,
            'payment_method' => 'manual',
            'payment_status' => 'success',
            'status' => 'success',
            'api_reference' => null,
            'meta' => json_encode([
                'admin_id' => Auth::id(),
                'action' => $request->action,
                'reason' => $request->reason,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance
            ])
        ]);

        // Log the action
        AdminLog::log(Auth::id(), 'adjust_wallet', [
            'user_id' => $user->id,
            'action' => $request->action,
            'amount' => $request->amount,
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance,
            'reason' => $request->reason
        ]);

        return redirect()->route('admin.users.show', $user->id)
            ->with('success', 'Wallet balance updated successfully.');
    }

    // Suspend/Unsuspend user
    public function toggleStatus($id)
    {
        $user = User::regularUsers()->findOrFail($id);

        $action = $user->status === 'suspended' ? 'activate' : 'suspend';
        $newStatus = $user->status === 'suspended' ? 'active' : 'suspended';

        $user->update(['status' => $newStatus]);

        AdminLog::log(Auth::id(), $action . '_user', [
            'user_id' => $user->id,
            'old_status' => $user->status,
            'new_status' => $newStatus
        ]);

        return back()->with('success', "User {$action}d successfully.");
    }

    // Delete user (soft delete)
    public function destroy($id)
    {
        $user = User::regularUsers()->findOrFail($id);

        // Check if user has balance
        if ($user->wallet && $user->wallet->balance > 0) {
            return back()->withErrors(['error' => 'Cannot delete user with wallet balance.']);
        }

        $user->delete();

        AdminLog::log(Auth::id(), 'delete_user', [
            'user_id' => $user->id,
            'user_email' => $user->email
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted successfully.');
    }

    // Export users
    public function export(Request $request)
    {
        $users = User::regularUsers()->with('wallet')->get();

        $csv = \League\Csv\Writer::createFromString('');
        $csv->insertOne([
            'ID', 'Name', 'Email', 'Phone', 'Email Verified', 'Balance', 
            'Total Funded', 'Total Withdrawn', 'Joined Date', 'Last Login'
        ]);

        foreach ($users as $user) {
            $csv->insertOne([
                $user->id,
                $user->name,
                $user->email,
                $user->phone ?? 'N/A',
                $user->email_verified_at ? 'Yes' : 'No',
                $user->wallet ? number_format($user->wallet->balance, 2) : '0.00',
                $user->wallet ? number_format($user->wallet->total_funded, 2) : '0.00',
                $user->wallet ? number_format($user->wallet->total_withdrawn, 2) : '0.00',
                $user->created_at->format('Y-m-d H:i:s'),
                $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : 'Never'
            ]);
        }

        return response((string) $csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="users_' . date('Y-m-d') . '.csv"',
        ]);
    }

    // AJAX search endpoint with suggestions
    public function search(Request $request)
    {
        $query = User::regularUsers()->with('wallet');
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        
        // Filter by status
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'active':
                    $query->where('last_login_at', '>=', now()->subDays(30));
                    break;
                case 'inactive':
                    $query->where(function($q) {
                        $q->where('last_login_at', '<', now()->subDays(30))
                          ->orWhereNull('last_login_at');
                    });
                    break;
            }
        }
        
        // Filter by verification
        if ($request->filled('verified')) {
            $query->where('email_verified_at', $request->verified == '1' ? '!=' : '=', null);
        }
        
        // Limit suggestions
        $users = $query->limit(10)->get(['id', 'name', 'email', 'phone', 'email_verified_at', 'last_login_at']);
        
        // Format suggestions
        $suggestions = $users->map(function($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'verified' => !empty($user->email_verified_at),
                'active' => $user->last_login_at && $user->last_login_at->diffInDays(now()) <= 30,
                'display' => "{$user->name} ({$user->email})"
            ];
        });
        
        return response()->json([
            'suggestions' => $suggestions,
            'count' => $suggestions->count()
        ]);
    }
    
    // AJAX search with pagination
    public function searchUsers(Request $request)
    {
        $query = User::regularUsers()->with('wallet');
        
        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        
        // Filter by status
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'active':
                    $query->where('last_login_at', '>=', now()->subDays(30));
                    break;
                case 'inactive':
                    $query->where(function($q) {
                        $q->where('last_login_at', '<', now()->subDays(30))
                          ->orWhereNull('last_login_at');
                    });
                    break;
            }
        }
        
        // Filter by verification
        if ($request->filled('verified')) {
            if ($request->verified == '1') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }
        
        // Sort
        $sort = $request->get('sort', 'created_at');
        $order = $request->get('order', 'desc');
        $query->orderBy($sort, $order);
        
        $users = $query->paginate(20);
        
        // Calculate stats for current filter
        $stats = [
            'filtered_total' => $users->total(),
            'filtered_active' => $query->clone()->where('last_login_at', '>=', now()->subDays(30))->count(),
            'filtered_verified' => $query->clone()->whereNotNull('email_verified_at')->count(),
        ];
        
        $html = view('admin.users.partials.users_table', compact('users'))->render();
        
        return response()->json([
            'html' => $html,
            'users' => $users,
            'stats' => $stats,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'per_page' => $users->perPage()
            ]
        ]);
    }

     // AJAX suggestions endpoint
    public function suggestions(Request $request)
    {
        $query = User::regularUsers();
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        
        $users = $query->limit(8)->get(['id', 'name', 'email', 'phone', 'email_verified_at', 'last_login_at']);
        
        $suggestions = $users->map(function($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'verified' => !empty($user->email_verified_at),
                'active' => $user->last_login_at && $user->last_login_at->diffInDays(now()) <= 30
            ];
        });
        
        return response()->json([
            'suggestions' => $suggestions
        ]);
    }
}