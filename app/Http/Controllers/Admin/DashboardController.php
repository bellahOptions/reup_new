<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaystackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\ContactMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Services\ClubKonnectService;

class DashboardController extends Controller
{
    protected PaystackController $paystackController;
    protected ClubKonnectService $clubKonnectService;

    public function __construct(PaystackController $paystackController, ClubKonnectService $clubKonnectService)
    {
        $this->paystackController = $paystackController;
        $this->clubKonnectService = $clubKonnectService;
    }
    
    // Dashboard index
    public function index()
    {
        $stats = $this->getDashboardStats();
        $recentTransactions = $this->getRecentTransactions();
        $recentUsers = $this->getRecentUsers();
        
        // Get chart data
        $chartData = $this->getChartData();
    
        /*
         * Gateway figures come from PaystackService, not PaystackController.
         *
         * The controller actions return JsonResponse (they serve the AJAX
         * endpoints), so treating them as arrays threw
         * "Cannot use object of type Illuminate\Http\JsonResponse as array" and
         * the whole admin dashboard was a 500. The service returns plain arrays
         * and also caches, so the dashboard no longer makes a gateway call per
         * page load.
         */
        try {
            $paystack = app(\App\Services\PaystackService::class);

            $paystackBalance = $paystack->isConfigured()
                ? $paystack->balance()
                : ['success' => false, 'amount' => 0, 'currency' => 'NGN', 'message' => 'Not configured'];

            $paystackStats = $paystack->isConfigured()
                ? $paystack->transactionTotals('today')
                : ['success' => false, 'total_transactions' => 0, 'total_volume' => 0, 'pending_transfers' => 0];
        } catch (\Throwable $e) {
            Log::error('Failed to fetch Paystack data for dashboard: ' . $e->getMessage());

            $paystackBalance = [
                'amount' => 0,
                'currency' => 'NGN',
                'success' => false,
                'error' => $e->getMessage(),
            ];
            $paystackStats = [
                'success' => false,
                'total_transactions' => 0,
                'total_volume' => 0,
                'pending_transfers' => 0,
                'error' => $e->getMessage(),
            ];
        }
        
        // Chat statistics
        $chatStats = [
            'active_chats' => ChatSession::where('status', 'active')->count(),
            'pending_chats' => ChatSession::where('status', 'pending')->count(),
            'unread_messages' => ChatMessage::where('is_read', false)
                ->where('sender_type', 'user')
                ->count(),
            'total_messages' => ChatMessage::whereDate('created_at', today())->count(),
        ];
        
        // Contact message statistics
        $contactStats = [
            'unread' => ContactMessage::where('is_read', false)->count(),
            'today' => ContactMessage::whereDate('created_at', today())->count(),
        ];
        
        // User statistics
        $userStats = [
            'total' => User::where('is_admin', false)->where('is_super_admin', false)->count(),
            'online_now' => User::where(function($q) {
                $q->where('is_online', true)
                  ->orWhere('last_login_at', '>=', now()->subMinutes(5));
            })->count(),
        ];
        
        // Get online users
        $onlineUsers = User::where(function($q) {
                $q->where('is_online', true)
                  ->orWhere('last_login_at', '>=', now()->subMinutes(5));
            })
            ->select('id', 'name', 'email', 'admin_role', 'last_login_at', 'profile_picture')
            ->latest('last_login_at')
            ->limit(20)
            ->get();
        
        // Get recent chats
        $recentChats = ChatSession::with('user')
            ->whereIn('status', ['active', 'pending'])
            ->latest('last_message_at')
            ->limit(10)
            ->get();
        

             // Get ClubKonnect balance
    $clubKonnectBalance = $this->getClubKonnectBalance();

    // No payload dump here: it carries the provider account's phone number.
    // The outcome is already logged inside getClubKonnectBalance().

        // Combine all stats
        $stats = array_merge($stats, [
            // Paystack stats
            'paystack_balance' => $paystackBalance['amount'] ?? 0,
            'paystack_currency' => $paystackBalance['currency'] ?? 'NGN',
            'paystack_success' => $paystackBalance['success'] ?? false,
            'paystack_error' => $paystackBalance['error'] ?? null,
            'paystack_stats' => $paystackStats,

                    // ClubKonnect stats
        'clubkonnect_balance' => $clubKonnectBalance['balance'] ?? 0,
        'clubkonnect_currency' => $clubKonnectBalance['currency'] ?? 'NGN',
        'clubkonnect_success' => $clubKonnectBalance['success'] ?? false,
        'clubkonnect_error' => $clubKonnectBalance['error'] ?? null,
        'clubkonnect_date' => $clubKonnectBalance['date'] ?? null,
        'clubkonnect_phone' => $clubKonnectBalance['phone'] ?? null,
        ]);
        
        // Recent activity feed
        $recentLogs = \App\Models\AdminLog::with('user')->latest()->limit(8)->get();

        // Upstream floats for every configured provider. A shortfall here is the
        // single most likely cause of failed vends, so it belongs on the
        // dashboard rather than buried in a log.
        $providerBalances = app(\App\Services\BillPaymentService::class)->providerBalances();

        return view('admin.dashboard.index', compact(
            'stats', 
            'recentTransactions', 
            'recentUsers',
            'chartData',
            'chatStats',
            'contactStats',
            'userStats',
            'onlineUsers',
            'recentChats',
            'recentLogs',
            'providerBalances'
        ));
    }

