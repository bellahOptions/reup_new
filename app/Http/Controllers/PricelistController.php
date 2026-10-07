<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use App\Services\ClubKonnectCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PricelistController extends Controller
{
    public function __construct(
        private readonly BillPaymentService $bills,
        private readonly ClubKonnectCatalogue $catalogue,
    ) {
    }

    public function index()
    {
        return view('pricelist.index', ['data' => $this->catalogue->plans()]);
    }

    // API endpoint for AJAX calls
    public function api()
    {
        return response()->json($this->catalogue->plans());
    }

    // Manual refresh endpoint
    public function refresh()
    {
        $this->catalogue->forget();

        return redirect()->route('pricelist')
            ->with('success', 'Rates refreshed from the provider.');
    }

    // Get plan details for purchase
    public function getPlan($planCode)
    {
        $plan = $this->findPlan($planCode);

        return $plan
            ? response()->json($plan)
            : response()->json(['error' => 'Plan not found'], 404);
    }

    /**
     * Resolve a plan from the server-side catalogue by product code or id.
     *
     * This is the only authoritative price. A request must never be able to
     * tell us what a plan costs — the pricelist markup previously carried
     * `plan_price` from a data attribute and posted it back, so editing the
     * page in devtools bought a ₦20,000 bundle for ₦1.
     */
    private function findPlan(string $planCode): ?array
    {
        return $this->catalogue->plan($planCode);
    }

    /**
     * Buy a data bundle from the pricelist.
     *
     * The plan and its price are resolved server-side, the wallet is debited
     * through BillPaymentService, and the upstream call happens afterwards with
     * a compensating refund on failure.
     */
    public function purchaseData(Request $request)
    {
        $validated = $request->validate([
            'plan_code' => 'required|string|max:60',
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $plan = $this->findPlan($validated['plan_code']);

        if (! $plan) {
            return back()->withInput()
                ->with('error', 'That data plan is no longer available. Please refresh the pricelist and try again.');
        }

        $networkCode = (string) ($plan['network_code'] ?? '');
        $amount = round((float) ($plan['your_price'] ?? 0), 2);

        if ($amount <= 0 || $networkCode === '') {
            return back()->withInput()
                ->with('error', 'That data plan has no valid price. Please contact support.');
        }

        $user = Auth::user();

        /*
         * Built once and handed to BillPaymentService as well as to the
         * dispatch closure: provider selection needs the same parameters to ask
         * each upstream what this bundle costs it, and comparing those answers
         * is what decides who vends.
         */
        $providerParams = [
            'network' => $networkCode,
            'plan' => (string) ($plan['plan_id'] ?? $plan['plan_code']),
            'phone' => $validated['phone'],
        ];

        $result = $this->bills->purchase(
            user: $user,
            product: 'data',
            amount: $amount,
            fee: (float) Transactions::calculateServiceFee('data', $amount),
            recipient: $validated['phone'],
            providerLabel: $plan['network'] ?? $this->catalogue->networkName($networkCode),
            description: 'Data — ' . ($plan['plan_name'] ?? $plan['plan_code']),
            meta: [
                'network_code' => $networkCode,
                'plan_id' => $plan['plan_id'] ?? null,
                'plan_code' => $plan['plan_code'] ?? null,
                'plan_name' => $plan['plan_name'] ?? null,
                'plan_type' => $plan['plan_type'] ?? null,
                'phone_number' => $validated['phone'],
                'source' => 'pricelist',
            ],
            providerParams: $providerParams,
            dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                'data',
                $providerParams,
                $transaction->reference,
            ),
            successMessage: ($plan['plan_name'] ?? 'Data bundle') . ' sent to ' . $validated['phone'] . '.',
            pin: $validated['pin'],
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );

        if (! $result['ok']) {
            return back()->withInput()->with('error', $result['message']);
        }

        $this->bills->sendReceipts($user, $result['transaction']);

        return redirect()->route('transactions.success', $result['transaction']->reference)
            ->with('success', $result['message']);
    }

    /**
     * Status poll for a purchase initiated from the pricelist.
     */
    public function queryTransaction(string $orderId)
    {
        $transaction = Transactions::where('user_id', Auth::id())
            ->whereReference($orderId)
            ->first();

        if (! $transaction) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'reference' => $transaction->reference,
                'status' => $transaction->status,
                'payment_status' => $transaction->payment_status,
                'amount' => (float) $transaction->amount,
                'description' => $transaction->description,
                'provider_reference' => $transaction->api_reference,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'completed_at' => $transaction->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Upstream status callback for pricelist purchases.
     *
     * Authenticated by an HMAC of the raw body in `X-Reup-Signature`. Without
     * verification this endpoint would let anyone mark any transaction
     * successful.
     */
    public function callback(Request $request)
    {
        $secret = (string) config('services.clubkonnect.api_key');
        $signature = (string) $request->header('X-Reup-Signature', '');

        if ($secret === '' || $signature === '') {
            abort(401, 'Missing signature.');
        }

        if (! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            Log::warning('Pricelist callback rejected: bad signature', ['ip' => $request->ip()]);
            abort(401, 'Invalid signature.');
        }

        $reference = $request->input('reference') ?? $request->input('RequestID');

        if (! $reference) {
            return response()->json(['success' => false, 'message' => 'No reference supplied.'], 422);
        }

        $transaction = Transactions::whereReference($reference)->first();

        if (! $transaction) {
            Log::warning('Pricelist callback for unknown reference', ['reference' => $reference]);

            return response()->json(['success' => false, 'message' => 'Unknown reference.'], 404);
        }

        Log::info('Pricelist callback received', [
            'transaction_id' => $transaction->id,
            'status' => $request->input('status'),
        ]);

        // Status is reconciled by a provider status query, not by an inbound
        // callback asserting success — that would be a credit with no funds.
        return response()->json(['success' => true]);
    }
}