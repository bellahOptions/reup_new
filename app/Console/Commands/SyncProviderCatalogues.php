<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Providers\CatalogueSync;
use App\Providers\ProviderRegistry;
use Illuminate\Console\Command;

/**
 * Refresh our cost figures from every provider's catalogue.
 *
 * ## The distinction this command exists to preserve
 *
 * It updates **costs**; it never creates a **product**. A `provider_products` row is
 * a decision that we sell a particular thing from a particular provider, and a
 * catalogue sync cannot make that decision from a provider's display text. Anything
 * it does not recognise is staged in `provider_catalogue_items` for an operator to
 * map.
 *
 * The reason is not purity. A fuzzy match of `MTN 1GB Monthly` against our own
 * product names would work on the day it was written and sell the wrong bundle the
 * first time a provider reworded a plan — and the customer is charged for something
 * they did not choose.
 *
 * ## Idempotency
 *
 * Every write is keyed by a unique index, so running this twice changes
 * `last_seen_at` and nothing else. An overlapping or retried run is harmless.
 *
 * ## History is never rewritten
 *
 * `provider_products.provider_cost_minor` is current state and is updated. Each
 * change also appends a `provider_price_snapshots` row, and `pricing_snapshots` — the
 * price a customer actually agreed to — is never touched. Repricing affects the
 * future and cannot affect a completed sale.
 *
 * ## Usage
 *
 * ```
 * php artisan providers:sync-catalogues
 * php artisan providers:sync-catalogues --provider=sogo --capability=data
 * ```
 *
 * Exits non-zero when any provider's catalogue could not be read, so a scheduler
 * that only checks exit codes still learns about it.
 */
class SyncProviderCatalogues extends Command
{
    protected $signature = 'providers:sync-catalogues
                            {--provider= : Sync only this provider slug}
                            {--capability= : Sync only this capability}
                            {--json : Emit machine-readable output}';

    protected $description = 'Refresh provider cost figures from their catalogues without creating any product';

    public function handle(CatalogueSync $sync, ProviderRegistry $registry): int
    {
        $slug = $this->option('provider');
        $capability = $this->option('capability');

        if ($capability !== null && ! $registry->driverIsRoutable($capability)) {
            /*
             * Refused rather than ignored. A capability no adapter implements would
             * silently sync nothing, and "the sync ran and found nothing" is
             * indistinguishable from "the sync ran and there is nothing to find".
             */
            $this->error("No adapter in this build implements [{$capability}]. Nothing was synced.");

            return self::FAILURE;
        }

        if ($slug !== null) {
            $provider = Provider::where('slug', $slug)->first();

            if ($provider === null) {
                $this->error("No provider has the slug [{$slug}]. Nothing was synced.");

                return self::FAILURE;
            }

            if (! $registry->hasAdapter($provider->driver)) {
                $this->error("Provider [{$slug}] declares driver [{$provider->driver}], which this build has no adapter for.");

                return self::FAILURE;
            }

            $capabilities = $capability !== null
                ? [$capability]
                : array_values(array_intersect(
                    (array) $provider->capabilities,
                    $registry->routableCapabilities()
                ));

            $result = $sync->syncProvider($provider, $capabilities);
            $result['providers'] = 1;
            $result['errors'] = [];
        } else {
            $result = $sync->syncAll($capability);
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Providers', 'Cost changes', 'Updated', 'Unmapped', 'Unavailable'],
            [[
                $result['providers'],
                $result['cost_changes'],
                $result['updated'],
                $result['unmapped'],
                $result['unavailable'],
            ]],
        );

        if ($result['unmapped'] > 0) {
            $this->newLine();
            $this->warn(
                $result['unmapped'] . ' provider variant(s) are not mapped to one of our products and cannot be sold. '
                . 'Map them in the admin console — the sync will not guess.'
            );
        }

        if ($result['unavailable'] > 0) {
            $this->warn(
                $result['unavailable'] . ' mapped product(s) no longer appear in their provider\'s catalogue and have '
                . 'been flagged unavailable. They are not deleted: an order may reference them.'
            );
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        if (config('providers.sandbox')) {
            $this->newLine();
            $this->warn(
                'PROVIDER_SANDBOX is on: these costs came from sandbox catalogues. They are not real '
                . 'provider charges, and the first production sync replaces them.'
            );
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
