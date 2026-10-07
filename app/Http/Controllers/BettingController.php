<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Betting wallet funding.
 *
 * Customers top up a bookmaker account (Bet9ja, SportyBet, …) from their ReUp
 * wallet. The account number is validated with the provider before any money
 * moves, because a mistyped betting ID is not recoverable once funded.
 */
class BettingController extends Controller
{
    public function __construct(private readonly BillPaymentService $bills)
    {
    }

    public function index()
    {
        $user = Auth::user();

        return view('betting.index', [
            'user' => $user,
            'providers' => (array) config('bills.betting_providers', []),
            'limits' => (array) config('bills.ranges.betting', []),
            'fee' => (float) config('bills.fees.betting', 0),
            'hasPin' => app(\App\Services\SecurityService::class)->hasPin($user),
            'recentTransactions' => Transactions::where('user_id', $user->id)
                ->where('service_type', 'betting')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function history()
    {
        return view('betting.history', [
            'transactions' => $this->bills->historyFor(Auth::user(), ['betting']),
        ]);
    }

    public function providers()
    {
        return response()->json((array) config('bills.betting_providers', []));
    }

    /**
     * Resolve a betting customer ID to an account name.
     *
     * Server-side so the provider credential never reaches the browser.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:' . implode(',', array_keys((array) config('bills.betting_providers', []))),
            'customer_id' => 'required|string|min:4|max:20|regex:/^[A-Za-z0-9]+$/',
        ]);

        try {
            $provider = $this->bills->provider('betting');

            $response = $provider->verifyCustomer('betting', [
                'betting_code' => $validated['provider'],
                'customer_id' => $validated['customer_id'],
                'request_id' => 'VBF-' . strtoupper(Str::random(10)),
            ]);

            if (! $provider->isSuccess($response)) {
                return response()->json([
                    'success' => false,
                    'message' => $provider->errorMessage($response),
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'customer_name' => data_get($response, 'customer_name')
                        ?? data_get($response, 'CustomerName')
                        ?? data_get($response, 'data.customer_name'),
                    'customer_id' => $validated['customer_id'],
                    'provider' => $validated['provider'],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Betting account verification failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'We could not verify that betting account. Please check the ID and try again.',
            ], 502);
        }
    }

    public function fund(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:' . implode(',', array_keys((array) config('bills.betting_providers', []))),
            'customer_id' => 'required|string|min:4|max:20|regex:/^[A-Za-z0-9]+$/',
            'amount' => [
                'required',
                'numeric',
                'min:' . (float) config('bills.ranges.betting.min', 100),
                'max:' . (float) config('bills.ranges.betting.max', 100000),
            ],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
            'phone' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
        ]);

        $user = Auth::user();
        $amount = round((float) $validated['amount'], 2);

        // Shared with provider selection so each upstream can be asked what this
        // top-up costs it before one of them is chosen to vend it.
        $providerParams = [
            'betting_code' => $validated['provider'],
            'customer_id' => $validated['customer_id'],
            'amount' => $amount,
            'phone' => $validated['phone'] ?? (string) $user->phone,
        ];

        $bookmakers = (array) config('bills.betting_providers', []);
        $config = $bookmakers[$validated['provider']] ?? null;
        $label = $config['label'] ?? $validated['provider'];

        // Upstream floors differ per bookmaker; enforce the tighter of the two.
        $minimum = max((float) ($config['min'] ?? 0), (float) config('bills.ranges.betting.min', 100));

        if ($amount < $minimum) {
            return back()->withInput()
                ->with('error', $label . ' requires a minimum top-up of ₦' . number_format($minimum, 2) . '.');
        }

        try {
            $result = $this->bills->purchase(
                user: $user,
                product: 'betting',
                amount: $amount,
                fee: (float) config('bills.fees.betting', 0),
                recipient: $validated['customer_id'],
                providerLabel: $label,
                description: 'Betting wallet funding — ' . $label,
                meta: [
                    'betting_code' => $validated['provider'],
                    'betting_label' => $label,
                    'customer_id' => $validated['customer_id'],
                    'phone' => $validated['phone'] ?? $user->phone,
                ],
                providerParams: $providerParams,
                dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                    'betting',
                    $providerParams,
                    $transaction->reference,
                ),
                successMessage: '₦' . number_format($amount, 2) . ' added to '
                    . $label . ' account ' . $validated['customer_id'] . '.',
                pin: $validated['pin'],
                idempotencyKey: $validated['idempotency_key'] ?? null,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if (! $result['ok']) {
            return back()->withInput()->with('error', $result['message']);
        }

        return redirect()->route('transactions.success', $result['transaction']->reference)
            ->with('success', $result['message']);
    }
}
