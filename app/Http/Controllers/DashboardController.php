<?php

namespace App\Http\Controllers; 

use App\Models\User;
use App\Models\Transactions;
use App\Models\ChatSession;
use App\Models\ContactMessage;
use App\Models\PromotionNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // Get the authenticated user
        $user = Auth::user();
        
       $announcements = PromotionNotification::where('is_active', true)
    ->orderBy('created_at', 'desc')
    ->limit(6) 
    ->get();

        // User-specific statistics
        $userStats = [
            'balance' => $user->balance ?? 0,
            'total_transactions' => Transactions::where('user_id', $user->id)->count(),
            'completed_transactions' => Transactions::where('user_id', $user->id)
                ->where('status', 'completed')
                ->count(),
            'pending_transactions' => Transactions::where('user_id', $user->id)
                ->where('status', 'pending')
                ->count(),
        ];

        // User's recent transactions
        $recentTransactions = Transactions::where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get();

        // User's chat sessions
        $userChats = ChatSession::where('user_id', $user->id)
            ->with(['messages' => function($query) {
                $query->latest()->limit(1);
            }])
            ->latest('last_message_at')
            ->limit(5)
            ->get();

        // User's recent activity
        $recentActivity = [
            'last_login' => $user->last_login_at,
            'account_created' => $user->created_at,
            'status' => $user->status,
        ];

        // If user has transactions, get some stats
        $transactionStats = null;
        if ($recentTransactions->count() > 0) {
            $transactionStats = [
                'total_spent' => Transactions::where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->sum('amount'),
                'average_transaction' => Transactions::where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->avg('amount'),
                'last_transaction_date' => Transactions::where('user_id', $user->id)
                    ->latest()
                    ->first()
                    ->created_at ?? null,
            ];
        }

        // Quick actions/links for the user
        $quickActions = [
            ['title' => 'Make a Payment', 'route' => 'payments.create', 'icon' => 'credit-card'],
            ['title' => 'Start Chat', 'route' => 'chats.create', 'icon' => 'message-square'],
            ['title' => 'View Transactions', 'route' => 'transactions.index', 'icon' => 'list'],
            ['title' => 'Profile Settings', 'route' => 'profile.edit', 'icon' => 'user'],
        ];

        // Return USER dashboard view (not admin)
        return view('dashboard', compact(
            'user',
            'userStats',
            'recentTransactions',
            'userChats',
            'recentActivity',
            'transactionStats',
            'quickActions',
            'announcements'
        ));
    }

    public function getRealtimeStats()
    {
        $user = Auth::user();
        
        return response()->json([
            'user_balance' => $user->balance ?? 0,
            'unread_messages' => DB::table('chat_messages')
                ->where('chat_session_id', function($query) use ($user) {
                    $query->select('id')
                        ->from('chat_sessions')
                        ->where('user_id', $user->id)
                        ->where('status', 'active')
                        ->limit(1);
                })
                ->where('is_read', false)
                ->where('sender_type', '!=', 'user') // Messages not from the user
                ->count(),
            'pending_transactions' => Transactions::where('user_id', $user->id)
                ->where('status', 'pending')
                ->count(),
            'active_chats' => ChatSession::where('user_id', $user->id)
                ->where('status', 'active')
                ->count(),
        ]);
    }
}