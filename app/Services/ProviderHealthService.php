<?php

namespace App\Services;

use App\Services\Providers\BillProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Whether an upstream is actually answering.
 *
 * The requirement this exists for: a customer must not be debited for a vend the
 * provider was never going to be able to perform. The float check
 * (ProviderBalanceService) answers "is there enough money upstream"; this answers
 * the question that comes first — "is the upstream there at all, and does it
 * still accept our credentials".
 *
 * Three deliberate choices:
 *
 *   * a provider's *cheapest authenticated* endpoint is probed (a wallet
 *     enquiry), because a probe that costs money or time is worse than none;
 *   * the answer is cached for a minute — long enough that a busy hour does not
 *     turn into a probe per purchase, short enough that a recovery is noticed
 *     while an operator is still watching; it is thrown away the moment a real
 *     request fails, so a stale "available" cannot linger;
 *   * a failed probe is believed. If the upstream cannot answer a balance
 *     enquiry, sending it a purchase and refunding afterwards is strictly worse
 *     than telling the customer the service is unavailable now.
 */
class ProviderHealthService
{
    /** Seconds a probe result is trusted. */
    private const TTL = 60;

    public function cacheKey(BillProvider $provider): string
    {
        return 'provider.health.' . $provider->name();
    }

    /**
     * @return array{available:bool,checked_at:?string}
     */
    public function get(BillProvider $provider, bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget($this->cacheKey($provider));
        }

        return Cache::remember($this->cacheKey($provider), self::TTL, function () use ($provider) {
            $available = $provider->ping();

            Log::info('Provider availability checked', [
                'provider' => $provider->name(),
                'available' => $available,
            ]);

            return [
                'available' => $available,
                'checked_at' => now()->toDateTimeString(),
            ];
        });
    }

    /**
     * Whether a purchase may be sent to this provider.
     *
     * Returns true when health checking is switched off, so the switch is a real
     * opt-out rather than a silent "everything is down".
     */
    public function isAvailable(BillProvider $provider): bool
    {
        if (! (bool) config('bills.check_provider_health', true)) {
            return true;
        }

        return (bool) ($this->get($provider)['available'] ?? true);
    }

    /**
     * Drop the cached answer so the next check re-probes. Called after a real
     * request failed, where the cache is the last thing we should trust.
     */
    public function invalidate(BillProvider $provider): void
    {
        Cache::forget($this->cacheKey($provider));
    }
}
