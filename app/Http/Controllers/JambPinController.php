<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class JambPinController extends Controller
{
    public function __construct(private readonly BillPaymentService $bills)
    {
    }

    public function index()
    {
        $user = Auth::user();

        return view('jamb-pin.index', [
            'user' => $user,
            'types' => (array) config('bills.exam_pins.jamb', []),
            'recentTransactions' => Transactions::where('user_id', $user->id)
                ->where('service_type', 'exam')
                ->where('provider', 'like', '%JAMB%')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function types()
    {
        return response()->json((array) config('bills.exam_pins.jamb', []));
    }

    public function history()
    {
        return view('jamb-pin.history', [
            'transactions' => $this->bills->historyFor(Auth::user(), ['exam']),
        ]);
    }

    /**
     * Resolve a JAMB profile ID to a candidate name.
     */
    public function verifyProfile(Request $request)
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'string', 'size:10', 'regex:/^[A-Z0-9]+$/'],
            'exam_type' => 'required|in:utme,de',
        ]);

        if (! $this->bills->provider('jamb')->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Profile verification is temporarily unavailable. Please try again later.',
            ], 503);
        }

        try {
            $provider = $this->bills->provider('jamb');

            $response = $provider->verifyCustomer('jamb', [
                'profile_id' => $validated['profile_id'],
                'exam_type' => $validated['exam_type'],
                'request_id' => 'JMB-' . $validated['profile_id'],
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
                    'profile_id' => $validated['profile_id'],
                    'customer_name' => $response['customer_name'] ?? $response['CustomerName'] ?? null,
                    'exam_type' => $validated['exam_type'],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('JAMB profile verification failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'We could not reach the provider. Please try again.',
            ], 502);
        }
    }

    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'string', 'size:10', 'regex:/^[A-Z0-9]+$/'],
            'exam_type' => 'required|in:utme,de',
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $user = Auth::user();
        $types = (array) config('bills.exam_pins.jamb', []);
        $unitPrice = (float) ($types[$validated['exam_type']]['price'] ?? 0);
        $label = $types[$validated['exam_type']]['label'] ?? strtoupper($validated['exam_type']);

        if ($unitPrice <= 0) {
            return back()->withInput()->with('error', 'JAMB e-PIN pricing is not configured. Please contact support.');
        }

        // `request_id` was previously supplied by the browser; deriving it here
        // means a client cannot replay or collide provider request ids.
        $requestId = 'JMB-' . $validated['profile_id'] . '-' . now()->format('ymdHis');

        // Shared with provider selection so each upstream can be asked what this
        // e-PIN costs it before one of them is chosen to vend it.
        $providerParams = [
            'exam_type' => $validated['exam_type'],
            'quantity' => 1,
            'phone' => $validated['phone'],
        ];

        $result = $this->bills->purchase(
            user: $user,
            product: 'jamb',
            amount: $unitPrice,
            fee: (float) config('bills.fees.exam_pin', 0),
            recipient: $validated['profile_id'],
            providerLabel: 'JAMB',
            description: 'JAMB e-PIN — ' . $label,
            meta: [
                'exam_type' => $validated['exam_type'],
                'profile_id' => $validated['profile_id'],
                'phone' => $validated['phone'],
                'provider_request_id' => $requestId,
            ],
            providerParams: $providerParams,
            dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                'jamb',
                $providerParams,
                $requestId,
            ),
            successMessage: $label . ' PIN generated for profile ' . $validated['profile_id'] . '.',
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
