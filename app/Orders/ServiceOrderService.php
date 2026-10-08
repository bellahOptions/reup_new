<?php

namespace App\Orders;

use App\Models\ProfitRecord;
use App\Models\ProviderTransaction;
use App\Models\ServiceOrder;
use App\Models\ServiceProduct;
use App\Models\User;
use App\Providers\ProviderRegistry;
use App\Providers\Support\DeliveryTokenStore;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The Wave 1 purchase pipeline.
 *
 * ## Ordering, and why it is this order
 *
 *   1. **Price** — resolved server-side from the provider cost and the active
 *      pricing rule. The frontend never supplies a price, so a tampered form
 *      cannot buy a gift card for ₦1.
 *   2. **Profitability gate** — a quote the pricing engine refused stops here,
 *      before any money moves. Selling below the configured floor is the failure
 *      the pricing subsystem exists to prevent.
 *   3. **Idempotency reservation** — the customer's request key is reserved in
 *      the database. A duplicate submit loses at the unique index.
 *   4. **Wallet debit + order + pricing snapshot, in ONE transaction** — so a
 *      debit can never exist without its snapshot, and a snapshot can never
 *      describe a debit that was rolled back.
 *   5. **Provider call, outside the transaction** — a network call inside a
 *      database transaction holds a row lock for its duration, which under load
 *      serialises every purchase behind one slow provider.
 *   6. **Outcome handling**, in which UNKNOWN is treated as its own thing.
 *
 * ## Why the provider call is not retried on UNKNOWN
 *
 * The `service_orders.provider_idempotency_key` is generated once and reused for
 * the same operation. If the outcome is unknown, the *same* key must be used to
 * reconcile — never a new one, because Sogo binds a key permanently to its
 * transaction and a new key is a new charge. `retrySafely()` enforces that.
 */
class ServiceOrderService
{
    public function __construct(
        private readonly \App\Pricing\PricingEngine $pricing,
        private readonly ProviderRegistry $registry,
        private readonly WalletService $wallets,
        private readonly \App\Services\SecurityService $security,
        private readonly DeliveryTokenStore $deliveries,
        private readonly ProfitRecorder $profit,
    ) {
    }

