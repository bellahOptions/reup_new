<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\Transactions;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    /** Columns the list may be sorted by. Anything else is rejected. */
    private const SORTABLE = [
        'id', 'name', 'email', 'created_at', 'last_login_at',
        'email_verified_at', 'wallet_balance',
    ];

    public function __construct(private readonly WalletService $wallets)
    {
    }

    /**
     * Normalise a user-supplied sort into something safe.
     *
     * This is the fix for the SQL injection that existed where
     * `$query->orderBy($request->get('sort'), $request->get('order'))` fed an
     * unvalidated column name straight into the query builder. orderBy() does
     * not bind identifiers, so `?sort=id,(select ...)` was injectable.
     *
     * @return array{0:string,1:string} [column, direction]
     */
    private function resolveSort(Request $request, string $default = 'created_at'): array
    {
        $column = (string) $request->query('sort', $default);

        if (! in_array($column, self::SORTABLE, true)) {
            $column = $default;
        }

        $direction = strtolower((string) $request->query('order', 'desc'));

        return [$column, $direction === 'asc' ? 'asc' : 'desc'];
    }

    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|in:verified,unverified,active,inactive,suspended',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'sort' => 'nullable|string|max:40',
            'order' => 'nullable|in:asc,desc',
        ]);

        $query = User::regularUsers()->with('wallet');

        if ($request->filled('search')) {
            $search = (string) $request->input('search', '');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        match ((string) $request->input('status', '')) {
            'verified' => $query->whereNotNull('email_verified_at'),
            'unverified' => $query->whereNull('email_verified_at'),
            'active' => $query->where('last_login_at', '>=', now()->subDays(30)),
            'inactive' => $query->where(fn ($q) => $q->where('last_login_at', '<', now()->subDays(30))->orWhereNull('last_login_at')),
            'suspended' => $query->where('status', 'suspended'),
            default => null,
        };

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        [$sort, $direction] = $this->resolveSort($request);

        if ($sort === 'wallet_balance') {
            $query->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
                ->orderBy('wallets.balance', $direction)
                ->select('users.*');
        } else {
            $query->orderBy($sort, $direction);
        }

        $users = $query->paginate(20)->withQueryString();

        $stats = [
            'total' => User::regularUsers()->count(),
            'active' => User::regularUsers()->where('last_login_at', '>=', now()->subDays(30))->count(),
            'verified' => User::regularUsers()->whereNotNull('email_verified_at')->count(),
            'new_this_month' => User::regularUsers()->where('created_at', '>=', now()->startOfMonth())->count(),
            'total_today' => User::regularUsers()->whereDate('created_at', today())->count(),
            'online_now' => User::regularUsers()->where('last_activity', '>=', now()->subMinutes(5))->count(),
        ];

        return view('admin.users.index', compact('users', 'stats'));
    }

    public function show(User $user)
    {
        abort_unless(! $user->isAdmin(), 404);

        $user->load('wallet');

        $transactions = Transactions::where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get();

        $stats = [
            'total_transactions' => Transactions::where('user_id', $user->id)->count(),
            'successful_transactions' => Transactions::where('user_id', $user->id)->where('status', 'success')->count(),
            'total_spent' => (float) Transactions::where('user_id', $user->id)->where('type', 'debit')->where('status', 'success')->sum('amount'),
            'total_funded' => (float) Transactions::where('user_id', $user->id)->where('type', 'credit')->where('status', 'success')->sum('amount'),
            'last_transaction' => Transactions::where('user_id', $user->id)->latest()->first(),
        ];

        return view('admin.users.show', compact('user', 'transactions', 'stats'));
    }

    public function edit(User $user)
    {
        abort_unless(! $user->isAdmin(), 404);

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        abort_unless(! $user->isAdmin(), 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
        ]);

        $previousEmail = $user->email;
        $snapshot = $user->only(['name', 'email', 'phone']);

        $user->fill($validated);

        // Changing the address invalidates the verification it carried.
        if ($previousEmail !== $validated['email']) {
            $user->email_verified_at = null;
        }

        $user->save();

        AdminLog::log(Auth::id(), 'update_user', [
            'user_id' => $user->id,
            'before' => $snapshot,
            'after' => $user->only(['name', 'email', 'phone']),
        ]);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'Customer record updated.');
    }

    /**
     * Manual wallet adjustment.
     *
     * Rewritten because the previous version:
     *   - called `Transaction::create()` (the model is `Transactions`), which
     *     fatal-errored on every use;
     *   - wrote to `$wallet->total_withdrawn`, a column that does not exist;
     *   - referenced `Auth` without importing the facade;
     *   - mutated the balance outside a transaction with no row lock.
     */
    public function updateWallet(Request $request, User $user)
    {
        abort_unless(! $user->isAdmin(), 404);

        $validated = $request->validate([
            'action' => 'required|in:add,deduct,set',
            'amount' => 'required|numeric|min:0.01|max:10000000',
            'reason' => 'required|string|min:5|max:500',
        ]);

        $amount = round((float) $validated['amount'], 2);

        $result = DB::transaction(function () use ($user, $validated, $amount) {
            $wallet = $this->wallets->lockForUser($user);
            $before = (float) $wallet->balance;

            match ($validated['action']) {
                'add' => $wallet->balance = $before + $amount,
                'deduct' => $wallet->balance = $before - $amount,
                'set' => $wallet->balance = $amount,
            };

            if ((float) $wallet->balance < 0) {
                throw new \RuntimeException('That deduction would put the wallet below zero.');
            }

            $after = (float) $wallet->balance;

            if ($validated['action'] === 'add') {
                $wallet->total_funded = (float) $wallet->total_funded + $amount;
            }
            if ($validated['action'] === 'deduct') {
                $wallet->total_spent = (float) $wallet->total_spent + $amount;
            }

            $wallet->transaction_count = (int) $wallet->transaction_count + 1;
            $wallet->save();

            $transaction = Transactions::create([
                'user_id' => $user->id,
                'reference' => $this->wallets->generateReference('ADJ'),
                'type' => $validated['action'] === 'add' ? 'credit' : 'debit',
                'service_type' => 'manual_adjustment',
                'description' => 'Manual balance adjustment — ' . $validated['reason'],
                'amount' => $amount,
                'service_fee' => 0,
                'total_amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'payment_method' => 'manual',
                'payment_status' => 'success',
                'status' => 'success',
                'provider' => 'admin',
                'completed_at' => now(),
                'meta' => [
                    'admin_id' => Auth::id(),
                    'action' => $validated['action'],
                    'reason' => $validated['reason'],
                ],
            ]);

            AdminLog::log(Auth::id(), 'adjust_wallet', [
                'user_id' => $user->id,
                'action' => $validated['action'],
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $validated['reason'],
            ]);

            return ['before' => $before, 'after' => $after, 'transaction' => $transaction];
        });

        return redirect()->route('admin.users.show', $user)->with(
            'success',
            'Wallet adjusted from ₦' . number_format($result['before'], 2)
            . ' to ₦' . number_format($result['after'], 2) . '.'
        );
    }

    public function toggleStatus(Request $request, User $user)
    {
        abort_unless(! $user->isAdmin(), 404);

        $request->validate(['reason' => 'nullable|string|max:500']);

        $wasSuspended = $user->status === 'suspended';
        $newStatus = $wasSuspended ? 'active' : 'suspended';

        $user->forceFill(['status' => $newStatus])->save();

        AdminLog::log(Auth::id(), $wasSuspended ? 'activate_user' : 'suspend_user', [
            'user_id' => $user->id,
            'new_status' => $newStatus,
            'reason' => $request->input('reason'),
        ]);

        return back()->with('success', $wasSuspended
            ? 'Customer account reactivated.'
            : 'Customer account suspended.');
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless(! $user->isAdmin(), 404);

        $wallet = $user->wallet;

        if ($wallet && (float) $wallet->balance > 0) {
            return back()->with('error', 'This customer still holds a wallet balance. Zero the balance before deleting the account.');
        }

        $request->validate(['confirm_email' => 'required|email']);

        if (strcasecmp($request->input('confirm_email'), $user->email) !== 0) {
            return back()->with('error', 'The confirmation email did not match this customer\'s address.');
        }

        DB::transaction(function () use ($user) {
            $user->wallet?->delete();
            $user->delete();
        });

        AdminLog::log(Auth::id(), 'delete_user', ['user_email' => $user->email]);

        return redirect()->route('admin.users.index')->with('success', 'Customer account deleted.');
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|in:verified,unverified,suspended',
        ]);

        $query = User::regularUsers()->with('wallet');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }
        match ((string) $request->input('status', '')) {
            'verified' => $query->whereNotNull('email_verified_at'),
            'unverified' => $query->whereNull('email_verified_at'),
            'suspended' => $query->where('status', 'suspended'),
            default => null,
        };

        $filename = 'customers_' . now()->format('Y-m-d_His') . '.csv';

        // Streamed so a large export does not materialise in memory, and
        // prefixed against CSV formula injection.
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID', 'Name', 'Email', 'Phone', 'Verified', 'Status',
                'Balance', 'Total funded', 'Total spent', 'Joined', 'Last login',
            ]);

            $query->chunk(500, function ($users) use ($out) {
                foreach ($users as $user) {
                    fputcsv($out, [
                        $user->id,
                        $this->csvSafe($user->name),
                        $this->csvSafe($user->email),
                        $this->csvSafe($user->phone),
                        $user->email_verified_at ? 'yes' : 'no',
                        $user->status ?? 'active',
                        number_format((float) ($user->wallet->balance ?? 0), 2, '.', ''),
                        number_format((float) ($user->wallet->total_funded ?? 0), 2, '.', ''),
                        number_format((float) ($user->wallet->total_spent ?? 0), 2, '.', ''),
                        optional($user->created_at)->format('Y-m-d H:i:s'),
                        optional($user->last_login_at)->format('Y-m-d H:i:s') ?? 'never',
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Neutralise spreadsheet formula injection in exported text fields. */
    private function csvSafe(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /**
     * Lightweight type-ahead for the global admin search.
     */
    public function suggestions(Request $request)
    {
        $request->validate(['search' => 'nullable|string|max:120']);

        $search = (string) $request->input('search', '');

        if ($search === '') {
            return response()->json(['suggestions' => []]);
        }

        $users = User::regularUsers()
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            })
            ->limit(8)
            ->get(['id', 'name', 'email', 'phone', 'email_verified_at', 'last_login_at']);

        return response()->json([
            'suggestions' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'verified' => $user->email_verified_at !== null,
                'last_login_at' => optional($user->last_login_at)->toIso8601String(),
                'url' => route('admin.users.show', $user),
            ]),
        ]);
    }
}
