<?php

namespace App\Http\Controllers;

use App\Models\ProviderProduct;
use App\Models\ServiceOrder;
use App\Models\ServiceProduct;
use App\Models\Transactions;
use App\Pricing\PriceChangeQuote;
use App\Providers\Support\ProviderStatus;
use App\Smm\SmmCatalogue;
use App\Smm\SmmOrders;
use App\Smm\SmmPricing;
use App\Smm\SmmTargetValidator;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Social Boost — the customer-facing SMM experience.
 *
 * ## The shape of the flow, and why it has this many steps
 *
 * `catalogue → configure → review → confirm → receipt`
 *
 * A social-boost order is the only purchase in this application where the
 * customer cannot undo a mistake: the provider starts work immediately, the
 * target cannot be changed, and a mistyped link means the followers arrive on
 * somebody else's account. Every other product here is a bill payment or a
 * top-up whose details are checked by the recipient network (a wrong meter
 * number is rejected before delivery). So the review step exists, it shows the
 * exact target the order will be sent with, and the debit happens only after an
 * explicit confirmation.
 *
 * ## What the customer never sees
 *
 * No provider service id, no provider status string, no raw API response, no
 * provider name. The order detail view renders `ServiceOrder::status` through a
 * customer-facing label map; the provider's own vocabulary lives on the admin
 * screen, where an operator needs it for support.
 *
 * ## What the customer cannot set
 *
 * A price. Every figure on every page comes from `SmmPricing`, which is the only
 * thing that decides what a social-boost order costs. The forms carry a service
 * key, a target and a quantity, and nothing else that reaches the pricing path.
 */
class SocialBoostController extends Controller
{
    public function __construct(
        private readonly SmmCatalogue $catalogue,
        private readonly SmmOrders $orders,
        private readonly SmmPricing $pricing,
        private readonly SmmTargetValidator $targets,
        private readonly WalletService $wallets,
    ) {
    }

    /* =====================================================================
     | A. Landing
     | =================================================================== */

    public function index()
    {
        $user = Auth::user();

        return view('social-boost.index', [
            'platforms' => $this->catalogue->platforms(),
            'serviceTypes' => $this->catalogue->serviceTypes(),
            'recentOrders' => ServiceOrder::forUser($user->getKey())
                ->with(['serviceProduct', 'pricingSnapshot'])
                ->latest('id')
                ->limit(4)
                ->get(),
            'stats' => $this->customerStats($user),
            'wallet' => $this->wallets->forUser($user),
        ]);
    }

    /* =====================================================================
     | B. Catalogue
     | =================================================================== */

    public function catalogue(Request $request)
    {
        $filters = $request->validate([
            'platform' => 'nullable|string|max:60',
            'type' => 'nullable|string|max:60',
            'q' => 'nullable|string|max:80',
            'page' => 'nullable|integer|min:1|max:1000',
        ]);

        $results = $this->catalogue->search(
            platformKey: $filters['platform'] ?? null,
            type: $filters['type'] ?? null,
            term: $filters['q'] ?? null,
            page: (int) ($filters['page'] ?? 1),
            perPage: 20,
        );

        return view('social-boost.catalogue', [
            'results' => $results,
            'platforms' => $this->catalogue->platforms(),
            'serviceTypes' => $this->catalogue->serviceTypes($filters['platform'] ?? null),
            'filters' => $filters,
        ]);
    }

    /* =====================================================================
     | C. Order configuration
     | =================================================================== */