    /**
     * Badge counts rendered in the admin sidebar.
     *
     * The layout has always read `$pendingTransfers` but no controller ever
     * shared it, so the bank-transfer badge never appeared. Registered as a
     * view composer from AppServiceProvider instead of being duplicated in
     * every controller.
     */
    public static function sidebarCounts(): array
    {
        return Cache::remember('admin.sidebar_counts', 30, function () {
            return [
                'pendingTransfers' => Transactions::where('service_type', 'funding')
                    ->where('payment_method', 'bank_transfer')
                    ->whereIn('status', ['pending', 'verifying'])
                    ->count(),
                'unreadContacts' => ContactMessage::where('is_read', false)->count(),
                'pendingChats' => ChatSession::where('status', 'pending')->count(),
            ];
        });
    }

    // Get dashboard statistics
    private function getDashboardStats()
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'online_admins' => User::admins()->where('is_online', true)->count(),

            // User Stats
            'total_users' => User::regularUsers()->count(),
            'new_users_today' => User::regularUsers()->whereDate('created_at', $today)->count(),
            'active_users' => User::regularUsers()->where('last_login_at', '>=', $today->copy()->subDays(30))->count(),
            'total_admins' => User::admins()->count(),

            // Transaction Stats
            'total_transactions' => Transactions::count(),
            'successful_transactions' => Transactions::where('status', 'success')->count(),
            'failed_transactions' => Transactions::where('status', 'failed')->count(),
            'pending_transactions' => Transactions::where('status', 'pending')->count(),

            // Financial Stats
            'total_volume' => Transactions::where('status', 'success')->sum('amount') ?? 0,
            'total_fees' => Transactions::where('status', 'success')->sum('service_fee') ?? 0,
            'today_volume' => Transactions::where('status', 'success')->whereDate('created_at', $today)->sum('amount') ?? 0,
            'monthly_volume' => Transactions::where('status', 'success')->where('created_at', '>=', $thisMonth)->sum('amount') ?? 0,

            // Wallet Stats
            'total_wallet_balance' => Wallet::sum('balance') ?? 0,
            'total_pending_balance' => Wallet::sum('pending_balance') ?? 0,
            'total_funded' => Wallet::sum('total_funded') ?? 0,
            'total_withdrawn' => Wallet::sum('total_spent') ?? 0,

            // Pending Bank Transfers
            'pending_transfers' => Transactions::where('service_type', 'funding')
                ->where('payment_method', 'bank_transfer')
                ->where('status', 'pending')
                ->count(),
                
