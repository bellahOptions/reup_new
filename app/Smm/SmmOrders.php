<?php

namespace App\Smm;

use App\Models\ProviderProduct;
use App\Models\ServiceOrder;
use App\Models\ServiceProduct;
use App\Models\User;
use App\Orders\ServiceOrderService;
use App\Providers\Adapters\NitroSmmProvider;
use App\Providers\ProviderRegistry;
use App\Providers\Support\ProviderResult;
use Illuminate\Support\Facades\Log;

/**
 * Social Boost orders: everything that is specific to Nitro on top of the shared
 * order pipeline.
 *
 * ## What this does *not* do
 *
 * It does not debit a wallet, write a ledger entry, record profit, reconcile a
 * status, or refund. `ServiceOrderService` already does all of that — with the
 * row lock, the idempotency reservation, the pricing snapshot and the ledger —
 * and it is used unchanged. This class supplies the three things that are
 * genuinely Nitro-specific:
 *
 *   1. **which offering** the order is bought from, decided once and recorded;
 *   2. **the dispatch closure**, which calls the adapter;
 *   3. **reconciliation and the two per-service actions** — refill and cancel —
 *      which Nitro supports only where the service's own flags say so.
 *
 * ## Why the offering is resolved here and not passed in
 *
 * The price the customer agreed to and the service the order is sent against must
 * be the same row. Resolving the offering once, inside the same call that prices
 * and places, means a concurrent sync or an operator pausing a service cannot
 * change which row was priced between the quote and the charge.
 *
 * ## The idempotency key
 *
 * `idempotencyKey()` is **deterministic** — a hash of the user, the service, the
 * target and the quantity. Two clicks on the same form produce the same key, so
 * the database's unique index turns the second into a replay of the first rather
 * than a second order. A random key would make every double submit a new order,
 * which is the failure the brief is explicit about.
 */
class SmmOrders
{
    /** The `transactions.service_type` and permission vocabulary for this module. */
    public const SERVICE_TYPE = 'smm';

    public function __construct(
        private readonly ServiceOrderService $orders,
        private readonly SmmCatalogue $catalogue,
        private readonly ProviderRegistry $registry,
    ) {
    }

    /**
     * The deterministic request key for an order.
     *
     * Deterministic rather than random, and derived only from things the customer
     * chose — so a double-click, a slow-network retry, or a browser resubmit all
     * present the *same* key and the second one is answered from the first.
     *
     * The `quantity` is part of it: ordering 1,000 followers and then 2,000 is two
     * legitimate purchases and must not be collapsed into one.
     */
    public function idempotencyKey(User $user, ServiceProduct $product, string $target, int $quantity): string
    {
        return 'smm-' . substr(hash('sha256', implode('|', [
            (int) $user->getKey(),
            $product->product_key,
            mb_strtolower(trim($target)),
            $quantity,
        ])), 0, 40);
    }

    /**
     * Place a social-boost order.
     *
     * @return array{ok:bool,order:?ServiceOrder,status:string,message:string,quote:?\App\Pricing\PriceQuote}
     */
    public function place(
        User $user,
        ServiceProduct $product,
        ProviderProduct $offering,
        string $target,
        int $quantity,
        array $orderParams = [],
        ?string $pin = null,
        ?string $idempotencyKey = null
    ): array {
        $idempotencyKey = $idempotencyKey ?: $this->idempotencyKey($user, $product, $target, $quantity);

        /*
         * The cost the pricing engine is given is the **whole order's** cost,
         * computed from Nitro's published rate for this quantity — not the
         * per-unit figure on the offering. A rate is a price per 1,000 units, so
         * passing it through as if it were a unit cost would price a 10,000-unit
         * order at a thousandth of what it costs.
         */
        $priced = app(SmmPricing::class)->quote($offering, $quantity, $this->catalogue->pricingContext($offering));

        if (! $priced['ok']) {
            return [
                'ok' => false,
                'order' => null,
                'status' => \App\Providers\Support\ProviderStatus::FAILED,
                'message' => $priced['message'] ?? 'This service is temporarily unavailable.',
                'quote' => $priced['quote'],
            ];
        }

        $quote = $priced['quote'];

        $result = $this->orders->place(
            user: $user,
            product: $product,
            capability: SmmCatalogue::CAPABILITY,
            /*
             * The base cost of the whole order. `PriceQuote::markupAmountMinor` is
             * computed from this, so the markup the customer is charged is the
             * configured percentage of what the order actually costs us.
             */
            providerCostMinor: $quote->baseCostMinor,
            quantity: $quantity,
            recipient: $target,
            description: $this->description($product, $quantity),
            /*
             * ## Why the offering is captured here and not read off the order
             *
             * `ServiceOrderService::place()` creates the order row and *then*
             * dispatches, and it deliberately knows nothing about provider
             * offerings — it is capability-agnostic. So `$order->providerProduct` is
             * null at the moment the dispatch closure runs, and a closure that read
             * it would find nothing and send the provider no service id at all.
             *
             * That is not a theoretical failure: it is what happened on the first
             * run of this module's tests, and it produced an UNKNOWN outcome —
             * meaning the order was held for reconciliation against a request that
             * was never sent, and the customer's money sat against nothing. Passing
             * the offering in from here, where it was resolved once for pricing, is
             * what makes the priced service and the ordered service the same row.
             */
            dispatch: fn ($adapter, ServiceOrder $order) => $this->dispatch($adapter, $order, $offering),
            meta: $this->orderMeta($product, $offering, $target, $orderParams, $priced),
            pin: $pin,
            idempotencyKey: $idempotencyKey,
            serviceType: self::SERVICE_TYPE,
        );

        return $result;
    }

