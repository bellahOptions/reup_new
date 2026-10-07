<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WaecPinController extends Controller
{
    public function __construct(private readonly BillPaymentService $bills)
    {
    }

    public function index()
    {
        $user = Auth::user();

        return view('waec-pin.index', [
            'user' => $user,
            'unitPrice' => (float) config('bills.exam_pins.waec', 0),
            'recentTransactions' => Transactions::where('user_id', $user->id)
                ->where('service_type', 'exam')
                ->where('provider', 'like', '%WAEC%')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function history()
    {
        return view('waec-pin.history', [
            'transactions' => $this->bills->historyFor(Auth::user(), ['exam']),
        ]);
    }

    /**
     * Live unit price and quantity limits, so the form cannot drift from the
     * server-side rules.
     */
    public function quantity(Request $request)
    {
        $request->validate(['quantity' => 'nullable|integer|min:1|max:10']);

        $unitPrice = (float) config('bills.exam_pins.waec', 0);
        $quantity = (int) $request->input('quantity', 1);

        return response()->json([
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => round($unitPrice * $quantity, 2),
            'max_quantity' => 10,
        ]);
    }

    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:10',
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $user = Auth::user();
        $quantity = (int) $validated['quantity'];
        $unitPrice = (float) config('bills.exam_pins.waec', 0);

        if ($unitPrice <= 0) {
            return back()->withInput()->with('error', 'WAEC e-PIN pricing is not configured. Please contact support.');
        }

        $amount = round($unitPrice * $quantity, 2);

        // Shared with provider selection so each upstream can be asked what this
        // e-PIN order costs it before one of them is chosen to vend it.
        $providerParams = [
            'exam_type' => 'WAEC',
            'quantity' => $quantity,
            'phone' => $validated['phone'],
        ];

        $result = $this->bills->purchase(
            user: $user,
            product: 'waec',
            amount: $amount,
            fee: (float) config('bills.fees.exam_pin', 0),
            recipient: $validated['phone'],
            providerLabel: 'WAEC',
            description: 'WAEC e-PIN × ' . $quantity,
            meta: [
                'exam_type' => 'waec',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'phone' => $validated['phone'],
            ],
            providerParams: $providerParams,
            dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                'waec',
                $providerParams,
                $transaction->reference,
            ),
            successMessage: $quantity . ' WAEC e-PIN' . ($quantity > 1 ? 's' : '') . ' generated. View the code on your receipt.',
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
