<?php

namespace App\Console\Commands;

use App\Models\ProviderCatalogueItem;
use App\Models\ProviderProduct;
use App\Providers\CatalogueSync;
use App\Providers\ProviderRegistry;
use Illuminate\Console\Command;

/**
 * Refresh the gift card buy catalogue.
 *
 * Gift cards get their own command because they are the one Wave 1 product whose
 * provider cost is a *discount off face value* rather than a price. Sogo publishes
 * each card with a `discountPercentage`, and the margin is that discount minus
 * whatever we charge the customer — so a discount that shrinks from 3% to 1% halves
 * the margin on every sale, silently, while every sale still succeeds. The figure to
 * watch is not a price, and it would not stand out in the general cost-movement
 * report.
 *
 * What this command does is run the standard sync for the `gift_cards` capability and
 * then report the state an operator has to act on: how many variants are waiting to
 * be mapped, and how many are mapped and sellable. The discount itself is preserved
 * verbatim in the staged payload and in the mapped product's cost, so the decision
 * can be reviewed without re-reading the provider's catalogue.
 *
 * ## Buy only
 *
 * This is the *buy* side: ReUp purchasing a card to deliver to a customer. ReUp does
 * not operate a gift card trading or sell-back service, and no code here could
 * support one — there is no endpoint called and no table written that would
 * represent a customer selling a card to us.
 *
 * ## Usage
 *
 * ```
 * php artisan providers:sync-gift-cards
 * php artisan providers:sync-gift-cards --provider=sogo
 * ```
 */
class SyncGiftCards extends Command
{
    protected $signature = 'providers:sync-gift-cards
                            {--provider= : Sync only this provider slug}
                            {--json : Emit machine-readable output}';

    protected $description = 'Refresh the gift card buy catalogue and report what is staged and what is sellable';

    public function handle(CatalogueSync $sync, ProviderRegistry $registry): int
    {
        if (! $registry->driverIsRoutable('gift_cards')) {
            $this->error('No adapter in this build provides gift cards. Nothing was synced.');

            return self::FAILURE;
        }

        $exitCode = $this->call('providers:sync-catalogues', array_filter([
            '--provider' => $this->option('provider'),
            '--capability' => 'gift_cards',
        ], fn ($value) => $value !== null));

        $counts = [
            'staged' => ProviderCatalogueItem::query()
                ->where('capability', 'gift_cards')
                ->awaitingMapping()
                ->present()
                ->count(),
            'mapped' => ProviderProduct::query()
                ->available()
                ->where('service_type', 'giftcard')
                ->count(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($counts, JSON_PRETTY_PRINT));

            return $exitCode;
        }

        $this->newLine();
        $this->info("Gift card variants staged for mapping: {$counts['staged']}");
        $this->line("Gift card variants mapped and sellable: {$counts['mapped']}");

        if ($counts['staged'] > 0) {
            $this->newLine();
            $this->warn(
                'Staged variants cannot be sold until an operator maps each one to a ReUp product and sets a customer '
                . 'price. The sync deliberately does not do this: a gift card with the wrong brand or the wrong '
                . 'denomination is money handed over for the wrong thing.'
            );
        }

        return $exitCode;
    }
}
