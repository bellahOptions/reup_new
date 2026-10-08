<?php

namespace App\Console\Commands;

use App\Models\InternationalProduct;
use App\Providers\CatalogueSync;
use App\Providers\ProviderRegistry;
use Illuminate\Console\Command;

/**
 * Refresh the international top-up catalogue.
 *
 * International products are their own command because their cost is not a number
 * the provider states in naira. Both VTpass and VTUGate quote in the recipient's
 * currency and convert at a rate that moves, and the rate is as much a part of the
 * price as the amount is. A sync that reported only "the cost changed" would hide the
 * thing an operator needs to see: whether the *rate* moved.
 *
 * ## The rate limit is why this is bounded
 *
 * VTpass's catalogue is a country → product type → operator → variation walk, so one
 * country is up to four requests and a full walk is hundreds — against an API that
 * rate-limits. The walk is therefore bounded by `providers.sync.countries` (the
 * markets we actually sell into) or by `--country=` for a one-off, with a cap as the
 * fallback. A sync that trips the provider's rate limit is worse than a narrow one
 * that completes.
 *
 * ## Unmapped is the normal starting state
 *
 * A synced international product has no `service_product_id` until an operator maps
 * it, and an unmapped row cannot be sold. That is deliberate: international products
 * are added and withdrawn by providers constantly, and a catalogue that sold
 * whatever appeared would offer customers bundles nobody had priced.
 *
 * ## Usage
 *
 * ```
 * php artisan providers:sync-international-products
 * php artisan providers:sync-international-products --country=GH --country=KE
 * ```
 */
class SyncInternationalProducts extends Command
{
    protected $signature = 'providers:sync-international-products
                            {--provider= : Sync only this provider slug}
                            {--country=* : Restrict the walk to these country codes}
                            {--json : Emit machine-readable output}';

    protected $description = 'Refresh the international top-up catalogue, including the exchange rates behind each price';

    public function handle(CatalogueSync $sync, ProviderRegistry $registry): int
    {
        if (! $registry->driverIsRoutable('international_airtime')) {
            $this->error('No adapter in this build provides international top-up. Nothing was synced.');

            return self::FAILURE;
        }

        $countries = array_values(array_filter(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            (array) $this->option('country')
        )));

        if ($countries !== []) {
            /*
             * Applied via config rather than an argument, because the same bound is
             * read inside the walk. Passing it twice would be two places for the
             * restriction to be dropped.
             */
            config(['providers.sync.countries' => $countries]);

            $this->line('Restricting the walk to: ' . implode(', ', $countries));
        }

        $exitCode = $this->call('providers:sync-catalogues', array_filter([
            '--provider' => $this->option('provider'),
            '--capability' => 'international_airtime',
        ], fn ($value) => $value !== null));

        $counts = [
            'total' => InternationalProduct::query()->count(),
            'sellable' => InternationalProduct::query()->sellable()->count(),
            'unmapped' => InternationalProduct::query()->whereNull('service_product_id')->count(),
            'countries' => InternationalProduct::query()->distinct()->count('country_code'),
            'with_rate' => InternationalProduct::query()->whereNotNull('exchange_rate_micros')->count(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($counts, JSON_PRETTY_PRINT));

            return $exitCode;
        }

        $this->newLine();
        $this->table(
            ['Products', 'Sellable', 'Awaiting mapping', 'Countries', 'With a recorded rate'],
            [[
                $counts['total'],
                $counts['sellable'],
                $counts['unmapped'],
                $counts['countries'],
                $counts['with_rate'],
            ]],
        );

        if ($counts['unmapped'] > 0) {
            $this->warn(
                $counts['unmapped'] . ' international product(s) are awaiting a mapping and cannot be sold. '
                . 'Each needs a ReUp product and a customer price before it can be offered.'
            );
        }

        if ($counts['total'] > 0 && $counts['with_rate'] === 0) {
            $this->warn(
                'No exchange rate was recorded for any product. A price derived without a recorded rate cannot be '
                . 'explained to a customer or audited later, so check the provider catalogue shape before selling.'
            );
        }

        return $exitCode;
    }
}
