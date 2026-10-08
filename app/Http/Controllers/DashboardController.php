<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\PromotionNotification;
use App\Models\Transactions;
use App\Support\Money;
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

        /*
         * Five counters over the same user's transactions, collapsed into one
         * query.
         *
         * As five separate `count()`/`sum()` calls this was five round trips for
         * data that comes from one index range — and it ran on every dashboard
         * load, which is the most frequently requested authenticated page in the
         * product. Conditional aggregation gets the same figures in one pass.
         *
         * The sums come back as decimal strings and are read through `Money`, so
         * the dashboard cannot disagree with the wallet by a kobo.
         */
        $aggregate = Transactions::forUser($user->id)
            ->selectRaw('COUNT(*) AS total_transactions')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful_transactions")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_transactions")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' AND status = 'success' AND created_at >= ? THEN amount ELSE 0 END), 0) AS spent_this_month", [$monthStart])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' AND status = 'success' AND created_at >= ? THEN amount ELSE 0 END), 0) AS funded_this_month", [$monthStart])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' AND status = 'success' THEN amount ELSE 0 END), 0) AS total_spent")
            ->selectRaw("COALESCE(AVG(CASE WHEN status = 'success' THEN amount END), 0) AS average_transaction")
            ->first();

        $userStats = [
            // `balance` and `status` were read from the users table by the old
            // controller (`$user->balance`, `$user->status`); balance lives on
            // the wallets table and the accessor resolves it.
            'balance' => $user->wallet_balance,
            'total_transactions' => (int) $aggregate->total_transactions,
            'successful_transactions' => (int) $aggregate->successful_transactions,
            'pending_transactions' => (int) $aggregate->pending_transactions,
            'spent_this_month' => Money::fromDatabase($aggregate->spent_this_month)->toFloat(),
            'funded_this_month' => Money::fromDatabase($aggregate->funded_this_month)->toFloat(),
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
            'total_spent' => Money::fromDatabase($aggregate->total_spent)->toFloat(),
            /*
             * `AVG()` is the one aggregate that is not a sum of `decimal(15,2)`
             * values — MySQL returns it with more places than the column has
             * (e.g. `1666.666667`), which is not a representable naira amount.
             * It is rounded to the kobo *here*, explicitly, because `Money`
             * refuses to guess and would otherwise reject the value outright.
             */
            'average_transaction' => Money::fromNaira(
                number_format((float) $aggregate->average_transaction, 2, '.', '')
            )->toFloat(),
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
            'balance' => $user->wallet_balance,
            'unread_messages' => $user->unread_chats_count,
            'pending_transactions' => Transactions::forUser($user->id)->where('status', 'pending')->count(),
            'active_chats' => ChatSession::where('user_id', $user->id)->where('status', 'active')->count(),
        ]);
    }
}
