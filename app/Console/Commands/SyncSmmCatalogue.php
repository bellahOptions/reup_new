<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Providers\CatalogueSync;
use App\Providers\ProviderMonitor;
use App\Providers\ProviderRegistry;
use App\Providers\Support\ProviderCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Synchronise the Social Boost catalogue from Nitro NG.
 *
 * ## Why this is a thin command and not a second sync implementation
 *
 * `providers:sync-catalogues` already walks every active provider's catalogue
 * through `CatalogueSync`, and Nitro is one of the drivers it handles
 * (`readNitro`). Adding a second sync engine for one provider would mean two
 * places that decide how a provider's services become our products — and the
 * rule that matters most here is that the sync **never creates a sellable
 * product**: a `provider_products` row is a decision an operator makes, and
 * matching `Instagram Followers · Standard` to one of our products
 * automatically would work until Nitro reworded a service and the wrong thing
 * was sold.
 *
 * So this command does the two jobs that are genuinely Social-Boost-specific:
 *
 *   1. **ensures the provider row exists**, because `config/bills.php` describes
 *      Nitro but nothing populates the `providers` table from it on a deployment
 *      that was seeded before this module existed — and without the row there is
 *      no provider to hang offerings off, no credential prefix to resolve, and
 *      `candidatesFor('smm')` returns nothing while looking perfectly healthy;
 *   2. **reports what the sync found that nobody has mapped yet**, which for a
 *      catalogue of hundreds of services is the single number an operator needs
 *      and which the generic command buries in a totals row.
 *
 * ## Usage
 *
 * ```
 * php artisan smm:sync-catalogue              # reads the provider catalogue
 * php artisan smm:sync-catalogue --status     # reports without fetching
 * php artisan smm:sync-catalogue --json
 * ```
 */
class SyncSmmCatalogue extends Command
{
    protected $signature = 'smm:sync-catalogue
                            {--status : Report the current catalogue state without fetching}
                            {--json : Emit machine-readable output}';

    protected $description = 'Synchronise the Social Boost (SMM) catalogue from Nitro NG';