    /**
     * Place an order.
     *
     * @param  callable(\App\Providers\AbstractProviderAdapter, ServiceOrder): ProviderResult  $dispatch
     *         Sends the charge to the chosen adapter. Called once, outside any
     *         database transaction.
     * @param  array<string, mixed>  $meta  order detail for the receipt and history
     * @return array{ok:bool,order:?ServiceOrder,status:string,message:string,quote:?\App\Pricing\PriceQuote}
     */
    public function place(
        User $user,
        ServiceProduct $product,
        string $capability,
        int $providerCostMinor,
        int $quantity,
        string $recipient,
        string $description,
        callable $dispatch,
        array $meta = [],
        ?string $pin = null,
        ?string $idempotencyKey = null,
        string $serviceType = 'other',
    ): array {
        /* ---- 1. Price, server-side ------------------------------------- */

        $context = $this->pricingContext($product, $capability);

        $quote = $this->pricing->quote($providerCostMinor, $quantity, $context);

        if (! $quote->isSellable()) {
            /*
             * A refused quote is not an error to swallow. It is the pricing
             * engine doing its job, and the customer is told plainly rather than
             * being sold at a loss.
             */
            Log::warning('Order refused by the pricing engine', [
                'user_id' => $user->getKey(),
                'product' => $product->slug,
                'provider_cost_minor' => $providerCostMinor,
                'reason' => $quote->refusalReason,
            ]);

            return [
                'ok' => false,
                'order' => null,
                'status' => ProviderStatus::FAILED,
                'message' => $quote->refusalReason ?? 'This service is temporarily unavailable.',
                'quote' => $quote,
            ];
        }

        $price = $quote->price();

        /* ---- 2. Security gates ----------------------------------------- */

        $this->security->verifyPin($user, $pin);
        $this->security->assertNotThrottled($user);
        $this->security->assertWithinLimits($user, $price, $serviceType);
        $this->security->assertNotDuplicate($user, $serviceType, $price, $recipient);

        /* ---- 3. Balance, before anything is created -------------------- */

        $balance = $this->wallets->balanceFor($user);

        if ($balance->lessThan($price)) {
            throw new RuntimeException(
                'Insufficient wallet balance. You need ' . $price->format()
                . ' but your balance is ' . $balance->format() . '.'
            );
        }

        /* ---- 4. Idempotency reservation -------------------------------- */

        $reservation = null;

        if ($idempotencyKey) {
            $reservation = $this->security->reserve(
                $idempotencyKey,
                'service_order',
                $user,
                $this->security->requestHash($user, [
                    'product' => $product->slug,
                    'quantity' => $quantity,
                    'recipient' => $recipient,
                    'price' => $price->toDecimalString(),
                ]),
            );

            if ($reservation['replay']) {
                return $this->replay($reservation);
            }
        }

        /* ---- 5. Debit, order and snapshot atomically ------------------- */

        try {
            $order = DB::transaction(function () use (
                $user, $product, $capability, $quote, $price, $quantity, $recipient,
                $description, $meta, $serviceType, $idempotencyKey
            ) {
                $transaction = \App\Models\Transactions::create([
                    'user_id' => $user->getKey(),
                    'reference' => $this->wallets->generateReference($this->referencePrefix($serviceType)),
                    'type' => 'debit',
                    'service_type' => $serviceType,
                    'description' => $description,
                    'amount' => $price->toDecimalString(),
                    'service_fee' => Money::fromMinor($quote->customerFeeMinor)->toDecimalString(),
                    'total_amount' => $price->toDecimalString(),
                    'recipient' => $recipient,
                    'provider' => $product->providerProducts()->first()?->provider_name,
                    'payment_method' => 'wallet',
                    'payment_status' => 'success',
                    'status' => 'processing',
                    'idempotency_key' => $idempotencyKey,
                    'paid_at' => now(),
                    'meta' => $meta + ['product' => $product->slug, 'capability' => $capability],
                ]);

                /*
                 * The debit is the only thing that moves money, and it goes
                 * through WalletService — the single approved mutation path,
                 * with the row lock, the balance check and the ledger entry.
                 */
                $movement = $this->wallets->debit(
                    user: $user,
                    amount: $price,
                    description: $description,
                    transaction: $transaction,
                    metadata: ['product' => $product->slug, 'recipient' => $recipient],
                );

                $transaction->forceFill([
                    'balance_before' => $movement['balance_before'],
                    'balance_after' => $movement['balance_after'],
                ])->save();

                $order = ServiceOrder::create([
                    'user_id' => $user->getKey(),
                    'transaction_id' => $transaction->getKey(),
                    'provider_id' => null,
                    'service_product_id' => $product->getKey(),
                    'provider_product_id' => null,
                    'product_key' => $product->product_key,
                    'recipient' => $recipient,
                    'quantity' => $quantity,
                    'order_payload' => $meta,
                    'status' => ServiceOrder::STATUS_PENDING,
                    'submitted_at' => now(),
                    'next_reconcile_at' => now()->addMinute(),
                ]);

                /*
                 * The snapshot is written in the same transaction as the debit.
                 * An immutable price with no corresponding charge, or a charge
                 * with no recorded price, would both be worse than either alone.
                 */
                $order->pricingSnapshot()->create(
                    $quote->toSnapshotAttributes() + [
                        'pricing_rule_snapshot' => $this->ruleSnapshot($quote),
                        'pricing_rule_version' => $quote->pricingRuleVersion,
                        'inputs' => [
                            'capability' => $capability,
                            'quantity' => $quantity,
                            'provider_cost_minor' => $quote->providerCostMinor,
                        ],
                    ]
                );

                return $order;
            });
        } catch (Throwable $e) {
            // Nothing was charged, so the key must not stay reserved.
            if ($reservation) {
                $this->security->release($reservation['record'], $e->getMessage());
            }

            throw $e;
        }

        /* ---- 6. Provider call, outside the transaction ----------------- */

        $attempt = $this->dispatchToProvider($order, $capability, $dispatch);

        if ($reservation) {
            $this->security->complete($reservation['record'], $order->transaction, [
                'outcome' => $attempt['status'],
            ]);
        }

        return [
            'ok' => $attempt['status'] === ProviderStatus::SUCCESS,
            'order' => $order->fresh(),
            'status' => $attempt['status'],
            'message' => $attempt['message'],
            'quote' => $quote,
        ];
    }