    /**
     * Send the order to Nitro.
     *
     * The adapter is reached through the registry rather than constructed, so the
     * configured provider row — its credentials, its base URL, its rate divisor —
     * is the one that was health-checked and is the one that will be billed.
     *
     * ## The offering is passed in, and written to the order here
     *
     * `ServiceOrderService::place()` creates the order row and *then* dispatches,
     * and it deliberately knows nothing about provider offerings — it is
     * capability-agnostic. So `$order->providerProduct` is null at the moment this
     * runs, and a version of this method that read it found nothing and sent the
     * provider no service id.
     *
     * That was not a theoretical failure. It is what the first run of this module's
     * tests produced, and its symptom is the worst kind: an **UNKNOWN** outcome
     * against a request that was never sent, so the order was held for
     * reconciliation, the customer's money stayed debited, and the reconciliation
     * sweep would have polled a provider reference that did not exist.
     *
     * So the offering is written onto the order here — before the request goes out
     * — which is also what a reconciliation running while the request is in flight
     * needs, since the order row is the only thing a later process has to go on.
     */
    private function dispatch($adapter, ServiceOrder $order, ProviderProduct $offering): ProviderResult
    {
        $order->forceFill(['provider_product_id' => $offering->getKey()])->save();

        if (! $adapter instanceof NitroSmmProvider) {
            /*
             * A different adapter claiming `smm` would be a routing
             * misconfiguration, and sending it an SMM order would be sending the
             * wrong request to the wrong vendor. UNKNOWN rather than FAILED: the
             * order has already been charged, and "we did not send it" is a claim
             * this code cannot make on the provider's behalf.
             */
            Log::error('An order for the smm capability reached a non-Nitro adapter', [
                'order_id' => $order->getKey(),
                'adapter' => is_object($adapter) ? get_class($adapter) : gettype($adapter),
            ]);

            return ProviderResult::fromUnrecognised('wrong_adapter');
        }

        $serviceId = $this->providerServiceId($offering);

        if ($serviceId === null) {
            Log::error('A Social Boost order has no provider service id to send', [
                'order_id' => $order->getKey(),
                'provider_product_id' => $offering->getKey(),
                'provider_product_ref' => $offering->provider_product_id,
            ]);

            return ProviderResult::fromUnrecognised('missing_provider_service_id');
        }

        return $adapter->addOrder(
            $serviceId,
            (string) $order->recipient,
            (int) $order->quantity,
            $this->providerParams($order),
        );
    }

    /* =====================================================================
     | Reconciliation
     |=================================================================== */

