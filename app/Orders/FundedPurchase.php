<?php

namespace App\Orders;

use App\Models\Transactions;
use App\Models\User;
use App\Models\WalletLedger;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A purchase the customer pays for with Paystack instead of their wallet.
 *
 * ## The shape of the flow, and why it is this shape
 *
 * The customer picks a product, sees the exact price, chooses "Pay with card or
 * transfer", and is sent to Paystack for *that exact amount*. When the payment is
 * confirmed the wallet is credited with it and the purchase is debited from the
 * wallet immediately, through the existing `BillPaymentService` pipeline.
 *
 * The two obvious alternatives were both worse:
 *
 *   * **Top up first, then buy.** Two steps the customer did not ask for, and the
 *     failure mode is somebody holding a wallet balance when they wanted airtime.
 *   * **A second settlement path that never touches the wallet.** Cleanest on paper
 *     and the most dangerous in practice: it means a second implementation of the
 *     debit, the refund, the ledger entry and the pricing snapshot, and a second
 *     implementation of money handling is how two records of the same sale start
 *     disagreeing.
 *
 * Crediting and then debiting costs one ledger round trip and buys something worth
 * far more: **the purchase is byte-for-byte the same code path as a wallet
 * purchase.** Same provider selection, same float check, same pricing snapshot, same
 * profit recording, same refund. There is nothing to keep in step because there is
 * nothing duplicated.
 *
 * ## Profit is not a special case here
 *
 * This is the part the brief cares about: ReUp's margin is whatever the pricing
 * engine put in the quote, and it is collected **from the customer's payment**, not
 * from the wallet. The customer pays ₦1,000 for ₦1,000 of airtime; the provider
 * charges us ₦970; the ₦30 is ours the moment Paystack settles, because the debit
 * that follows is ₦1,000 and the cost behind it is ₦970. Nothing about paying by
 * card changes the margin, and nothing needs to.
 *
 * ## The funding fee is not charged
 *
 * A wallet top-up carries a ReUp service fee, deducted from the balance after the
 * credit. This is not a top-up — the money is already spent on goods before it ever
 * rests in the wallet — and charging the fee would leave the customer unable to
 * afford the very purchase they just paid for. `creditFunding()` already skips the
 * fee for a transaction whose meta says so, so this sets that flag rather than
 * adding a second branch to the settlement path.
 */
class FundedPurchase
{
    /** Meta key: the purchase this funding row exists to pay for. */
    public const META_KEY = 'funded_purchase';

    /** The states the intent can be in. */
    public const STATE_PENDING = 'pending';
    public const STATE_VENDING = 'vending';
    public const STATE_VENDED = 'vended';
    public const STATE_FAILED = 'failed';

    /**
     * Meta key read by `PaystackService::creditFunding()` to skip the service fee.
     *
     * Named here, next to the reason it is set, and read there by the settlement
     * path that owns fee collection.
     */
    public const META_SKIP_FEE = 'skip_funding_fee';

    public function __construct(
        private readonly WalletService $wallets,
    ) {
    }

    /**
     * Describe a purchase on the funding row that will pay for it.
     *
     * Called before the customer is sent to Paystack, with the quote the pricing
     * engine produced. Everything needed to vend later is recorded here, because
     * "later" is a different request — a webhook, or a sweep five minutes from now —
     * and it has nothing else to go on.
     *
     * @param  array<string,mixed>  $intent  product, recipient, provider params, quote
     */
    public function attach(Transactions $funding, array $intent): void
    {
        $funding->forceFill([
            'meta' => array_merge($funding->meta ?? [], [
                self::META_KEY => array_merge([
                    'state' => self::STATE_PENDING,
                    'attached_at' => now()->toDateTimeString(),
                    'attempts' => 0,
                ], $intent),
                // Read by the settlement path: do not take a funding fee from money
                // that is about to be spent on goods.
                self::META_SKIP_FEE => true,
            ]),
        ])->save();
    }