    public function configure(Request $request, string $service)
    {
        $product = $this->catalogue->findByKey($service);

        if (! $product) {
            return redirect()->route('social-boost.catalogue')
                ->with('error', 'That service is no longer listed. Please choose another.');
        }

        $offering = $this->catalogue->offeringFor($product);

        /*
         * Deliberately not a 404. A customer who followed a bookmark or a search
         * result to a service that has since been paused deserves to be told that,
         * and sent somewhere useful, rather than shown "not found" for something
         * they can see in their history.
         */
        if (! $offering) {
            return redirect()->route('social-boost.catalogue')
                ->with('error', $product->name . ' is temporarily unavailable. Please choose another service.');
        }

        return view('social-boost.configure', [
            'row' => $this->catalogue->row($product),
            'product' => $product,
            'target' => $this->targets->rulesFor($offering->platform),
            'wallet' => $this->wallets->forUser(Auth::user()),
            'prefill' => [
                'quantity' => $offering->min_quantity ?: 100,
            ],
        ]);
    }

    /* =====================================================================
     | C2. Price preview (the form's running estimate)
     | =================================================================== */

    /**
     * What an order of this size would cost, for the form's running total.
     *
     * ## Why this is a server endpoint and not arithmetic in the browser
     *
     * Nitro's price is `rate × quantity ÷ 1000` applied to *its* rate, then run
     * through the pricing engine with the mandatory markup floor. Reproducing
     * that in JavaScript would mean a second implementation of the pricing rule —
     * and a second implementation is what makes a page show one figure and the
     * purchase charge another, which is exactly the defect this application has
     * already had to remove once.
     *
     * ## It is advisory, and it is treated as such
     *
     * The number this returns is never charged. The review step recomputes from
     * the catalogue, and `place()` recomputes again immediately before the debit.
     * So the endpoint is safe to expose to the customer's own browser: the worst
     * a tampered request can do is change the estimate shown on the page.
     *
     * Read-only, so no PIN and no CSRF-sensitive state; throttled because it is
     * called as the customer types.
     */
    public function pricePreview(Request $request)
    {
        $validated = $request->validate([
            'service' => 'required|string|max:120',
            'quantity' => 'required|integer|min:1|max:100000000',
        ]);

        $resolved = $this->resolve($validated['service']);

        if (! $resolved) {
            return response()->json(['sellable' => false, 'reason' => 'unavailable']);
        }

        [$product, $offering] = $resolved;

        $priced = $this->pricing->quote(
            $offering,
            (int) $validated['quantity'],
            $this->catalogue->pricingContext($offering),
        );

        if (! $priced['ok'] || $priced['quote'] === null) {
            return response()->json(['sellable' => false, 'reason' => 'unavailable']);
        }

        $quote = $priced['quote'];

        return response()->json([
            'sellable' => true,
            'quantity' => (int) $validated['quantity'],
            'price' => $quote->price()->toDecimalString(),
            'formatted' => $quote->price()->format(),
            /*
             * The floor, so the form can explain a refusal in the terms an
             * operator would fix it. No cost or profit figure is ever returned to
             * a customer — `toCustomerBreakdown()` is the object that guarantees
             * that, and this mirrors its contract.
             */
            'minimum_quantity' => $offering->min_quantity,
            'maximum_quantity' => $offering->max_quantity,
            'supports_refill' => (bool) $offering->supports_refill,
        ]);
    }

    /* =====================================================================
     | D. Review
     | =================================================================== */
    /**
     * The review screen: everything the order will be, priced, before any money
     * moves.
     *
     * Nothing is created here. The quote is computed, displayed, and recomputed
     * again at submission — so a review page left open while a rate changed shows
     * a stale figure that the next step refuses rather than charges.
     */
    public function review(Request $request)
    {
        $validated = $this->validateOrder($request);

        $resolved = $this->resolve($validated['service']);

        if (! $resolved) {
            return redirect()->route('social-boost.catalogue')
                ->with('error', 'That service is no longer available. Please choose another.');
        }

        [$product, $offering] = $resolved;

        $priced = $this->pricing->quote($offering, $validated['quantity'], $this->catalogue->pricingContext($offering));

        if (! $priced['ok']) {
            return back()->withInput()->with('error', $priced['message']);
        }

        $quote = $priced['quote'];
        $balance = $this->wallets->balanceFor(Auth::user());

        return view('social-boost.review', [
            'row' => $this->catalogue->row($product),
            'product' => $product,
            'quantity' => $validated['quantity'],
            'target' => $validated['target'],
            // The platform's own wording for what the target should be, so the
            // review reads "Discord server invite link" rather than "Target".
            'targetLabel' => $this->targets->labelFor($offering->platform),
            'orderParams' => $this->orderParams($request),
            'quote' => $quote,
            'balance' => $balance,
            'balanceAfter' => $balance->minus($quote->price()),
            'affordable' => $balance->greaterThanOrEqual($quote->price()),
            'hasPin' => app(\App\Services\SecurityService::class)->hasPin(Auth::user()),
        ]);
    }

