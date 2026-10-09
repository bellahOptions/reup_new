<?php

namespace App\Services;

use App\Models\Transactions;
use App\Models\User;
use App\Models\WalletLedger;
use App\Services\Providers\BillProvider;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The single purchase pipeline for every product that spends from the wallet.
 *
 * Ordering guarantees, identical for every product:
 *
 *   1. **Customer balance** — `WalletService::debit()` re-reads the wallet under
 *      a `SELECT ... FOR UPDATE` row lock and refuses when the balance is short.
 *      Nothing else happens until that succeeds, so a purchase can never start
 *      that the customer cannot pay for.
 *   2. **Spend controls** — transaction PIN, velocity, idempotency and limits,
 *      all evaluated before the lock is taken.
 *   3. **Provider balance** — the charge is only sent to an upstream whose float
 *      covers it, so the platform does not take money it cannot vend. This is
 *      what turns "customer debited, provider rejects, customer waits for a
 *      refund" into "customer is told immediately, no money moves".
 *   4. **Failover** — only provider-side failures (float, outage, timeout) are
 *      retried on the next provider. A validation error stops immediately,
 *      because retrying it elsewhere fails identically or double-charges.
 *   5. **Compensating refund** — if every provider fails, an explicit credit
 *      reverses the debit. A failed refund is logged at `critical`, since it
 *      means a customer is out of pocket until somebody intervenes.
 */
class BillPaymentService
{
    /*
     * The four outcomes a purchase can end in. These are returned to the caller
     * *and* written to `transactions.status` (except `retryable`, which is
     * internal — a purchase is only ever left in a state a customer can be shown
     * the truth about).
     *
     * The distinction that matters most: OUTCOME_UNKNOWN is not OUTCOME_FAILED.
     */
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_UNKNOWN = 'unknown';

    /**
     * Product key => the value stored in `transactions.service_type`.
     *
     * The product keys in config/bills.php are the vocabulary the forms, the
     * spend limits and the provider adapters speak, and three of them are *not*
     * the vocabulary the rest of the application reads:
     *
     *   * cable TV is stored as `cable-tv` — the spelling every history page and
     *     the admin counters filter on;
     *   * WAEC and JAMB are both `exam`, which is what the two PIN pages and the
     *     admin counters query. Which exam it was is kept in the transaction
     *     meta, where the receipt already reads it from.
     *
     * Storing the raw product key instead meant the column held a value the ENUM
     * did not accept — error 1265 on a server running the default strict SQL
     * mode, an empty string on a lax one — and left cable, WAEC and JAMB history
     * pages permanently empty on either.
     */
    private const SERVICE_TYPES = [
        'cable_tv' => 'cable-tv',
        'waec' => 'exam',
        'jamb' => 'exam',
    ];

    /**
     * The `transactions.service_type` value for a product key.
     *
     * Public so a test can check the mapping against the column's ENUM: a value
     * the schema does not carry does not merely look wrong, it aborts the insert
     * on a server running the default strict SQL mode.
     */
    public static function serviceType(string $product): string
    {
        return self::SERVICE_TYPES[$product] ?? $product;
    }

    public function __construct(
        private readonly WalletService $wallets,
        private readonly ProviderManager $providers,
        private readonly ProviderBalanceService $balances,
        private readonly SecurityService $security,
    ) {
    }

    /**
     * Preferred provider for read-only lookups (meter, smartcard, betting
     * account). Unaffected by float, since nothing is charged.
     */
    public function provider(string $product): BillProvider
    {
        return $this->providers->verifier($product);
    }

    /**
     * Read-only catalogue access (data plans, cable bouquets).
     *
     * Catalogues are static reference data rather than a vending operation, so
     * they come from the primary provider directly instead of going through
     * failover.
     */
    public function catalogues(): \App\Services\ClubKonnectService
    {
        return app(\App\Services\ClubKonnectService::class);
    }

    /** Every provider, with its float — for the admin console. */
    public function providerBalances(): array
    {
        return $this->balances->all($this->providers->all());
    }

    /* =====================================================================
     | Purchase
     |=================================================================== */