    /**
     * Ask Nitro what happened to an order, and apply the answer.
     *
     * ## One order per request, deliberately
     *
     * Nitro documents a bulk `status` call taking up to 100 comma-separated ids.
     * It returns a bare array, and the documentation does not say that the array
     * is index-aligned with the ids requested — it shows a single-order example
     * and a shape that could equally be keyed by the provider's own order id.
     * Mapping the wrong entry onto the wrong order would mark one customer's
     * order with another customer's outcome, so bulk polling is not used until
     * Nitro documents the ordering. The rate limit is 300 requests/minute and the
     * batch size is configured, so this is a throughput trade made in favour of
     * correctness — and the honest one, because the alternative is guessing at an
     * undocumented behaviour with somebody's money.
     *
     * @return ProviderResult
     */
    public function reconcile(ServiceOrder $order): ProviderResult
    {
        $adapter = $this->adapterFor($order);

        if (! $adapter) {
            return ProviderResult::fromTransportFailure('The service provider is not available.');
        }

        $reference = $order->provider_reference;

        if ($reference === null || $reference === '') {
            /*
             * No provider order id. Either the request never left us or the
             * response did not carry one, and in both cases there is nothing to
             * ask about — which is NOT the same as "it failed". UNKNOWN keeps the
             * money held and the order on the reconciliation list.
             */
            return ProviderResult::fromUnrecognised('no_provider_reference');
        }

        return $adapter->orderStatus([$reference]);
    }

    /**
     * Reconcile an order and, where the provider settled it against the customer,
     * make the customer whole.
     *
     * ## The case this exists for
     *
     * Nitro refunds a cancelled or undeliverable order **to ReUp's wallet** and
     * reports the order as `Refunded`. That is the provider settling with us. It
     * is not the customer being credited, and until the second movement happens
     * the customer's debit stands against an order that was never delivered.
     *
     * The refund itself goes through `ServiceOrderService::refundUnfulfilled()`,
     * which delegates to the one refund implementation in the application, and
     * which is idempotent by the transaction's own `payment_status` — so running
     * this on every reconciliation pass credits once.
     *
     * @param  callable(ServiceOrder): ProviderResult|null  $query  an alternative
     *         status source, for tests and for a manual admin reconcile
     */
    public function reconcileAndSettle(ServiceOrder $order, ?callable $query = null): ProviderResult
    {
        $result = $this->orders->reconcile(
            $order,
            $query ?? fn (ServiceOrder $o) => $this->reconcile($o),
        );

        $order->refresh();

        if (in_array($order->status, [ServiceOrder::STATUS_REFUNDED, ServiceOrder::STATUS_FAILED, ServiceOrder::STATUS_CANCELLED], true)) {
            $this->orders->refundUnfulfilled(
                $order,
                $this->refundReason($order, $result),
            );
        }

        return $result;
    }

    /* =====================================================================
     | Per-service actions
     |=================================================================== */

    /**
     * Whether a refill may be requested for an order.
     *
     * Nitro publishes `refill` **per service**, and the documentation is explicit
     * that it applies only where the flag is set. A refill is therefore refused
     * unless the offering that served this order says it supports one — checked
     * against the order's own offering rather than the product's current one, so
     * a catalogue change after the purchase cannot grant a capability the order
     * was never bought with.
     */
    public function canRefill(ServiceOrder $order): bool
    {
        if ($order->status !== ServiceOrder::STATUS_SUCCESS) {
            return false;
        }

        $offering = $order->providerProduct;

        return (bool) $offering?->supports_refill && $order->provider_reference !== null;
    }

    /**
     * Whether a cancellation may be requested for an order.
     *
     * Nitro cancels only where the service's `cancel` flag is set *and* the order
     * has not completed — the documented refusal is `Order already completed`. An
     * order in a final delivered state is refused here rather than sent and
     * refused by the provider, so a customer is told immediately.
     */
    public function canCancel(ServiceOrder $order): bool
    {
        if ($order->isFinal() || $order->status === ServiceOrder::STATUS_PARTIAL) {
            return false;
        }

        if (in_array($order->status, [ServiceOrder::STATUS_SUCCESS, ServiceOrder::STATUS_REFUNDED], true)) {
            return false;
        }

        $offering = $order->providerProduct;

        return (bool) $offering?->supports_cancel && $order->provider_reference !== null;
    }

    /**
     * Request a refill.
     *
     * A refill is free and does not move money, so the only thing at stake is
     * whether the provider accepts it. The result is returned rather than applied
     * to the order's status: a refill creates a *new* delivery on an order that
     * already succeeded, and overwriting the order's status with the refill's
     * acknowledgement would lose the record of the original delivery.
     */
    public function refill(ServiceOrder $order): ProviderResult
    {
        if (! $this->canRefill($order)) {
            return ProviderResult::fromUnrecognised('refill_not_supported');
        }

        $adapter = $this->adapterFor($order);

        if (! $adapter) {
            return ProviderResult::fromTransportFailure('The service provider is not available.');
        }

        return $adapter->refill((int) $order->provider_reference);
    }