    /* =====================================================================
     | E. Confirm
     | =================================================================== */

    /**
     * Place the order.
     *
     * ## Revalidation, and the price-change modal
     *
     * The price the customer reviewed is re-derived here, from the provider's
     * current rate and the current rule. If it has moved, the order is **not**
     * placed: the previous and new prices are returned with a single-use
     * confirmation token, and `placeConfirmed()` is what acts on it. Charging the
     * new figure silently would be charging an amount nobody agreed to, and
     * refusing outright would be the dead end this flow replaces.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function place(Request $request)
    {
        $validated = $this->validateOrder($request);

        $resolved = $this->resolve($validated['service']);

        if (! $resolved) {
            return $this->refusal($request, 'That service is no longer available. Please choose another.', 409);
        }

        [$product, $offering] = $resolved;

        $priced = $this->pricing->quote($offering, $validated['quantity'], $this->catalogue->pricingContext($offering));

        if (! $priced['ok']) {
            return $this->refusal($request, $priced['message'], 409);
        }

        $quote = $priced['quote'];
        $presented = $this->presentedMinor($request->input('price'));

        /*
         * A confirmation token means this is the second pass: the customer has
         * seen the new price and accepted it.
         */
        if (filled($request->input('price_confirmation'))) {
            return $this->placeConfirmed($request, $validated, $product, $offering, $priced, $presented);
        }

        if ($presented !== null && $this->priceMoved($presented, $quote->customerPriceMinor)) {
            return $this->priceChanged($request, $validated, $product, $offering, $quote, $presented);
        }

