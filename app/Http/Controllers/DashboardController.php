<?php

namespace App\Http\Controllers;

use App\Catalogue\ServiceCatalogue;
use App\Models\ChatSession;
use App\Models\PromotionNotification;
use App\Models\Transactions;
use App\Services\DashboardPrompts;
use App\Services\SecurityService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ServiceCatalogue $catalogue,
        private readonly DashboardPrompts $prompts,
        private readonly SecurityService $security,
    ) {
    }

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

        /*
         * The dashboard's service sections, assembled from the database rather than
         * listed in the template (§51.42). A template that named its own services
         * would keep offering one after its last provider was withdrawn, and the
         * customer would find out at the payment step.
         *
         * No price is included: a price belongs to a quote at checkout.
         */
        $catalogue = $this->catalogue->payload();

        /*
         * The greeting follows a local clock, and §51.8's copy uses `{first_name}`.
         *
         * The first name is now a field of its own — collected at sign-up, because
         * Paystack needs it for the customer's account name — so the greeting no
         * longer has to guess it by splitting `name` on the first space. The
         * accessor still falls back to that split for accounts that predate the
         * field, and `?: $user->name` covers an account with no name at all, so a
         * greeting is always a greeting rather than a blank.
         *
         * The timezone is the one place where this is a genuine approximation rather
         * than a derivation: a customer abroad reads the greeting of the deployment's
         * timezone. It is a greeting, so the cost of being wrong is a slightly odd
         * salutation rather than a wrong amount, and a per-account timezone is the
         * upgrade if it ever matters.
         */
        $greetingName = trim((string) $user->first_name) ?: trim((string) $user->name);
        $localNow = now()->setTimezone(config('app.timezone', 'UTC'));

        /*
         * The two dashboard prompts: the PIN reminder and the promotional modal.
         *
         * Resolved in one call because they compete for the same thing — the one
         * dialog a customer will read. `DashboardPrompts` owns that decision so the
         * template does not have to, and so it can be tested without rendering a
         * page.
         */
        $prompts = $this->prompts->forDashboard($user, $request, $this->security);

        // The affiliate link needs the customer's own code, which the modal cannot
        // look up from a config file.
        $referralCode = (string) $user->referral_code;

        $response = response()->view('dashboard', compact(
            'user',
            'userStats',
            'recentTransactions',
            'userChats',
            'recentActivity',
            'transactionStats',
            'quickActions',
            'announcements',
            'showTips',
            'catalogue',
            'greetingName',
            'localNow',
            'prompts',
            'referralCode',
        ));

        /*
         * The promotion is recorded as shown by the response, not by the request
         * that renders it.
         *
         * A cookie can only be attached to a response, and recording it here means
         * the window is consumed exactly when the markup carrying the dialog is
         * actually sent — not when a page was merely considered. `show_promotion`
         * is checked rather than `promotion`, because a deferred promotion (the
         * account has no PIN) must not consume its own frequency window: it was
         * chosen but never shown, and counting it would silently skip a customer's
         * next opportunity.
         */


        if ($prompts['show_promotion']) {
            $response->withCookie(cookie(
                DashboardPrompts::PROMO_COOKIE,
                $this->prompts->promotionCookie($user, $request),
                (int) config('prompts.dismissal_minutes', 525600),
            ));
        }

        return $response;
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