    /**
     * Request cancellation.
     *
     * Nitro answers per order: `{"order": 1, "cancel": 1}` or
     * `{"order": 2, "cancel": {"error": "..."}}`, so a batch result is normal.
     * The order's own status is deliberately **not** changed here — cancellation
     * is a request, and Nitro's `status` is the authority on whether it was
     * granted. The reconciliation sweep is what applies the outcome, which is
     * also what triggers the customer's refund.
     */
    public function cancel(ServiceOrder $order): ProviderResult
    {
        if (! $this->canCancel($order)) {
            return ProviderResult::fromUnrecognised('cancel_not_supported');
        }

        $adapter = $this->adapterFor($order);

        if (! $adapter) {
            return ProviderResult::fromTransportFailure('The service provider is not available.');
        }

        return $adapter->cancel([(int) $order->provider_reference]);
    }

    /* =====================================================================
     | Internals
     |=================================================================== */

    private function adapterFor(ServiceOrder $order): ?NitroSmmProvider
    {
        $offering = $order->providerProduct;

        if (! $offering || ! $offering->provider) {
            return null;
        }

        $adapter = $this->registry->adapterFor($offering->provider);

        return $adapter instanceof NitroSmmProvider ? $adapter : null;
    }

    /**
     * Nitro's service id for an order.
     *
     * Read from the offering the order was placed against — never from the
     * request, and never from the current catalogue. A sync that removes and
     * re-adds a service under a new id must not silently redirect an in-flight
     * order, and a client that could name a service id could buy a different
     * service at this order's price.
     */
    private function providerServiceId(ProviderProduct $offering): ?int
    {
        $raw = $offering->provider_product_id;

        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * The extra order fields Nitro asks for, filtered to the service's own type.
     *
     * `comments`, `usernames`, `keywords`, `country`, `device`, `keyword` and
     * `referrer` are only meaningful for particular service types, and sending a
     * field a service does not use is at best ignored and at worst rejected. The
     * form collects them; this decides which reach the provider.
     *
     * @return array<string,mixed>
     */
    private function providerParams(ServiceOrder $order): array
    {
        $payload = (array) ($order->order_payload ?? []);
        $params = (array) ($payload['order_params'] ?? []);

        $allowed = ['comments', 'usernames', 'keywords', 'country', 'device', 'keyword', 'referrer'];

        return array_intersect_key($params, array_flip($allowed));
    }

    /**
     * The immutable record of what was ordered.
     *
     * Carries the provider's service id and rate, the quantity and the pricing
     * verdict, so an order can be explained years later without reading today's
     * catalogue. The provider's *rate* is stored rather than the cost, because the
     * rate is what the provider published and the cost is a function of it.
     *
     * @return array<string,mixed>
     */
    private function orderMeta(
        ServiceProduct $product,
        ProviderProduct $offering,
        string $target,
        array $orderParams,
        array $priced
    ): array {
        return [
            'product' => $product->slug,
            'product_name' => $product->name,
            'capability' => SmmCatalogue::CAPABILITY,
            'platform' => $offering->platform,
            'service_name' => $offering->provider_name,
            /*
             * Kept on the order, not shown to the customer. The history and detail
             * screens read the product and the platform; the provider's identifier
             * is here so support and reconciliation can address the right service.
             */
            'provider_service_id' => $offering->provider_product_id,
            'provider_rate_minor' => $offering->provider_rate_minor,
            'rate_divisor' => $offering->rate_divisor,
            'minimum_markup_bps' => $priced['minimum_markup_bps'] ?? null,
            'effective_markup_bps' => $priced['effective_markup_bps'] ?? null,
            'order_params' => $orderParams,
            'target' => $target,
            'request_ip' => request()?->ip(),
            'request_user_agent' => \Illuminate\Support\Str::limit((string) request()?->userAgent(), 255, ''),
        ];
    }

    private function description(ServiceProduct $product, int $quantity): string
    {
        return $product->name . ' × ' . number_format($quantity);
    }

    private function refundReason(ServiceOrder $order, ProviderResult $result): string
    {
        return $order->status === ServiceOrder::STATUS_REFUNDED
            ? 'Refunded by the service provider.'
            : ($order->failure_reason ?? $result->message ?? 'The order could not be completed.');
    }
}