        return $this->submit($request, $validated, $product, $offering);
    }

    /**
     * Continue at a price the customer explicitly accepted.
     *
     * The token is consumed *before* anything is attempted, so a replayed
     * confirmation finds nothing. The price and the rule are then re-checked
     * against a quote taken now: if either moved again the customer is asked
     * again, with a fresh token, rather than charged.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function placeConfirmed(
        Request $request,
        array $validated,
        ServiceProduct $product,
        ProviderProduct $offering,
        array $priced,
        ?int $presented
    ) {
        $user = Auth::user();
        $token = (string) $request->input('price_confirmation');

        $consumed = app(PriceChangeQuote::class)->consume($token, $user, PriceChangeQuote::NAMESPACE_SMM);

        if (! $consumed['ok']) {
            /*
             * Unknown or expired: the remedy is the same as a moved price — show
             * the current figure and ask again — so it produces the same response
             * rather than a second kind of message.
             */
            return $this->priceChanged($request, $validated, $product, $offering, $priced['quote'], $presented, $consumed['reason']);
        }

        $drift = app(PriceChangeQuote::class)->stillValid(
            $consumed['payload'],
            $this->descriptor($product, $offering, $validated['quantity']),
            $priced['quote'],
        );

        if ($drift !== null) {
            return $this->priceChanged(
                $request,
                $validated,
                $product,
                $offering,
                $priced['quote'],
                (int) ($consumed['payload']['price_minor'] ?? $presented ?? 0),
                $drift,
            );
        }

        return $this->submit($request, $validated, $product, $offering);
    }

    /**
     * Hand a validated, priced order to the shared pipeline.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function submit(Request $request, array $validated, ServiceProduct $product, ProviderProduct $offering)
    {
        $user = Auth::user();

        try {
            $result = $this->orders->place(
                user: $user,
                product: $product,
                offering: $offering,
                target: $validated['target'],
                quantity: $validated['quantity'],
                orderParams: $this->orderParams($request),
                pin: $validated['pin'] ?? null,
            );
        } catch (Throwable $e) {
            /*
             * A throw from the pipeline is almost always a security gate — a wrong
             * PIN, a velocity limit, insufficient balance — and every one of them
             * is a message the customer is meant to read. `getMessage()` is the
             * same string every other product surfaces.
             */
            return $this->refusal($request, $e->getMessage(), 422);
        }

        /*
         * A *placed* order is not always a *successful* one.
         *
         * `ServiceOrderService::place()` reports `ok` as "the provider returned
         * SUCCESS", which is the right question for a synchronous provider and the
         * wrong one here: Nitro is asynchronous and its `add` action returns an
         * order id with every order starting PENDING. Treating PENDING as a failure
         * would show an error to a customer whose order is running normally.
         *
         * So the question asked here is the one the customer is asking: **was the
         * order placed?** PENDING and PROCESSING answer yes. UNKNOWN answers "we
         * are checking" and is surfaced as its own message — never as a failure,
         * because telling a customer an order failed when it may have been
         * delivered is how they buy it a second time.
         */
        $status = $result['status'] ?? ProviderStatus::UNKNOWN;
        $placed = $result['order'] !== null && in_array($status, [
            ProviderStatus::SUCCESS,
            ProviderStatus::PENDING,
            ProviderStatus::PROCESSING,
        ], true);

        if (! $placed) {
            if ($result['order'] === null) {
                return $this->refusal($request, $result['message'], 409);
            }

            // The order exists and is held; the customer is sent to it with the
            // message that says so rather than to a refusal.
            return $this->toOrder($request, $result['order'], $result['message']);
        }

        return $this->toOrder($request, $result['order'], $result['message']);
    }

    /**
     * Send the customer to their order, in whichever shape the request expects.
     *
     * The order page *is* the confirmation: it shows the reference, the service,
     * the amount and the live status, and it is where a customer returns to watch
     * progress. A separate "thank you" page would be a second place for the same
     * information to go stale.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function toOrder(Request $request, ServiceOrder $order, string $message)
    {
        $url = route('social-boost.orders.show', $order->uuid);

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'created',
                'redirect' => $url,
                'reference' => $order->uuid,
                'message' => $message,
            ], 201);
        }

        return redirect()->to($url)->with('success', $message);
    }

    /**
     * The price-change decision.
     *
     * Nothing has been charged and nothing will be until the customer accepts.
     * The figures are the backend's own; the example amounts a brief might use
     * appear nowhere.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function priceChanged(
        Request $request,
        array $validated,
        ServiceProduct $product,
        ProviderProduct $offering,
        \App\Pricing\PriceQuote $quote,
        ?int $previousMinor,
        ?string $reason = null
    ) {
        $user = Auth::user();

        $confirmation = app(PriceChangeQuote::class)->issue(
            $user,
            $this->descriptor($product, $offering, $validated['quantity']),
            $quote->customerPriceMinor,
            $quote->pricingRuleId,
            null,
            PriceChangeQuote::NAMESPACE_SMM,
        );

        $previousMinor = $previousMinor ?? $quote->customerPriceMinor;
        $balance = $this->wallets->balanceFor($user);

        $payload = [
            'status' => 'price_changed',
            'reason' => $reason,
            'product' => [
                'service' => $product->product_key,
                'name' => $product->name,
                'platform' => $this->catalogue->platformLabel((string) $offering->platform),
                'quantity' => $validated['quantity'],
                'target' => $validated['target'],
            ],
            'previous_price' => \App\Support\Money::fromMinor($previousMinor)->toDecimalString(),
            'previous_price_formatted' => \App\Support\Money::fromMinor($previousMinor)->format(),
            'new_price' => \App\Support\Money::fromMinor($quote->customerPriceMinor)->toDecimalString(),
            'new_price_formatted' => \App\Support\Money::fromMinor($quote->customerPriceMinor)->format(),
            'wallet_balance_formatted' => $balance->format(),
            'affordable' => $balance->greaterThanOrEqual($quote->price()),
            'direction' => $quote->customerPriceMinor > $previousMinor ? 'increase' : 'decrease',
            'quote' => [
                'identifier' => $confirmation['token'],
                'issued_at' => $confirmation['issued_at'],
                'expires_at' => $confirmation['expires_at'],
                'expires_at_unix' => $confirmation['expires_at_unix'],
                'ttl_seconds' => $confirmation['ttl_seconds'],
            ],
            'message' => 'The price for this boost has changed from '
                . \App\Support\Money::fromMinor($previousMinor)->format()
                . ' to ' . \App\Support\Money::fromMinor($quote->customerPriceMinor)->format()
                . '. Would you like to continue at the updated price?',
        ];

        if ($request->wantsJson()) {
            return response()->json($payload, 409);
        }

        return back()->withInput()->with('price_change', $payload);
    }

    /**
     * A refusal, in whichever shape the caller can render.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function refusal(Request $request, ?string $message, int $code)
    {
        $message = $message ?: 'We could not complete that order. No money has left your wallet.';

        if ($request->wantsJson()) {
            return response()->json(['status' => 'refused', 'message' => $message], $code);
        }

        return back()->withInput()->with('error', $message);
    }

    /* =====================================================================
     | F. My orders
     | =================================================================== */

    public function orders(Request $request)
    {
        $filters = $request->validate([
            'status' => 'nullable|string|in:' . implode(',', array_keys($this->statusLabels())),
            'service' => 'nullable|string|max:120',
            'q' => 'nullable|string|max:80',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $user = Auth::user();

        $orders = ServiceOrder::forUser($user->getKey())
            ->with(['serviceProduct', 'pricingSnapshot'])
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['service'] ?? null), fn ($q) => $q->where('product_key', $filters['service']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters) {
                $term = trim((string) $filters['q']);

                $q->where(function ($inner) use ($term) {
                    $inner->where('uuid', 'like', '%' . $term . '%')
                        ->orWhere('recipient', 'like', '%' . $term . '%')
                        ->orWhere('product_key', 'like', '%' . $term . '%');
                });
            })
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('social-boost.orders', [
            'orders' => $orders,
            'filters' => $filters,
            /*
             * The canonical statuses, in lifecycle order rather than alphabetical,
             * so the filter reads the way a customer thinks about an order: sent,
             * working, done.
             */
            'allStatuses' => [
                ServiceOrder::STATUS_UNKNOWN,
                ServiceOrder::STATUS_PENDING,
                ServiceOrder::STATUS_PROCESSING,
                ServiceOrder::STATUS_SUCCESS,
                ServiceOrder::STATUS_PARTIAL,
                ServiceOrder::STATUS_RETRYABLE,
                ServiceOrder::STATUS_FAILED,
                ServiceOrder::STATUS_CANCELLED,
                ServiceOrder::STATUS_REFUNDED,
            ],
            'services' => $this->orderedServices($user->getKey()),
        ]);
    }

    /* =====================================================================
     | F2. Order status poll
     | =================================================================== */

    /**
     * The live status of one order, for the detail page to refresh itself.
     *
     * ## Why this reads our own database and never the provider
     *
     * An SMM order can take hours. Polling Nitro directly from every open browser
     * tab would spend the rate limit that purchases need on customers watching a
     * progress bar, and it would make the provider's latency the page's latency.
     * The scheduled reconciliation sweep is what asks Nitro; this endpoint reports
     * what that sweep last learned, which is at most a few minutes old.
     *
     * The payload is deliberately tiny: a status label, a progress figure and the
     * two action flags. No provider identifier, no provider status string, no
     * response body.
     */
    public function orderStatus(string $reference)
    {
        $order = $this->ownedOrder($reference);

        return response()->json([
            'status' => $order->status,
            'label' => $this->statusLabels()[$order->status]['label'] ?? 'In progress',
            'blurb' => $this->statusLabels()[$order->status]['blurb'] ?? '',
            'tone' => $this->statusLabels()[$order->status]['tone'] ?? 'info',
            'settled' => in_array($order->status, [
                ServiceOrder::STATUS_SUCCESS,
                ServiceOrder::STATUS_FAILED,
                ServiceOrder::STATUS_CANCELLED,
                ServiceOrder::STATUS_REFUNDED,
            ], true),
            'progress' => $this->progress($order),
            'can_refill' => $this->orders->canRefill($order),
            'can_cancel' => $this->orders->canCancel($order),
            'completed_at' => $order->completed_at?->toIso8601String(),
        ]);
    }

    /* =====================================================================
     | G. Order details
     | =================================================================== */

    public function show(string $reference)
    {
        $order = $this->ownedOrder($reference);

        return view('social-boost.show', [
            'order' => $order,
            'label' => $this->statusLabels()[$order->status] ?? ['label' => 'In progress', 'tone' => 'info', 'blurb' => ''],
            'progress' => $this->progress($order),
            'canRefill' => $this->orders->canRefill($order),
            'canCancel' => $this->orders->canCancel($order),
            'product' => $order->serviceProduct,
        ]);
    }

    /**
     * Request a refill.
     *
     * Refill is free and offered only where the service supports it. The result is
     * messaged rather than applied to the order's status: a refill adds a new
     * delivery to an order that already succeeded, and the status stays `Completed`
     * because that is what the customer bought.
     */
    public function refill(string $reference)
    {
        $order = $this->ownedOrder($reference);

        if (! $this->orders->canRefill($order)) {
            return back()->with('error', 'A refill is not available for this order.');
        }

        $result = $this->orders->refill($order);

        if ($result->status !== ProviderStatus::SUCCESS) {
            return back()->with('error', $result->message ?? 'We could not request a refill for this order.');
        }

        return back()->with('success', 'Refill requested. Delivery usually begins within a few minutes.');
    }

    /**
     * Request cancellation.
     *
     * Deliberately does not change the order's status or refund anything. Nitro
     * answers a cancellation request, not a cancellation: the order's own status
     * is what decides whether it was granted, and the reconciliation sweep applies
     * the outcome and the customer's refund. Marking it cancelled optimistically
     * would take money from a customer whose order was in fact delivered.
     */
    public function cancel(string $reference)
    {
        $order = $this->ownedOrder($reference);

        if (! $this->orders->canCancel($order)) {
            return back()->with('error', 'This order can no longer be cancelled.');
        }

        $result = $this->orders->cancel($order);

        if ($result->status !== ProviderStatus::SUCCESS) {
            return back()->with('error', $result->message ?? 'We could not cancel this order.');
        }

        return back()->with(
            'success',
            'Cancellation requested. If the provider grants it, the amount is refunded to your wallet automatically.'
        );
    }

    /* =====================================================================
     | H. Help
     | =================================================================== */

    public function help()
    {
        return view('social-boost.help', [
            'targets' => config('smm.targets', []),
            'fallback' => config('smm.target_fallback', []),
        ]);
    }

    /* =====================================================================
     | Validation and resolution
     | =================================================================== */

    /**
     * @return array{service:string,target:string,quantity:int,pin:?string}
     */
    private function validateOrder(Request $request): array
    {
        $validated = $request->validate([
            'service' => 'required|string|max:120',
            'target' => 'required|string|max:500',
            'quantity' => 'required|integer|min:1|max:100000000',
            'pin' => 'nullable|string|size:4',
            // Accepted only so a tampered client is *seen* to send one; the value
            // is compared against the server's own quote and never charged from.
            'price' => 'nullable|numeric|min:0|max:100000000',
            'price_confirmation' => 'nullable|string|max:120',
        ]);

        $validated['quantity'] = (int) $validated['quantity'];

        /*
         * The service is resolved and the quantity checked against *that*
         * service's published bounds before anything else happens. Nitro documents
         * min and max per service and rejects out-of-range quantities, so
         * catching it here turns a provider error minutes later into an inline
         * message now.
         */
        $resolved = $this->resolve($validated['service']);

        if (! $resolved) {
            abort(redirect()->route('social-boost.catalogue')
                ->with('error', 'That service is no longer available. Please choose another.'));
        }

        [$product, $offering] = $resolved;

        $this->assertQuantity($offering, $validated['quantity']);
        $this->assertTarget($offering, $validated['target']);

        return $validated;
    }

    private function assertQuantity(ProviderProduct $offering, int $quantity): void
    {
        if (! $offering->quantityIsWithinBounds($quantity)) {
            $min = $offering->min_quantity ? number_format((int) $offering->min_quantity) : '1';
            $max = $offering->max_quantity ? number_format((int) $offering->max_quantity) : null;

            $message = $max === null
                ? "This service accepts at least {$min}."
                : "This service accepts between {$min} and {$max}.";

            throw \Illuminate\Validation\ValidationException::withMessages(['quantity' => $message]);
        }
    }

    private function assertTarget(ProviderProduct $offering, string $target): void
    {
        $verdict = $this->targets->validate($offering->platform, $target);

        if (! $verdict['ok']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['target' => $verdict['message']]);
        }
    }

    /**
     * @return array{0:ServiceProduct, 1:ProviderProduct}|null
     */
    private function resolve(string $key): ?array
    {
        $product = $this->catalogue->findByKey($key);

        if (! $product || $product->status !== ServiceProduct::STATUS_ACTIVE) {
            return null;
        }

        $offering = $this->catalogue->offeringFor($product);

        return $offering === null ? null : [$product, $offering];
    }

    /**
     * The extra Nitro parameters the form collected, filtered by service type.
     *
     * A field the service's type does not use is not sent, so a stray posted value
     * cannot reach the provider; the form only renders the fields that apply.
     *
     * @return array<string,string>
     */
    private function orderParams(Request $request): array
    {
        $params = [];

        foreach (['comments', 'usernames', 'keywords', 'country', 'device', 'keyword', 'referrer'] as $field) {
            $value = $request->input($field);

            if (is_string($value) && trim($value) !== '') {
                $params[$field] = \Illuminate\Support\Str::limit(trim($value), 4000, '');
            }
        }

        return $params;
    }

    /* =====================================================================
     | Presentation helpers
     | =================================================================== */

    /**
     * Customer-facing labels for the canonical statuses.
     *
     * Never the provider's vocabulary and never a raw enum. `tone` maps onto the
     * application's existing badge classes, so a status renders the same way here
     * as in every other history table.
     *
     * @return array<string, array{label:string,tone:string,blurb:string}>
     */
    public function statusLabels(): array
    {
        return [
            ServiceOrder::STATUS_PENDING => [
                'label' => 'Pending',
                'tone' => 'warning',
                'blurb' => 'We have sent your order and are waiting for the provider to start it.',
            ],
            ServiceOrder::STATUS_PROCESSING => [
                'label' => 'In progress',
                'tone' => 'info',
                'blurb' => 'Your order is being delivered now. This can take a little while to finish.',
            ],
            ServiceOrder::STATUS_SUCCESS => [
                'label' => 'Completed',
                'tone' => 'success',
                'blurb' => 'Your order was delivered in full.',
            ],
            ServiceOrder::STATUS_PARTIAL => [
                'label' => 'Partly delivered',
                'tone' => 'warning',
                'blurb' => 'Part of your order was delivered. Our team is reviewing the remainder.',
            ],
            ServiceOrder::STATUS_FAILED => [
                'label' => 'Failed',
                'tone' => 'destructive',
                'blurb' => 'The order could not be delivered and your wallet has been refunded.',
            ],
            ServiceOrder::STATUS_CANCELLED => [
                'label' => 'Cancelled',
                'tone' => 'neutral',
                'blurb' => 'This order was cancelled and your wallet has been refunded.',
            ],
            ServiceOrder::STATUS_REFUNDED => [
                'label' => 'Refunded',
                'tone' => 'neutral',
                'blurb' => 'This order was refunded to your wallet in full.',
            ],
            ServiceOrder::STATUS_RETRYABLE => [
                'label' => 'Not delivered',
                'tone' => 'destructive',
                'blurb' => 'The provider could not deliver this order. It is being resolved.',
            ],
            ServiceOrder::STATUS_UNKNOWN => [
                'label' => 'Being confirmed',
                'tone' => 'info',
                'blurb' => 'We are confirming this order with the provider. Please do not submit it again.',
            ],
        ];
    }

    /**
     * Delivery progress, where the provider gives us a real number.
     *
     * Nitro reports `start_count` and `remains` — the count the target had before
     * the order and the quantity still outstanding. A percentage is derived from
     * those two and **only** when both are known and the ordered quantity is
     * positive; otherwise this returns null and the view shows the raw progress
     * text instead. Inventing a bar from no data is how a page implies delivery
     * that has not happened.
     *
     * @return array{percent:int,delivered:int,ordered:int,starts_at:?int,remains:?int}|null
     */
    private function progress(ServiceOrder $order): ?array
    {
        $payload = (array) ($order->providerTransactions->last()?->response_payload ?? []);

        $ordered = (int) $order->quantity;
        $remains = is_numeric($payload['remains'] ?? null) ? (int) $payload['remains'] : null;
        $startsAt = is_numeric($payload['start_count'] ?? null) ? (int) $payload['start_count'] : null;

        if ($ordered < 1 || $remains === null) {
            return null;
        }

        $delivered = max(0, min($ordered, $ordered - $remains));

        return [
            'percent' => (int) floor($delivered / $ordered * 100),
            'delivered' => $delivered,
            'ordered' => $ordered,
            'starts_at' => $startsAt,
            'remains' => $remains,
        ];
    }

    /**
     * A quote descriptor for the confirmation token.
     *
     * The identifier is the *product key* plus the quantity: two orders of the
     * same service at different quantities are different purchases at different
     * prices, and a confirmation for one must not authorise the other.
     *
     * @return array<string,mixed>
     */
    private function descriptor(ServiceProduct $product, ProviderProduct $offering, int $quantity): array
    {
        return [
            'identifier' => $product->product_key . ':' . $quantity,
            'label' => $product->name,
            'cost_minor' => null,
            'meta' => [
                'provider_product_id' => $offering->getKey(),
                'quantity' => $quantity,
            ],
        ];
    }

    /** @return array<string,int> */
    private function customerStats($user): array
    {
        $base = ServiceOrder::forUser($user->getKey());

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->unresolved()->count(),
            'completed' => (clone $base)->where('status', ServiceOrder::STATUS_SUCCESS)->count(),
        ];
    }

    /** @return array<int,string> */
    private function orderedServices(int $userId): array
    {
        return ServiceOrder::forUser($userId)
            ->select('product_key')
            ->distinct()
            ->orderBy('product_key')
            ->pluck('product_key')
            ->all();
    }

    private function ownedOrder(string $reference): ServiceOrder
    {
        return ServiceOrder::with(['serviceProduct', 'pricingSnapshot', 'providerTransactions', 'providerProduct.provider'])
            ->where('user_id', Auth::id())
            ->where(fn ($q) => $q->where('uuid', $reference)->orWhere('id', $reference))
            ->firstOrFail();
    }

    private function presentedMinor($value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return \App\Support\Money::fromNaira($value)->minor();
    }

    private function priceMoved(?int $presented, ?int $current): bool
    {
        if ($presented === null || $current === null) {
            return false;
        }

        // One kobo: the smallest representable difference, so this cannot fire on
        // representation noise but catches any real re-price.
        return abs($presented - $current) > 1;
    }
}