    /**
     * Send an order to a provider and record what came back.
     *
     * Never fails over on its own. Failover is the caller's decision, and it is
     * only ever permitted when the result says `RETRYABLE` — a state that means
     * the provider explicitly did not fulfil the request. `ProviderStatus::
     * permitsFailover()` is the single place that rule lives.
     *
     * @param  callable(\App\Providers\AbstractProviderAdapter, ServiceOrder): ProviderResult  $dispatch
     * @return array{status:string,message:string}
     */
    private function dispatchToProvider(ServiceOrder $order, string $capability, callable $dispatch): array
    {
        $adapters = $this->registry->candidatesFor($capability);

        if ($adapters === []) {
            $order->markUnknown('No provider is currently available for this service.');

            Log::critical('No provider available for an already-charged order', [
                'order_id' => $order->getKey(),
                'reference' => $order->uuid,
                'user_id' => $order->user_id,
                'capability' => $capability,
            ]);

            return [
                'status' => ProviderStatus::UNKNOWN,
                'message' => 'We could not reach the service provider. Your money is held, not lost — we are checking.',
            ];
        }

        $lastStatus = ProviderStatus::UNKNOWN;
        $lastMessage = 'The service provider could not be reached.';

        foreach ($adapters as $index => $adapter) {
            $order->forceFill([
                'provider_id' => $adapter->provider->getKey(),
                'provider_name' => $adapter->provider->slug,
            ])->save();

            try {
                $result = $dispatch($adapter, $order);
            } catch (Throwable $e) {
                /*
                 * An exception from an adapter is UNKNOWN, not FAILED. The
                 * request may have been sent; `AbstractProviderAdapter` already
                 * converts transport failures into UNKNOWN results, so reaching
                 * this handler means something genuinely unexpected happened —
                 * which is even less reason to assume nothing was sent.
                 */
                Log::error('Provider dispatch threw; outcome unknown', [
                    'order_id' => $order->getKey(),
                    'provider' => $adapter->slug(),
                    'error' => $e->getMessage(),
                ]);

                $result = ProviderResult::fromTransportFailure(
                    'The service provider could not be reached.',
                    'exception',
                );
            }

            $this->recordProviderTransaction($order, $adapter->provider->getKey(), $result);

            $this->applyOutcome($order, $result);

            if ($result->status === ProviderStatus::SUCCESS) {
                return ['status' => ProviderStatus::SUCCESS, 'message' => 'Order completed.'];
            }

            /*
             * Only an explicit RETRYABLE permits the next provider. UNKNOWN,
             * PENDING, PROCESSING and FAILED all stop here: in every one of those
             * cases the order may have been fulfilled, and asking a second
             * provider would be a second charge.
             */
            if (! ProviderStatus::permitsFailover($result->status)) {
                return [
                    'status' => $result->status,
                    'message' => $this->customerMessage($result),
                ];
            }

            $lastStatus = $result->status;
            $lastMessage = $result->message ?? $lastMessage;

            $isLast = $index === count($adapters) - 1;

            if (! $isLast) {
                Log::warning('Provider could not serve the order; failing over', [
                    'order_id' => $order->getKey(),
                    'provider' => $adapter->slug(),
                    'reason' => $lastMessage,
                ]);
            }
        }

        return ['status' => $lastStatus, 'message' => $lastMessage];
    }

