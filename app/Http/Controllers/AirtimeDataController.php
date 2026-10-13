<?php

namespace App\Http\Controllers;

use App\Models\PromotionNotification;
use App\Models\Transactions;
use App\Models\User;
use App\Pricing\PriceChangeQuote;
use App\Pricing\PricingEngine;
use App\Services\BillPaymentService;
use App\Services\ClubKonnectCatalogue;
use App\Services\DataPriceService;
use App\Services\NetworkBrand;
use App\Services\NetworkResolver;
use App\Services\PhoneNumber;
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
        private readonly DataPriceService $dataPrices,
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

    /**
     * The network a phone number is probably on, for the page to pre-select.
     *
     * ## What this endpoint does not do
     *
     * It makes **no provider request**. Detection is a prefix hint resolved from
     * `config/networks.php`, so it is free and instantaneous, and the page can call
     * it whenever the customer finishes typing without spending a provider's rate
     * limit on it. There is no provider endpoint to call — see
     * `PhoneNumber::detect()`.
     *
     * ## Why the network code is returned as well as the name
     *
     * The page needs to tick a radio button, and the radios are keyed by the
     * provider's code (`01`), not by our canonical key (`mtn`). Returning both
     * keeps that translation on the server, where `config/networks.php` owns it,
     * rather than teaching the browser a second mapping that could drift.
     */
    public function detectNetwork(Request $request)
    {
        $phone = (string) $request->query('phone', '');

        $parsed = PhoneNumber::parse($phone);

        if (! $parsed['valid']) {
            return response()->json([
                'valid' => false,
                'reason' => $parsed['reason'],
                'detected' => null,
            ]);
        }

        $detection = PhoneNumber::detect($parsed['national']);
        $networkKey = $detection['key'] ?? null;

        return response()->json([
            'valid' => true,
            'reason' => null,
            'formatted' => PhoneNumber::format($parsed['national']),
            'international' => $parsed['international'],
            'detected' => $detection === null ? null : [
                'key' => $networkKey,
                // The code the form posts, or null when the prefix matched nothing.
                'code' => self::codeFor($networkKey),
                'name' => $detection['name'],
                /*
                 * The brand mark, resolved through the one shared mapping. Sent so
                 * the page can show the logo without holding a second copy of the
                 * asset paths — the duplication `NetworkBrand` exists to prevent.
                 */
                'logo' => NetworkBrand::logoUrlFor($networkKey),
                'confidence' => $detection['confidence'],
                'source' => $detection['source'],
                /*
                 * The page words its own caveat from this, so the portability
                 * warning travels with the answer rather than being something each
                 * view has to remember to write.
                 */
                'portable' => $detection['confidence'] === PhoneNumber::CONFIDENCE_UNCERTAIN,
            ],
        ]);
    }

    /**
     * The form code for a canonical network key.
     *
     * The inverse of `NetworkResolver::key($code, 'clubkonnect')`, derived from the
     * same map so the two cannot disagree.
     */
    public static function codeFor(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        foreach (self::NETWORKS as $code => $label) {
            if (NetworkResolver::key((string) $code, 'clubkonnect') === $key) {
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * Whether the customer's chosen network can be sent to this number.
     *
     * ## The rule, and why it is a refusal with an override rather than a warning
     *
     * A prefix hint is strong evidence and not proof: Nigerian numbers can be ported
     * while keeping their prefix. When the chosen network contradicts the hint, the
     * overwhelmingly likely explanation is that the wrong network is selected — and
     * sending an MTN bundle to an Airtel number spends the customer's money on
     * nothing that arrives.
     *
     * So the contradiction **stops the purchase before the wallet is debited** and
     * asks the customer to confirm. Confirmation is the override for a genuinely
     * ported number, and it is recorded as the customer's own statement rather than
     * as a detection, so the receipt and the audit trail say who decided.
     *
     * A prefix we do not hold yields no candidate, so nothing is refused — the
     * customer's choice stands. That asymmetry is deliberate: an unknown prefix must
     * not block a sale.
     *
     * @return array{ok:bool,message:?string,detected:?string,detected_name:?string,formatted:?string}
     */
    private function networkAgreesWithNumber(string $phone, ?string $chosenKey, bool $confirmed): array
    {
        $parsed = PhoneNumber::parse($phone);

        if (! $parsed['valid']) {
            return ['ok' => false, 'message' => $parsed['reason'], 'detected' => null, 'detected_name' => null, 'formatted' => null];
        }

        $detection = PhoneNumber::detect($parsed['national']);

        $result = [
            'ok' => true,
            'message' => null,
            'detected' => $detection['key'] ?? null,
            'detected_name' => $detection['name'] ?? null,
            'formatted' => PhoneNumber::format($parsed['national']),
        ];

        // No candidate, or no chosen network to compare against: nothing to refuse.
        if ($detection === null || $detection['key'] === null || $chosenKey === null) {
            return $result;
        }

        if ($detection['key'] === $chosenKey) {
            return $result;
        }

        /*
         * The customer has already been asked and has said yes. Their statement
         * wins — it is the only thing that can be right about a ported number.
         */
        if ($confirmed) {
            return $result;
        }

        return [
            'ok' => false,
            'message' => 'That number looks like a ' . $detection['name'] . ' number, but a '
                . (NetworkResolver::labelFor($chosenKey) ?? $chosenKey) . ' bundle is selected. '
                . 'Please confirm you want to continue.',
            'detected' => $detection['key'],
            'detected_name' => $detection['name'],
            'formatted' => PhoneNumber::format($parsed['national']),
        ];
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
            'network_confirmation' => 'nullable|string|in:1,true,on,yes',
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
         * Airtime is delivered to the same number a bundle is, so the same
         * contradiction is refused for the same reason: top-up sent to the wrong
         * network is money spent on nothing. See `networkAgreesWithNumber()`.
         */
        $agreement = $this->networkAgreesWithNumber(
            (string) $validated['phone'],
            $networkKey,
            filled($validated['network_confirmation'] ?? null),
        );

        if (! $agreement['ok']) {
            return $this->networkMismatchResponse($request, $validated, $agreement, [
                'plan' => null,
                'network_key' => $networkKey,
                'network_name' => $networkName,
            ]);
        }

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
    /**
     * Buy a data bundle.
     *
     * ## The price-mismatch dead end, and what replaces it
     *
     * This used to refuse a purchase whose posted price no longer matched and
     * tell the customer to submit again — leaving them to retype a figure the
     * page still showed as the old one, with nothing recording that they had
     * seen and accepted the new amount. A customer who did submit again was
     * simply re-checked against the same moving target.
     *
     * The refusal is still correct — the money must not move without agreement —
     * but the answer is now a *decision*: a structured price-change response
     * carrying the previous price, the new price, the bundle's identity and a
     * short-lived server-side confirmation token, which the page renders as a
     * modal. `confirmDataPrice()` is where that decision is validated and acted
     * on.
     *
     * ## Nothing the client sends is trusted
     *
     * `plan_price` is read only to decide whether the customer's view is stale.
     * The amount charged comes from the quote this method builds and hands to
     * `BillPaymentService`, which debits `$quote->price()` — never the posted
     * figure. A tampered price therefore cannot buy a bundle for ₦1; it can only
     * make the server believe the page was stale, which produces a confirmation
     * prompt for the real price.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function purchaseData(Request $request)
    {
        $validated = $request->validate([
            'data_network' => 'required|string|in:' . implode(',', array_keys(self::NETWORKS)),
            'data_plan' => 'required|string|max:120',
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'plan_name' => 'required|string|max:120',
            /*
             * Kept as a cross-check only, so it is deliberately not allowed to
             * fail the request: a client that omits it, or sends something
             * unparseable, gets the ordinary flow rather than a validation error.
             * Treating a missing price as invalid would break the page for
             * anybody whose JavaScript had not run.
             */
            'plan_price' => 'nullable|numeric|min:0|max:1000000',
            'plan_type' => 'nullable|string|max:60',
            'payment_method' => 'nullable|string|in:wallet,paystack',
            'pin' => ['required_unless:payment_method,' . self::PAY_WITH_PAYSTACK, 'nullable', 'string', 'size:4'],
            'idempotency_key' => 'nullable|string|min:8|max:64',
            /*
             * The confirmation token from a previous price-change prompt, when the
             * customer is accepting a changed price without re-submitting the form.
             */
            'price_confirmation' => 'nullable|string|max:120',
            /*
             * Set when the customer has explicitly accepted a network that
             * contradicts their number's prefix — a ported number. It is the
             * override for `networkAgreesWithNumber()`, and it is deliberately a
             * separate field from the network itself so that "the customer chose
             * Airtel" and "the customer confirmed Airtel for a number that looks
             * like MTN" are not the same claim.
             */
            'network_confirmation' => 'nullable|string|in:1,true,on,yes',
        ]);

        $paysByCard = $request->input('payment_method') === self::PAY_WITH_PAYSTACK;

        /*
         * The network the form claims, resolved once. `DataPriceService::quote()`
         * checks it against the bundle's own network and refuses a mismatch, so a
         * plan from another network cannot be priced at all — see that method for
         * why the check lives there rather than here.
         */
        $selectedNetworkKey = NetworkResolver::key((string) $validated['data_network'], 'clubkonnect');

        $priced = $this->dataPrices->quote((string) $validated['data_plan'], $selectedNetworkKey);

        if (! $priced['found']) {
            /*
             * 409 rather than 404 in both cases, because neither is "no such
             * endpoint": the bundle is either not on the network the customer
             * selected — a conflict between what they chose and what exists — or it
             * has gone from the catalogue, which is also a state conflict rather
             * than a missing resource. Nothing is charged either way.
             */
            return $this->purchaseRefusal($request, $priced['refusal'], 409);
        }

        if ($priced['price_minor'] === null) {
            /*
             * Either the bundle's network does not match the selected one, or the
             * engine could not price it. Both are refusals of the same kind — the
             * bundle cannot be sold to this customer as selected — and the message
             * says which, so it is 409 rather than 404.
             */
            return $this->purchaseRefusal($request, $priced['refusal'], 409);
        }

        /*
         * The bundle is deliverable. Now: does the customer's *number* agree with
         * the network they chose, or have they said so?
         *
         * This happens before the price is examined, because it is not a matter of
         * price — a bundle sent to the wrong network arrives nowhere at any price.
         */
        $agreement = $this->networkAgreesWithNumber(
            (string) $validated['phone'],
            $priced['network_key'],
            filled($validated['network_confirmation'] ?? null),
        );

        if (! $agreement['ok']) {
            /*
             * Answered as a structured 409 rather than a plain error, so the page
             * can raise the confirmation dialog instead of a dead-end message. The
             * shape mirrors the price-change response so the view has one pattern
             * to render.
             */
            return $this->networkMismatchResponse($request, $validated, $agreement, $priced);
        }

        /*
         * The agreement travels on `$validated` rather than as another parameter.
         *
         * There are three routes into `dispatchDataPurchase` — the ordinary one, the
         * accepted-confirmation one, and the resubmission after a network override —
         * and a parameter would have to be threaded through all of them, where
         * missing one would silently record a confirmed network as a detection. As a
         * field it is either present or visibly absent.
         */
        $validated['network_source'] = filled($validated['network_confirmation'] ?? null)
            ? PhoneNumber::SOURCE_CUSTOMER
            : PhoneNumber::SOURCE_PREFIX;
        $validated['network_detected'] = $agreement['detected'];

        $presented = $this->dataPrices->presentedMinor($validated['plan_price'] ?? null);

        /*
         * A confirmation token means the customer has already been shown this
         * price and accepted it. It is validated against the *current* quote — not
         * against whatever the token said — so a price that moved again while the
         * modal was open produces a fresh prompt rather than a purchase at a
         * figure nobody agreed to.
         */
        if (! empty($validated['price_confirmation'])) {
            return $this->confirmDataPrice($request, $validated, $priced, $paysByCard, $presented);
        }

        if ($this->dataPrices->priceHasMoved($presented, $priced['price_minor'])) {
            return $this->priceChangedResponse($request, $validated, $priced, $presented);
        }

        return $this->dispatchDataPurchase($request, $validated, $priced, $paysByCard);
    }

    /**
     * Continue a purchase at a price the customer has explicitly accepted.
     *
     * The five things checked here are the whole security argument for the modal:
     *
     *   1. the token was issued by us and has not expired (the cache entry is the
     *      expiry, so an evicted token is indistinguishable from a forged one);
     *   2. it belongs to this user — a token issued to another account cannot be
     *      presented by this one;
     *   3. it names this exact bundle;
     *   4. the price it recorded is still the price now;
     *   5. the pricing rule that produced it is still the rule producing it.
     *
     * Only then is the token consumed and the purchase made, from a *fresh* quote
     * rather than from the figures the token carries. The token proves the
     * customer agreed to this bundle at this price; it never supplies the amount.
     *
     * @param  array<string,mixed>  $validated
     * @param  array<string,mixed>  $priced
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function confirmDataPrice(
        Request $request,
        array $validated,
        array $priced,
        bool $paysByCard,
        ?int $presented = null
    ) {
        $user = Auth::user();

        $token = (string) $validated['price_confirmation'];
        $consumed = $this->dataPrices->quotes()->consume($token, $user, PriceChangeQuote::NAMESPACE_DATA);

        if (! $consumed['ok']) {
            /*
             * An unknown or expired token is not an error the customer has to
             * understand: they are shown the current price and asked again. That
             * is the same response a moved price produces, deliberately — the
             * remedy is identical and a second kind of message would only invite
             * a second kind of dead end.
             */
            return $this->priceChangedResponse($request, $validated, $priced, $presented, $consumed['reason']);
        }

        $drift = $this->dataPrices->quotes()->stillValid(
            $consumed['payload'],
            PriceChangeQuote::descriptorForPlan($priced['plan']),
            $priced['quote'],
        );

        if ($drift !== null) {
            /*
             * The price or the rule moved while the modal was open. The customer
             * is asked again, for the latest figure — never silently charged.
             *
             * The "previous price" shown is the one the customer was actually
             * looking at, taken from the quote rather than from the page: the
             * page's figure is a claim, the quote's is what we issued.
             */
            return $this->priceChangedResponse(
                $request,
                $validated,
                $priced,
                (int) ($consumed['payload']['price_minor'] ?? $presented ?? 0),
                $drift,
            );
        }

        /*
         * The confirmation carries the idempotency key the original submission
         * used, so the accepted attempt and the refused one share a key: if the
         * first request had actually reached the debit before failing, the replay
         * protection answers from that row instead of charging a second time.
         *
         * A submission that arrived without a key is not left unprotected. The
         * confirmation token is single-use — it was consumed a few lines above, so
         * a replayed confirmation cannot reach this point twice — and the
         * `assertNotDuplicate` cache window covers the immediate double-submit.
         */
        if (! empty($consumed['payload']['idempotency_key'])) {
            $validated['idempotency_key'] = $consumed['payload']['idempotency_key'];
        }

        return $this->dispatchDataPurchase($request, $validated, $priced, $paysByCard);
    }

    /**
     * Tell the customer the price moved, and give them a way to accept it.
     *
     * The response is structured rather than a flash message, because a modal
     * needs the two figures and the bundle's identity to state what changed, and
     * because the confirmation has to carry something the server can verify when
     * it comes back.
     *
     * Nothing has been charged at this point, and nothing will be until the
     * customer accepts: this method runs before `BillPaymentService` is reached,
     * so the wallet is untouched and no provider is called.
     *
     * @param  array<string,mixed>  $validated
     * @param  array<string,mixed>  $priced
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function priceChangedResponse(
        Request $request,
        array $validated,
        array $priced,
        ?int $previousMinor,
        ?string $reason = null
    ) {
        $quote = $priced['quote'];
        $plan = $priced['plan'];

        $confirmation = $this->dataPrices->issueConfirmation(
            Auth::user(),
            $plan,
            $quote,
            $validated['idempotency_key'] ?? null,
        );

        $previousMinor = $previousMinor ?? $quote->customerPriceMinor;

        /*
         * The confirming customer's own balance, computed once. The modal shows it
         * so affordability is answered *before* they accept rather than after —
         * discovering that a new price is unaffordable at the moment of purchase
         * is the same dead end in a different place.
         */
        $balance = $this->wallets->balanceFor(Auth::user());

        $payload = [
            'status' => 'price_changed',
            'reason' => $reason,
            'product' => [
                'plan_code' => ClubKonnectCatalogue::planIdentifier($plan),
                'plan_name' => $plan['plan_name'] ?? $validated['plan_name'],
                'network' => $priced['network_name'],
                'network_key' => $priced['network_key'],
                'data_volume' => $plan['data_volume'] ?? null,
                'validity' => $plan['validity'] ?? null,
            ],
            /*
             * Actual backend values. The example amounts in the brief are
             * illustrations and appear nowhere in this code.
             */
            'previous_price' => Money::fromMinor($previousMinor)->toDecimalString(),
            'previous_price_formatted' => Money::fromMinor($previousMinor)->format(),
            'new_price' => Money::fromMinor($quote->customerPriceMinor)->toDecimalString(),
            'new_price_formatted' => Money::fromMinor($quote->customerPriceMinor)->format(),
            // The confirming customer's own wallet, so the modal can be honest
            // about affordability before they accept rather than after.
            'wallet_balance_formatted' => $balance->format(),
            'affordable' => $balance->greaterThanOrEqual($quote->price()),
            'direction' => $quote->customerPriceMinor > $previousMinor ? 'increase' : 'decrease',
            'pricing_rule_id' => $quote->pricingRuleId,
            'pricing_rule_version' => $quote->pricingRuleVersion,
            'quote' => [
                'identifier' => $confirmation['token'],
                'issued_at' => $confirmation['issued_at'],
                'expires_at' => $confirmation['expires_at'],
                'expires_at_unix' => $confirmation['expires_at_unix'],
                'ttl_seconds' => $confirmation['ttl_seconds'],
            ],
            'catalogue_stale' => (bool) $priced['catalogue_stale'],
            'message' => 'The price for your selected data bundle has changed from '
                . Money::fromMinor($previousMinor)->format()
                . ' to ' . Money::fromMinor($quote->customerPriceMinor)->format()
                . '. Would you like to continue at the updated price?',
        ];

        if ($request->wantsJson()) {
            // 409 Conflict: the request was well-formed and the state was not.
            return response()->json($payload, 409);
        }

        /*
         * Without JavaScript the modal cannot render, so the same decision is
         * presented as a page state: the bundle and both figures are carried back
         * with the form, and the confirm button posts the token. A customer on a
         * degraded connection is not locked out of their own purchase.
         */
        return back()->withInput()->with('price_change', $payload);
    }

    /**
     * Ask the customer to confirm a network their number does not match.
     *
     * ## Why this reuses the price-change payload shape
     *
     * The view already renders a modal for a decision the server cannot make on the
     * customer's behalf: two values, a continue button, a cancel button, and a
     * hidden field carrying the decision back. A network conflict is the same shape
     * of decision — the server cannot know whether a ported number is involved — so
     * it travels in the same envelope with a different `status`. Two bespoke modals
     * would drift; one pattern with two titles does not.
     *
     * ## It issues no confirmation token
     *
     * Deliberately. A price confirmation commits to a *figure* that may move again,
     * so it must be recorded server-side. Confirming a network is a statement about
     * the customer's own SIM, and the price is quoted fresh on the resubmission —
     * so `network_confirmation=1` is the whole payload. Nothing here can be replayed
     * to buy at a stale price, because it carries no price at all.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function networkMismatchResponse(
        Request $request,
        array $validated,
        array $agreement,
        array $priced
    ) {
        $plan = $priced['plan'] ?? null;

        /*
         * Airtime has no bundle, so the "product" block describes the denomination
         * instead. Sharing one payload shape means the view renders one modal; the
         * only difference is what the summary line says.
         */
        $product = $plan === null
            ? [
                'plan_code' => null,
                'plan_name' => 'Airtime ' . Money::fromNaira($validated['amount'] ?? 0)->format(),
                'network' => $priced['network_name'],
                'network_key' => $priced['network_key'],
            ]
            : [
                'plan_code' => ClubKonnectCatalogue::planIdentifier($plan),
                'plan_name' => $plan['plan_name'] ?? $validated['plan_name'],
                'network' => $priced['network_name'],
                'network_key' => $priced['network_key'],
            ];

        $payload = [
            'status' => 'network_mismatch',
            'product' => $product,
            /*
             * The number as the customer typed it, and as we read it. Showing both is
             * how a customer notices a mistyped digit rather than concluding that
             * detection is broken.
             */
            'phone_formatted' => $agreement['formatted'],
            'selected_network_key' => $priced['network_key'],
            'selected_network_name' => $priced['network_name'],
            'detected_network_key' => $agreement['detected'],
            'detected_network_name' => $agreement['detected_name'],
            // The page words its caveat from this rather than assuming it.
            'portable' => true,
            'message' => $agreement['message']
                . ' If your number was ported to '
                . ($priced['network_name'] ?? 'this network')
                . ', continue and the purchase will go ahead on the network you chose.',
        ];

        if ($request->wantsJson()) {
            // 409 Conflict: the request was well-formed and the state was not.
            return response()->json($payload, 409);
        }

        return back()->withInput()->with('network_mismatch', $payload);
    }

    /**
     * The refusal path, in whichever shape the caller can render.
     *
     * Nothing has been charged on any of these, and every message says so where
     * it matters — a customer deciding whether to try again needs to know whether
     * money moved.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function purchaseRefusal(Request $request, ?string $message, int $status)
    {
        $message = $message ?: 'That data bundle is temporarily unavailable. No money has left your wallet.';

        if ($request->wantsJson()) {
            return response()->json(['status' => 'refused', 'message' => $message], $status);
        }

        return back()->withInput()->with('error', $message);
    }

    /**
     * Hand an accepted, validated data quote to the payment pipeline.
     *
     * Extracted so the ordinary path and the price-change confirmation path run
     * *the same code* from here on. Two dispatch sites would be two places for
     * the provider payload, the network attribution or the pricing context to
     * drift, and the confirmation path is the one that would be forgotten.
     *
     * @param  array<string,mixed>  $validated
     * @param  array<string,mixed>  $priced
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function dispatchDataPurchase(Request $request, array $validated, array $priced, bool $paysByCard)
    {
        $plan = $priced['plan'];
        $quote = $priced['quote'];

        // Everything downstream reads the catalogue's values, not the form's.
        $networkCode = (string) ($plan['network_code'] ?? $validated['data_network']);
        $planName = (string) ($plan['plan_name'] ?? $validated['plan_name']);

        /*
         * The network comes from the catalogue row that priced the bundle, not
         * from the posted field, so a tampered form cannot label an MTN bundle as
         * Airtel and cannot influence the network-scoped pricing rule that
         * applies.
         */
        $networkKey = $priced['network_key'] ?? NetworkResolver::key($networkCode, 'clubkonnect');
        $networkName = $priced['network_name'] ?? NetworkResolver::displayName($networkCode, 'clubkonnect');

        return $this->dispatchPurchase(
            $request,
            product: 'data',
            amount: Money::fromMinor($quote->customerPriceMinor)->toFloat(),
            recipient: $validated['phone'],
            providerLabel: $networkName,
            description: 'Data — ' . $planName,
            quote: $quote,
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
                /*
                 * Who decided the network, and what the number suggested.
                 *
                 * Recorded because "the customer confirmed a network their number
                 * does not match" and "we sent it to the network we detected" are
                 * different claims, and only one of them is a detection. A support
                 * question about a bundle that did not arrive needs to know which
                 * happened — and the answer here is the only place it is kept, since
                 * the number may be ported again by the time anybody asks.
                 */
                'network_source' => $validated['network_source'] ?? PhoneNumber::SOURCE_PREFIX,
                'network_detected' => $validated['network_detected'] ?? null,
                // Recorded so support can see that a changed price was shown and
                // accepted, rather than inferring it from a mismatch.
                'price_confirmed_at' => filled($validated['price_confirmation'] ?? null) ? now()->toDateTimeString() : null,
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
