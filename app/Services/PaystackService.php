<?php

namespace App\Services;

use App\Exceptions\PhoneNumberRequiredException;
use App\Models\Transactions;
use App\Models\User;
use App\Models\WalletLedger;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * All Paystack gateway interaction for wallet funding.
 *
 * Consolidates what was previously duplicated across PaystackController and
 * WalletController — two implementations that disagreed on status vocabulary
 * ('completed' vs 'success') and on whether to verify the amount actually
 * paid. This class owns:
 *   - payment initialisation
 *   - server-side verification against the gateway
 *   - exactly-once crediting, guarded by a row lock and a status check
 */
class PaystackService
{
    /**
     * The user whose customer details have already been pushed to Paystack in
     * this request, so the dedicated-account path does not send them twice.
     *
     * An instance property rather than a model column on purpose: this is a
     * "have I already done this network call" flag, not durable state, and
     * storing it would mean a stale value could suppress a genuine sync.
     */
    private ?int $detailsSyncedFor = null;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly AffiliateService $affiliates,
    ) {
    }

    public function isConfigured(): bool
    {
        return ! empty(config('services.paystack.secret_key'));
    }

    /**
     * Is the configured key a test-mode key?
     *
     * Worth knowing rather than guessing, because the two Paystack objects are
     * genuinely different businesses: a **test key cannot issue a dedicated
     * virtual account at all**. Every partner bank answers
     * `"<bank> is not available in test mode"`, which reads like a bank problem
     * and is not one — `paystack:diagnose-dva` demonstrates that by trying all
     * of them.
     *
     * Nothing branches on this to *enable* a feature. It is used to tell the
     * truth about why an account cannot be issued, so an operator reading a
     * customer's report does not go looking for a bank misconfiguration that
     * does not exist.
     *
     * ## The override
     *
     * `services.paystack.assume_live_dva` makes this report `false` regardless.
     * It exists for the test suite, which needs to exercise the assignment path
     * with a test key, and for the rare operator who has been told by Paystack
     * that their test account *can* issue accounts.
     *
     * It is a config value rather than an environment check because
     * `app()->environment('testing')` inside a service is how a production
     * guard quietly stops guarding: the same code path then behaves differently
     * under test than in production, which is the opposite of what a test is
     * for.
     */
    public function isTestMode(): bool
    {
        if (config('services.paystack.assume_live_dva')) {
            return false;
        }

        return str_starts_with((string) config('services.paystack.secret_key'), 'sk_test_');
    }

    private function secretKey(): string
    {
        $key = config('services.paystack.secret_key');

        if (empty($key)) {
            throw new RuntimeException('Paystack is not configured.');
        }

        return $key;
    }

    private function client()
    {
        return Http::withToken($this->secretKey())
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * The URL Paystack sends the customer's browser back to after checkout.
     *
     * This is the one place where a wrong APP_URL costs money rather than
     * merely breaking a link, so it is validated before the gateway is called.
     * `route()` builds an absolute URL from APP_URL, which means:
     *
     *   * a stale APP_URL (exported in the supervisor's environment, where it
     *     silently wins over .env — see docs/RUNNING.md and `env:doctor`) sends
     *     Paystack's redirect to the wrong host;
     *   * if that host is localhost or a private address, the customer is
     *     redirected somewhere their browser cannot reach *after* their card
     *     has been charged, and the session that owns the callback is gone
     *     because the cookie belongs to the real domain.
     *
     * Paystack itself accepts an unreachable callback_url happily, so this
     * cannot be caught by looking at the API response. Refusing up front with
     * a configuration error is the honest failure: it leaves a failed row and
     * a log line naming the real problem instead of a paid customer staring at
     * a dead redirect.
     *
     * Only production is policed — local development legitimately points at
     * 127.0.0.1, and `localhost`/`.test` installs must keep working.
     */
    public function callbackUrl(): string
    {
        $url = route('wallet.paystack.callback');

        if (! app()->environment('production')) {
            return $url;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);

        $unreachable = $host === ''
            || $scheme !== 'https'
            || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with(strtolower($host), '.local')
            || str_ends_with(strtolower($host), '.test');

        if ($unreachable) {
            throw new RuntimeException(
                "Paystack callback_url [{$url}] is not a public https URL. "
                . 'Set APP_URL in production to the exact public origin customers use '
                . '(and restart the process that exports it, if it is set in the environment).'
            );
        }

        return $url;
    }

    /**
     * The transaction split every checkout is initialised with, or null when
     * splitting is disabled.
     *
     * ## Why a malformed code is refused rather than sent
     *
     * A split code decides where the money goes. The dangerous failure is not a
     * rejected one — Paystack rejects an unknown code and the customer sees a
     * funding error, which is recoverable. The dangerous failure is a code that is
     * *accepted* but is not the intended split: a settlement then routes to the
     * wrong subaccount, and nothing surfaces it until somebody reconciles the
     * bank. So a value that cannot be a Paystack split code (`SPL_` followed by
     * the identifier) throws here, at configuration time, rather than being handed
     * to the gateway to interpret.
     *
     * @throws RuntimeException when the configured code is malformed
     */
    public function splitCode(): ?string
    {
        $code = trim((string) config('services.paystack.split_code', ''));

        if ($code === '') {
            // Splitting deliberately disabled: settlements land in full in the
            // main account.
            return null;
        }

        if (! preg_match('/^SPL_[A-Za-z0-9]+$/', $code)) {
            throw new RuntimeException(
                'PAYSTACK_SPLIT_CODE [' . $code . '] is not a valid Paystack split code. '
                . 'Expected the SPL_ code from the Paystack dashboard (Splits), or empty to disable splitting.'
            );
        }

        return $code;
    }

    /**
     * Create a Paystack transaction and return the hosted checkout URL.
     *
     * @throws RuntimeException when the gateway rejects the request.
     */
    public function initialize(Transactions $transaction, User $user): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Paystack secret key is not configured.');
        }

        $callbackUrl = $this->callbackUrl();
        $splitCode = $this->splitCode();

        $payload = [
            'email' => $user->email,
            'amount' => Money::fromDatabase($transaction->total_amount)->minor(),
            'currency' => 'NGN',
            // The UUID, not the display reference: the reconciler polls
            // /transaction/verify/{this value}, so it has to match exactly what
            // the gateway lodged. `gatewayReference()` falls back to `reference`
            // for rows created before the uuid column existed.
            'reference' => $transaction->gatewayReference(),
            'callback_url' => $callbackUrl,
            'metadata' => [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'display_reference' => $transaction->reference,
            ],
        ];

        /*
         * The transaction split — added only when one is configured.
         *
         * Sent on every checkout rather than being optional per payment: the split
         * is how the platform's share is routed, and a checkout that omits it
         * settles wholly into the main account, a discrepancy nobody notices until
         * reconciliation.
         *
         * Assigned conditionally rather than filtered out afterwards so the
         * required fields above cannot be caught by a filter's notion of "empty":
         * dropping `amount` or `email` from an initialise request would be a far
         * more expensive mistake than an unwanted split code.
         */
        if ($splitCode !== null) {
            $payload['split_code'] = $splitCode;
        }

        $response = $this->client()->post('https://api.paystack.co/transaction/initialize', $payload);

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            throw new RuntimeException('Paystack rejected the request: ' . ($body['message'] ?? 'unknown error'));
        }

        $data = $body['data'];

        $transaction->forceFill([
            'api_reference' => $data['reference'] ?? $transaction->gatewayReference(),
            'meta' => array_merge($transaction->meta ?? [], [
                'paystack_access_code' => $data['access_code'] ?? null,
                // Paystack's own numeric id — useful when talking to their
                // support, and the only handle that survives a reference typo.
                'paystack_transaction_id' => $data['id'] ?? null,
                'initialized_at' => now()->toDateTimeString(),
                // Recorded so support can answer "where were you sent back
                // to?" long after the fact, when the environment has changed.
                'callback_url' => $callbackUrl,
                /*
                 * Which split this charge was initialised under. Recorded because
                 * the split can be changed between a payment and the question
                 * "why did this settle there?" — the row has to carry the answer,
                 * and Paystack's own verify response does not always echo it.
                 */
                'paystack_split_code' => $splitCode,
            ]),
        ])->save();

        return $data['authorization_url'];
    }

    /**
     * Verify a reference directly with Paystack.
     *
     * @return array<string,mixed>|null the `data` block, or null when the
     *                                   reference is unknown to the gateway.
     */
    public function verify(string $reference): ?array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Paystack secret key is not configured.');
        }

        $response = $this->client()->get('https://api.paystack.co/transaction/verify/' . rawurlencode($reference));
        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            Log::warning('Paystack verification failed', [
                'reference' => $reference,
                'http_status' => $response->status(),
                'message' => $body['message'] ?? null,
            ]);

            return null;
        }

        return $body['data'] ?? null;
    }

    /**
     * Credit a funding transaction exactly once.
     *
     * ## Idempotency
     *
     * Four independent guards, in order:
     *
     *   1. the transaction row is re-read **under a `SELECT ... FOR UPDATE` row
     *      lock**, so two simultaneous settlements serialise rather than both
     *      reading `pending`;
     *   2. an already-successful row is returned untouched — this is what makes
     *      a duplicate webhook harmless;
     *   3. the gateway reference is verified to belong to *this* row, so a
     *      settled payment cannot be applied to a different transaction;
     *   4. `transactions.payment_reference` carries a UNIQUE index, so even a
     *      race that got past the check fails the insert rather than crediting
     *      twice.
     *
     * ## Validation
     *
     * The gateway is the authority on what was actually paid, and every value it
     * reports is checked before any money moves:
     *
     *   * **status** — only `success` settles;
     *   * **amount** — compared in integer kobo against the exact amount asked
     *     for. Previously a ₦100 charge could settle a ₦500,000 funding row;
     *   * **currency** — only NGN. A USD payment against a naira row is a
     *     misconfiguration or an attack, and crediting it at face value would
     *     hand over roughly 1,500x the value paid.
     *
     * @param  array<string,mixed>|null  $gatewayData  Verified Paystack payload.
     * @return array{status:string,transaction:Transactions}
     */
    public function settle(Transactions $transaction, ?array $gatewayData = null): array
    {
        return DB::transaction(function () use ($transaction, $gatewayData) {
            $locked = Transactions::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'success') {
                return ['status' => 'already_settled', 'transaction' => $locked];
            }

            // The gateway is the authority on what was actually paid.
            if ($gatewayData !== null) {
                $rejection = $this->validateGatewayPayment($locked, $gatewayData);

                if ($rejection !== null) {
                    return ['status' => $rejection, 'transaction' => $locked];
                }
            }

            return $this->creditFunding($locked, [
                'channel' => $gatewayData['channel'] ?? null,
                'reference' => $gatewayData['reference'] ?? null,
                'currency' => $gatewayData['currency'] ?? null,
                'gateway_response' => $gatewayData['gateway_response'] ?? null,
                'gateway' => 'paystack',
                'raw' => $gatewayData,
            ]);
        });
    }

    /**
     * Credit a funding row that another gateway has already verified.
     *
     * The only public door into `creditFunding`, for BachsService. It exists so
     * a second gateway can settle funding without reimplementing the exactly-
     * once credit — the row lock, the `payment_reference` unique index and the
     * ledger entry all stay in one place.
     *
     * The caller is responsible for having validated the amount and currency
     * against the locked row first; this does not re-validate because it has no
     * gateway payload to validate against.
     *
     * @param  array{channel:?string,reference:?string,currency:?string,gateway_response:?string,gateway:string,raw:array<string,mixed>|null}  $verdict
     * @return array{status:string,transaction:Transactions}
     */
    public function creditVerifiedFunding(Transactions $locked, array $verdict): array
    {
        return $this->creditFunding($locked, $verdict);
    }

    /**
     * The crediting half of settlement, shared by every gateway.
     *
     * This was extracted out of `settle()` when Bachs was added as a fallback:
     * the money-moving sequence must be byte-for-byte identical no matter which
     * gateway reported the payment, and a second copy of it is exactly how the
     * two providers drift apart on something financial. Callers do their own
     * payload validation first, then hand the normalised values here.
     *
     * Must be called inside a transaction with the row already locked: the
     * caller owns the lock, this owns the credit.
     *
     * @param  array{channel:?string,reference:?string,currency:?string,gateway_response:?string,gateway:string,raw:array<string,mixed>|null}  $verdict
     * @return array{status:string,transaction:Transactions}
     */
    private function creditFunding(Transactions $locked, array $verdict): array
    {
        $user = $locked->user;

        if (! $user) {
            throw new RuntimeException('Funding transaction ' . $locked->id . ' has no associated user.');
        }

        $amount = Money::fromDatabase($locked->amount);

        /*
         * The credit carries the transaction it settles, so the immutable
         * ledger entry and the transaction row reference each other. The
         * reference is also stamped onto `payment_reference`, whose unique
         * index is the last line of defence against a double credit.
         */
        $movement = $this->wallets->credit(
            user: $user,
            amount: $amount,
            countsAsFunding: true,
            entryType: WalletLedger::ENTRY_CREDIT,
            description: 'Wallet funding — ' . ($locked->payment_method === 'bank_transfer' ? 'Bank transfer' : 'Card'),
            transaction: $locked,
            metadata: [
                'channel' => $verdict['channel'],
                'gateway' => $verdict['gateway'],
            ],
        );

        $locked->forceFill([
            'status' => 'success',
            'payment_status' => 'success',
            'balance_before' => $movement['balance_before'],
            'balance_after' => $movement['balance_after'],
            'paid_at' => now(),
            'completed_at' => now(),
            'api_response' => $verdict['raw'],
            'payment_reference' => $locked->payment_reference
                ?: (string) ($verdict['reference'] ?: $locked->gatewayReference()),
            'meta' => array_merge($locked->meta ?? [], [
                'channel' => $verdict['channel'],
                'gateway' => $verdict['gateway'],
                'gateway_response' => $verdict['gateway_response'],
                'currency' => $verdict['currency'],
                'settled_at' => now()->toDateTimeString(),
                'ledger_entry_id' => $movement['ledger']->id,
            ]),
        ])->save();

        /*
         * Referral reward, after the funding is committed above.
         *
         * Deliberately placed here rather than in a webhook controller so it
         * fires for every route that settles funding — webhook, browser
         * callback and the payments:reconcile sweep all pass through here.
         * It is idempotent and swallows its own duplicate-key races, so a
         * failure here cannot cost the customer their credit.
         *
         * It reads `wallet.total_funded`, which the fee debit does not touch
         * (`countsAsFunding: false`), so the qualifying amount is the gross the
         * customer funded rather than the net after our charge.
         */
        $this->rewardReferrer($user, $locked);

        /*
         * The fee audit trail goes on LAST, and that ordering is load-bearing.
         *
         * Writing it before the `forceFill` above meant the `array_merge` there
         * merged the fee keys back into a `meta` that predated them — so the row
         * ended up with no `funding_fee_status` at all, and the retry sweep (which
         * selects on exactly that key) could never see a fee it needed to collect.
         * The fee row was still written; only the record of it was lost, which is
         * the failure mode that would have gone unnoticed in production.
         *
         * ## The one funding that carries no fee
         *
         * Money that arrived to pay for a product rather than to top up a wallet.
         * It is already spent before it rests in the wallet, and taking a percentage
         * of it would leave the customer unable to afford the very purchase they
         * paid for — the debit that follows a few milliseconds later would fail for
         * want of the fee. `FundedPurchase` sets the flag when it attaches the
         * intent; this is the settlement path that owns fee collection, so the
         * decision is honoured here rather than in each caller.
         *
         * The vending call below is deliberately *outside* this branch: skipping the
         * fee must never skip the delivery.
         */
        if (! $this->isFeeExempt($locked)) {
            $this->chargeFundingFee($user, $locked);
        }

        /*
         * A payment made to buy something, rather than to top up a wallet.
         *
         * Runs **last**, after the credit and the fee bookkeeping have been written,
         * and deliberately so: the customer's money must be safely in their wallet
         * before a provider is asked to vend anything. If vending fails from here the
         * money is still theirs and still spendable, the failure is recorded on the
         * intent, and the sweep retries it.
         *
         * It never throws. `settle()` is reached from the webhook, the browser
         * callback, the reconciler and the auto-resolve sweep, and an exception here
         * would make a payment that *did* settle look unsettled to all four.
         */
        $this->vendFundedPurchase($locked);

        /*
         * Record the funding as a lifecycle event, **inside this transaction**.
         *
         * Inside is the point. The event and the credit it describes commit together
         * or not at all, so a worker can never read a funding that was rolled back.
         * Dispatching a queued job from here instead would allow exactly that: the
         * worker runs before the commit and sends "you have funded your wallet" for a
         * payment that then fails.
         *
         * Nothing is sent from here — a separate process turns these rows into
         * scheduled messages. See `App\Lifecycle\LifecycleRecorder`.
         */
        $this->recordLifecycleEvent(
            \App\Lifecycle\LifecycleTrigger::WALLET_FUNDED,
            $user,
            [
                'amount_minor' => $amount->minor(),
                'payment_method' => (string) $locked->payment_method,
                'funding_count' => DB::table('transactions')
                    ->where('user_id', $user->getKey())
                    ->where('service_type', 'funding')
                    ->where('status', 'success')
                    ->where('type', 'credit')
                    ->count(),
            ],
            // Keyed on the funding row, so a replayed settlement is one event.
            dedupeKey: 'funding-' . $locked->getKey()
        );

        return ['status' => 'settled', 'transaction' => $locked];
    }

    /**
     * Write a lifecycle event as part of the current transaction.
     *
     * Never fatal. It runs inside the settlement transaction, where an exception would
     * roll back a credit the customer has already paid for — the one outcome worse
     * than a missing lifecycle event.
     *
     * @param  array<string,mixed>  $payload
     */
    private function recordLifecycleEvent(string $name, User $user, array $payload, string $dedupeKey): void
    {
        try {
            app(\App\Lifecycle\LifecycleRecorder::class)->record($name, $user, $payload, $dedupeKey);
        } catch (Throwable $e) {
            Log::error('Could not record a lifecycle event', [
                'event' => $name,
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Vend the product a settled payment was made for, if it was made for one.
     *
     * A no-op for an ordinary wallet top-up, which is the overwhelming majority of
     * settlements — the check is one array lookup on the row that has just settled.
     *
     * ## Why settlement is the trigger
     *
     * Four paths can settle a Paystack payment: the webhook, the browser callback,
     * `payments:reconcile` and `payments:auto-resolve`. Hooking each of them
     * separately is four places to forget, and a fifth added later would silently
     * take money without vending. All four pass through here, so one call covers
     * them all and a new settlement path gets the behaviour for free.
     *
     * `FundedPurchase::vend()` takes a row lock and claims the intent before calling
     * any provider, so two of those four arriving at once cannot vend twice.
     */
    private function vendFundedPurchase(Transactions $funding): void
    {
        try {
            app(\App\Orders\FundedPurchase::class)->vend($funding);
        } catch (Throwable $e) {
            /*
             * Logged at error rather than warning: a customer has paid and does not
             * have what they bought. The intent is left vendable so the sweep picks
             * it up — but somebody should see this line before that sweep runs.
             */
            Log::error('A settled payment could not be vended', [
                'funding_transaction_id' => $funding->id,
                'reference' => $funding->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Does this funding row carry no ReUp service fee?
     *
     * Only one kind does: money that arrived to pay for a product. It is flagged by
     * {@see \App\Orders\FundedPurchase}, which is the code that knows what the money
     * is for, and read here, which is the code that collects fees. Keeping the flag
     * rather than a second settlement branch means there is still exactly one place
     * a fee is taken from.
     */
    private function isFeeExempt(Transactions $funding): bool
    {
        return (bool) (($funding->meta ?? [])[\App\Orders\FundedPurchase::META_SKIP_FEE] ?? false);
    }

    /**
     * Collect the funding service fee and record the outcome on the funding row.
     *
     * The row's `meta` is the audit trail: it says whether the fee has been
     * taken, how much, which transaction took it, and — when it could not be
     * taken — why, so `payments:charge-funding-fees` can retry it and support can
     * answer "was I charged?" from the row alone.
     */
    private function chargeFundingFee(User $user, Transactions $funding): void
    {
        $result = $this->wallets->chargeFundingFee($user, $funding);

        $funding->forceFill([
            'meta' => array_merge($funding->meta ?? [], [
                'funding_fee' => $result['fee'],
                'funding_fee_status' => $result['status'],
                'funding_fee_transaction_id' => $result['transaction']?->getKey(),
                'funding_fee_charged_at' => $result['status'] === 'charged' ? now()->toDateTimeString() : null,
                'funding_fee_error' => $result['message'],
            ]),
        ])->save();
    }

    /**
     * Check everything the gateway reported before crediting anything.
     *
     * @param  array<string,mixed>  $gatewayData
     * @return string|null the rejection status, or null when the payment is good
     */
    private function validateGatewayPayment(Transactions $transaction, array $gatewayData): ?string
    {
        /*
         * The reference the gateway verified must be the one we lodged. Without
         * this, a verified payload for reference A could be applied to row B —
         * an attacker who can reach the callback with a reference they control
         * could otherwise choose which transaction gets credited.
         */
        $gatewayReference = (string) ($gatewayData['reference'] ?? '');

        if ($gatewayReference !== '' && ! in_array($gatewayReference, array_filter([
            $transaction->uuid,
            $transaction->reference,
            $transaction->api_reference,
            $transaction->payment_reference,
        ]), true)) {
            Log::critical('Paystack reference does not belong to this transaction — refusing to credit', [
                'transaction_id' => $transaction->id,
                'gateway_reference' => $gatewayReference,
            ]);

            return 'reference_mismatch';
        }

        /*
         * Currency. `Money` is NGN-only by construction, and the platform has no
         * FX. A non-NGN settlement is a configuration error or an attack, and in
         * either case crediting it at face value overpays by orders of magnitude.
         */
        $currency = strtoupper((string) ($gatewayData['currency'] ?? 'NGN'));

        if ($currency !== Money::CURRENCY) {
            Log::critical('Paystack currency mismatch — refusing to credit', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'expected_currency' => Money::CURRENCY,
                'paid_currency' => $currency,
            ]);

            $transaction->forceFill([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => "Currency mismatch: paid {$currency}, expected " . Money::CURRENCY . '.',
                'completed_at' => now(),
            ])->save();

            return 'currency_mismatch';
        }

        $paidKobo = (int) ($gatewayData['amount'] ?? 0);
        $expectedKobo = Money::fromDatabase($transaction->total_amount)->minor();

        if ($paidKobo !== $expectedKobo) {
            Log::critical('Paystack amount mismatch — refusing to credit', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'expected_kobo' => $expectedKobo,
                'paid_kobo' => $paidKobo,
            ]);

            $transaction->forceFill([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => 'Amount mismatch: paid '
                    . Money::fromMinor($paidKobo)->format() . ' expected '
                    . Money::fromMinor($expectedKobo)->format(),
                'completed_at' => now(),
            ])->save();

            return 'amount_mismatch';
        }

        if (($gatewayData['status'] ?? null) !== 'success') {
            $transaction->forceFill([
                'status' => 'failed',
                'payment_status' => 'failed',
                'status_message' => $gatewayData['gateway_response'] ?? 'Payment was not successful.',
                'api_response' => $gatewayData,
                'completed_at' => now(),
            ])->save();

            return 'failed';
        }

        return null;
    }

    /**
     * Pay the referrer if this funding qualified, without ever failing the
     * caller. A referral problem must not roll back a credited wallet.
     */
    private function rewardReferrer(User $user, Transactions $transaction): void
    {
        try {
            $this->affiliates->rewardIfEligible($user, $transaction);
        } catch (Throwable $e) {
            Log::error('Referral reward evaluation failed', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* =====================================================================
     | Dedicated Virtual Accounts
     |====================================================================
     | Paystack assigns one permanent NUBAN per customer. Every inbound
     | transfer to it arrives as a `charge.success` webhook with
     | `channel = dedicated_nuban`; because the payer types the number into
     | their own bank, there is no reference to match on — the receiver
     | account number is the only identifier, which is why it is stored on the
     | user and indexed.
     */

    /**
     * Resolve a Paystack customer code for this user, creating the customer if
     * needed.
     *
     * A DVA is tied to a Paystack customer, and the account name is built from
     * the customer's name, so it must be present before assignment.
     */
    public function ensureCustomer(User $user): string
    {
        if (! empty($user->paystack_customer_code)) {
            return $user->paystack_customer_code;
        }

        // Look the customer up by email first: a previous run may have created
        // one without us recording the code.
        $existing = $this->client()->get('https://api.paystack.co/customer/' . rawurlencode($user->email));

        if ($existing->successful() && ($existing->json('status') ?? false)) {
            $code = $existing->json('data.customer_code');

            if ($code) {
                $user->forceFill(['paystack_customer_code' => $code])->save();

                return $code;
            }
        }

        /*
         * `first_name` and `last_name` are read from the model, not guessed from
         * `name`, and `nameParts()` is the single place that decides what to send
         * when one is missing.
         *
         * Both are genuinely required for this integration, not merely nice:
         * Paystack's Customer API documentation makes `first_name`, `last_name`
         * and `phone` compulsory when the customer is going to be assigned a
         * dedicated virtual account and the business category is Betting,
         * Financial services or General Service — and ReUp is a wallet. The
         * dedicated-account error reference states the same thing from the other
         * side: "first_name, last_name are required in request body and must not
         * be empty".
         */
        [$firstName, $lastName] = $user->nameParts();

        /*
         * `formatted_phone`, not the raw column: it converts a local Nigerian
         * number (`08031234567`) to the `234…` form Paystack stores. Sending the
         * local form is accepted by customer creation but leaves a record the
         * dedicated-account endpoint then rejects as a missing phone, which is a
         * confusing failure much later in the flow.
         *
         * `array_filter` drops a null phone, so a customer created here without
         * one has *no* phone on the Paystack side. That is fine for a card, and
         * is exactly why `dedicatedAccount()` re-checks and pushes one before it
         * asks for an account.
         */
        $created = $this->client()->post('https://api.paystack.co/customer', array_filter([
            'email' => $user->email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $this->internationalPhone($user),
        ]));

        $body = $created->json() ?? [];

        if (! $created->successful() || ! ($body['status'] ?? false)) {
            throw new RuntimeException(
                'Could not register you with the payment provider: ' . ($body['message'] ?? 'unknown error')
            );
        }

        $code = $body['data']['customer_code'] ?? null;

        if (! $code) {
            throw new RuntimeException('The payment provider returned no customer code.');
        }

        $user->forceFill(['paystack_customer_code' => $code])->save();

        return $code;
    }

    /**
     * Create (or fetch) a dedicated virtual account for a customer.
     *
     * Paystack rejects a second assignment for a customer that already has one,
     * so the stored details are returned when present and the API is only
     * consulted when they are missing or a refresh is explicitly requested.
     *
     * ## The order of the three calls, and why it is this order
     *
     *   1. **Read back first.** `GET /dedicated_account?customer=…` answers "does
     *      this customer already have one?" without creating anything. A customer
     *      whose account exists on Paystack's side but not on ours — the row was
     *      rolled back, or the account was created by hand in their dashboard —
     *      is recovered here rather than being shown "you already have one".
     *   2. **Make the customer valid.** Paystack attaches the account to a
     *      *customer record* and refuses one whose record has no phone, or whose
     *      `first_name`/`last_name` are empty ("Customer phone number is
     *      required", "first_name, last_name are required in request body and
     *      must not be empty"). Both names and the phone are pushed to the
     *      existing record, because a record created during an earlier card
     *      payment has neither and `ensureCustomer()` will never revisit it — the
     *      code is already stored, so it returns early. That gap used to be
     *      permanent and surfaced much later, on a different page, as a provider
     *      error.
     *   3. **Assign.** `POST /dedicated_account` with the customer code and the
     *      preferred bank slug. The names and phone are sent again as the
     *      endpoint's own optional parameters: they are redundant with step 2 on
     *      a well-behaved account, and they are the difference between an account
     *      and a hard failure on one where step 2 was refused.
     *
     * ## The phone number
     *
     * We check for it **ourselves, first**, and raise
     * {@see PhoneNumberRequiredException} before spending a request, so the
     * customer gets a prompt they can act on instead of the gateway's internal
     * wording relayed back to them.
     *
     * @return array{account_number:string,bank_name:string,account_name:string}
     */
    public function dedicatedAccount(User $user, bool $refresh = false): array
    {
        if (! $refresh && $user->dva_account_number) {
            return [
                'account_number' => $user->dva_account_number,
                'bank_name' => $user->dva_bank_name,
                'account_name' => $user->dva_account_name,
            ];
        }

        $customerCode = $this->ensureCustomer($user);

        /*
         * The phone check happens before anything else, including the read-back:
         * it is the one failure the *customer* can fix, and telling them so is
         * more useful than any gateway answer about an account they cannot have.
         */
        if (! $this->hasPhoneForDedicatedAccount($user)) {
            throw new PhoneNumberRequiredException();
        }

        /*
         * The partner bank is resolved *first*, and not lazily at the create
         * call.
         *
         * It is a configuration check, and a misconfigured slug has to fail
         * before anything is asked of the gateway: Paystack's answer to a bank it
         * does not offer for this business is a message about a *bank*, which
         * sends whoever reads it looking for a bank problem that does not exist.
         * Deciding it up front means the failure names our own setting.
         */
        $bank = $this->dvaBank();

        /*
         * Read back before creating. Paystack refuses a second assignment for a
         * customer — and, depending on timing, answers with a message that merely
         * *contains* "already" — so asking first turns "you already have an
         * account, here it is" into the success it actually is rather than an
         * error the customer cannot act on.
         */
        $existing = $this->findDedicatedAccount($customerCode);

        if ($existing !== null) {
            return $this->storeDedicatedAccount($user, $existing, $bank);
        }

        [$firstName, $lastName] = $user->nameParts();

        // Names and phone onto the customer record, before it is asked for an
        // account. Idempotent on Paystack's side and skipped when already sent in
        // this request.
        $this->syncCustomerDetails($user, $customerCode);

        $response = $this->client()->post('https://api.paystack.co/dedicated_account', array_filter([
            'customer' => $customerCode,
            'preferred_bank' => $bank,
            // Sent as strings, never null: the endpoint rejects an empty value
            // but is happy to be told what it already knows.
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $this->internationalPhone($user),
        ]));

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            $message = (string) ($body['message'] ?? 'unknown error');

            /*
             * "Already assigned" is not an error. Paystack words it differently
             * depending on timing (`"Customer already has a dedicated account"`,
             * `"Assign dedicated account in progress"`), so this matches on
             * `already` anywhere in the message rather than on one phrasing.
             */
            if (stripos($message, 'already') !== false) {
                $fetched = $this->findDedicatedAccount($customerCode);

                if ($fetched !== null) {
                    return $this->storeDedicatedAccount($user, $fetched);
                }
            }

            /*
             * Access Denied is the one failure with an action attached to it, and
             * Paystack's error reference names that action: the business has not
             * been activated for dedicated virtual accounts, and support has to
             * enable it. It is raised as a distinguishable exception so
             * `paystack:diagnose-dva` and the operator log can say so, while the
             * customer still gets the same sanitised message as every other
             * provider failure.
             */
            if ($this->isDvaAccessDenied($response->status(), $message)) {
                throw new RuntimeException(
                    'Dedicated virtual accounts are not enabled on this Paystack business (Access Denied). '
                    . 'Ask Paystack support to activate "Dedicated Virtual Accounts" for the integration.',
                    403
                );
            }

            throw new RuntimeException('Could not generate a bank account: ' . $message);
        }

        return $this->storeDedicatedAccount($user, $body['data'] ?? [], $bank);
    }

    /**
     * The partner bank slug to ask for an account at.
     *
     * `PAYSTACK_DVA_BANK`, validated against what Paystack says this integration
     * actually supports (`GET /dedicated_account/available_providers`). The
     * documentation names Wema Bank and Titan Paystack as the supported
     * providers, but which of them a given business may use is Paystack's
     * decision, returned by that endpoint — so the configuration is checked
     * against the live list rather than against a hardcoded pair that goes stale.
     *
     * ## Why this is worth a cached round trip
     *
     * A wrong slug fails at the last step of a flow the customer has already
     * waited through, with `"<bank> is not available"` — a message about a bank,
     * for a problem in our configuration. Checking first turns that into one
     * clear sentence before any assignment is attempted. The list changes when
     * Paystack changes it, so a day of cache is plenty and costs one request.
     *
     * If the configured bank is not on the list and others are, the configured
     * one is still refused and the alternatives are named in the exception: a
     * silent fallback to a different bank would change where customers' money
     * settles, which is a commercial decision, not a repair.
     *
     * @throws RuntimeException when the configured bank is not available
     */
    public function dvaBank(): string
    {
        $configured = trim((string) config('services.paystack.dva_bank', 'wema-bank'));

        $providers = $this->availableDvaProviders();

        // An empty list means the question could not be answered (gateway down,
        // endpoint changed). Proceed with the configured value and let the
        // assignment call be the authority: refusing to try because a *diagnostic*
        // failed would turn a transient gateway problem into an outage.
        if ($providers === []) {
            return $configured;
        }

        $slugs = array_values(array_filter(array_map(
            fn ($provider) => is_array($provider) ? ($provider['slug'] ?? null) : null,
            $providers
        )));

        if (in_array($configured, $slugs, true)) {
            return $configured;
        }

        throw new RuntimeException(
            'PAYSTACK_DVA_BANK [' . $configured . '] is not an available dedicated-account provider on this '
            . 'Paystack business. Available: ' . (implode(', ', $slugs) ?: 'none')
            . '. Set PAYSTACK_DVA_BANK to one of them, or leave it unset for wema-bank.'
        );
    }

    /**
     * The dedicated-account providers this integration may use.
     *
     * @return array<int,mixed>
     */
    public function availableDvaProviders(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        return Cache::remember('paystack.dva.providers', now()->addDay(), function (): array {
            try {
                $response = $this->client()->get('https://api.paystack.co/dedicated_account/available_providers');

                if ($response->successful() && ($response->json('status') ?? false)) {
                    return (array) ($response->json('data') ?? []);
                }

                Log::warning('Could not list Paystack dedicated-account providers', [
                    'http_status' => $response->status(),
                    'message' => $response->json('message'),
                ]);
            } catch (Throwable $e) {
                Log::warning('Could not reach Paystack for the dedicated-account provider list', [
                    'error' => $e->getMessage(),
                ]);
            }

            // An empty list rather than null: `remember` treats null as a miss
            // and would retry the call on every page load.
            return [];
        });
    }

    /**
     * Is this response Paystack refusing the feature rather than the request?
     *
     * Both halves are checked because the two arrive separately in practice: the
     * status is a 403 when the business is not activated, and the wording is
     * "Access Denied" when the endpoint is answering with a plain error body.
     * Matched on either so a wording change on their side does not turn an
     * actionable configuration failure back into an anonymous one.
     */
    private function isDvaAccessDenied(int $status, string $message): bool
    {
        return $status === 403 || stripos($message, 'access denied') !== false;
    }

    /**
     * Look up an existing dedicated account for a customer code, without
     * creating one.
     *
     * Returns the active NGN account when there is one. `null` means "this
     * customer has none that we can see" — which includes the case where the
     * endpoint errored, deliberately: an unreachable gateway must not be read as
     * "no account exists", because the next thing the caller does is *create*
     * one, and Paystack rejects a duplicate. Returning null there would turn a
     * transient outage into a confusing provider error, which is why the status
     * is logged loudly.
     *
     * @return array<string,mixed>|null
     */
    public function findDedicatedAccount(string $customerCode, bool $logFailures = true): ?array
    {
        if ($customerCode === '') {
            return null;
        }

        try {
            $response = $this->client()->get('https://api.paystack.co/dedicated_account', [
                'customer' => $customerCode,
            ]);
        } catch (Throwable $e) {
            if ($logFailures) {
                Log::warning('Dedicated-account lookup failed', [
                    'customer_code' => $customerCode,
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        }

        $rows = $response->json('data') ?? [];

        // The endpoint returns a list. `accounts[]` appears nested inside a single
        // object on some responses, so both shapes are flattened.
        foreach ((array) $rows as $row) {
            foreach ($this->accountRows(is_array($row) ? $row : []) as $candidate) {
                if (($candidate['active'] ?? false) && ($candidate['currency'] ?? 'NGN') === 'NGN') {
                    return $candidate;
                }
            }
        }

        foreach ((array) $rows as $row) {
            foreach ($this->accountRows(is_array($row) ? $row : []) as $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalise the two shapes a dedicated-account payload arrives in.
     *
     * The Create and Fetch endpoints return the account object directly
     * (`data.account_number`, `data.bank.name`, `data.id`), while the List
     * endpoint returns a list of them and some responses wrap the same objects in
     * `data.accounts[]`. Rather than have every caller know which of the three it
     * is looking at, this yields the account objects one at a time.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array<string,mixed>>
     */
    private function accountRows(array $row): array
    {
        if (isset($row['account_number']) || isset($row['account_id'])) {
            return [$row];
        }

        $nested = $row['accounts'] ?? null;

        if (is_array($nested) && $nested !== []) {
            return array_values(array_filter($nested, 'is_array'));
        }

        return [];
    }

    /**
     * Fetch an existing dedicated account for a customer code.
     *
     * Kept as the public name used elsewhere in the application; the work is done
     * by {@see findDedicatedAccount()}, which additionally reports failures.
     *
     * @return array<string,mixed>|null
     */
    public function fetchDedicatedAccount(string $customerCode): ?array
    {
        return $this->findDedicatedAccount($customerCode);
    }

    /**

    /**
     * Does this account have a phone number a virtual account can be built on?
     *
     * Exposed so the controller can report the *condition* rather than catch the
     * exception: the caller needs to know this is an actionable gap in the
     * customer's own data, and asking the question is a clearer way to say that
     * than raising and catching an error for a state we can see coming.
     *
     * Deliberately the same accessor {@see internationalPhone()} uses, so the
     * answer and the value sent to the gateway cannot disagree — which is the
     * failure mode of spelling the rule out in the controller as well.
     */
    public function hasPhoneForDedicatedAccount(User $user): bool
    {
        return $this->internationalPhone($user) !== null;
    }

    /**
     * Guarantee both we and the gateway hold this customer's name and phone.
     *
     * Synchronous and deliberately before the assignment call: doing it lazily
     * would mean the first attempt fails, the customer retries, and the failure
     * and the fix are separated by an unexplained error.
     *
     * An empty payload — every field already present on the record — costs no
     * request at all, which is the common case for a customer created by this
     * release. Only the fields Paystack might be missing are sent, so this cannot
     * *remove* a value: `PUT /customer/{code}` merges, and a field that is absent
     * from the payload is left alone.
     */
    private function syncCustomerDetails(User $user, string $customerCode): void
    {
        // Already pushed in this request — `dedicatedAccount()` is called once per
        // page load, but a retry loop or a future caller should not re-send it.
        if ($this->detailsSyncedFor === $user->getKey()) {
            return;
        }

        $phone = $this->internationalPhone($user);

        if (! $user->paystack_details_synced_at) {
            $payload = array_filter([
                'first_name' => trim((string) $user->first_name),
                'last_name' => trim((string) $user->last_name),
                'phone' => $phone,
            ], fn ($value) => filled($value));

            /*
             * The record is only stamped as synced when Paystack accepted the
             * update. Stamping it regardless would be the worse bug of the two: a
             * refused update would be recorded as done, and the customer's record
             * would stay incomplete forever while every later page load skipped
             * the call that would have fixed it.
             */
            if ($payload !== [] && $this->updateCustomer($customerCode, $payload)) {
                $user->forceFill(['paystack_details_synced_at' => now()])->save();
            }
        }

        $this->detailsSyncedFor = $user->getKey();
    }

    /**
     * Push name and phone to an existing Paystack customer record.
     *
     * Used by the profile form, so that a customer who corrects their name or
     * adds a phone number does not keep a stale record at the gateway — the two
     * name fields are what the *account name* of a dedicated virtual account is
     * built from, so a correction there has to reach Paystack or the customer's
     * next account would be issued in the name they just told us was wrong.
     *
     * Safe to call for a customer that does not exist yet: the caller passes the
     * stored code, and there is nothing to do without one.
     */
    public function syncCustomerProfile(User $user): void
    {
        if (empty($user->paystack_customer_code) || ! $this->isConfigured()) {
            return;
        }

        $payload = array_filter([
            'first_name' => trim((string) $user->first_name),
            'last_name' => trim((string) $user->last_name),
            'phone' => $this->internationalPhone($user),
        ], fn ($value) => filled($value));

        if ($payload === []) {
            return;
        }

        $this->updateCustomer((string) $user->paystack_customer_code, $payload);

        $user->forceFill(['paystack_details_synced_at' => now()])->save();
    }

    /**
     * Attach details to an existing Paystack customer.
     *
     * `PUT /customer/{code}` merges, so only the fields given are touched. It is
     * idempotent — re-sending the same values is a no-op on their side — which is
     * what makes it safe to call on every attempt rather than tracking whether the
     * record already has them (a `GET /customer/{code}` per attempt to find out
     * would be a second round trip for no benefit).
     *
     * @param  array<string,string>  $payload
     * @return bool whether Paystack accepted the update
     */
    public function updateCustomer(string $customerCode, array $payload): bool
    {
        try {
            $response = $this->client()->put(
                'https://api.paystack.co/customer/' . rawurlencode($customerCode),
                $payload
            );
        } catch (Throwable $e) {
            // Logged, not thrown as a provider error: the assignment call that
            // follows is what decides the outcome, and it will fail with the
            // gateway's own wording if the details really are the blocker.
            // Throwing here would replace a specific, gateable failure with a
            // generic one.
            Log::warning('Could not update the Paystack customer record', [
                'customer_code' => $customerCode,
                'fields' => array_keys($payload),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            Log::warning('Could not update the Paystack customer record', [
                'customer_code' => $customerCode,
                'fields' => array_keys($payload),
                'http_status' => $response->status(),
                'message' => $body['message'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Attach a phone number to an existing Paystack customer.
     *
     * Kept because it is the narrow, well-named operation the profile form used
     * before names were pushed as well, and because a caller that only has a
     * phone should not have to build a payload to say so.
     */
    public function updateCustomerPhone(string $customerCode, string $phone): bool
    {
        return $this->updateCustomer($customerCode, ['phone' => $phone]);
    }

    /**
     * E.164 without the `+`, which is what Paystack stores.
     *
     * The `formatted_phone` accessor already does this conversion for the
     * customer-creation path; using it here rather than repeating the rule is
     * what stops the two disagreeing about what "0803…" means. A number that
     * does not look Nigerian is sent as digits, because guessing a country code
     * for a foreign number is worse than sending it and letting the gateway
     * reject it.
     */
    private function internationalPhone(User $user): ?string
    {
        $formatted = preg_replace('/\D/', '', (string) ($user->formatted_phone ?? ''));

        return $formatted === '' ? null : $formatted;
    }

    /**
     * Persist an assigned dedicated account onto the customer.
     *
     * ## What is read, and from which shape
     *
     * Paystack returns the account object directly from Create and Fetch
     * (`data.account_number`, `data.bank.name`, `data.id`) and wraps the same
     * objects in `data.accounts[]` in some responses. `accountRows()` handles
     * both, so the three access patterns that used to be spelled out here as
     * `??` chains — each of which had to know about the nesting — are now one
     * lookup.
     *
     * ## The account id and bank slug
     *
     * Both are stored because both are needed to talk about this account later:
     * Fetch Dedicated Account and Requery Dedicated Account address it by `id`,
     * and the slug is what a support request has to name when the question is
     * "can this customer be moved to the other partner bank?". Neither is used to
     * credit a transfer — that is `dva_account_number`, which is unique and must
     * stay that way.
     *
     * @param  array<string,mixed>  $data
     * @return array{account_number:string,bank_name:string,account_name:string}
     */
    private function storeDedicatedAccount(User $user, array $data, ?string $bankSlug = null): array
    {
        $account = $this->accountRows($data)[0] ?? $data;

        $accountNumber = (string) ($account['account_number'] ?? '');

        $bankName = (string) (
            data_get($account, 'bank.name')
            ?? (is_string($account['bank'] ?? null) ? $account['bank'] : '')
            ?? ''
        );

        $accountName = (string) ($account['account_name'] ?? '');

        if ($accountNumber === '') {
            throw new RuntimeException('The payment provider did not return an account number.');
        }

        $user->forceFill([
            'dva_account_number' => $accountNumber,
            'dva_bank_name' => $bankName,
            'dva_bank_slug' => data_get($account, 'bank.slug') ?: $bankSlug,
            'dva_account_name' => $accountName,
            'dva_account_id' => $account['id'] ?? null,
            'dva_created_at' => now(),
        ])->save();

        Log::info('Dedicated virtual account assigned', [
            'user_id' => $user->id,
            'bank' => $bankName,
            'bank_slug' => data_get($account, 'bank.slug') ?: $bankSlug,
            // The provider's own id, not a credential: it is what a support
            // request quotes to re-query the account.
            'account_id' => $account['id'] ?? null,
        ]);

        return [
            'account_number' => $accountNumber,
            'bank_name' => $bankName,
            'account_name' => $accountName,
        ];
    }

    /**
     * Find the user a dedicated-account transfer belongs to.
     *
     * Only the receiver account number identifies the customer, so the lookup
     * is deliberately exact — crediting the wrong wallet would be worse than
     * not crediting at all.
     */
    public function userForDedicatedAccount(string $accountNumber): ?User
    {
        return User::where('dva_account_number', $accountNumber)->first();
    }

    /**
     * Credit a wallet from an inbound bank transfer.
     *
     * Paystack sends one `charge.success` per deposit and retries until it gets
     * a 2xx, so this is idempotent on the provider's transaction reference.
     * Unlike card funding there is no pre-created transaction row, so one is
     * written here.
     *
     * @param  array<string,mixed>  $data  The webhook `data` payload.
     * @return array{status:string,transaction:?Transactions}
     */
    public function creditDedicatedAccountTransfer(array $data): array
    {
        $providerReference = (string) ($data['reference'] ?? '');
        $accountNumber = (string) data_get($data, 'authorization.receiver_bank_account_number', '');

        // Exact, from the gateway's own integer minor units.
        $amount = Money::fromMinor((int) ($data['amount'] ?? 0));

        if ($providerReference === '' || $accountNumber === '') {
            Log::warning('Dedicated-account transfer missing reference or receiver account', [
                'has_reference' => $providerReference !== '',
                'has_account' => $accountNumber !== '',
            ]);

            return ['status' => 'ignored', 'transaction' => null];
        }

        /*
         * Currency check first, before any lookup: a non-NGN transfer to a naira
         * account is either a gateway misconfiguration or an attempt to have a
         * small foreign amount credited as a large naira one.
         */
        $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));

        if ($currency !== Money::CURRENCY) {
            Log::critical('Dedicated-account transfer in an unexpected currency — refusing to credit', [
                'reference' => $providerReference,
                'currency' => $currency,
            ]);

            return ['status' => 'currency_mismatch', 'transaction' => null];
        }

        if (! $amount->isPositive()) {
            return ['status' => 'ignored', 'transaction' => null];
        }

        /*
         * Idempotency, inside the transaction and under a lock on the existing
         * row when there is one. The previous implementation checked outside the
         * transaction, which two concurrent retries could both pass.
         *
         * `provider` is stored lowercase ('paystack') across this codebase; the
         * comparison is case-insensitive so older capitalised rows still match.
         */
        return DB::transaction(function () use ($data, $providerReference, $amount, $accountNumber) {
            $existing = Transactions::whereRaw('LOWER(provider) = ?', ['paystack'])
                ->where('api_reference', $providerReference)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['status' => 'duplicate', 'transaction' => $existing];
            }

            $user = $this->userForDedicatedAccount($accountNumber);

            if (! $user) {
                Log::warning('Dedicated-account transfer for an unknown account number', [
                    'reference' => $providerReference,
                ]);

                return ['status' => 'unknown_account', 'transaction' => null];
            }

            $transaction = Transactions::create([
                'user_id' => $user->id,
                'reference' => $this->wallets->generateReference('DVA'),
                'type' => 'credit',
                'service_type' => 'funding',
                'description' => 'Bank transfer — ' . (data_get($data, 'authorization.sender_bank') ?: 'inbound transfer'),
                'amount' => $amount->toDecimalString(),
                'service_fee' => '0.00',
                'total_amount' => $amount->toDecimalString(),
                'recipient' => $accountNumber,
                'provider' => 'paystack',
                'payment_method' => 'bank_transfer',
                'payment_status' => 'success',
                'status' => 'success',
                'api_reference' => $providerReference,
                // Unique index on this column: a second credit for the same
                // transfer cannot be inserted.
                'payment_reference' => $providerReference,
                'api_response' => $data,
                'paid_at' => now(),
                'completed_at' => now(),
                'meta' => [
                    'channel' => 'dedicated_nuban',
                    'sender_name' => data_get($data, 'authorization.sender_name'),
                    'sender_bank' => data_get($data, 'authorization.sender_bank'),
                    'sender_account_number' => data_get($data, 'authorization.sender_bank_account_number'),
                    'narration' => data_get($data, 'authorization.narration'),
                    'settled_at' => now()->toDateTimeString(),
                ],
            ]);

            $movement = $this->wallets->credit(
                user: $user,
                amount: $amount,
                countsAsFunding: true,
                entryType: WalletLedger::ENTRY_CREDIT,
                description: 'Bank transfer received',
                transaction: $transaction,
                metadata: ['channel' => 'dedicated_nuban', 'gateway' => 'paystack'],
            );

            $transaction->forceFill([
                'balance_before' => $movement['balance_before'],
                'balance_after' => $movement['balance_after'],
                'meta' => array_merge($transaction->meta ?? [], [
                    'ledger_entry_id' => $movement['ledger']->id,
                ]),
            ])->save();

            Log::info('Wallet credited from dedicated-account transfer', [
                'user_id' => $user->id,
                'amount' => $amount->toDecimalString(),
                // The provider reference is an identifier, not a credential.
                'reference' => $providerReference,
            ]);

            /*
             * The service fee, on the same terms as card funding.
             *
             * A virtual-account transfer settles here rather than in
             * `creditFunding`, so the fee has to be collected in both places —
             * that is a consequence of the DVA having no per-transaction lookup
             * and therefore its own settlement path. `chargeFundingFee` is
             * idempotent, so the two can never both charge for one funding.
             */
            $this->chargeFundingFee($user, $transaction);

            // Bank transfers are a first-class funding route, so they qualify
            // for the referral reward exactly like a card payment.
            $this->rewardReferrer($user, $transaction);

            return ['status' => 'credited', 'transaction' => $transaction];
        });
    }

    /* =====================================================================
     | Admin reporting endpoints
     |=================================================================== */

    /**
     * Live gateway balance.
     *
     * Uses the HTTP client rather than the raw cURL block that previously
     * lived in PaystackController, and never logs key material.
     */
    public function balance(): array
    {
        return Cache::remember('paystack.balance', 300, function () {
            $response = $this->client()->get('https://api.paystack.co/balance');
            $body = $response->json() ?? [];

            if (! $response->successful() || ! ($body['status'] ?? false)) {
                Log::error('Failed to fetch Paystack balance', [
                    'http_status' => $response->status(),
                    'message' => $body['message'] ?? null,
                ]);

                return ['success' => false, 'message' => $body['message'] ?? 'Unable to reach Paystack.'];
            }

            $entry = $body['data'][0] ?? [];

            return [
                'success' => true,
                'currency' => $entry['currency'] ?? 'NGN',
                'amount' => ((int) ($entry['balance'] ?? 0)) / 100,
                'ledger_balance' => ((int) ($entry['ledger_balance'] ?? 0)) / 100,
                'fetched_at' => now()->toDateTimeString(),
            ];
        });
    }

    public function forgetBalanceCache(): void
    {
        Cache::forget('paystack.balance');
    }

    /**
     * Aggregate transaction totals for a period.
     */
    public function transactionTotals(string $period = 'today'): array
    {
        return Cache::remember('paystack.totals.' . $period, 300, function () use ($period) {
            $from = $period === 'today' ? now()->toDateString() : now()->subDays(30)->toDateString();

            $response = $this->client()->get('https://api.paystack.co/transaction/totals', [
                'from' => $from,
                'to' => now()->toDateString(),
            ]);

            $body = $response->json() ?? [];

            if (! $response->successful() || ! ($body['status'] ?? false)) {
                return ['success' => false, 'message' => $body['message'] ?? 'Unable to reach Paystack.'];
            }

            $data = $body['data'] ?? [];

            return [
                'success' => true,
                'period' => $period,
                'total_transactions' => $data['total_transactions'] ?? 0,
                'unique_customers' => $data['unique_customers'] ?? 0,
                'total_volume' => ((int) ($data['total_volume'] ?? 0)) / 100,
                'pending_transfers' => ((int) ($data['pending_transfers'] ?? 0)) / 100,
                'total_transfers' => ((int) ($data['total_transfers'] ?? 0)) / 100,
                'fetched_at' => now()->toDateTimeString(),
            ];
        });
    }
}