    /**
     * @param  callable(BillProvider, Transactions):?array  $dispatch
     *        Sends the charge to a given provider. Called once per attempt.
     * @param  array<string,mixed>  $providerParams
     *        Exactly what `$dispatch` passes to `BillProvider::purchase()`. Given
     *        to provider selection as well, because pricing the purchase — and so
     *        choosing the cheaper upstream — needs the same parameters.
     * @return array{ok:bool,transaction:?Transactions,message:string,provider:?string,outcome:string}
     */
    public function purchase(
        User $user,
        string $product,
        $amount,
        $fee,
        string $recipient,
        string $providerLabel,
        string $description,
        array $meta,
        callable $dispatch,
        string $successMessage,
        ?string $pin = null,
        ?string $idempotencyKey = null,
        array $providerParams = [],
        ?\App\Pricing\PriceQuote $quote = null,
        ?string $networkKey = null,
        ?string $networkName = null,
    ): array {
        /*
         * ---- The quoted price is the price ---------------------------------
         *
         * When a caller has priced the sale through the pricing engine, the
         * engine's figure is authoritative for every money movement, and `amount`
         * is the customer-facing *amount of service* rather than what it cost us.
         *
         * The two differ, deliberately:
         *
         *   * `transactions.amount` is what the customer bought. For airtime that
         *     is the face value (₦1,000 of airtime), which is also what the
         *     provider payload must carry. For cost-plus products it is the price.
         *     It is never the provider cost — a receipt reading "₦970 airtime"
         *     would be wrong about what the customer received.
         *   * `transactions.total_amount` is what was debited, and comes from the
         *     quote so it cannot diverge from the price the customer was shown.
         *   * the provider cost lives on the pricing snapshot, which is where
         *     margin is computed from.
         *
         * The legacy `$amount`/`$fee` pair is unused when a quote is present, except
         * that keeping it in the idempotency hash preserves the request shape.
         */
        $amountMoney = $quote
            ? Money::fromMinor($this->customerAmountMinor($quote))
            : Money::fromNaira($amount);

        $feeMoney = $quote ? Money::fromMinor($quote->customerFeeMinor) : Money::fromNaira($fee ?? 0);

        $total = $quote ? $quote->price() : $amountMoney->plus($feeMoney);

        if (! $total->isPositive()) {
            throw new RuntimeException('Transaction amount must be greater than zero.');
        }

        /*
         * ---- Replay protection -------------------------------------------
         *
         * The database is the authority: `reserve()` inserts a row whose UNIQUE
         * key is the idempotency key, so a duplicate submit loses the race at
         * the database rather than in a cache that a second web server, a cache
         * flush or a rolled-back transaction can invalidate.
         *
         * The reservation is deliberately taken AFTER the security gates below
         * and AFTER the provider selection, so a request that is going to be
         * refused for a wrong PIN does not consume the key. It is taken BEFORE
         * the debit, which is the point of no return.
         */
        // ---- Security gates, before any lock is taken ---------------------
        $this->security->verifyPin($user, $pin);
        $this->security->assertNotThrottled($user);
        $this->security->assertWithinLimits($user, $total, $product);
        $this->security->assertNotDuplicate($user, $product, $total, $recipient);

        // ---- Customer balance --------------------------------------------
        $balance = $this->wallets->balanceFor($user);

        if ($balance->lessThan($total)) {
            throw new RuntimeException(
                'Insufficient wallet balance. You need ' . $total->format()
                . ' but your balance is ' . $balance->format() . '.'
            );
        }

        // ---- Provider float and availability ------------------------------
        $candidates = $this->providers->affordable($product, $total->toFloat(), $providerParams);

        if (! $candidates) {
            throw new RuntimeException(
                'This service is temporarily unavailable. No refund has been made and no money has left your wallet.'
            );
        }

        // ---- Reserve the idempotency key, or answer from the original ------
        $reservation = null;

        if ($idempotencyKey) {
            $reservation = $this->security->reserve(
                $idempotencyKey,
                'bill_purchase',
                $user,
                $this->security->requestHash($user, [
                    'product' => $product,
                    'amount' => $amountMoney->toDecimalString(),
                    'fee' => $feeMoney->toDecimalString(),
                    'recipient' => $recipient,
                ])
            );

            if ($reservation['replay']) {
                return $this->replayResponse($reservation);
            }
        }

        // ---- Debit under a row lock --------------------------------------
        try {
            $transaction = DB::transaction(function () use ($user, $product, $amountMoney, $feeMoney, $total, $recipient, $providerLabel, $description, $meta, $idempotencyKey, $quote, $networkKey, $networkName) {
                $reference = $this->wallets->generateReference('TXN');

                $transaction = Transactions::create([
                    'user_id' => $user->id,
                    'reference' => $reference,
                    'type' => 'debit',
                    'service_type' => self::serviceType($product),
                    'description' => $description,
                    'amount' => $amountMoney->toDecimalString(),
                    'service_fee' => $feeMoney->toDecimalString(),
                    'total_amount' => $total->toDecimalString(),
                    'recipient' => $recipient,
                    /*
                     * `provider` is the upstream that served the request — an
                     * internal detail. The *network* the customer bought is stored
                     * separately, below, because the two are different things and
                     * conflating them is what put "ClubKonnect" on a customer's
                     * receipt under the heading "Network".
                     */
                    'provider' => $providerLabel,
                    'network_code' => $networkKey,
                    'network_name' => $networkName,
                    'payment_method' => 'wallet',
                    'payment_status' => 'success',
                    'status' => 'processing',
                    'idempotency_key' => $idempotencyKey,
                    'paid_at' => now(),
                    'meta' => $meta + ['requested_provider' => $providerLabel],
                ]);

                // The debit writes the ledger entry and stamps the balances onto
                // the transaction, so the money and its record cannot diverge.
                $movement = $this->wallets->debit(
                    user: $user,
                    amount: $total,
                    description: $description,
                    transaction: $transaction,
                    metadata: ['product' => $product, 'recipient' => $recipient],
                );

                $transaction->forceFill([
                    'balance_before' => $movement['balance_before'],
                    'balance_after' => $movement['balance_after'],
                ])->save();

                /*
                 * The pricing snapshot is written here, inside the same transaction
                 * as the debit, for the same reason `ServiceOrderService` writes its
                 * own there: a charge with no recorded price makes the margin
                 * unanswerable, and a recorded price with no charge makes the record
                 * a lie. Either alone is worse than both together.
                 */
                if ($quote) {
                    $transaction->attachPricingSnapshot($quote, [
                        'product' => $product,
                        'recipient' => $recipient,
                        'customer_amount_minor' => $amountMoney->minor(),
                    ]);
                }

                return $transaction;
            });
        } catch (Throwable $e) {
            // Nothing was charged, so the key must not stay reserved: the
            // customer's retry has to be allowed through.
            if ($reservation) {
                $this->security->release($reservation['record'], $e->getMessage());
            }

            throw $e;
        }

        // ---- Deliver, with failover --------------------------------------
        $attempts = [];
        $lastError = 'No provider accepted the request.';

        foreach ($candidates as $provider) {
            try {
                $response = $dispatch($provider, $transaction);

                $outcome = $this->providers->classify($provider, $response);

                $attempts[] = [
                    'provider' => $provider->name(),
                    'status' => $response['status'] ?? null,
                    'outcome' => $outcome,
                ];

                if ($outcome === 'success') {
                    $this->markSuccess($transaction, $provider, $response);

                    // The float has moved; make the next check re-read it.
                    $this->balances->invalidate($provider);

                    if ($reservation) {
                        $this->security->complete($reservation['record'], $transaction, [
                            'outcome' => 'success',
                            'provider' => $provider->name(),
                        ]);
                    }

                    $this->sendReceipts($user, $transaction);

                    return [
                        'ok' => true,
                        'transaction' => $transaction,
                        'message' => $successMessage,
                        'provider' => $provider->name(),
                        'outcome' => self::OUTCOME_SUCCESS,
                    ];
                }

                /*
                 * ---- UNKNOWN: do not retry, do not refund -----------------
                 *
                 * We sent the request and never learned what happened to it. The
                 * order may have been vended. Retrying it on the second provider
                 * could vend it twice; refunding it could hand over goods for
                 * free. The only safe action is to stop, record that the outcome
                 * is unknown, and let the provider tell us — by webhook, by a
                 * status query, or by a human looking at it.
                 */
                if ($outcome === 'unknown') {
                    $lastError = $provider->errorMessage($response);

                    $this->settleUnknown($transaction, $provider, $response, $lastError, $attempts);

                    if ($reservation) {
                        $this->security->complete($reservation['record'], $transaction, [
                            'outcome' => 'unknown',
                            'provider' => $provider->name(),
                        ]);
                    }

                    return [
                        'ok' => false,
                        'transaction' => $transaction->fresh(),
                        'message' => 'We could not confirm this purchase with the provider. '
                            . 'Your money is held, not lost — we are checking and will update you shortly. '
                            . 'Please do not submit it again.',
                        'provider' => $provider->name(),
                        'outcome' => self::OUTCOME_UNKNOWN,
                    ];
                }

                if ($outcome === 'retryable') {
                    $lastError = $provider->errorMessage($response);

                    Log::warning('Provider could not serve the request; failing over', [
                        'transaction_id' => $transaction->id,
                        'provider' => $provider->name(),
                        'reason' => $lastError,
                    ]);

                    // Float has moved (or is unknown); re-read before the next.
                    $this->balances->invalidate($provider);

                    // A request just failed, so the cached "available" is the
                    // last thing to trust for the next purchase.
                    $this->providers->invalidateHealth($provider);

                    continue;
                }

                // Fatal: a bad meter number, a duplicate reference, an invalid
                // account. Another provider would reject it identically, so
                // stop and refund rather than gamble on a double charge.
                $lastError = $provider->errorMessage($response);

                Log::notice('Provider rejected the request outright', [
                    'transaction_id' => $transaction->id,
                    'provider' => $provider->name(),
                    'reason' => $lastError,
                ]);

                break;
            } catch (Throwable $e) {
                /*
                 * An exception thrown by the adapter is treated the same as an
                 * explicit UNKNOWN, not as a failure: the request may already
                 * have been received and processed upstream, and we cannot tell
                 * from here. The adapter's own `post()` catches transport errors
                 * and returns a status, so reaching this handler means something
                 * genuinely unexpected happened — which is even less reason to
                 * assume the order did not vend.
                 */
                $lastError = 'Provider error: ' . $e->getMessage();

                $attempts[] = [
                    'provider' => $provider->name(),
                    'outcome' => 'unknown',
                    'message' => $e->getMessage(),
                ];

                Log::error('Provider threw during purchase — outcome unknown', [
                    'transaction_id' => $transaction->id,
                    'provider' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);

                $this->providers->invalidateHealth($provider);

                $this->settleUnknown($transaction, $provider, null, $lastError, $attempts);

                if ($reservation) {
                    $this->security->complete($reservation['record'], $transaction, [
                        'outcome' => 'unknown',
                        'provider' => $provider->name(),
                    ]);
                }

                return [
                    'ok' => false,
                    'transaction' => $transaction->fresh(),
                    'message' => 'We could not confirm this purchase with the provider. '
                        . 'Your money is held, not lost — we are checking and will update you shortly. '
                        . 'Please do not submit it again.',
                    'provider' => $provider->name(),
                    'outcome' => self::OUTCOME_UNKNOWN,
                ];
            }
        }

        // ---- Every provider refused: refund -------------------------------
        $reason = $lastError;
        $this->refund($transaction, $reason);

        $transaction->forceFill([
            'status' => 'failed',
            'payment_status' => 'refunded',
            'status_message' => $reason,
            'completed_at' => now(),
            'meta' => array_merge($transaction->meta ?? [], ['provider_attempts' => $attempts]),
        ])->save();

        if ($reservation) {
            $this->security->complete($reservation['record'], $transaction, [
                'outcome' => 'failed',
                'message' => $reason,
            ]);
        }

        return [
            'ok' => false,
            'transaction' => $transaction,
            'message' => $lastError . ' Your wallet has been refunded in full.',
            'provider' => null,
            'outcome' => self::OUTCOME_FAILED,
        ];
    }

    /**
     * The `transactions.amount` figure for a quoted purchase: what the customer
     * bought, not what it cost us.
     *
     * For a FACE_VALUE sale (airtime) the customer bought a face value, which is
     * what the provider payload must also carry — sending the discounted cost
     * upstream would vend ₦970 of airtime for a ₦1,000 request. For every other
     * strategy the price and the amount are the same thing.
     */
    private function customerAmountMinor(\App\Pricing\PriceQuote $quote): int
    {
        if ($quote->priceBasisMinor !== null && $quote->priceBasisMinor > 0) {
            return $quote->priceBasisMinor;
        }

        /*
         * `customerPriceMinor` already includes the customer fee. Reporting it as
         * `amount` and setting `service_fee` to zero would double-count it in
         * `total_amount`, so the fee is subtracted back out here and recorded in
         * its own column.
         */
        return $quote->customerPriceMinor - $quote->customerFeeMinor;
    }

    /**
     * The idempotent replay response for an already-used key.
     * @param  array{record:\App\Models\IdempotencyKey,transaction:?Transactions,hash_mismatch:bool}  $reservation
     * @return array{ok:bool,transaction:?Transactions,message:string,provider:?string,outcome:string}
     */
    private function replayResponse(array $reservation): array
    {
        $original = $reservation['transaction'];

        /*
         * A key presented with a *different* body is refused outright rather
         * than answered with the original outcome. Answering would report
         * success for a purchase that was never made, and executing it would
         * make the key meaningless.
         */
        if ($reservation['hash_mismatch']) {
            throw new RuntimeException(
                'This request signature has already been used for a different purchase. Please start again.'
            );
        }

        if (! $original) {
            // Reserved but never completed: the original request is either still
            // running or died before it charged anything. Either way, doing the
            // work now risks doing it twice.
            throw new RuntimeException('This purchase is already being processed. Please wait a moment.');
        }

        $outcome = match (true) {
            $original->status === 'success' => self::OUTCOME_SUCCESS,
            $original->status === 'unknown' => self::OUTCOME_UNKNOWN,
            $original->payment_status === 'refunded' => self::OUTCOME_FAILED,
            default => self::OUTCOME_FAILED,
        };

        return [
            'ok' => $outcome === self::OUTCOME_SUCCESS,
            'transaction' => $original,
            'message' => match ($outcome) {
                self::OUTCOME_SUCCESS => 'This purchase was already completed.',
                self::OUTCOME_UNKNOWN => 'This purchase is still being confirmed with the provider.',
                default => 'This purchase was already submitted and did not complete.',
            },
            'provider' => data_get($original->meta, 'provider'),
            'outcome' => $outcome,
        ];
    }

    /**
     * Record a purchase whose provider outcome is genuinely unknown.
     *
     * ## Why this is not a failure
     *
     * The request left this application. Whether the provider vended it is not
     * something ReUp knows, and every consequential action is unsafe:
     *
     *   * **refund** — if the customer was vended, ReUp has just given away the
     *     goods and the money;
     *   * **retry on the other provider** — if the customer was vended, they get
     *     two tokens for one payment and ReUp pays the second one;
     *   * **mark failed** — the customer is told the purchase failed, submits it
     *     again, and is charged twice when the first one actually succeeded.
     *
     * So the row is marked `unknown`, the money stays debited, and the outcome
     * is resolved by whichever of these arrives first:
     *
     *   1. a provider status query, attempted immediately below;
     *   2. the provider's webhook (Pairgate settles electricity and exam pins
     *      this way);
     *   3. an operator, via the "unconfirmed purchases" review.
     *
     * A successful status query is settled here and now, which is the only one
     * of the three that can save the customer a wait.
     *
     * @param  array<int,array<string,mixed>>  $attempts
     */
    private function settleUnknown(
        Transactions $transaction,
        BillProvider $provider,
        ?array $response,
        string $reason,
        array $attempts
    ): void {
        $verdict = $this->verifyWithProvider($provider, $transaction);

        if ($verdict === 'success') {
            $this->markSuccess($transaction, $provider, $response);
            $this->balances->invalidate($provider);
            $this->sendReceipts($transaction->user, $transaction);

            Log::info('Provider confirmed a timed-out purchase as successful', [
                'transaction_id' => $transaction->id,
                'provider' => $provider->name(),
            ]);

            return;
        }

        if ($verdict === 'failed') {
            // The provider has told us the order did not vend, so the refund is
            // now safe and is the right thing to do.
            $this->refund($transaction, $reason);

            $transaction->forceFill([
                'status' => 'failed',
                'payment_status' => 'refunded',
                'status_message' => $reason,
                'completed_at' => now(),
                'meta' => array_merge($transaction->meta ?? [], [
                    'provider_attempts' => $attempts,
                    'verified_outcome' => 'failed',
                ]),
            ])->save();

            Log::notice('Provider confirmed a timed-out purchase as failed; refunded', [
                'transaction_id' => $transaction->id,
                'provider' => $provider->name(),
            ]);

            return;
        }

        /*
         * Either the provider says the order is still in flight, or it could not
         * tell us. Both mean "wait" — the money stays held, the row says
         * `unknown`, and it is flagged for review so it cannot be forgotten.
         */
        $transaction->forceFill([
            'status' => 'unknown',
            'payment_status' => 'processing',
            'status_message' => Str::limit('Unconfirmed: ' . $reason, 500),
            'api_response' => $response,
            'meta' => array_merge($transaction->meta ?? [], [
                'provider_attempts' => $attempts,
                'unconfirmed' => true,
                'unconfirmed_since' => now()->toDateTimeString(),
                'verified_outcome' => $verdict,
                'requested_provider' => $provider->name(),
            ]),
        ])->save();

        Log::critical('SECURITY/FINANCE: purchase outcome unknown — held for verification', [
            'event' => 'purchase_outcome_unknown',
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'user_id' => $transaction->user_id,
            'amount' => (string) $transaction->total_amount,
            'provider' => $provider->name(),
            'verification' => $verdict,
            'reason' => $reason,
        ]);
    }

    /**
     * Ask the provider what happened, if it can tell us.
     *
     * @return string 'success' | 'failed' | 'pending' | 'unknown'
     */
    private function verifyWithProvider(BillProvider $provider, Transactions $transaction): string
    {
        try {
            $reference = $transaction->api_reference ?: $transaction->reference;

            return $provider->orderStatus($reference, [
                'reference_code' => $transaction->api_reference,
                'params' => $transaction->meta['provider_params'] ?? [],
            ]);
        } catch (Throwable $e) {
            // A failed query is not a verdict either.
            Log::warning('Provider status verification failed', [
                'transaction_id' => $transaction->id,
                'provider' => $provider->name(),
                'error' => $e->getMessage(),
            ]);

            return 'unknown';
        }
    }

    /**
     * Resolve an `unknown` purchase by asking the provider what happened.
     *
     * Called by the scheduled `payments:review-unconfirmed` sweep and available
     * to the admin review screen. The rules are deliberately asymmetric:
     *
     *   * the provider says **success** → settle it and send the receipt, because
     *     the customer has the goods and the money is already theirs to pay;
     *   * the provider says **failed** → only now is a refund safe, and it is
     *     issued;
     *   * the provider says **pending** or cannot say → change nothing. The row
     *     stays `unknown` and stays visible. Refunding on a non-answer is how a
     *     vended order is given away.
     *
     * @param  int  $actorId  the administrator, or 0 for the scheduled sweep
     * @param  bool  $confirmSuccess  settle on the provider's word without a second signal
     * @param  bool  $allowPending  also accept a row the *admin* is asking about
     *                              while it still reads `pending`. The scheduled
     *                              sweep keeps the narrow default: it exists to
     *                              clear `unknown` rows, and widening it would
     *                              have it second-guessing purchases that are
     *                              still legitimately in flight.
     * @return array{resolved:bool,verdict:string}
     */
    public function resolveUnknown(
        Transactions $transaction,
        int $actorId,
        bool $confirmSuccess = false,
        bool $allowPending = false
    ): array {
        $acceptable = $allowPending ? ['unknown', 'pending', 'processing'] : ['unknown'];

        if (! in_array($transaction->status, $acceptable, true)) {
            return ['resolved' => false, 'verdict' => 'not_unknown'];
        }

        $providerName = (string) data_get($transaction->meta, 'requested_provider', $transaction->provider);
        $provider = $this->providers->byName($providerName);

        if ($provider) {
            $verdict = $this->verifyWithProvider($provider, $transaction);
        } else {
            $verdict = 'unknown';
        }

        if ($verdict === 'success') {
            $this->markSuccess($transaction, $provider, $transaction->api_response);

            if ($provider) {
                $this->balances->invalidate($provider);
            }

            $this->sendReceipts($transaction->user, $transaction);

            Log::info('Unconfirmed purchase resolved as successful by provider query', [
                'transaction_id' => $transaction->id,
                'provider' => $providerName,
                'actor_id' => $actorId ?: null,
            ]);

            return ['resolved' => true, 'verdict' => 'success'];
        }

        if ($verdict === 'failed') {
            $reason = 'Resolved as failed after checking with the provider.';

            if ($this->refund($transaction, $reason, WalletLedger::ENTRY_REFUND, $actorId ?: null)) {
                Log::notice('Unconfirmed purchase resolved as failed and refunded', [
                    'transaction_id' => $transaction->id,
                    'provider' => $providerName,
                    'actor_id' => $actorId ?: null,
                ]);

                return ['resolved' => true, 'verdict' => 'failed'];
            }

            return ['resolved' => false, 'verdict' => 'refund_failed'];
        }

        // `pending`, or the provider could not tell us. Leave it alone.
        return ['resolved' => false, 'verdict' => $verdict];
    }

    private function markSuccess(Transactions $transaction, ?BillProvider $provider, ?array $response): void
    {
        $token = $provider?->issuedToken($response);

        $transaction->forceFill([
            'status' => 'success',
            'payment_status' => 'success',
            // A provider may be null when a purchase is settled from a status
            // query by the review sweep; the label already on the row is kept.
            'provider' => $provider?->label() ?: $transaction->provider,
            'api_reference' => $provider?->orderReference($response) ?: $transaction->api_reference,
            'api_response' => $response,
            'completed_at' => now(),
            'meta' => array_merge($transaction->meta ?? [], array_filter([
                'provider' => $provider?->name(),
                'token' => $token,
            ], fn ($v) => $v !== null)),
        ])->save();
    }

    /* =====================================================================
     | Refunds
     |=================================================================== */

    /**
     * Return money for a failed purchase and record a linked credit.
     *
     * ## Idempotency
     *
     * This is the only place in the application that reverses a bill purchase,
     * and it is called from three paths that can race: the purchase pipeline
     * itself, the Pairgate failure webhook, and an administrator reversing a
     * transaction by hand. Two guards make a double refund impossible:
     *
     *   1. the originating row is re-read **under a row lock** and its
     *      `payment_status` is checked; anything already `refunded` or
     *      `reversed` returns early;
     *   2. the refund transaction's `payment_reference` is derived from the
     *      original, and that column carries a UNIQUE index — so even if two
     *      processes got past the check simultaneously, the second insert fails
     *      and the whole movement rolls back with it.
     *
     * The wallet credit and the refund transaction are written in one database
     * transaction, so the money and its record always agree.
     *
     * @param  string  $entryType  WalletLedger::ENTRY_REFUND (default) or ENTRY_REVERSAL
     * @param  int|null  $actorId  the administrator, when the reversal is manual
     * @return bool whether this call performed the refund
     */
    public function refund(
        Transactions $transaction,
        string $reason,
        string $entryType = WalletLedger::ENTRY_REFUND,
        ?int $actorId = null
    ): bool {
        try {
            return (bool) DB::transaction(function () use ($transaction, $reason, $entryType, $actorId) {
                $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->first();

                if (! $locked) {
                    Log::error('Refund requested for a transaction that no longer exists', [
                        'transaction_id' => $transaction->getKey(),
                    ]);

                    return false;
                }

                // Already reversed. Whoever got here first owns the refund.
                if (in_array($locked->payment_status, ['refunded', 'reversed'], true)) {
                    Log::info('Refund skipped: the transaction is already reversed', [
                        'transaction_id' => $locked->id,
                        'payment_status' => $locked->payment_status,
                    ]);

                    return false;
                }

                // A claim that was never charged must not be credited.
                if ($locked->type !== 'debit' || ! in_array($locked->status, ['success', 'processing', 'unknown'], true)) {
                    Log::warning('Refund refused: the transaction is not a settled debit', [
                        'transaction_id' => $locked->id,
                        'type' => $locked->type,
                        'status' => $locked->status,
                    ]);

                    return false;
                }

                $user = $locked->user;

                if (! $user) {
                    Log::critical('Refund impossible: the transaction has no user', [
                        'transaction_id' => $locked->id,
                    ]);

                    return false;
                }

                $amount = Money::fromDatabase($locked->total_amount);

                /*
                 * The refund record is created first so that the wallet ledger
                 * entry written by the credit below can reference it directly.
                 * Ledger rows are immutable — there is no later step that can
                 * point the entry at this row.
                 */
                $refund = Transactions::create([
                    'user_id' => $user->id,
                    'reference' => $this->wallets->generateReference('RFND'),
                    'type' => 'credit',
                    'service_type' => 'refund',
                    'description' => 'Refund — ' . Str::limit($reason, 120),
                    'amount' => $amount->toDecimalString(),
                    'service_fee' => '0.00',
                    'total_amount' => $amount->toDecimalString(),
                    'payment_method' => 'wallet',
                    'payment_status' => 'success',
                    'status' => 'success',
                    'completed_at' => now(),
                    // The unique index on this column is the last line of
                    // defence against a second refund for the same charge.
                    'payment_reference' => 'REFUND-OF-' . $locked->id,
                    'meta' => [
                        'refunded_transaction_id' => $locked->id,
                        'refunded_reference' => $locked->reference,
                        'reason' => $reason,
                        'actor_id' => $actorId,
                    ],
                ]);

                // Compensating movement: it carries the wallet row lock and
                // writes the immutable ledger entry in this same transaction.
                $movement = $this->wallets->credit(
                    user: $user,
                    amount: $amount,
                    countsAsFunding: false,
                    entryType: $entryType,
                    description: 'Refund — ' . Str::limit($reason, 120),
                    transaction: $refund,
                    metadata: [
                        'refunded_transaction_id' => $locked->id,
                        'refunded_reference' => $locked->reference,
                        'reason' => $reason,
                    ],
                    actorId: $actorId,
                );

                // The original row now carries the balance it was reversed at,
                // so the admin view shows where the customer ended up.
                $locked->forceFill([
                    'payment_status' => $entryType === WalletLedger::ENTRY_REVERSAL ? 'reversed' : 'refunded',
                    'status' => 'failed',
                    'status_message' => Str::limit($reason, 500),
                    'balance_after' => $movement['balance_after'],
                    'completed_at' => $locked->completed_at ?: now(),
                    'meta' => array_merge($locked->meta ?? [], [
                        'refund_transaction_id' => $refund->id,
                        'refunded_at' => now()->toDateTimeString(),
                        'refund_reason' => Str::limit($reason, 200),
                    ]),
                ])->save();

                return true;
            });
        } catch (Throwable $e) {
            // Never mask the original failure, but make this loud: a customer is
            // out of pocket until somebody intervenes.
            Log::critical('Refund failed — manual intervention required', [
                'transaction_id' => $transaction->getKey(),
                'user_id' => $transaction->user_id,
                'amount' => (string) $transaction->total_amount,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /* =====================================================================
     | Receipts and history
     |=================================================================== */

    public function sendReceipts(User $user, Transactions $transaction): void
    {
        try {
            Mail::to($user->email)->send(new \App\Mail\TransactionReceiptMail($transaction));

            $ops = config('services.support.ops_emails', []);

            if (! empty($ops)) {
                Mail::to($ops)->send(new \App\Mail\AdminTransactionNotification($transaction));
            }
        } catch (Throwable $e) {
            // A mail failure must never fail a completed purchase.
            Log::error('Receipt email failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function historyFor(?User $user, array $serviceTypes, int $perPage = 20)
    {
        return Transactions::where('user_id', $user?->id)
            ->whereIn('service_type', $serviceTypes)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }
}