    /** Does this funding row exist to pay for something? */
    public function isPurchase(Transactions $funding): bool
    {
        return $this->intent($funding) !== null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function intent(Transactions $funding): ?array
    {
        $intent = ($funding->meta ?? [])[self::META_KEY] ?? null;

        return is_array($intent) ? $intent : null;
    }

    /**
     * Vend the product a settled funding row paid for.
     *
     * ## Idempotency
     *
     * The state on the funding row is the claim, and it is moved to `vending` under
     * a **row lock** before anything is sent to a provider. A webhook arriving while
     * the callback is mid-flight therefore waits, sees `vending` or `vended`, and
     * does nothing. That is the same shape `PaystackService::settle()` uses for the
     * credit, and for the same reason: a duplicate settlement is a free product.
     *
     * ## Failure is not a rollback
     *
     * The customer has paid and the wallet has been credited. If vending fails, the
     * money is **not** lost — it stays in the wallet, where the customer can spend
     * it or have it refunded. That is the honest outcome, and it is why this returns
     * a state instead of throwing: the caller's job is to settle a payment, and a
     * provider outage must not make a payment look unsettled.
     *
     * @return array{state:string,transaction:?Transactions,message:string}
     */
    public function vend(Transactions $funding): array
    {
        $locked = Transactions::whereKey($funding->getKey())->lockForUpdate()->first();

        if (! $locked) {
            return ['state' => self::STATE_FAILED, 'transaction' => null, 'message' => 'The payment row no longer exists.'];
        }

        $intent = $this->intent($locked);

        if ($intent === null) {
            return ['state' => self::STATE_FAILED, 'transaction' => null, 'message' => 'This payment is not a product purchase.'];
        }

        /*
         * Already done, or being done. Returned rather than re-vended: a second
         * provider call would vend the product twice and there would be no way to
         * take one of them back.
         */
        if (in_array($intent['state'] ?? null, [self::STATE_VENDED, self::STATE_VENDING], true)) {
            $existing = isset($intent['product_transaction_id'])
                ? Transactions::find($intent['product_transaction_id'])
                : null;

            return [
                'state' => (string) $intent['state'],
                'transaction' => $existing,
                'message' => $intent['message'] ?? 'Already handled.',
            ];
        }

        /*
         * The funding row must actually be settled. Vendable states are the ones
         * where the money is genuinely ours: a `success` row. A row still `pending`
         * is a customer who has not paid, and vending on that basis is handing over
         * goods for free.
         */
        if ($locked->status !== 'success') {
            return [
                'state' => (string) ($intent['state'] ?? self::STATE_PENDING),
                'transaction' => null,
                'message' => 'Payment has not settled yet.',
            ];
        }

        $user = $locked->user;

        if (! $user) {
            return ['state' => self::STATE_FAILED, 'transaction' => null, 'message' => 'The payment has no customer.'];
        }

        // Claim before the provider call, so a concurrent caller stops here.
        $intent['state'] = self::STATE_VENDING;
        $intent['attempts'] = (int) ($intent['attempts'] ?? 0) + 1;
        $this->writeIntent($locked, $intent);

        try {
            $result = app(\App\Services\BillPaymentService::class)->purchase(
                user: $user,
                product: (string) $intent['product'],
                amount: (float) $intent['amount'],
                fee: 0.0,
                recipient: (string) $intent['recipient'],
                providerLabel: (string) ($intent['provider_label'] ?? ''),
                description: (string) ($intent['description'] ?? 'Purchase'),
                meta: array_merge((array) ($intent['meta'] ?? []), [
                    'funded_by' => $locked->reference,
                    'paid_with' => 'paystack',
                ]),
                providerParams: (array) ($intent['provider_params'] ?? []),
                dispatch: function ($provider, Transactions $transaction) use ($intent) {
                    return $provider->purchase(
                        (string) $intent['product'],
                        (array) ($intent['provider_params'] ?? []),
                        $transaction->reference,
                    );
                },
                successMessage: (string) ($intent['success_message'] ?? 'Purchase complete.'),
                /*
                 * No PIN. The customer authorised this payment at the gateway, which
                 * is a stronger authorisation than a four-digit PIN — asking for one
                 * here would be asking them to authorize a second time for money that
                 * has already left their account.
                 */
                pin: null,
                idempotencyKey: (string) ($intent['idempotency_key'] ?? $locked->reference),
                networkKey: $intent['network_key'] ?? null,
                networkName: $intent['network_name'] ?? null,
                fundingAlreadySettled: true,
                /*
                 * Re-price at the amount the customer paid, from the live provider
                 * cost, so the pricing snapshot records the margin actually earned
                 * rather than the one quoted at checkout. `null` quote, because a
                 * checkout quote is a stale basis by the time this runs.
                 */
                snapshotFactory: $this->snapshotFactory($intent),
            );
        } catch (Throwable $e) {
            return $this->fail($locked, $intent, $e->getMessage());
        }

        if (! ($result['ok'] ?? false)) {
            return $this->fail($locked, $intent, (string) ($result['message'] ?? 'The purchase could not be completed.'));
        }

        /** @var Transactions $product */
        $product = $result['transaction'];

        $intent['state'] = self::STATE_VENDED;
        $intent['product_transaction_id'] = $product->getKey();
        $intent['product_reference'] = $product->reference;
        $intent['vended_at'] = now()->toDateTimeString();
        $intent['message'] = (string) ($result['message'] ?? '');
        $this->writeIntent($locked, $intent);

        $this->settleSurplus($locked, $intent, $product, $user);

        Log::info('A Paystack-funded purchase was vended', [
            'funding_reference' => $locked->reference,
            'product_reference' => $product->reference,
            'product' => $intent['product'],
            'user_id' => $user->id,
        ]);

        return ['state' => self::STATE_VENDED, 'transaction' => $product, 'message' => (string) $intent['message']];
    }

    /**
     * Vend every settled purchase that has not been vended yet.
     *
     * The backstop for the case the settlement hooks cannot cover: the process died
     * between the credit and the provider call, or the purchase is marked `failed`
     * because the provider was briefly down. Money has been taken and goods have not
     * been delivered, which is the one state a sweep must never leave alone.
     *
     * @return array{vended:int,failed:int,examined:int}
     */
    public function sweep(int $limit = 50, int $graceMinutes = 1): array
    {
        $candidates = Transactions::query()
            ->where('service_type', 'funding')
            ->where('status', 'success')
            ->whereNotNull('meta->' . self::META_KEY)
            ->where('created_at', '<=', now()->subMinutes($graceMinutes))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $vended = 0;
        $failed = 0;
        $examined = 0;

        foreach ($candidates as $funding) {
            $intent = $this->intent($funding);

            if ($intent === null || ($intent['state'] ?? null) === self::STATE_VENDED) {
                continue;
            }

            $examined++;

            $result = $this->vend($funding);

            if ($result['state'] === self::STATE_VENDED) {
                $vended++;
            } elseif ($result['state'] === self::STATE_FAILED) {
                $failed++;
            }
        }

        return ['vended' => $vended, 'failed' => $failed, 'examined' => $examined];
    }

    /**
     * Return anything the customer paid above the price of what they bought.
     *
     * The amount charged at checkout is pinned, so this is normally zero and the
     * check costs a comparison. It is not dead code: a rule change between checkout
     * and vend would otherwise leave the difference sitting in a wallet with no
     * record of why — and an unexplained credit is exactly what a support ticket is
     * made of.
     */
    private function settleSurplus(Transactions $funding, array $intent, Transactions $product, User $user): void
    {
        $paid = Money::fromDatabase($funding->total_amount);
        $spent = Money::fromDatabase($product->total_amount);
        $surplus = $paid->minus($spent);

        if (! $surplus->isPositive()) {
            return;
        }

        try {
            $this->wallets->credit(
                user: $user,
                amount: $surplus,
                countsAsFunding: false,
                entryType: WalletLedger::ENTRY_REFUND,
                description: 'Price difference on ' . $product->reference,
                transaction: $funding,
                metadata: [
                    'reason' => 'price_difference',
                    'product_reference' => $product->reference,
                    'paid_minor' => $paid->minor(),
                    'spent_minor' => $spent->minor(),
                ],
            );

            Log::info('Refunded the difference between the amount paid and the price charged', [
                'funding_reference' => $funding->reference,
                'product_reference' => $product->reference,
                'difference' => $surplus->toDecimalString(),
            ]);
        } catch (Throwable $e) {
            /*
             * Logged as critical rather than swallowed: the customer is owed this
             * money and there is now a discrepancy somebody has to settle by hand.
             */
            Log::critical('Could not refund the difference on a funded purchase — manual intervention required', [
                'funding_reference' => $funding->reference,
                'product_reference' => $product->reference,
                'difference' => $surplus->toDecimalString(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a failure on the intent.
     *
     * The money stays in the wallet. It is not refunded to the card because the
     * platform has no card-refund path that is not itself a new money movement, and
     * a wallet credit is a real, spendable, recorded outcome — the customer has not
     * lost anything, and the message says plainly where their money is.
     */
    private function fail(Transactions $funding, array $intent, string $message): array
    {
        $intent['state'] = self::STATE_FAILED;
        $intent['failed_at'] = now()->toDateTimeString();
        $intent['message'] = 'We could not complete this purchase, so the amount you paid is in your wallet. '
            . 'You can try again from your balance, or contact support with reference ' . $funding->reference . '.';

        $this->writeIntent($funding, $intent);

        Log::error('A Paystack-funded purchase could not be vended', [
            'funding_reference' => $funding->reference,
            'user_id' => $funding->user_id,
            'product' => $intent['product'] ?? null,
            'provider_message' => $message,
        ]);

        return ['state' => self::STATE_FAILED, 'transaction' => null, 'message' => $intent['message']];
    }

    /** @param array<string,mixed> $intent */
    private function writeIntent(Transactions $funding, array $intent): void
    {
        $funding->forceFill([
            'meta' => array_merge($funding->meta ?? [], [self::META_KEY => $intent]),
        ])->save();
    }

    /**
     * Build a fresh quote for the purchase at vend time, or nothing.
     *
     * The pricing knowledge lives in the product controllers — they own the cost
     * resolvers and the pricing context — so this asks the same resolvers they use,
     * rather than reimplementing the rule lookup here. A second implementation of
     * "what does this cost" is how two prices for one product start disagreeing.
     *
     * The resolver is chosen by product rather than assumed: airtime is priced from a
     * per-network discount on the face value, while a data bundle is priced from the
     * catalogue's cost, and calling the wrong one would produce a nonsense cost and
     * therefore a nonsense margin.
     *
     * Returns null when the product has no re-pricing path here, which leaves the
     * sale **unmeasured** rather than mis-measured. `pricingAnalysis()` reports that
     * honestly as null instead of as zero profit.
     *
     * @param  array<string,mixed>  $intent
     * @return (callable(int):?\App\Pricing\PriceQuote)|null
     */
    private function snapshotFactory(array $intent): ?callable
    {
        $product = (string) ($intent['product'] ?? '');
        $networkKey = $intent['network_key'] ?? null;
        $context = (array) ($intent['pricing_context'] ?? []);
        $plan = $intent['plan'] ?? null;

        if ($context === []) {
            return null;
        }

        // Only the two products that carry a cost resolver today. Anything else
        // records no snapshot, which is the honest outcome.
        if (! in_array($product, ['airtime', 'data'], true)) {
            return null;
        }

        return function (int $expectMinor) use ($product, $networkKey, $context, $plan) {
            $costs = app(\App\Services\ProviderCostResolver::class);

            $cost = $product === 'data' && is_array($plan)
                ? $costs->forDataBundle($plan)
                : $costs->forAirtime($expectMinor, $networkKey);

            if (! $cost) {
                return null;
            }

            return app(\App\Pricing\PricingEngine::class)->quote(
                providerCost: $cost->costMinor,
                quantity: 1,
                context: $context + ['network' => $networkKey],
                // Airtime prices against the face value; a bundle has no face value,
                // so the engine prices it cost-plus and this is omitted.
                priceBasisMinor: $product === 'airtime' ? $expectMinor : null,
                costMeta: $cost->toEngineMetadata(),
            );
        };
    }
}