    /**
     * Apply a provider outcome to the order.
     *
     * The UNKNOWN branch is the one that matters: it records the uncertainty and
     * schedules reconciliation, and does **not** refund, retry or mark failed.
     */
    private function applyOutcome(ServiceOrder $order, ProviderResult $result): void
    {
        switch ($result->status) {
            case ProviderStatus::SUCCESS:
                $order->markSuccess($result->providerStatus, $result->providerReference);

                if ($result->delivery && ! $result->delivery->isEmpty()) {
                    $this->deliveries->store($order, $result->delivery);
                }

                $this->profit->record($order->fresh(), $result);
                $this->recordCostDiscrepancy($order, $result);

                break;

            case ProviderStatus::PENDING:
            case ProviderStatus::PROCESSING:
                $order->markProcessing($result->providerStatus);
                break;

            case ProviderStatus::FAILED:
            case ProviderStatus::CANCELLED:
                $order->markFailed($result->message ?? 'The provider rejected the order.', $result->providerStatus);
                break;

            case ProviderStatus::REFUNDED:
                $order->forceFill([
                    'status' => ServiceOrder::STATUS_REFUNDED,
                    'provider_status' => $result->providerStatus,
                    'refunded_at' => now(),
                    'next_reconcile_at' => null,
                ])->save();
                break;

            case ProviderStatus::PARTIAL:
                $order->forceFill([
                    'status' => ServiceOrder::STATUS_PARTIAL,
                    'provider_status' => $result->providerStatus,
                    'status_message' => 'Partially delivered; awaiting review.',
                ])->save();
                break;

            case ProviderStatus::RETRYABLE:
                $order->markRetryable($result->message ?? 'The provider could not serve the order.', $result->providerStatus);
                break;

            case ProviderStatus::UNKNOWN:
            default:
                $order->markUnknown($result->message ?? 'Outcome unknown.', $result->providerStatus);

                Log::critical('Order outcome unknown — held for reconciliation', [
                    'event' => 'service_order_unknown',
                    'order_id' => $order->getKey(),
                    'reference' => $order->uuid,
                    'user_id' => $order->user_id,
                    'amount_minor' => $order->pricingSnapshot?->customer_price_minor,
                    'provider' => $order->provider_name,
                    'provider_status' => $result->providerStatus,
                ]);

                break;
        }
    }

    /**
     * Persist the provider interaction, with credentials and delivery tokens
     * already absent from the payload.
     */
    private function recordProviderTransaction(ServiceOrder $order, ?int $providerId, ProviderResult $result): void
    {
        if ($providerId === null) {
            return;
        }

        ProviderTransaction::create([
            'service_order_id' => $order->getKey(),
            'provider_id' => $providerId,
            'operation' => $order->status === ServiceOrder::STATUS_PENDING ? ProviderTransaction::OP_PURCHASE : ProviderTransaction::OP_STATUS,
            'idempotency_key' => $order->provider_idempotency_key,
            'provider_reference' => $result->providerReference,
            'status' => $result->status,
            'provider_status' => $result->providerStatus,
            'response_payload' => $result->payload,
            'http_status' => $result->httpStatus,
            'rate_limited' => $result->rateLimited,
            'error_message' => $result->message,
        ]);
    }

    /**
     * Compare the provider's reported charge against the cost we priced on.
     *
     * A mismatch means the rate moved between pricing and the call, or the
     * divisor is wrong — and in either case the margin recorded on the snapshot
     * is not the margin earned. Logged loudly rather than silently accepted,
     * because a systematic error here quietly erodes every SMM margin.
     */
    private function recordCostDiscrepancy(ServiceOrder $order, ProviderResult $result): void
    {
        $snapshot = $order->pricingSnapshot;

        if (! $snapshot) {
            return;
        }

        $matches = $result->costMatches((int) $snapshot->provider_cost_minor);

        if ($matches === false) {
            Log::critical('Provider charged a different amount than the pricing engine assumed', [
                'event' => 'provider_cost_mismatch',
                'order_id' => $order->getKey(),
                'assumed_cost_minor' => $snapshot->provider_cost_minor,
                'provider_cost_minor' => $result->providerCostMinor,
                'provider' => $order->provider_name,
            ]);
        }
    }

    /* =====================================================================
     | Reconciliation and safe retry
     | =================================================================== */