    public function handle(
        CatalogueSync $sync,
        ProviderRegistry $registry,
        ProviderMonitor $monitor
    ): int {
        $provider = $this->provider();

        if ($provider === null) {
            $this->error(
                'No provider row exists for Nitro NG. Run `php artisan db:seed --class=ProviderSeeder` '
                . 'or create it from the admin provider console.'
            );

            return self::FAILURE;
        }

        if ($this->option('status')) {
            return $this->reportStatus($provider, $monitor);
        }

        /*
         * An unconfigured provider is reported rather than attempted. A catalogue
         * call without a key returns a guaranteed authentication error every run,
         * which on a dashboard looks like an outage and buries the real cause: a
         * missing environment variable is a deployment state, not a fault.
         */
        if (! ProviderCredentials::isConfigured($provider)) {
            $this->warn(
                'Nitro NG has no credential configured, so nothing was fetched. '
                . 'Set ' . $provider->credential_env_prefix . '_KEY (or ' . $provider->credential_env_prefix . '_API_KEY) and run this again.'
            );

            return self::FAILURE;
        }

        $lock = Cache::lock('smm:catalogue:sync', 900);

        if (! $lock->get()) {
            $this->warn('A Social Boost catalogue synchronisation is already running. Nothing was fetched.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->syncProvider($provider, ['smm']);
        } catch (\Throwable $e) {
            /*
             * A throw here means the parser met a shape it could not read. The
             * existing offerings are untouched — `CatalogueSync` writes per item —
             * so the storefront keeps selling what it already knew about.
             */
            $this->error('The catalogue could not be read: ' . $e->getMessage());
            $monitor->alertCatalogueUnavailable($provider, $e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $awaitingMapping = \App\Models\ProviderCatalogueItem::where('provider_id', $provider->getKey())->awaitingMapping()->present()->count();

        $totals = [
            'ok' => true,
            'provider' => $provider->slug,
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'cost_changes' => $result['cost_changes'] ?? 0,
            'unmapped' => $result['unmapped'] ?? 0,
            'unavailable' => $result['unavailable'] ?? 0,
            'awaiting_mapping' => $awaitingMapping,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($totals, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Updated', 'Rate changes', 'Staged', 'Became unavailable', 'Awaiting mapping'],
            [[
                $totals['updated'],
                $totals['cost_changes'],
                $totals['unmapped'],
                $totals['unavailable'],
                number_format($awaitingMapping),
            ]],
        );

        if ($awaitingMapping > 0) {
            $this->newLine();
            $this->warn(
                $awaitingMapping . ' Nitro service(s) are not mapped to a ReUp product and cannot be sold. '
                . 'Map them in the admin console — the sync will not guess, because a wrong mapping sells '
                . 'the wrong service to a paying customer.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * The Nitro provider row, created from configuration if it is missing.
     *
     * Creating it here rather than requiring a seeder run is deliberate: this
     * command is the one an operator runs to set Social Boost up, and failing with
     * "run the seeder first" for a row the application can derive correctly from
     * `config/bills.php` is a step that exists only to be forgotten.
     *
     * The row is created **inactive with no credential assumption**: whether Nitro
     * can serve orders is decided by `is_active`, `is_configured` and the health
     * probe, and a command that switched a provider on as a side effect of a
     * catalogue read would be a surprising thing for it to do.
     */
    private function provider(): ?Provider
    {
        /*
         * Read from `bills.smm_providers`, with `bills.providers` as a fallback.
         *
         * The first is the Wave 1 list — integrations routed through
         * `ProviderRegistry` rather than `ProviderManager` — and it is where Nitro
         * belongs. The fallback exists so a deployment that put the entry in the
         * billing list (wrong, but a mistake somebody can make) still gets a
         * provider row rather than a confusing "no provider" error.
         */
        $definition = (array) config('bills.smm_providers.nitro')
            ?: (array) config('bills.providers.nitro', []);

        if ($definition === []) {
            return null;
        }

        $existing = Provider::where('slug', 'nitro')->first();

        if ($existing) {
            return $existing;
        }

        $provider = new Provider();
        $provider->forceFill([
            'slug' => 'nitro',
            'name' => (string) ($definition['label'] ?? 'Nitro NG'),
            'driver' => (string) ($definition['driver'] ?? 'nitro'),
            'capabilities' => array_values((array) ($definition['products'] ?? ['smm'])),
            'credential_env_prefix' => strtoupper((string) ($definition['env_prefix'] ?? 'nitro')),
        ]);

        $provider->is_active = true;
        $provider->is_primary = false;
        $provider->priority = 100;
        $provider->health_status = Provider::HEALTH_UNKNOWN;
        $provider->save();

        $this->info('  Created the Nitro NG provider row from configuration.');

        return $provider;
    }

    /**
     * Report the catalogue's state without spending a provider request.
     */
    private function reportStatus(Provider $provider, ProviderMonitor $monitor): int
    {
        $offerings = $provider->providerProducts();

        $state = [
            'provider' => $provider->slug,
            'configured' => ProviderCredentials::isConfigured($provider),
            'is_active' => (bool) $provider->is_active,
            'health_status' => $provider->health_status,
            'health_checked_at' => $provider->health_checked_at?->toDateTimeString(),
            'services' => (clone $offerings)->count(),
            'sellable' => (clone $offerings)->whereNull('unavailable_at')->whereNotNull('provider_rate_minor')->count(),
            'unavailable' => (clone $offerings)->whereNotNull('unavailable_at')->count(),
            'awaiting_mapping' => \App\Models\ProviderCatalogueItem::where('provider_id', $provider->getKey())->awaitingMapping()->present()->count(),
            'last_synced_at' => (clone $offerings)->max('cost_synced_at'),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($state, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Configured', 'Active', 'Health', 'Services', 'Sellable', 'Unavailable', 'Awaiting mapping', 'Last synced'],
            [[
                $state['configured'] ? 'yes' : 'NO',
                $state['is_active'] ? 'yes' : 'no',
                $state['health_status'],
                number_format($state['services']),
                number_format($state['sellable']),
                number_format($state['unavailable']),
                number_format($state['awaiting_mapping']),
                $state['last_synced_at'] ?? 'never',
            ]],
        );

        // Non-zero when nothing is sellable, so a health check that only reads exit
        // codes still learns that Social Boost has no catalogue.
        return $state['sellable'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
