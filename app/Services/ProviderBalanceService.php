<?php

namespace App\Services;

use App\Services\Providers\BillProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Upstream float checks.
 *
 * The requirement is that the platform never debits a customer for a vend it
 * cannot fulfil. Two checks are needed:
 *
 *   1. the *customer's* wallet must cover the charge — WalletService enforces
 *      this under a row lock, before anything else happens;
 *   2. the *provider's* float must cover it too, or the upstream will accept the
 *      debit and then reject the vend, leaving a refund to clean up.
 *
 * Balances are cached briefly because they are read on every purchase and the
 * endpoints are slow and rate-limited upstream. A short TTL plus an explicit
 * invalidation after a purchase keeps the figure honest without hammering the
 * provider.
 */
class ProviderBalanceService
{
    /** Seconds a cached balance stays usable. */
    private const TTL = 120;

    /**
     * Safety margin: do not spend a provider down to exactly zero, since fees
     * and rounding mean the real cost drifts above the quoted amount.
     */
    private const HEADROOM = 500.0;

    public function cacheKey(BillProvider $provider): string
    {
        return 'provider.balance.' . $provider->name();
    }

    /**
     * @return array{success:bool,balance:float,currency:string,message?:string}
     */
    public function get(BillProvider $provider, bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget($this->cacheKey($provider));
        }

        // Providers cache internally too; this outer layer protects callers
        // that hit several codepaths in one request.
        return Cache::remember($this->cacheKey($provider), self::TTL, function () use ($provider) {
            $result = $provider->balance();

            if (! ($result['success'] ?? false)) {
                Log::warning('Provider balance unavailable', [
                    'provider' => $provider->name(),
                    'message' => $result['message'] ?? null,
                ]);
            }

            return $result;
        });
    }

    /**
     * Whether a provider can fund a charge.
     *
     * Returns true when the balance cannot be determined: an unreadable balance
     * is a monitoring problem, not proof of insufficient funds, and failing
     * closed here would take vending offline whenever the provider's balance
     * endpoint misbehaves.
     */
    public function canCover(BillProvider $provider, float $amount): bool
    {
        if (! (bool) config('bills.check_provider_balance', true)) {
            return true;
        }

        $result = $this->get($provider);

        if (! ($result['success'] ?? false)) {
            return true;
        }

        $needed = $amount + (float) config('bills.provider_headroom', self::HEADROOM);

        if ($result['balance'] < $needed) {
            Log::warning('Provider float below required amount', [
                'provider' => $provider->name(),
                'balance' => $result['balance'],
                'required' => $needed,
            ]);

            return false;
        }

        return true;
    }

    /** Drop the cache after a successful vend so the next check re-reads it. */
    public function invalidate(BillProvider $provider): void
    {
        Cache::forget($this->cacheKey($provider));
    }

    /**
     * Every provider's float, for the admin dashboard.
     *
     * @param  array<int, BillProvider>  $providers
     * @return array<int, array<string, mixed>>
     */
    public function all(array $providers): array
    {
        return array_map(fn (BillProvider $p) => [
            'name' => $p->name(),
            'label' => $p->label(),
            'configured' => $p->isConfigured(),
        ] + $this->get($p), $providers);
    }
}
