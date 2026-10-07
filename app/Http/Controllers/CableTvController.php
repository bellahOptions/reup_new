<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CableTvController extends Controller
{
    public function __construct(private readonly BillPaymentService $bills)
    {
    }

    public function index()
    {
        $user = Auth::user();

        return view('cable-tv.index', [
            'user' => $user,
            'providers' => (array) config('bills.cable_providers', []),
            // The bouquet catalogue is fetched server-side and cached. The view
            // used to call the upstream API from the browser with the provider
            // client id in the query string, which published the credential to
            // every visitor and leaked their IP to the provider.
            'packages' => $this->bills->catalogues()->cableTvPackages() ?? [],
            'recentTransactions' => $this->recent($user->id),
        ]);
    }

    /**
     * Bouquet catalogue as JSON, for clients that refresh without a reload.
     */
    public function packages()
    {
        return response()->json([
            'success' => true,
            'packages' => $this->bills->catalogues()->cableTvPackages() ?? [],
        ]);
    }

    public function history()
    {
        return view('cable-tv.history', [
            'transactions' => $this->bills->historyFor(Auth::user(), ['cable-tv']),
        ]);
    }

    public function providers()
    {
        return response()->json((array) config('bills.cable_providers', []));
    }

    /**
     * Resolve a smartcard / IUC number to a customer name.
     *
     * Runs on the server so the provider credential never reaches the browser.
     * The page previously called the upstream API directly with the API key
     * interpolated into the markup.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:' . implode(',', array_keys((array) config('bills.cable_providers', []))),
            'smartcard_number' => 'required|string|min:6|max:20|regex:/^[0-9]+$/',
        ]);

        if (! $this->bills->provider('cable_tv')->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Cable TV verification is temporarily unavailable. Please try again later.',
            ], 503);
        }

        try {
            $provider = $this->bills->provider('cable_tv');

            $response = $provider->verifyCustomer('cable_tv', [
                'provider' => $validated['provider'],
                'smartcard_number' => $validated['smartcard_number'],
                'request_id' => 'VER-' . strtoupper(Str::random(10)),
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
                    'customer_name' => $response['customer_name'] ?? $response['CustomerName'] ?? null,
                    'customer_number' => $response['customer_number'] ?? null,
                    'package' => $response['package'] ?? $response['CustomerPackage'] ?? null,
                    'status' => $response['status'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Cable TV verification failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'We could not reach the provider. Please try again.',
            ], 502);
        }
    }

    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:' . implode(',', array_keys((array) config('bills.cable_providers', []))),
            'smartcard_number' => 'required|string|min:6|max:20|regex:/^[0-9]+$/',
            'package' => 'required|string|max:80',
            'amount' => 'required|numeric|min:' . (float) config('bills.ranges.cable_tv.min', 100) . '|max:' . (float) config('bills.ranges.cable_tv.max', 200000),
            'phone' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $user = Auth::user();
        $amount = round((float) $validated['amount'], 2);
        $providerName = config('bills.cable_providers.' . $validated['provider'], $validated['provider']);

        try {
            $result = $this->bills->purchase(
                user: $user,
                product: 'cable_tv',
                amount: $amount,
                fee: (float) config('bills.fees.cable_tv', 0),
                recipient: $validated['smartcard_number'],
                providerLabel: $providerName,
                description: 'Cable TV — ' . $providerName . ' ' . $validated['package'],
                meta: [
                    'provider_code' => $validated['provider'],
                    'smartcard_number' => $validated['smartcard_number'],
                    'package' => $validated['package'],
                    'phone' => $validated['phone'] ?? $user->phone,
                ],
                dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                    'cable_tv',
                    [
                        'provider' => $validated['provider'],
                        'package' => $validated['package'],
                        'smartcard_number' => $validated['smartcard_number'],
                        'amount' => $amount,
                        'phone' => $validated['phone'] ?? (string) $user->phone,
                    ],
                    $transaction->reference,
                ),
                successMessage: $providerName . ' ' . $validated['package'] . ' activated on '
                    . $validated['smartcard_number'] . '.',
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

    private function recent(int $userId)
    {
        return Transactions::where('user_id', $userId)
            ->where('service_type', 'cable-tv')
            ->latest()
            ->limit(5)
            ->get();
    }
}
