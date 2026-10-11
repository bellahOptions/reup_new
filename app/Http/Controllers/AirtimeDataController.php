<?php

namespace App\Http\Controllers;

use App\Models\PromotionNotification;
use App\Models\Transactions;
use App\Models\User;
use App\Pricing\PricingEngine;
use App\Services\BillPaymentService;
use App\Services\ClubKonnectCatalogue;
use App\Services\NetworkResolver;
use App\Services\ProviderCostResolver;
use App\Services\SecurityService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AirtimeDataController extends Controller
{
    /**
     * The `payment_method` value meaning "charge my card or transfer, not my wallet".
     *
     * A constant rather than a literal because it appears in the validation rule, the
     * controller's branch and the form, and a typo in one of those would silently
     * charge the wrong rail.
     */
    public const PAY_WITH_PAYSTACK = 'paystack';

    /**
     * ClubKonnect network identifiers.
     *
     * Kept because the forms post these codes and the provider adapters expect
     * them. The *name* shown to a customer is resolved through `NetworkResolver`,
     * which owns the canonical spelling and the neutral fallback — this map is a
     * protocol detail, not a display concern.
     */
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
        private readonly PricingEngine $pricing,
        private readonly ProviderCostResolver $costs,
        private readonly \App\Services\PaystackService $paystack,
        private readonly \App\Orders\FundedPurchase $fundedPurchases,
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

        /*
         * `payment_method` chooses the rail, and it decides whether a PIN is
         * required. A wallet purchase is authorised by the transaction PIN; a card
         * purchase is authorised at the gateway, and asking for a PIN as well would
         * be asking the customer to authorise the same payment twice — once with a
         * four-digit code and once with their bank.
         *
         * `required_unless` rather than `required`, so the two rails genuinely differ
         * instead of a Paystack customer being made to invent a PIN.
         */
        $paysByCard = $request->input('payment_method') === self::PAY_WITH_PAYSTACK;

        $validated = $request->validate([
            'network' => 'required|string|in:' . implode(',', array_keys(self::NETWORKS)),
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'amount' => 'required|numeric|min:' . $range['min'] . '|max:' . $range['max'],
            'payment_method' => 'nullable|string|in:wallet,paystack',
            'pin' => ['required_unless:payment_method,' . self::PAY_WITH_PAYSTACK, 'nullable', 'string', 'size:4'],
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        /*
         * The network is resolved once, here, from the *validated* code the form
         * posted. Everything downstream — the pricing rule context, the persisted
         * columns and the receipt — uses this canonical value, so the customer's
         * receipt cannot disagree with the network they picked, and the provider's
         * own name can never be mistaken for it.
         */
        $networkCode = (string) $validated['network'];
        $networkKey = NetworkResolver::key($networkCode, 'clubkonnect');
        $networkName = NetworkResolver::displayName($networkCode, 'clubkonnect');

        /*
         * Integer kobo, exactly. This was `round((float) $validated['amount'], 2)`
         * and carried through the pipeline as a float — which is how a ₦1,000
         * airtime purchase can end up recorded as ₦999.99.
         */
        $faceValue = Money::fromNaira($validated['amount']);

        $quote = $this->quoteAirtime($faceValue, $networkKey, $networkCode);

        if (! $quote['ok']) {
            return back()->withInput()->with('error', $quote['message']);
        }

        return $this->dispatchPurchase(
            $request,
            product: 'airtime',
            amount: $faceValue->toFloat(),
            recipient: $validated['phone'],
            providerLabel: $networkName,
            description: 'Airtime — ' . $networkName,
            quote: $quote['quote'],
            networkKey: $networkKey,
            networkName: $networkName,
            meta: [
                'network_code' => $networkCode,
                'network_name' => $networkName,
                'phone_number' => $validated['phone'],
            ],
            providerParams: [
                'network' => $networkCode,
                'phone' => $validated['phone'],
                'amount' => $faceValue->toFloat(),
            ],
            successMessage: $faceValue->format() . ' airtime sent to ' . $validated['phone'] . '.',
            pin: $validated['pin'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
            paysByCard: $paysByCard,
            pricingContext: [
                'network' => $networkKey,
                'capability' => ProviderCostResolver::CAPABILITY_AIRTIME,
            ],
        );
    }

    /**
     * Price an airtime purchase through the central pricing engine.
     *
     * ## The 2% fee is gone
     *
     * This used to be `round($amount * 0.02, 2)`: every airtime purchase carried
     * an unconditional 2% service fee, so ₦200 of airtime cost ₦204. That is not
     * the ReUp model. Airtime now prices under `FACE_VALUE` — the customer pays
     * the airtime they asked for — and the only way a customer fee appears is if a
     * Super Admin explicitly enables one on the applicable rule.
     *
     * ## Where the profit comes from
     *
     * Not from the customer. The provider's discount (3% by assumption, held as a
     * configurable per-network term) means ₦1,000 of airtime costs us ₦970, and the
     * ₦30 spread is the gross profit. That cost is resolved by
     * `ProviderCostResolver` and never enters the price.
     *
     * @return array{ok:bool,quote:?\App\Pricing\PriceQuote,message:string}
     */
    private function quoteAirtime(Money $faceValue, ?string $networkKey, string $networkCode): array
    {
        $cost = $this->costs->forAirtime($faceValue->minor(), $networkKey);

        if (! $cost) {
            /*
             * No usable provider cost. The brief is explicit: do not invent one.
             * Refusing costs us a sale; guessing costs us the credibility of every
             * profit figure in the system, and would let an unprofitable purchase
             * through silently.
             */
            return [
                'ok' => false,
                'quote' => null,
                'message' => $this->costs->unavailableMessage(),
            ];
        }

        $quote = $this->pricing->quote(
            providerCost: $cost->costMinor,
            quantity: 1,
            context: [
                'network' => $networkKey,
                'capability' => ProviderCostResolver::CAPABILITY_AIRTIME,
            ],
            priceBasisMinor: $faceValue->minor(),
            costMeta: $cost->toEngineMetadata(),
        );

        if (! $quote->isSellable()) {
            return [
                'ok' => false,
                'quote' => $quote,
                'message' => $quote->refusalReason ?? $this->costs->unavailableMessage(),
            ];
        }

        /*
         * Last line of defence on the pricing model: if the engine has produced a
         * price above face value, a fee has been enabled that the operator did not
         * intend, or a rule is misconfigured. Refusing is right — silently charging
         * more than the airtime is worth is the exact behaviour being removed.
         */
        if ($quote->customerPriceMinor > $faceValue->minor() && $quote->customerFeeMinor <= 0) {
            \Illuminate\Support\Facades\Log::warning('Airtime quote exceeded face value without an enabled fee', [
                'network' => $networkKey,
                'network_code' => $networkCode,
                'face_value_minor' => $faceValue->minor(),
                'quoted_minor' => $quote->customerPriceMinor,
                'rule_id' => $quote->pricingRuleId,
            ]);

            return [
                'ok' => false,
                'quote' => $quote,
                'message' => $this->costs->unavailableMessage(),
            ];
        }

        return ['ok' => true, 'quote' => $quote, 'message' => ''];
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
            'payment_method' => 'nullable|string|in:wallet,paystack',
            'pin' => ['required_unless:payment_method,' . self::PAY_WITH_PAYSTACK, 'nullable', 'string', 'size:4'],
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $paysByCard = $request->input('payment_method') === self::PAY_WITH_PAYSTACK;

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
        $planName = (string) ($plan['plan_name'] ?? $validated['plan_name']);

        /*
         * The network comes from the catalogue row that priced the bundle, not from
         * the posted field, so a tampered form cannot label an MTN bundle as Airtel
         * and cannot influence the network-scoped pricing rule that applies.
         */
        $networkKey = NetworkResolver::key($networkCode, 'clubkonnect');
        $networkName = NetworkResolver::displayName($networkCode, 'clubkonnect');

        $quote = $this->quoteData($plan, $networkKey, $validated['plan_price']);

        if (! $quote['ok']) {
            return back()->withInput()->with('error', $quote['message']);
        }

        return $this->dispatchPurchase(
            $request,
            product: 'data',
            amount: $amount,
            recipient: $validated['phone'],
            providerLabel: $networkName,
            description: 'Data — ' . $planName,
            quote: $quote['quote'],
            networkKey: $networkKey,
            networkName: $networkName,
            meta: [
                'network_code' => $networkCode,
                'network_name' => $networkName,
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
            pin: $validated['pin'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
            paysByCard: $paysByCard,
            pricingContext: [
                'network' => $networkKey,
                'capability' => ProviderCostResolver::CAPABILITY_DATA,
            ],
        );
    }

    /**
     * Price a data bundle through the central pricing engine.
     *
     * The catalogue's `clubkonnect_price` is what the provider charges **us** and
     * is the effective cost. The price the customer pays comes from the engine's
     * rule for this network — no longer a hard-coded 1.5% baked into the cached
     * catalogue, which meant every bundle on every network carried the same margin
     * and no operator could change one without a code deploy.
     *
     * @param  array<string,mixed>  $plan  the resolved catalogue row
     * @return array{ok:bool,quote:?\App\Pricing\PriceQuote,message:string}
     */
    private function quoteData(array $plan, ?string $networkKey, $requestedPrice): array
    {
        $cost = $this->costs->forDataBundle($plan);

        if (! $cost) {
            return [
                'ok' => false,
                'quote' => null,
                'message' => $this->costs->unavailableMessage(),
            ];
        }

        $quote = $this->pricing->quote(
            providerCost: $cost->costMinor,
            quantity: 1,
            context: [
                'network' => $networkKey,
                'capability' => ProviderCostResolver::CAPABILITY_DATA,
            ],
            /*
             * No price basis for a bundle: the customer buys a *bundle*, not a face
             * value, so the engine prices it cost-plus from the verified cost. A
             * FACE_VALUE rule on data therefore prices at cost — which is exactly
             * what face value means for a bundle, and why data is normally given a
             * markup rule.
             */
            costMeta: $cost->toEngineMetadata(),
        );

        if (! $quote->isSellable()) {
            return [
                'ok' => false,
                'quote' => $quote,
                'message' => $quote->refusalReason ?? $this->costs->unavailableMessage(),
            ];
        }

        /*
         * The price moved between the page rendering and the submit. Refused rather
         * than silently charged: the customer must agree to the new amount, which is
         * the behaviour this check has always had and is now measured against the
         * engine's figure instead of the catalogue's.
         */
        $quoted = Money::fromMinor($quote->customerPriceMinor);
        $presented = Money::fromNaira($requestedPrice);

        if (! $quoted->equals($presented)) {
            return [
                'ok' => false,
                'quote' => $quote,
                'message' => 'The price of that bundle is now ' . $quoted->format()
                    . '. Please confirm the new price and submit again — no money has left your wallet.',
            ];
        }

        return ['ok' => true, 'quote' => $quote, 'message' => ''];
    }

    /**
     * Shared dispatch for airtime and data.
     *
     * `$quote` is the authoritative price from the pricing engine. `$amount` is kept
     * only for the provider payload and the legacy idempotency hash — the wallet is
     * debited `$quote->customerPriceMinor`, and `transactions.total_amount` is
     * written from the same figure, so the amount the customer was quoted and the
     * amount they are charged cannot disagree.
     *
     * The network is passed as a canonical key plus its display name and stored on
     * the transaction, so a receipt printed later reproduces what the customer was
     * told rather than depending on today's catalogue.
     */
    private function dispatchPurchase(
        Request $request,
        string $product,
        float $amount,
        string $recipient,
        string $providerLabel,
        string $description,
        \App\Pricing\PriceQuote $quote,
        ?string $networkKey,
        string $networkName,
        array $meta,
        array $providerParams,
        string $successMessage,
        ?string $pin,
        ?string $idempotencyKey,
        bool $paysByCard = false,
        array $pricingContext = [],
    ) {
        $user = Auth::user();

        /*
         * ---- Pay with card or transfer ---------------------------------
         *
         * The customer is sent to the gateway for the exact quoted price, and the
         * purchase happens when that payment settles. See `App\Orders\FundedPurchase`
         * for why the money is credited to the wallet and then debited from it —
         * the short version is that it makes a card purchase the *same* code path as
         * a wallet purchase, so the margin, the provider selection, the pricing
         * snapshot and the refund behaviour cannot drift apart from it.
         */
        if ($paysByCard) {
            return $this->startCardPurchase(
                request: $request,
                user: $user,
                product: $product,
                amount: $amount,
                recipient: $recipient,
                providerLabel: $providerLabel,
                description: $description,
                quote: $quote,
                networkKey: $networkKey,
                networkName: $networkName,
                meta: $meta,
                providerParams: $providerParams,
                successMessage: $successMessage,
                idempotencyKey: $idempotencyKey,
                pricingContext: $pricingContext,
            );
        }

        // Captured once, here, so every product's security block in the receipt
        // email has real values rather than blanks. This is the record the
        // customer checks when they want to know whether a charge was them, so
        // it is worth the two extra columns of JSON.
        $meta = $meta + [
            'request_ip' => $request->ip(),
            'request_user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 255, ''),
            // Recorded so a legacy-read path and the admin console can resolve the
            // network without joining anything.
            'network_key' => $networkKey,
            'network_name' => $networkName,
        ];

        try {
            $result = $this->bills->purchase(
                user: $user,
                product: $product,
                amount: $amount,
                fee: 0.0,
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
                quote: $quote,
                networkKey: $networkKey,
                networkName: $networkName,
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

    /**
     * Send the customer to the gateway for the exact price of a purchase.
     *
     * ## What is created here, and what is not
     *
     * A `funding` transaction for the quoted total, with the *purchase intent*
     * attached to its meta: the product, the recipient, the provider payload and the
     * pricing context. That row is the promise — it is what the gateway is asked for,
     * what the webhook settles, and what the vending step reads when it runs in a
     * later request with nothing else to go on.
     *
     * What is deliberately **not** created here is the product transaction. No
     * provider is called and no airtime is vended, because no money has arrived yet.
     * Vending on the strength of a redirect would hand over goods to anybody who
     * reached this endpoint and closed the tab.
     *
     * ## The price is pinned
     *
     * The gateway is asked for `$quote->price()` — the figure the customer was shown.
     * `PaystackService::settle()` then compares the amount actually paid against this
     * row's `total_amount`, so a partial or altered payment cannot settle it. The
     * customer pays what they were quoted, which is the funding promise this
     * application already makes for wallet top-ups and now makes here too.
     *
     * ## Margin
     *
     * ReUp's profit is unaffected by the rail. The customer pays ₦1,000 for ₦1,000 of
     * airtime; the provider charges us ₦970; the ₦30 is ours the moment the payment
     * settles, because the debit that follows is the full ₦1,000 and the cost behind
     * it is unchanged. Paying by card does not move the margin, and nothing here
     * tries to — it comes from the quote the pricing engine already produced.
     */
    private function startCardPurchase(
        Request $request,
        User $user,
        string $product,
        float $amount,
        string $recipient,
        string $providerLabel,
        string $description,
        \App\Pricing\PriceQuote $quote,
        ?string $networkKey,
        string $networkName,
        array $meta,
        array $providerParams,
        string $successMessage,
        ?string $idempotencyKey,
        array $pricingContext,
    ) {
        $price = $quote->price();

        if (! $price->isPositive()) {
            return back()->withInput()->with('error', 'That purchase has no price right now. Please try again.');
        }

        $meta = $meta + [
            'request_ip' => $request->ip(),
            'request_user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 255, ''),
            'network_key' => $networkKey,
            'network_name' => $networkName,
        ];

        try {
            $funding = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $price, $description, $recipient, $idempotencyKey, $meta) {
                return Transactions::create([
                    'user_id' => $user->id,
                    'reference' => $this->wallets->generateReference('FND'),
                    'type' => 'credit',
                    'service_type' => 'funding',
                    'description' => 'Payment for ' . $description,
                    'amount' => $price->toDecimalString(),
                    /*
                     * `total_amount` is what the gateway is asked for, and it equals
                     * the quote's price. Settlement compares the two, so the amount
                     * the customer agreed to and the amount they are charged cannot
                     * diverge.
                     */
                    'service_fee' => '0.00',
                    'total_amount' => $price->toDecimalString(),
                    'recipient' => $recipient,
                    'provider' => 'paystack',
                    'payment_method' => 'paystack',
                    'payment_status' => 'pending',
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                    'meta' => $meta + [
                        'funding_amount' => $price->toDecimalString(),
                        'total_payable' => $price->toDecimalString(),
                        'initiated_at' => now()->toDateTimeString(),
                        /*
                         * Marked so the funding page, the receipt and support all
                         * know this money was not a top-up: it was already spent
                         * before it arrived.
                         */
                        'purpose' => 'product_purchase',
                    ],
                ]);
            });
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'We could not start that payment. Nothing has been charged.');
        }

        /*
         * The intent goes on after the row exists, so the record of what the money
         * is for is written by the same service that will act on it.
         */
        $this->fundedPurchases->attach($funding, [
            'product' => $product,
            'amount' => $amount,
            'recipient' => $recipient,
            'provider_label' => $providerLabel,
            'description' => $description,
            'success_message' => $successMessage,
            'provider_params' => $providerParams,
            'meta' => $meta,
            'network_key' => $networkKey,
            'network_name' => $networkName,
            'idempotency_key' => $idempotencyKey,
            /*
             * What the pricing engine needs to re-price this sale when it is vended,
             * so the snapshot records the margin actually earned rather than the one
             * quoted at checkout. A moved provider cost would otherwise be misstated.
             */
            'pricing_context' => $pricingContext,
            'quoted_price_minor' => $price->minor(),
            'quoted_cost_minor' => $quote->providerCostMinor,
            'quoted_profit_minor' => $quote->grossProfitMinor,
        ]);

        try {
            $url = $this->paystack->initialize($funding, $user);
        } catch (Throwable $e) {
            report($e);

            /*
             * Nothing reached a gateway, so the row is failed rather than left
             * pending — `payments:reconcile` would otherwise have to age it out, and
             * a pending row for a payment that was never started is a row an operator
             * has to investigate.
             */
            $funding->forceFill([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => 'Could not start the card payment session.',
                'completed_at' => now(),
            ])->save();

            return back()->withInput()->with(
                'error',
                'We could not start the card payment session. Nothing has been charged — please try again or pay from your wallet.'
            );
        }

        return $this->sendToGateway($request, $url);
    }

    /**
     * Hand the customer to the gateway, in whichever shape the request asked for.
     *
     * The same contract as `WalletController::sendToGateway()`, for the same reason:
     * this page submits with `fetch()` so it can report a failure in one round trip,
     * and a full-page POST leaves the old page — and its spinner — on screen for as
     * long as the gateway takes to answer.
     */
    private function sendToGateway(Request $request, string $url)
    {
        if (! $request->wantsJson()) {
            return redirect()->away($url);
        }

        return response()->json([
            'status' => 'redirect',
            'redirect' => $url,
        ]);
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