            // Today's Transactions
            'today_transactions' => Transactions::whereDate('created_at', $today)->count(),
        ];
    }

    // Get recent transactions
    private function getRecentTransactions()
    {
        return Transactions::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->paginate(5);
    }

    // Get recent users
    private function getRecentUsers()
    {
        return User::where('is_admin', false)
            ->where('is_super_admin', false)
            ->with('wallet')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
    }

    // Get chart data for dashboard
    private function getChartData()
    {
        $days = 7; // Last 7 days
        $revenueData = [];
        $labels = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            
            // Get revenue for the day (successful transactions)
            $revenue = Transactions::where('status', 'success')
                ->whereDate('created_at', $date)
                ->sum('amount');
            
            $labels[] = Carbon::now()->subDays($i)->format('D');
            $revenueData[] = $revenue ? (float)$revenue : 0;
        }

        // Get transaction types data
        $transactionTypes = Transactions::select('service_type', DB::raw('COUNT(*) as count'))
            ->groupBy('service_type')
            ->get();

        $typeLabels = [];
        $typeData = [];
        
        // Default colors for common service types
        $typeColors = [
            'airtime' => '#3b82f6',
            'data' => '#8b5cf6',
            'funding' => '#10b981',
            'electricity' => '#f59e0b',
            'cable-tv' => '#ef4444',
            'exam' => '#06b6d4',
        ];

        foreach ($transactionTypes as $type) {
            $typeLabels[] = ucfirst(str_replace('-', ' ', $type->service_type));
            $typeData[] = $type->count;
        }

        return [
            'revenue' => [
                'labels' => $labels,
                'data' => $revenueData
            ],
            'types' => [
                'labels' => $typeLabels,
                'data' => $typeData,
                'colors' => $typeColors
            ]
        ];
    }

    // Get revenue statistics (API endpoint)
    public function revenueStats(Request $request)
    {
        $period = $request->get('period', 'weekly'); // daily, weekly, monthly
        
        $query = Transactions::where('status', 'success')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(amount) as revenue')
            )
            ->groupBy('date')
            ->orderBy('date');

        switch ($period) {
            case 'daily':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'weekly':
                $query->where('created_at', '>=', Carbon::now()->subDays(7));
                break;
            case 'monthly':
                $query->where('created_at', '>=', Carbon::now()->subDays(30));
                break;
        }

        $data = $query->get();

        return response()->json([
            'labels' => $data->pluck('date')->map(function($date) {
                return Carbon::parse($date)->format('M d');
            }),
            'revenue' => $data->pluck('revenue')->map(function($amount) {
                return $amount ? (float)$amount : 0;
            })
        ]);
    }

   
    
    /**
     * Transaction counts grouped by service type.
     *
     * Registered at admin.stats.transactions-by-type but the method did not
     * exist, so the endpoint 500'd.
     */
    public function transactionsByType(Request $request)
    {
        $days = (int) $request->input('days', 30);
        $days = max(1, min($days, 365));

        $rows = Transactions::select('service_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as volume'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('service_type')
            ->orderByDesc('count')
            ->get();

        return response()->json([
            'success' => true,
            'period_days' => $days,
            'labels' => $rows->map(fn ($row) => ucfirst(str_replace('-', ' ', (string) $row->service_type))),
            'counts' => $rows->pluck('count')->map(fn ($v) => (int) $v),
            'volumes' => $rows->pluck('volume')->map(fn ($v) => (float) $v),
            'total' => (int) $rows->sum('count'),
        ]);
    }

    /**
     * Transaction counts grouped by status.
     */
    public function transactionsByStatus(Request $request)
    {
        $days = (int) $request->input('days', 30);
        $days = max(1, min($days, 365));

        $rows = Transactions::select('status', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('status')
            ->get();

        $counts = [
            'success' => 0,
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->count;
        }

        $total = array_sum($counts);

        return response()->json([
            'success' => true,
            'period_days' => $days,
            'statuses' => $counts,
            'total' => $total,
            'success_rate' => $total > 0 ? round(($counts['success'] / $total) * 100, 1) : 0.0,
        ]);
    }

    /**
     * Get online users for AJAX
     */
    public function onlineUsers(Request $request)
    {
        if (!$request->ajax()) {
            abort(403, 'Unauthorized access');
        }
        
        $onlineUsers = User::where(function($q) {
                $q->where('is_online', true)
                  ->orWhere('last_login_at', '>=', now()->subMinutes(5));
            })
            ->select('id', 'name', 'email', 'admin_role', 'last_login_at', 'profile_picture')
            ->latest('last_login_at')
            ->limit(20)
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'admin_role' => $user->admin_role,
                    'profile_picture' => $user->profile_picture,
                    'initials' => strtoupper(substr($user->name, 0, 1)),
                    'last_active' => $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Just now',
                    'is_admin' => in_array($user->admin_role, ['admin', 'super_admin']),
                    'is_online' => $user->is_online || ($user->last_login_at && $user->last_login_at >= now()->subMinutes(5))
                ];
            });
        
        return response()->json([
            'success' => true,
            'users' => $onlineUsers,
            'count' => $onlineUsers->count(),
            'timestamp' => now()->toDateTimeString()
        ]);
    }
    
    /**
     * Get recent chat activity for AJAX
     */
    public function recentChats(Request $request)
    {
        if (!$request->ajax()) {
            abort(403, 'Unauthorized access');
        }
        
        $recentChats = ChatSession::with('user')
            ->whereIn('status', ['active', 'pending'])
            ->latest('last_message_at')
            ->limit(10)
            ->get()
            ->map(function($chat) {
                return [
                    'id' => $chat->id,
                    'user_id' => $chat->user_id,
                    'user_name' => $chat->user->name ?? 'Unknown User',
                    'user_initials' => strtoupper(substr($chat->user->name ?? 'U', 0, 1)),
                    'status' => $chat->status,
                    'status_class' => $chat->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800',
                    'last_message_at' => $chat->last_message_at ? $chat->last_message_at->diffForHumans() : 'Just started',
                    'created_at' => $chat->created_at->format('Y-m-d H:i:s'),
                    'url' => route('admin.chat.index', ['session' => $chat->id])
                ];
            });
        
        return response()->json([
            'success' => true,
            'chats' => $recentChats,
            'count' => $recentChats->count(),
            'timestamp' => now()->toDateTimeString()
        ]);
    }


    /**
     * Get ClubKonnect wallet balance
     */
    private function getClubKonnectBalance(): array
    {
        try {
            $balanceData = $this->clubKonnectService->checkBalance();

            // Log the outcome, not the payload: the provider's balance
            // response carries its account phone number, and a failure body can
            // echo the request credentials.
            Log::info('ClubKonnect balance checked', [
                'success' => isset($balanceData['balance']) && is_numeric($balanceData['balance']),
                'status' => $balanceData['status'] ?? null,
            ]);
            
            if (isset($balanceData['balance']) && is_numeric($balanceData['balance'])) {
                return [
                    'success' => true,
                    'balance' => (float) $balanceData['balance'],
                    'currency' => 'NGN',
                    'date' => $balanceData['date'] ?? null,
                    'phone' => $balanceData['phoneNo'] ?? null,
                    'raw_data' => $balanceData
                ];
            } else {
                // Handle different response formats or errors
                if (isset($balanceData['status']) && $balanceData['status'] === 'ORDER_RECEIVED') {
                    // Some APIs return success with different structure
                    return [
                        'success' => true,
                        'balance' => (float) ($balanceData['data']['balance'] ?? 0),
                        'currency' => 'NGN',
                        'date' => date('Y-m-d'),
                        'raw_data' => $balanceData
                    ];
                }
                
                // Error case
                return [
                    'success' => false,
                    'balance' => 0,
                    'currency' => 'NGN',
                    'error' => $balanceData['message'] ?? 'Unable to fetch balance',
                    'raw_data' => $balanceData
                ];
            }
            
        } catch (\Exception $e) {
            // The transport message can embed the request URL (and therefore
            // the credentials) — log the class, return a fixed string.
            Log::error('Failed to fetch ClubKonnect balance', [
                'exception' => get_class($e),
            ]);

            return [
                'success' => false,
                'balance' => 0,
                'currency' => 'NGN',
                'error' => 'ClubKonnect is unreachable right now.',
            ];
        }
    }
}