    /**
     * Ask the provider what happened, and apply the answer.
     *
     * This is the only permitted path out of UNKNOWN/PENDING/PROCESSING. It asks;
     * it does not guess, and it does not create a second provider operation.
     *
     * @param  callable(ServiceOrder): ProviderResult  $query
     */
    public function reconcile(ServiceOrder $order, callable $query): ProviderResult
    {
        if ($order->isFinal()) {
            return new ProviderResult(status: $order->status, message: 'The order is already resolved.');
        }

        $result = $query($order);

        $this->recordProviderTransaction($order, $order->provider_id, $result);

        $this->applyOutcome($order, $result);

        $order->scheduleReconciliation();

        return $result;
    }

    /**
     * Retry an order that the provider explicitly did not fulfil.
     *
     * ## The guard that matters
     *
     * Refuses unless the order is in `RETRYABLE`. That is the *only* state in
     * which the provider has stated it did not fulfil the request. An order in
     * UNKNOWN, PENDING or PROCESSING may already have been served, and re-sending
     * it is how a customer is charged twice for one purchase; an order in FAILED
     * was accepted and then failed, which is not evidence that a second provider
     * should be asked either.
     *
     * @param  callable(\App\Providers\AbstractProviderAdapter, ServiceOrder): ProviderResult  $dispatch
     * @return array{ok:bool,status:string,message:string}
     */
    public function retrySafely(ServiceOrder $order, string $capability, callable $dispatch): array
    {
        if (! $order->isSafelyRetryable()) {
            /*
             * Reconciling first is not a suggestion. Refusing here means a caller
             * that skipped the check cannot create a duplicate provider operation.
             */
            Log::warning('Retry refused: the order outcome is not known to be a non-fulfilment', [
                'order_id' => $order->getKey(),
                'status' => $order->status,
            ]);

            return [
                'ok' => false,
                'status' => $order->status,
                'message' => 'This order must be reconciled before it can be retried.',
            ];
        }

        $attempt = $this->dispatchToProvider($order, $capability, $dispatch);

        return [
            'ok' => $attempt['status'] === ProviderStatus::SUCCESS,
            'status' => $attempt['status'],
            'message' => $attempt['message'],
        ];
    }

    /**
     * Refund an order the provider confirmed it did not fulfil.
     *
     * Delegates to `BillPaymentService::refund()` so there is exactly one refund
     * implementation in the application — the one with the row lock, the
     * `payment_status` guard and the unique `payment_reference`. A second refund
     * path is a second chance to pay a customer twice.
     *
     * Refuses anything whose outcome is unresolved, and refuses `PARTIAL`, which
     * needs a human decision about how much is owed.
     */
    public function refundUnfulfilled(ServiceOrder $order, string $reason, ?int $actorId = null): bool
    {
        if (! $order->outcomeIsKnown()) {
            Log::warning('Refund refused: the provider outcome is unknown', [
                'order_id' => $order->getKey(),
                'status' => $order->status,
            ]);

            return false;
        }

        if ($order->status === ServiceOrder::STATUS_PARTIAL) {
            Log::warning('Refund refused: the order was partially delivered and needs a decision', [
                'order_id' => $order->getKey(),
            ]);

            return false;
        }

        if (! in_array($order->status, [ServiceOrder::STATUS_FAILED, ServiceOrder::STATUS_CANCELLED, ServiceOrder::STATUS_RETRYABLE], true)) {
            return false;
        }

        $refunded = app(\App\Services\BillPaymentService::class)->refund(
            transaction: $order->transaction,
            reason: $reason,
            entryType: \App\Models\WalletLedger::ENTRY_REFUND,
            actorId: $actorId,
        );

        if ($refunded) {
            $order->forceFill([
                'status' => ServiceOrder::STATUS_REFUNDED,
                'refunded_at' => now(),
                'next_reconcile_at' => null,
            ])->save();

            $this->profit->markRefunded($order->fresh());
        }

        return $refunded;
    }

    /* =====================================================================
     | Internals
     | =================================================================== */

