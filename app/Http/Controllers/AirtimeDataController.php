<?php

namespace App\Http\Controllers;

use App\Models\PromotionNotification;
use App\Models\Transactions;
use App\Services\BillPaymentService;
use App\Services\ClubKonnectCatalogue;
use App\Services\SecurityService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AirtimeDataController extends Controller
{
    /** ClubKonnect network identifiers. */
    public const NETWORKS = [
        '01' => 'MTN',
        '02' => 'Glo',
        '03' => '9mobile',
        '04' => 'Airtel',
    ];

    public function __construct(
        private readonly BillPaymentService $bills,
        private readonly WalletService $wallets,
        private readonly SecurityService $security,
        private readonly ClubKonnectCatalogue $catalogue,
    ) {
    }

    public function index()
    {
        $user = Auth::user();

        return view('airtime-data.index', [
            'user' => $user,
            'wallet' => $this->wallets->forUser($user),
            'recentTransactions' => Transactions::where('user_id', $user->id)
                ->whereIn('service_type', ['airtime', 'data'])
                ->latest()
                ->limit(5)
                ->get(),
            'networks' => self::NETWORKS,
            'ranges' => (array) config('bills.ranges', []),
            'hasPin' => $this->security->hasPin($user),
            /*
             * Live announcements for the marquee.
             *
             * The view had always guarded with `$announcements ?? []`, so when
             * the global view composer that used to supply this was removed, the
             * page kept rendering — just with an empty marquee and no error. That
             * silent degradation is why this is now passed explicitly here rather
             * than relied on from a global.
             */
            'announcements' => PromotionNotification::query()->live()->latest()->limit(6)->get(),
        ]);
    }

    public function history(Request $request)
    {
        return view('airtime-data.history', [
            'transactions' => $this->bills->historyFor(Auth::user(), ['airtime', 'data']),
        ]);
    }

    public function networks()
    {
        return response()->json(self::NETWORKS);
    }

    /* =====================================================================
     | Purchases
     |=================================================================== */

    public function purchaseAirtime(Request $request)
    {
        $range = (array) config('bills.ranges.airtime', ['min' => 50, 'max' => 50000]);

        $validated = $request->validate([
            'network' => 'required|string|in:' . implode(',', array_keys(self::NETWORKS)),
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'amount' => 'required|numeric|min:' . $range['min'] . '|max:' . $range['max'],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $network = self::NETWORKS[$validated['network']];
        $amount = round((float) $validated['amount'], 2);

        return $this->dispatchPurchase(
            $request,
            product: 'airtime',
            amount: $amount,
            recipient: $validated['phone'],
            providerLabel: $network,
            description: 'Airtime — ' . $network,
            meta: [
                'network_code' => $validated['network'],
                'network_name' => $network,
                'phone_number' => $validated['phone'],
            ],
            providerParams: [
                'network' => $validated['network'],
                'phone' => $validated['phone'],
                'amount' => $amount,
            ],
            successMessage: '₦' . number_format($amount, 2) . ' airtime sent to ' . $validated['phone'] . '.',
            pin: $validated['pin'],
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );
    }

    /**
     * Buy a data bundle.
     *
     * The bundle and its price are resolved here, from the catalogue, exactly
     * like the pricelist page does it. The posted `plan_price` is only a
     * cross-check: this page used to fetch the provider catalogue in the browser
     * (credential and all) and apply the markup there, which meant the amount
     * that reached the wallet was whatever the browser said it was — editable
     * with devtools, so a ₦20,000 bundle could be bought for ₦50.
     *
     * A price that has moved since the page was rendered is refused rather than
     * charged silently: the customer must see the new amount before paying it.
     */
    public function purchaseData(Request $request)
    {
        $range = (array) config('bills.ranges.data', ['min' => 50, 'max' => 200000]);

        $validated = $request->validate([
            'data_network' => 'required|string|in:' . implode(',', array_keys(self::NETWORKS)),
            'data_plan' => 'required|string|max:120',
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'plan_name' => 'required|string|max:120',
            'plan_price' => 'required|numeric|min:' . $range['min'] . '|max:' . $range['max'],
            'plan_type' => 'nullable|string|max:60',
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $plan = $this->catalogue->plan($validated['data_plan']);

        if (! $plan) {
            return back()->withInput()->with(
                'error',
                'That data bundle is no longer available. Please refresh the page and choose it again — no money has left your wallet.'
            );
        }

        $amount = round((float) ($plan['your_price'] ?? 0), 2);

        if ($amount <= 0) {
            return back()->withInput()->with(
                'error',
                'That data bundle has no price right now. Please choose another bundle or contact support.'
            );
        }

        if (abs($amount - round((float) $validated['plan_price'], 2)) > 0.01) {
            return back()->withInput()->with(
                'error',
                'The price of that bundle is now ₦' . number_format($amount, 2)
                . '. Please confirm the new price and submit again — no money has left your wallet.'
            );
        }

        // Everything downstream reads the catalogue's values, not the form's.
        $networkCode = (string) ($plan['network_code'] ?? $validated['data_network']);
        $network = self::NETWORKS[$networkCode] ?? ($plan['network'] ?? 'Unknown');
        $planName = (string) ($plan['plan_name'] ?? $validated['plan_name']);

        return $this->dispatchPurchase(
            $request,
            product: 'data',
            amount: $amount,
            recipient: $validated['phone'],
            providerLabel: $network,
            description: 'Data — ' . $planName,
            meta: [
                'network_code' => $networkCode,
                'network_name' => $network,
                'phone_number' => $validated['phone'],
                'plan_id' => $plan['plan_id'] ?? $validated['data_plan'],
                'plan_code' => $plan['plan_code'] ?? null,
                'plan_name' => $planName,
                'plan_type' => $plan['plan_type'] ?? null,
            ],
            providerParams: [
                'network' => $networkCode,
                'plan' => (string) ($plan['plan_id'] ?? $validated['data_plan']),
                'phone' => $validated['phone'],
            ],
            successMessage: $planName . ' sent to ' . $validated['phone'] . '.',
            pin: $validated['pin'],
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );
    }

    /**
     * Shared dispatch for airtime and data.
     *
     * The price for data comes from the posted `plan_price`, which the
     * pricelist page supplies from the server-rendered catalogue. Airtime takes
     * a customer-entered amount. Both are bounded by config('bills.ranges') and
     * re-checked against the spend limits inside the pipeline.
     */
    private function dispatchPurchase(
        Request $request,
        string $product,
        float $amount,
        string $recipient,
        string $providerLabel,
        string $description,
        array $meta,
        array $providerParams,
        string $successMessage,
        string $pin,
        ?string $idempotencyKey,
    ) {
        $user = Auth::user();

        // Captured once, here, so every product's security block in the receipt
        // email has real values rather than blanks. This is the record the
        // customer checks when they want to know whether a charge was them, so
        // it is worth the two extra columns of JSON.
        $meta = $meta + [
            'request_ip' => $request->ip(),
            'request_user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 255, ''),
        ];

        $fee = $product === 'airtime'
            ? round($amount * 0.02, 2)
            : 50.0;

        try {
            $result = $this->bills->purchase(
                user: $user,
                product: $product,
                amount: $amount,
                fee: $fee,
                recipient: $recipient,
                providerLabel: $providerLabel,
                description: $description,
                meta: $meta,
                providerParams: $providerParams,
                dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                    $product,
                    $providerParams,
                    $transaction->reference,
                ),
                successMessage: $successMessage,
                pin: $pin,
                idempotencyKey: $idempotencyKey,
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

    /* =====================================================================
     | Outcome pages — ownership enforced
     |=================================================================== */

    public function success(string $reference)
    {
        return view('transactions.success', ['transaction' => $this->ownedTransaction($reference)]);
    }

    public function failed(string $reference)
    {
        return view('transactions.failed', ['transaction' => $this->ownedTransaction($reference)]);
    }

    private function ownedTransaction(string $reference): Transactions
    {
        return Transactions::where('user_id', Auth::id())
            ->where('reference', $reference)
            ->firstOrFail();
    }
}
