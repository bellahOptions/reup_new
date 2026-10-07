<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\PromotionNotification;
use App\Models\Transactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // Filters the view's GET form actually submits.
        $filters = $request->validate([
            'type' => 'nullable|in:credit,debit',
            'status' => 'nullable|in:pending,processing,success,failed,cancelled,verifying',
            'date' => 'nullable|date',
        ]);

        // `live()` is the shared active-and-in-window predicate; this query
        // previously reimplemented it inline and could drift from the others.
        $announcements = PromotionNotification::query()
            ->live()
            ->latest()
            ->limit(6)
            ->get();

        $recentTransactions = Transactions::forUser($user->id)
            ->when(! empty($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['date']), fn ($q) => $q->whereDate('created_at', $filters['date']))
            ->latest()
            ->limit(10)
            ->get();

        $monthStart = now()->startOfMonth();

        $userStats = [
            // `balance` and `status` were read from the users table by the old
            // controller (`$user->balance`, `$user->status`); balance lives on
            // the wallets table and the accessor resolves it.
            'balance' => (float) $user->wallet_balance,
            'total_transactions' => Transactions::forUser($user->id)->count(),
            'successful_transactions' => Transactions::forUser($user->id)->where('status', 'success')->count(),
            'pending_transactions' => Transactions::forUser($user->id)->where('status', 'pending')->count(),
            'spent_this_month' => (float) Transactions::forUser($user->id)
                ->where('type', 'debit')->where('status', 'success')
                ->where('created_at', '>=', $monthStart)->sum('amount'),
            'funded_this_month' => (float) Transactions::forUser($user->id)
                ->where('type', 'credit')->where('status', 'success')
                ->where('created_at', '>=', $monthStart)->sum('amount'),
        ];

        $userChats = ChatSession::where('user_id', $user->id)
            ->with(['messages' => fn ($q) => $q->latest()->limit(1)])
            ->latest('last_message_at')
            ->limit(5)
            ->get();

        $recentActivity = [
            'last_login' => $user->last_login_at,
            'account_created' => $user->created_at,
            'status' => $user->status,
        ];

        $transactionStats = [
            'total_spent' => (float) Transactions::forUser($user->id)
                ->where('type', 'debit')->where('status', 'success')->sum('amount'),
            'average_transaction' => (float) (Transactions::forUser($user->id)
                ->where('status', 'success')->avg('amount') ?? 0),
            'last_transaction_date' => Transactions::forUser($user->id)->latest()->value('created_at'),
        ];

        // Quick actions point at routes that actually exist. The old array
        // referenced payments.create / chats.create / profile.edit, none of
        // which are registered, so every link 500'd.
        $quickActions = [
            ['title' => 'Buy airtime', 'route' => 'airtime-data.index', 'icon' => 'device-phone-mobile'],
            ['title' => 'Buy data', 'route' => 'airtime-data.index', 'icon' => 'signal'],
            ['title' => 'Cable TV', 'route' => 'cable-tv.index', 'icon' => 'tv'],
            ['title' => 'Electricity', 'route' => 'electricity.index', 'icon' => 'bolt'],
            ['title' => 'Fund wallet', 'route' => 'wallet.fund', 'icon' => 'wallet'],
            ['title' => 'Transaction history', 'route' => 'transactions.index', 'icon' => 'queue-list'],
            ['title' => 'Profile', 'route' => 'profile.index', 'icon' => 'user'],
        ];

        // Support is a WhatsApp deep link, not a route. Appended only when a
        // number is configured, so the tile never renders as a dead link.
        if ($whatsappUrl = config('services.support.whatsapp_url')) {
            $quickActions[] = [
                'title' => 'Support on WhatsApp',
                'url' => $whatsappUrl,
                'icon' => 'whatsapp',
            ];
        }

        /*
         * First sign-in introduction. Shown until the user finishes or skips it.
         *
         * Driven off `tips_seen_at` rather than session state so it follows the
         * account across devices — a session flag would re-introduce the app on
         * every new browser, which is exactly the nagging the tips are meant to
         * avoid.
         */
        $showTips = $user->tips_seen_at === null;

        return view('dashboard', compact(
            'user',
            'userStats',
            'recentTransactions',
            'userChats',
            'recentActivity',
            'transactionStats',
            'quickActions',
            'announcements',
            'showTips',
        ));
    }

    public function getRealtimeStats()
    {
        $user = Auth::user();

        return response()->json([
            'balance' => (float) $user->wallet_balance,
            'unread_messages' => $user->unread_chats_count,
            'pending_transactions' => Transactions::forUser($user->id)->where('status', 'pending')->count(),
            'active_chats' => ChatSession::where('user_id', $user->id)->where('status', 'active')->count(),
        ]);
    }
}
