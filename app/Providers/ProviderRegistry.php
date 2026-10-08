<?php

namespace App\Providers;

use App\Models\Provider;
use App\Providers\Adapters\NitroSmmProvider;
use App\Providers\Adapters\SogoProvider;
use App\Providers\Adapters\VtpassProvider;
use App\Providers\Adapters\VtugateProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resolves the configured adapters for a capability.
 *
 * ## Why this is separate from ProviderManager
 *
 * `ProviderManager` routes the five products that ClubKonnect and Pairgate
 * already vend, and it works. Rewriting it to also carry Wave 1 would put the
 * live vending path at risk to add new products, which is exactly the trade the
 * brief forbids. So the two coexist:
 *
 *   * `ProviderManager` continues to serve airtime, data, cable, electricity,
 *     exam and betting exactly as it does today;
 *   * `ProviderRegistry` serves the Wave 1 capabilities from database-configured
 *     providers, ordered by the Super Admin's priority.
 *
 * A product is served by exactly one of them, chosen by the order service, so
 * there is no ambiguity about which provider a given sale used.
 *
 * ## Priority and preference
 *
 * Ordering comes from `providers.priority` (lower first) with the `is_primary`
 * flag breaking ties toward the operator's stated preference. That is what makes
 * "move Sogo ahead of ClubKonnect for this category" an admin action rather than
 * a deploy.
 */
class ProviderRegistry
{
    /**
     * Driver slug → adapter class.
     *
     * This is the complete set of integrations this build ships. A provider row
     * naming a driver absent from this map is skipped rather than fatal, so a
     * decommissioned integration disables itself visibly instead of taking the
     * storefront down.
     *
     * The map is the whole list: a driver that is not named here cannot be
     * reached, however a `providers` row is configured. Adding one is a code
     * change with a review, which is the point.
     */
    private const ADAPTERS = [
        'sogo' => SogoProvider::class,
        'vtugate' => VtugateProvider::class,
        'vtpass' => VtpassProvider::class,
        'nitro' => NitroSmmProvider::class,
    ];

    /** @var array<string, AbstractProviderAdapter> */
    private array $resolved = [];

    /**
     * Whether an adapter class exists for a provider's declared driver.
     *
     * A provider row naming a driver this build does not ship is skipped rather
     * than fatal — the same reasoning as an unknown entry in
     * `BILL_PROVIDER_ORDER`: a decommissioned integration should disable itself
     * visibly, not take the storefront down.
     */
    public function hasAdapter(string $driver): bool
    {
        return isset(self::ADAPTERS[$driver]);
    }

    /**
     * Resolve the adapter for a provider row.
     */
    public function adapterFor(Provider $provider): AbstractProviderAdapter
    {
        $cacheKey = $provider->slug . ':' . $provider->id;

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $class = self::ADAPTERS[$provider->driver] ?? null;

        if ($class === null) {
            throw new RuntimeException(
                "Provider [{$provider->slug}] declares driver [{$provider->driver}], "
                . 'which this build has no adapter for.'
            );
        }

        return $this->resolved[$cacheKey] = new $class($provider);
    }

    /** Forget resolved adapters, so a config change in a long-running worker is picked up. */
    public function flush(): void
    {
        $this->resolved = [];
    }

    /* =====================================================================
     | Capability routing
     |=================================================================== */

    /**
     * Providers that can serve a capability, in preference order.
     *
     * Only providers that are active *and* configured are returned. A provider
     * whose credentials are absent is skipped silently — that is a valid
     * deployment, not an error — but it is never selected, because selecting it
     * would produce a guaranteed failure for a customer.
     *
     * @return array<int, AbstractProviderAdapter>
     */
    public function candidatesFor(string $capability): array
    {
        $providers = $this->configuredProvidersFor($capability);

        $adapters = [];

        foreach ($providers as $provider) {
            $adapter = $this->adapterFor($provider);

            /*
             * The database capability list is the authority for routing, but the
             * adapter's own list is checked too: a capability the adapter cannot
             * implement must never be routed to it, however the row is
             * configured. Two locks, because this one decides where a customer's
             * money goes.
             */
            if (! $adapter->supports($capability)) {
                continue;
            }

            /*
             * Configured is not the same as usable. An adapter whose integration is
             * incomplete — valid credentials, but request field names that no
             * published document confirms — must not receive an order, because the
             * failure mode is a top-up sent to the wrong recipient rather than an
             * error we can see. Skipping it here means the next candidate serves the
             * order instead, which is the whole point of a fallback chain.
             */
            if (! $adapter->isOperational()) {
                Log::info('Skipping a configured but non-operational provider', [
                    'provider' => $adapter->slug(),
                    'capability' => $capability,
                    'reason' => $adapter->operationalReason(),
                ]);

                continue;
            }

            $adapters[] = $adapter;
        }

        return $adapters;
    }

    /**
     * The provider rows for a capability, in preference order.
     *
     * @return Collection<int, Provider>
     */
    public function configuredProvidersFor(string $capability): Collection
    {
        if (! $this->driverIsRoutable($capability)) {
            return collect();
        }

        return Provider::query()
            ->where('is_active', true)
            ->whereJsonContains('capabilities', $capability)
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (Provider $provider) => $this->hasAdapter($provider->driver))
            ->filter(fn (Provider $provider) => $this->adapterFor($provider)->isConfigured())
            ->filter(fn (Provider $provider) => $this->adapterFor($provider)->isOperational())
            ->values();
    }

    /**
     * The preferred provider for a capability, or null when none is usable.
     *
     * Used for read-only work (a catalogue display, a verification) where
     * failover is unnecessary because nothing has been charged.
     */
    public function preferredFor(string $capability): ?AbstractProviderAdapter
    {
        return $this->candidatesFor($capability)[0] ?? null;
    }

    /**
     * Whether any *adapter in this build* could ever serve a capability.
     *
     * A capability with no adapter at all is not routable no matter how the
     * database is configured. This is what stops an admin adding `esim` to a
     * provider row and having orders routed into an adapter that has no endpoint
     * for it.
     */
    public function driverIsRoutable(string $capability): bool
    {
        return in_array($capability, $this->routableCapabilities(), true);
    }

    /**
     * Every capability any shipped adapter declares.
     *
     * The admin console offers this list rather than free text, so a capability
     * token that no adapter implements cannot be saved onto a provider row.
     *
     * @return array<int, string>
     */
    public function routableCapabilities(): array
    {
        /*
         * Read from configuration rather than from adapter instances. The
         * capability list is configuration-derived in every adapter, so asking
         * the config directly avoids constructing an adapter (which requires a
         * persisted provider row) just to enumerate static facts — and it keeps
         * this method usable from a form request that has no row yet.
         */
        $capabilities = [];

        foreach (array_keys(self::ADAPTERS) as $key) {
            foreach ((array) config("providers.{$key}.capabilities", []) as $capability) {
                $capabilities[] = (string) $capability;
            }
        }

        sort($capabilities);

        return array_values(array_unique($capabilities));
    }
}
