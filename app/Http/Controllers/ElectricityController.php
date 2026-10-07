<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ElectricityController extends Controller
{
    public function __construct(private readonly BillPaymentService $bills)
    {
    }

    public function index()
    {
        $user = Auth::user();

        return view('electricity.index', [
            'user' => $user,
            'discos' => (array) config('bills.discos', []),
            'recentTransactions' => Transactions::where('user_id', $user->id)
                ->where('service_type', 'electricity')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function history()
    {
        return view('electricity.history', [
            'transactions' => $this->bills->historyFor(Auth::user(), ['electricity']),
        ]);
    }

    public function discos()
    {
        return response()->json((array) config('bills.discos', []));
    }

    /**
     * Resolve a meter number to a customer name and address.
     */
    public function verifyMeter(Request $request)
    {
        $validated = $request->validate([
            'disco' => 'required|string|in:' . implode(',', array_keys((array) config('bills.discos', []))),
            'meter_number' => 'required|string|min:6|max:20|regex:/^[0-9]+$/',
            'meter_type' => 'required|in:prepaid,postpaid',
        ]);

        if (! $this->bills->provider('electricity')->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Meter verification is temporarily unavailable. Please try again later.',
            ], 503);
        }

        try {
            $provider = $this->bills->provider('electricity');

            $response = $provider->verifyCustomer('electricity', [
                'disco' => $validated['disco'],
                'meter_number' => $validated['meter_number'],
                'meter_type' => $validated['meter_type'],
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
                    'customer_address' => $response['customer_address'] ?? $response['CustomerAddress'] ?? null,
                    'meter_number' => $validated['meter_number'],
                    'meter_type' => $validated['meter_type'],
                    'status' => $response['status'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Meter verification failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'We could not reach the provider. Please try again.',
            ], 502);
        }
    }

    public function pay(Request $request)
    {
        $validated = $request->validate([
            'disco' => 'required|string|in:' . implode(',', array_keys((array) config('bills.discos', []))),
            'meter_number' => 'required|string|min:6|max:20|regex:/^[0-9]+$/',
            'meter_type' => 'required|in:prepaid,postpaid',
            'amount' => 'required|numeric|min:' . (float) config('bills.ranges.electricity.min', 500) . '|max:' . (float) config('bills.ranges.electricity.max', 200000),
            'phone' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $user = Auth::user();
        $amount = round((float) $validated['amount'], 2);
        $discoName = config('bills.discos.' . $validated['disco'], $validated['disco']);

        // Shared with provider selection so each upstream can be asked what this
        // meter payment costs it before one of them is chosen to vend it.
        $providerParams = [
            'disco' => $validated['disco'],
            'meter_number' => $validated['meter_number'],
            'meter_type' => $validated['meter_type'],
            'amount' => $amount,
            'phone' => $validated['phone'] ?? (string) $user->phone,
        ];

        $result = $this->bills->purchase(
            user: $user,
            product: 'electricity',
            amount: $amount,
            fee: (float) config('bills.fees.electricity', 0),
            recipient: $validated['meter_number'],
            providerLabel: $discoName,
            description: 'Electricity — ' . $discoName . ' (' . $validated['meter_type'] . ')',
            meta: [
                'disco_code' => $validated['disco'],
                'meter_number' => $validated['meter_number'],
                'meter_type' => $validated['meter_type'],
                'phone' => $validated['phone'] ?? $user->phone,
            ],
            providerParams: $providerParams,
            dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                'electricity',
                $providerParams,
                $transaction->reference,
            ),
            successMessage: '₦' . number_format($amount, 2) . ' electricity paid for meter '
                . $validated['meter_number'] . '.',
            pin: $validated['pin'],
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );

        if (! $result['ok']) {
            return back()->withInput()->with('error', $result['message']);
        }

        return redirect()->route('transactions.success', $result['transaction']->reference)
            ->with('success', $result['message']);
    }
}