    /**
     * @param  array{record:\App\Models\IdempotencyKey,transaction:?\App\Models\Transactions,hash_mismatch:bool}  $reservation
     * @return array{ok:bool,order:?ServiceOrder,status:string,message:string,quote:null}
     */
    private function replay(array $reservation): array
    {
        if ($reservation['hash_mismatch']) {
            throw new RuntimeException(
                'This request signature has already been used for a different order. Please start again.'
            );
        }

        $transaction = $reservation['transaction'];

        if (! $transaction) {
            throw new RuntimeException('This order is already being processed. Please wait a moment.');
        }

        $order = ServiceOrder::where('transaction_id', $transaction->getKey())->first();

        return [
            'ok' => $order?->isSuccess() ?? false,
            'order' => $order,
            'status' => $order?->status ?? ProviderStatus::UNKNOWN,
            'message' => 'This order was already submitted.',
            'quote' => null,
        ];
    }

    /**
     * The pricing context for a product, so rule resolution can walk the
     * hierarchy from provider product up to global.
     *
     * @return array<string, mixed>
     */
    private function pricingContext(ServiceProduct $product, string $capability): array
    {
        /*
         * The cheapest available offering is used for the context's
         * `provider_product_id`, so a rule pinned to a specific provider's
         * offering applies when that offering is the one being priced. The
         * per-order provider is not yet known at quote time — which is correct,
         * because the price the customer agrees to must not depend on which
         * upstream happens to serve the request.
         */
        $cheapest = $product->activeProviderProducts()
            ->whereNotNull('provider_cost_minor')
            ->orderBy('provider_cost_minor')
            ->first();

        return [
            'category_id' => $product->category_id,
            'service_product_id' => $product->getKey(),
            'provider_id' => $cheapest?->provider_id,
            'provider_product_id' => $cheapest?->getKey(),
        ];
    }

    /**
     * A copy of the rule that produced a quote.
     *
     * Stored on the snapshot so the price remains interpretable even if the rule
     * is later edited, deleted, or its subject renamed.
     *
     * @return array<string, mixed>|null
     */
    private function ruleSnapshot(\App\Pricing\PriceQuote $quote): ?array
    {
        if ($quote->pricingRuleId === null) {
            return null;
        }

        $rule = \App\Models\PricingRule::find($quote->pricingRuleId);

        if (! $rule) {
            return [
                // The rule existed at pricing time and has since been deleted.
                'deleted' => true,
                'id' => $quote->pricingRuleId,
                'name' => $quote->pricingRuleName,
            ];
        }

        return [
            'id' => $rule->getKey(),
            'name' => $rule->name,
            'scope' => $rule->scope,
            'markup_type' => $rule->markup_type,
            'markup_percentage_bps' => (int) $rule->markup_percentage_bps,
            'markup_fixed_minor' => (int) $rule->markup_fixed_minor,
            'minimum_profit_minor' => (int) $rule->minimum_profit_minor,
            'minimum_margin_bps' => (int) $rule->minimum_margin_bps,
            'rounding_step_minor' => (int) $rule->rounding_step_minor,
            'rounding_mode' => $rule->rounding_mode,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * A customer-facing message for a non-success outcome.
     *
     * UNKNOWN is deliberately phrased as "we are confirming", never as a failure
     * — telling a customer a purchase failed when it may have succeeded is how
     * they buy it a second time.
     */
    private function customerMessage(ProviderResult $result): string
    {
        return match ($result->status) {
            ProviderStatus::UNKNOWN => 'We are confirming this order with the service provider. '
                . 'Please do not submit it again while we check.',
            ProviderStatus::PENDING, ProviderStatus::PROCESSING => 'Your order is being processed.',
            ProviderStatus::PARTIAL => 'Part of your order was delivered. Our team is reviewing it.',
            ProviderStatus::REFUNDED => 'This order was refunded by the provider.',
            ProviderStatus::FAILED, ProviderStatus::CANCELLED => $result->message
                ?? 'We could not complete this order.',
            default => 'We could not complete this order.',
        };
    }

    private function referencePrefix(string $serviceType): string
    {
        return match ($serviceType) {
            'giftcard' => 'GFT',
            'esim' => 'ESIM',
            'smm' => 'SMM',
            'international' => 'INT',
            'epin' => 'EPIN',
            'internet' => 'NET',
            'education' => 'EDU',
            default => 'ORD',
        };
    }
}
