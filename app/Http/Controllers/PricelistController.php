<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use App\Services\ClubKonnectCatalogue;
use App\Services\DataPriceService;
use App\Services\NetworkResolver;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PricelistController extends Controller
{
    public function __construct(
        private readonly BillPaymentService $bills,
        private readonly ClubKonnectCatalogue $catalogue,
        private readonly DataPriceService $dataPrices,
    ) {
    }

    public function index()
    {
        return view('pricelist.index', ['data' => $this->dataPrices->cataloguePayload()]);
    }

    /**
     * API endpoint for AJAX calls.
     *
     * The payload carries the same prices the purchase path will charge, because
     * both read them from `DataPriceService`. It also carries the freshness of
     * the catalogue it came from, so a page can show that a price is old without
     * a second request — and so a client never has to guess whether it is looking
     * at current data.
     */
    public function api()
    {
        $payload = $this->dataPrices->cataloguePayload();
        $status = $this->catalogue->status();

        return response()->json($payload + [
            'catalogue' => [
                'stale' => $status['stale'],
                'age_seconds' => $status['age_seconds'],
                'last_success_at' => $status['last_success_at'],
                'last_failure_at' => $status['last_failure_at'],
                'error_type' => $status['error_type'],
                // How often a client should re-read *this* endpoint. Never the
                // provider's: the scheduled synchronisation is what refreshes the
                // catalogue, and the frontend only refreshes what it displays.
                'refresh_seconds' => max(
                    30,
                    (int) config('pricing.catalogue.frontend_refresh_seconds', 300)
                ),
            ],
        ]);
    }

    /**
     * Manual refresh endpoint.
     *
     * Deliberately does not call the provider. The catalogue is refreshed on a
     * schedule, and this button exists to drop a *stale local copy* so the next
     * read re-derives prices from the current rule — an operator clicking it
     * twice must not spend two provider requests, and must not be able to use it
     * to exceed the provider's rate limit.
     */
    public function refresh()
    {
        $status = $this->catalogue->status();

        if ($status['stale']) {
            $this->catalogue->forget();
        }

        return redirect()->route('pricelist')
            ->with('success', $status['stale']
                ? 'Rates reloaded. The catalogue was stale, so it will be re-read from the provider.'
                : 'Rates are already current.');
    }

    /**
     * Plan details for a bundle, by product code or id.
     *
     * Read from the same service that prices and sells it, so a caller cannot be
     * handed a row that the purchase path would resolve differently.
     */
    public function getPlan(string $planCode)
    {
        $priced = $this->dataPrices->quote($planCode);

        return $priced['found']
            ? response()->json($priced['plan'])
            : response()->json(['error' => 'Plan not found'], 404);
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

        /*
         * The plan and its price come from the same service the price list reads.
         *
         * This endpoint used not to check the customer's view at all: it ignored
         * any posted price and simply charged the current one, so the same bundle
         * bought from this page and from the airtime page behaved differently
         * when a price had moved — one charged silently, the other refused and
         * asked for a second submission. There is now one pricing path and one
         * answer.
         */
        $priced = $this->dataPrices->quote($validated['plan_code']);

        if (! $priced['found'] || $priced['price_minor'] === null) {
            return back()->withInput()->with(
                'error',
                $priced['refusal'] ?? 'That data plan is no longer available. Please refresh the pricelist and try again.'
            );
        }

        $plan = $priced['plan'];
        $quote = $priced['quote'];
        $networkCode = (string) ($plan['network_code'] ?? '');

        if ($networkCode === '') {
            return back()->withInput()
                ->with('error', 'That data plan is missing its network. Please contact support.');
        }

        $networkKey = $priced['network_key'] ?? NetworkResolver::key($networkCode, 'clubkonnect');
        $networkName = $priced['network_name'] ?? NetworkResolver::displayName($networkCode, 'clubkonnect');

        $user = Auth::user();

        /*
         * Built once and handed to BillPaymentService as well as to the
         * dispatch closure: provider selection needs the same parameters to ask
         * each upstream what this bundle costs it, and comparing those answers
         * is what decides who vends.
         */
        $providerParams = $priced['provider_params'] + ['phone' => $validated['phone']];

        $quoted = Money::fromMinor($quote->customerPriceMinor);

        $result = $this->bills->purchase(
            user: $user,
            product: 'data',
            amount: $quoted->toFloat(),
            /*
             * No service fee. It is why the customer-facing fee column on a data
             * purchase is now ₦0.00 and the total equals the quoted price — the
             * ₦50 that used to sit here was a legacy constant, not a configured
             * charge. A fee can be applied only by enabling one on the pricing rule,
             * in which case the engine adds it and it arrives in the quote below.
             */
            fee: 0.0,
            recipient: $validated['phone'],
            providerLabel: $networkName,
            description: 'Data — ' . ($plan['plan_name'] ?? $plan['plan_code']),
            meta: [
                'network_code' => $networkCode,
                'network_name' => $networkName,
                'network_key' => $networkKey,
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
            quote: $quote,
            networkKey: $networkKey,
            networkName: $networkName,
        );

        if (! $result['ok']) {
            return back()->withInput()->with('error', $result['message']);
        }

        /*
         * No receipt is sent here. `BillPaymentService` sends it on the success path,
         * and the previous explicit call meant a pricelist purchase emailed the
         * customer two receipts for one order.
         */
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