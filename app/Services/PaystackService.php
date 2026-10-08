<?php

namespace App\Services;

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
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AffiliateService $affiliates,
    ) {
    }

    public function isConfigured(): bool
    {
        return ! empty(config('services.paystack.secret_key'));
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

        $response = $this->client()->post('https://api.paystack.co/transaction/initialize', [
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
        ]);

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
                    'channel' => $gatewayData['channel'] ?? null,
                    'gateway' => 'paystack',
                ],
            );

            $locked->forceFill([
                'status' => 'success',
                'payment_status' => 'success',
                'balance_before' => $movement['balance_before'],
                'balance_after' => $movement['balance_after'],
                'paid_at' => now(),
                'completed_at' => now(),
                'api_response' => $gatewayData,
                'payment_reference' => $locked->payment_reference
                    ?: (string) ($gatewayData['reference'] ?? $locked->gatewayReference()),
                'meta' => array_merge($locked->meta ?? [], [
                    'channel' => $gatewayData['channel'] ?? null,
                    'gateway_response' => $gatewayData['gateway_response'] ?? null,
                    'currency' => $gatewayData['currency'] ?? null,
                    'settled_at' => now()->toDateTimeString(),
                    'ledger_entry_id' => $movement['ledger']->id,
                ]),
            ])->save();

            /*
             * Referral reward, after the funding is committed above.
             *
             * Deliberately placed here rather than in the webhook controller so
             * it fires for every route that settles funding — webhook, browser
             * callback and the payments:reconcile sweep all pass through settle().
             * It is idempotent and swallows its own duplicate-key races, so a
             * failure here cannot cost the customer their credit.
             */
            $this->rewardReferrer($user, $locked);

            return ['status' => 'settled', 'transaction' => $locked];
        });
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

        [$first, $last] = $this->splitName((string) $user->name);

        $created = $this->client()->post('https://api.paystack.co/customer', array_filter([
            'email' => $user->email,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $user->phone,
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

        $response = $this->client()->post('https://api.paystack.co/dedicated_account', [
            'customer' => $customerCode,
            'preferred_bank' => config('services.paystack.dva_bank', 'wema-bank'),
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            $message = $body['message'] ?? 'unknown error';

            // Already assigned is not an error: read the existing account back.
            if (stripos($message, 'already') !== false) {
                $existing = $this->fetchDedicatedAccount($customerCode);

                if ($existing) {
                    return $this->storeDedicatedAccount($user, $existing);
                }
            }

            throw new RuntimeException('Could not generate a bank account: ' . $message);
        }

        return $this->storeDedicatedAccount($user, $body['data'] ?? []);
    }

    /**
     * Fetch an existing dedicated account for a customer code.
     *
     * @return array<string,mixed>|null
     */
    public function fetchDedicatedAccount(string $customerCode): ?array
    {
        $response = $this->client()->get('https://api.paystack.co/dedicated_account', [
            'customer' => $customerCode,
        ]);

        $rows = $response->json('data') ?? [];

        // The endpoint returns a list; take the active NGN account.
        foreach ((array) $rows as $row) {
            if (($row['active'] ?? false) && ($row['currency'] ?? 'NGN') === 'NGN') {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{account_number:string,bank_name:string,account_name:string}
     */
    private function storeDedicatedAccount(User $user, array $data): array
    {
        $accountNumber = (string) (
            $data['account_number']
            ?? data_get($data, 'accounts.0.account_number')
            ?? ''
        );

        $bankName = (string) (
            data_get($data, 'bank.name')
            ?? data_get($data, 'accounts.0.bank.name')
            ?? data_get($data, 'bank')
            ?? ''
        );

        $accountName = (string) (
            $data['account_name']
            ?? data_get($data, 'accounts.0.account_name')
            ?? ''
        );

        if ($accountNumber === '') {
            throw new RuntimeException('The payment provider did not return an account number.');
        }

        $user->forceFill([
            'dva_account_number' => $accountNumber,
            'dva_bank_name' => $bankName,
            'dva_account_name' => $accountName,
            'dva_created_at' => now(),
        ])->save();

        Log::info('Dedicated virtual account assigned', [
            'user_id' => $user->id,
            'bank' => $bankName,
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

            // Bank transfers are a first-class funding route, so they qualify
            // for the referral reward exactly like a card payment.
            $this->rewardReferrer($user, $transaction);

            return ['status' => 'credited', 'transaction' => $transaction];
        });
    }

    /** Split a full name into first and last, for Paystack's customer record. */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? 'ReUp';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Customer';

        return [$first, $last];
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
