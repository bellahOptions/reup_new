<?php

namespace App\Services;

use App\Models\Transactions;
use App\Models\User;
use App\Services\Providers\BillProvider;
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
     * @return array{ok:bool,transaction:?Transactions,message:string,provider:?string}
     */
    public function purchase(
        User $user,
        string $product,
        float $amount,
        ?float $fee,
        string $recipient,
        string $providerLabel,
        string $description,
        array $meta,
        callable $dispatch,
        string $successMessage,
        ?string $pin = null,
        ?string $idempotencyKey = null,
    ): array {
        $fee = round((float) ($fee ?? 0), 2);
        $total = round($amount + $fee, 2);

        if ($total <= 0) {
            throw new RuntimeException('Transaction amount must be greater than zero.');
        }

        // ---- Replay protection -------------------------------------------
        if ($idempotencyKey && ($original = $this->security->replay($idempotencyKey))) {
            return [
                'ok' => $original->status === 'success',
                'transaction' => $original,
                'message' => 'This purchase was already submitted.',
                'provider' => data_get($original->meta, 'provider'),
            ];
        }

        // ---- Security gates, before any lock is taken ---------------------
        $this->security->verifyPin($user, $pin);
        $this->security->assertNotThrottled($user);
        $this->security->assertWithinLimits($user, $total, $product);
        $this->security->assertNotDuplicate($user, $product, $total, $recipient);

        // ---- Customer balance --------------------------------------------
        $wallet = $this->wallets->forUser($user);
        $balance = (float) $wallet->balance;

        if ($balance < $total) {
            throw new RuntimeException(
                'Insufficient wallet balance. You need ₦' . number_format($total, 2)
                . ' but your balance is ₦' . number_format($balance, 2) . '.'
            );
        }

        // ---- Provider float ----------------------------------------------
        $candidates = $this->providers->affordable($product, $total);

        if (! $candidates) {
            throw new RuntimeException(
                'This service is temporarily unavailable. No refund has been made and no money has left your wallet.'
            );
        }

        // ---- Debit under a row lock --------------------------------------
        $transaction = DB::transaction(function () use ($user, $product, $amount, $fee, $total, $recipient, $providerLabel, $description, $meta) {
            $movement = $this->wallets->debit($user, $total);

            return Transactions::create([
                'user_id' => $user->id,
                'reference' => $this->wallets->generateReference('TXN'),
                'type' => 'debit',
                'service_type' => $product,
                'description' => $description,
                'amount' => $amount,
                'service_fee' => $fee,
                'total_amount' => $total,
                'balance_before' => $movement['balance_before'],
                'balance_after' => $movement['balance_after'],
                'recipient' => $recipient,
                'provider' => $providerLabel,
                'payment_method' => 'wallet',
                'payment_status' => 'success',
                'status' => 'processing',
                'paid_at' => now(),
                'meta' => $meta + ['requested_provider' => $providerLabel],
            ]);
        });

        if ($idempotencyKey) {
            $this->security->remember($idempotencyKey, $transaction);
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

                    $this->sendReceipts($user, $transaction);

                    return [
                        'ok' => true,
                        'transaction' => $transaction,
                        'message' => $successMessage,
                        'provider' => $provider->name(),
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
                $lastError = 'Provider error: ' . $e->getMessage();

                $attempts[] = [
                    'provider' => $provider->name(),
                    'outcome' => 'exception',
                    'message' => $e->getMessage(),
                ];

                Log::error('Provider threw during purchase', [
                    'transaction_id' => $transaction->id,
                    'provider' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);

                // An exception may mean the request was received and processed.
                // Failing over risks a double vend, so stop and refund.
                break;
            }
        }

        // ---- Every provider failed: refund -------------------------------
        $this->refund($transaction, $lastError);

        $transaction->forceFill([
            'status' => 'failed',
            'payment_status' => 'refunded',
            'status_message' => $lastError,
            'completed_at' => now(),
            'meta' => array_merge($transaction->meta ?? [], ['provider_attempts' => $attempts]),
        ])->save();

        return [
            'ok' => false,
            'transaction' => $transaction,
            'message' => $lastError . ' Your wallet has been refunded in full.',
            'provider' => null,
        ];
    }

    private function markSuccess(Transactions $transaction, BillProvider $provider, ?array $response): void
    {
        $token = $provider->issuedToken($response);

        $transaction->forceFill([
            'status' => 'success',
            'payment_status' => 'success',
            'provider' => $provider->label(),
            'api_reference' => $provider->orderReference($response),
            'api_response' => $response,
            'completed_at' => now(),
            'meta' => array_merge($transaction->meta ?? [], array_filter([
                'provider' => $provider->name(),
                'token' => $token,
            ], fn ($v) => $v !== null)),
        ])->save();
    }

    /* =====================================================================
     | Refunds
     |=================================================================== */

    /**
     * Return money for a failed purchase and record a linked credit.
     */
    public function refund(Transactions $transaction, string $reason): void
    {
        try {
            $user = $transaction->user;

            if (! $user) {
                return;
            }

            DB::transaction(function () use ($user, $transaction, $reason) {
                $movement = $this->wallets->credit($user, (float) $transaction->total_amount, countsAsFunding: false);

                Transactions::create([
                    'user_id' => $user->id,
                    'reference' => $this->wallets->generateReference('RFND'),
                    'type' => 'credit',
                    'service_type' => 'refund',
                    'description' => 'Refund — ' . Str::limit($reason, 120),
                    'amount' => $transaction->total_amount,
                    'service_fee' => 0,
                    'total_amount' => $transaction->total_amount,
                    'balance_before' => $movement['balance_before'],
                    'balance_after' => $movement['balance_after'],
                    'payment_method' => 'wallet',
                    'payment_status' => 'success',
                    'status' => 'success',
                    'completed_at' => now(),
                    'meta' => ['refunded_transaction_id' => $transaction->id],
                ]);
            });
        } catch (Throwable $e) {
            // Never mask the original failure, but make this loud: a customer is
            // out of pocket until somebody intervenes.
            Log::critical('Refund failed — manual intervention required', [
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'amount' => (float) $transaction->total_amount,
                'error' => $e->getMessage(),
            ]);
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
