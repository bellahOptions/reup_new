<?php

namespace App\Services;

use App\Services\Providers\BillProvider;
use App\Services\Providers\ClubKonnectProvider;
use App\Services\Providers\PayvesselProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Chooses an upstream for a product, and fails over between them.
 *
 * Selection order for a product:
 *   1. providers that are configured and declare support, in configured order;
 *   2. of those, skip any whose float cannot cover the charge (see
 *      ProviderBalanceService) — this is the "confirm the admin balance first"
 *      rule, and it prevents the most common real-world failure, which is a
 *      successful customer debit followed by an upstream INSUFFICIENT_BALANCE;
 *   3. the first provider that actually accepts the request wins.
 *
 * Failure classification matters: only *provider-side* failures are retried
 * elsewhere. A validation error (bad meter number) or a duplicate reference is
 * returned to the caller immediately, because retrying it on a second provider
 * would either fail identically or double-charge.
 */
class ProviderManager
{
    /** @var array<string, BillProvider> */
    private array $providers;

    public function __construct(
        ClubKonnectProvider $clubKonnect,
        PayvesselProvider $payvessel,
        private readonly ProviderBalanceService $balances,
    ) {
        $this->providers = [
            $clubKonnect->name() => $clubKonnect,
            $payvessel->name() => $payvessel,
        ];
    }

    /**
     * Providers eligible for a product, best first.
     *
     * @return array<int, BillProvider>
     */
    public function candidates(string $product): array
    {
        $order = (array) config('bills.provider_order', array_keys($this->providers));

        $eligible = [];

        foreach ($order as $name) {
            $provider = $this->providers[$name] ?? null;

            if ($provider && $provider->isConfigured() && $provider->supports($product)) {
                $eligible[] = $provider;
            }
        }

        if (! $eligible) {
            throw new RuntimeException(
                "No configured provider supports [{$product}]. Check PAYVESSEL_* / CLUBKONNECT_* in .env."
            );
        }

        return $eligible;
    }

    /**
     * Read-only lookups (verifying a meter, a smartcard, a betting account)
     * should not be refused for float reasons, so they use the first configured
     * provider and do not consult balances.
     */
    public function verifier(string $product): BillProvider
    {
        return $this->candidates($product)[0];
    }

    /**
     * Providers that can fund this charge right now.
     *
     * @return array<int, BillProvider>
     */
    public function affordable(string $product, float $amount): array
    {
        $candidates = $this->candidates($product);

        if (! (bool) config('bills.check_provider_balance', true)) {
            return $candidates;
        }

        $affordable = [];

        foreach ($candidates as $provider) {
            if ($this->balances->canCover($provider, $amount)) {
                $affordable[] = $provider;
            }
        }

        // If balances cannot be read at all, do not block the sale — the
        // upstream will reject it and the pipeline refunds. Blocking here would
        // take the platform down whenever a balance endpoint hiccups.
        if (! $affordable) {
            Log::warning('No provider reported sufficient float; attempting primary anyway', [
                'product' => $product,
                'amount' => $amount,
                'candidates' => array_map(fn ($p) => $p->name(), $candidates),
            ]);

            return $candidates;
        }

        return $affordable;
    }

    public function byName(string $name): ?BillProvider
    {
        return $this->providers[$name] ?? null;
    }

    /** @return array<int, BillProvider> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * Classify a provider response.
     *
     * @return string one of: success | retryable | fatal
     */
    public function classify(BillProvider $provider, ?array $response): string
    {
        if ($provider->isSuccess($response)) {
            return 'success';
        }

        $status = strtoupper((string) ($response['status'] ?? ''));

        // Conditions another provider may not share.
        $retryable = [
            'INSUFFICIENT_BALANCE',
            'API_ERROR',
            'EXCEPTION',
            'INVALID_RESPONSE',
            'NOT_CONFIGURED',
            'SERVICE_UNAVAILABLE',
            'TIMEOUT',
        ];

        return in_array($status, $retryable, true) ? 'retryable' : 'fatal';
    }
}
