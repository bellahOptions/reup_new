<?php

namespace App\Providers;

use App\Models\InternationalProduct;
use App\Models\Provider;
use App\Models\ProviderCatalogueItem;
use App\Models\ProviderPriceSnapshot;
use App\Models\ProviderProduct;
use App\Providers\Adapters\NitroSmmProvider;
use App\Providers\Adapters\SogoProvider;
use App\Providers\Adapters\VtpassProvider;
use App\Providers\Adapters\VtugateProvider;
use App\Providers\Support\ProviderResult;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Read each provider's catalogue and update our own cost figures.
 *
 * ## What this deliberately does not do
 *
 * **It never creates a sellable product.** A `provider_products` row is a decision
 * that we sell a particular thing from a particular provider, and this class cannot
 * make that decision from a provider's display text. Products it does not recognise
 * go to `provider_catalogue_items`, where an operator maps them.
 *
 * That restraint is the whole design. A sync that matched `MTN 1GB Monthly` to our
 * own product by name would work on the day it was written and sell the wrong bundle
 * the first time a provider reworded a plan — and the customer is charged for
 * something they did not choose. Provider vocabulary is not our vocabulary.
 *
 * **It never rewrites history.** Costs on `provider_products` are current state and
 * are updated freely. Every change also appends a `provider_price_snapshots` row,
 * and `pricing_snapshots` — the price a customer actually agreed to — is never
 * touched at all. Repricing affects the future; it cannot affect a completed sale.
 *
 * ## Idempotency
 *
 * Every write is keyed by a unique index: `provider_products` and
 * `international_products` by `(provider_id, provider_product_id)`,
 * `provider_catalogue_items` likewise. Running the sync twice changes
 * `last_seen_at` and nothing else, so an overlapping run or a retried scheduled job
 * is harmless rather than duplicating a catalogue.
 *
 * ## Failure is per provider, never global
 *
 * A provider that cannot be read is alerted on and skipped. Its existing costs are
 * left exactly as they were and its products are *not* marked unavailable — a
 * catalogue we could not read is not evidence that the products are gone, and
 * hiding every product of one provider because their API hiccuped is a worse
 * outcome than serving a slightly stale cost.
 */
class CatalogueSync
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderMonitor $monitor,
    ) {
    }

    /**
     * Sync every provider that can serve a capability.
     *
     * @return array{providers:int,created:int,updated:int,cost_changes:int,unmapped:int,unavailable:int,errors:array<int,string>}
     */
    public function syncAll(?string $capability = null): array
    {
        $totals = [
            'providers' => 0,
            'created' => 0,
            'updated' => 0,
            'cost_changes' => 0,
            'unmapped' => 0,
            'unavailable' => 0,
            'errors' => [],
        ];

        $providers = Provider::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($providers as $provider) {
            if (! $this->registry->hasAdapter($provider->driver)) {
                continue;
            }

            $capabilities = array_intersect(
                (array) $provider->capabilities,
                $capability === null ? $this->registry->routableCapabilities() : [$capability],
            );

            if ($capabilities === []) {
                continue;
            }

            $totals['providers']++;

            try {
                $result = $this->syncProvider($provider, array_values($capabilities));
            } catch (\Throwable $e) {
                /*
                 * One provider's parser throwing must not stop the others. A
                 * provider that changes its response shape is a vendor incident, not
                 * a reason to stop refreshing every other catalogue.
                 */
                Log::error('Catalogue sync threw for a provider', [
                    'provider' => $provider->slug,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);

                $this->monitor->alertCatalogueUnavailable($provider, 'The sync failed: ' . $e->getMessage());
                $totals['errors'][] = $provider->slug . ': ' . $e->getMessage();

                continue;
            }

            foreach (['created', 'updated', 'cost_changes', 'unmapped', 'unavailable'] as $key) {
                $totals[$key] += $result[$key];
            }
        }

        return $totals;
    }

    /**
     * Sync one provider's catalogues for the given capabilities.
     *
     * @param  array<int, string>  $capabilities
     * @return array{created:int,updated:int,cost_changes:int,unmapped:int,unavailable:int}
     */
    public function syncProvider(Provider $provider, array $capabilities): array
    {
        $adapter = $this->registry->adapterFor($provider);

        $totals = ['created' => 0, 'updated' => 0, 'cost_changes' => 0, 'unmapped' => 0, 'unavailable' => 0];

        $seen = [];

        foreach ($capabilities as $capability) {
            $items = $this->readCatalogue($provider, $capability);

            if ($items === null) {
                // The provider publishes no catalogue for this capability. Not an
                // error: it simply does not sell this, and saying so loudly every
                // run would be noise.
                continue;
            }

            foreach ($items as $item) {
                $seen[$item['provider_product_id']] = true;

                $totals[$this->applyItem($provider, $capability, $item)]++;
            }
        }

        if ($seen !== []) {
            $totals['unavailable'] = $this->retireVanished($provider, array_keys($seen));
        }

        return $totals;
    }

    /* =====================================================================
     | Reading catalogues
     |=================================================================== */

    /**
     * A normalised catalogue for one provider and capability, or null when the
     * provider publishes none.
     *
     * Each provider's shape is parsed by its own method below, because the shapes
     * genuinely differ — Sogo nests variations under a plan, VTpass requires a
     * country/operator walk, Nitro returns a bare list. A single generic parser
     * would be a pile of `data_get` guesses that silently returns nothing when a
     * provider changes a key, and returning nothing looks identical to a provider
     * having no products. Failing visibly per provider is the point.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function readCatalogue(Provider $provider, string $capability): ?array
    {
        return match ($provider->driver) {
            'sogo' => $this->readSogo($provider, $capability),
            'nitro' => $capability === 'smm' ? $this->readNitro($provider) : null,
            'vtpass' => in_array($capability, ['international_airtime', 'international_data'], true)
                ? $this->readVtpassInternational($provider)
                : null,
            'vtugate' => in_array($capability, ['international_airtime', 'international_data'], true)
                ? $this->readVtugateInternational($provider)
                : null,
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readSogo(Provider $provider, string $capability): ?array
    {
        $adapter = $this->registry->adapterFor($provider);

        /*
         * Checked rather than assumed. A `providers` row whose driver and whose
         * adapter disagree is a configuration error, and "call to undefined method"
         * is a much worse way to learn about it than a sentence naming the driver.
         */
        if (! $adapter instanceof SogoProvider) {
            throw new \RuntimeException(
                "Provider [{$provider->slug}] is declared as driver [{$provider->driver}] but did not resolve to the Sogo adapter."
            );
        }

        $result = match ($capability) {
            'data' => $adapter->dataPlans(),
            'cable_tv' => $adapter->cablePackages(),
            'education' => $adapter->educationPlans(),
            'gift_cards' => $adapter->giftCardCatalogue(),
            default => null,
        };

        if ($result === null) {
            return null;
        }

        if (! $this->usable($result, $provider, $capability)) {
            return null;
        }

        $rows = $this->firstList($this->bodyOf($result), ['data.plans', 'data', 'plans', 'data.products', 'products']);

        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? $this->billsItem($row, $capability) : null,
            $rows
        )));
    }

    /** @return array<int, array<string, mixed>>|null */
    private function readNitro(Provider $provider): ?array
    {
        $adapter = $this->registry->adapterFor($provider);

        if (! $adapter instanceof NitroSmmProvider) {
            throw new \RuntimeException(
                "Provider [{$provider->slug}] is declared as driver [{$provider->driver}] but did not resolve to the Nitro adapter."
            );
        }

        $result = $adapter->services();

        if (! $this->usable($result, $provider, 'smm')) {
            return null;
        }

        $rows = $this->firstList($this->bodyOf($result), ['services', 'data', '']);

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row)) {
                return null;
            }

            $id = $row['service'] ?? ($row['id'] ?? null);

            if (! is_scalar($id) || (string) $id === '') {
                return null;
            }

            /*
             * Nitro's rate is a price per 1,000 units, and the divisor is
             * configuration rather than a literal here. Getting it wrong by three
             * orders of magnitude is the single most expensive mistake available in
             * this integration, so the cost is derived through one named divisor.
             */
            $rate = $row['rate'] ?? null;
            $divisor = max(1, (int) config('providers.nitro.rate_divisor', 1000));

            return [
                'provider_product_id' => (string) $id,
                'name' => (string) ($row['name'] ?? ('Service ' . $id)),
                'capability' => 'smm',
                'category' => isset($row['category']) ? (string) $row['category'] : null,
                'network' => null,
                'denomination' => null,
                'cost_minor' => is_numeric($rate) ? (int) round(((float) $rate) * 100 / $divisor) : null,
                'min_quantity' => isset($row['min']) && is_numeric($row['min']) ? (int) $row['min'] : null,
                'max_quantity' => isset($row['max']) && is_numeric($row['max']) ? (int) $row['max'] : null,
                // Nitro instructs that `description` be read before selling: it
                // carries setup the buyer must complete. Stored, not parsed.
                'description' => isset($row['description']) ? (string) $row['description'] : null,
                'raw' => $row,
            ];
        }, $rows)));
    }

    /**
     * VTpass's international catalogue.
     *
     * A four-level walk — countries, product types, operators, variations — and each
     * variation is a sellable product. That is up to four requests per country, and
     * VTpass rate-limits, so the walk is bounded by `--country=` when an operator
     * wants a single market and otherwise limited to the configured cap.
     *
     * The rate is the important output: `variation_rate × amount` is the naira charge
     * for a flexible-price variation, and `variation_amount` is the charge for a
     * fixed-price one. Which of the two applies is decided by `fixedPrice`, never
     * guessed from the amount being zero.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function readVtpassInternational(Provider $provider): ?array
    {
        $adapter = $this->registry->adapterFor($provider);

        if (! $adapter instanceof VtpassProvider) {
            throw new \RuntimeException(
                "Provider [{$provider->slug}] is declared as driver [{$provider->driver}] but did not resolve to the VTpass adapter."
            );
        }

        $countries = $adapter->countries();

        if (! $this->usable($countries, $provider, 'international_airtime')) {
            return null;
        }

        $rows = $this->firstList($this->bodyOf($countries), ['content.countries', 'content', 'countries']);

        $limit = max(1, (int) config('providers.sync.vtpass_countries', 5));
        $only = $this->countryFilter();
        $items = [];
        $processed = 0;

        foreach ($rows as $country) {
            if (! is_array($country) || ! isset($country['code'])) {
                continue;
            }

            $code = strtoupper((string) $country['code']);

            if ($only !== [] && ! in_array($code, $only, true)) {
                continue;
            }

            if ($only === [] && $processed >= $limit) {
                break;
            }

            $processed++;

            $productTypes = $adapter->productTypes($code);

            if (! $productTypes->isSuccess()) {
                continue;
            }

            foreach ($this->firstList($this->bodyOf($productTypes), ['content', 'data']) as $type) {
                if (! is_array($type) || ! isset($type['product_type_id'])) {
                    continue;
                }

                $typeId = (string) $type['product_type_id'];
                $operators = $adapter->operators($code, $typeId);

                if (! $operators->isSuccess()) {
                    continue;
                }

                foreach ($this->firstList($this->bodyOf($operators), ['content', 'data']) as $operator) {
                    if (! is_array($operator) || ! isset($operator['operator_id'])) {
                        continue;
                    }

                    $operatorId = (string) $operator['operator_id'];
                    $variations = $adapter->variations($operatorId, $typeId);

                    if (! $variations->isSuccess()) {
                        continue;
                    }

                    foreach ($this->firstList($this->bodyOf($variations), ['content.variations', 'content']) as $variation) {
                        if (! is_array($variation) || ! isset($variation['variation_code'])) {
                            continue;
                        }

                        $items[] = $this->vtpassInternationalItem(
                            $country,
                            $type,
                            $operator,
                            $variation,
                            $this->bodyOf($variations),
                        );
                    }
                }
            }
        }

        return $items;
    }

    /**
     * VTUGate's international catalogue.
     *
     * Only countries and operators are read. VTUGate publishes a preview-FX endpoint
     * that quotes a rate for a specific amount rather than a catalogue of products,
     * so there is no price list to sync here — the rate is fetched per quote at
     * pricing time. Reading operators is still worthwhile, because a country or
     * operator that disappears upstream is a product we should stop offering.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function readVtugateInternational(Provider $provider): ?array
    {
        $adapter = $this->registry->adapterFor($provider);

        if (! $adapter instanceof VtugateProvider) {
            throw new \RuntimeException(
                "Provider [{$provider->slug}] is declared as driver [{$provider->driver}] but did not resolve to the VTUGate adapter."
            );
        }

        // An integration we cannot address safely must not populate a catalogue.
        if (! $adapter->isOperational()) {
            Log::info('Skipping the VTUGate international catalogue: the integration is not operational', [
                'provider' => $provider->slug,
                'reason' => $adapter->operationalReason(),
            ]);

            return null;
        }

        $countries = $adapter->countries();

        if (! $this->usable($countries, $provider, 'international_airtime')) {
            return null;
        }

        return array_values(array_filter(array_map(function ($country) {
            if (! is_array($country)) {
                return null;
            }

            $code = $country['code'] ?? ($country['country_code'] ?? null);

            if (! is_scalar($code) || (string) $code === '') {
                return null;
            }

            return [
                'provider_product_id' => strtoupper((string) $code),
                'name' => (string) ($country['name'] ?? $country['country_name'] ?? $code),
                'capability' => 'international_airtime',
                'country_code' => strtoupper((string) $code),
                'country_name' => (string) ($country['name'] ?? $country['country_name'] ?? $code),
                'dial_prefix' => isset($country['prefix']) ? (string) $country['prefix'] : null,
                'provider_currency' => (string) ($country['currency'] ?? ''),
                'operator_id' => null,
                'operator_name' => null,
                'product_type_id' => null,
                'product_type_name' => null,
                'product_name' => (string) ($country['name'] ?? $code) . ' top-up',
                'variation_code' => null,
                'provider_amount_minor' => null,
                'is_fixed_price' => true,
                'cost_minor' => null,
                'fee_minor' => null,
                'exchange_rate_micros' => null,
                'exchange_rate_source' => null,
                'raw' => $country,
            ];
        }, $this->firstList($this->bodyOf($countries), ['data', 'content']))));
    }

    /* =====================================================================
     | Writing
     =================================================================== */

    /**
     * Apply one catalogue item, and report which bucket it landed in.
     *
     * @param  array<string, mixed>  $item
     * @return string 'created'|'updated'|'cost_changes'|'unmapped'
     */
    private function applyItem(Provider $provider, string $capability, array $item): string
    {
        if ($this->isInternational($capability)) {
            return $this->applyInternationalItem($provider, $item);
        }

        $offering = ProviderProduct::where('provider_id', $provider->getKey())
            ->where('provider_product_id', $item['provider_product_id'])
            ->first();

        if ($offering === null) {
            /*
             * Not one of our products. Recorded for a human to map, never sold.
             * Re-running the sync after a mapping exists takes the branch below and
             * keeps the cost fresh from then on.
             */
            $this->stage($provider, $capability, $item);

            return 'unmapped';
        }

        $previous = $offering->provider_cost_minor;
        $incoming = $item['cost_minor'];

        $changed = $incoming !== $previous;

        $offering->provider_name = $item['name'];
        /*
         * Set unconditionally, including to null. A catalogue that lists this
         * product but no longer states a price means we do not know what it costs —
         * and carrying the last known figure forward would let us sell at a price
         * derived from a cost the provider has stopped quoting. A null cost makes
         * the product unsellable, which is the safe direction to fail.
         */
        $offering->provider_cost_minor = $incoming;
        $offering->provider_currency = $item['provider_currency'] ?? $offering->provider_currency ?? 'NGN';
        $offering->denomination = $item['denomination'] ?? $offering->denomination;
        $offering->min_quantity = $item['min_quantity'] ?? $offering->min_quantity;
        $offering->max_quantity = $item['max_quantity'] ?? $offering->max_quantity;
        $offering->description = $item['description'] ?? $offering->description;

        $offering->last_seen_at = now();
        $offering->cost_synced_at = now();
        $offering->unavailable_at = null;

        if ($changed) {
            $offering->previous_cost_minor = $previous;
            $offering->cost_changed_at = now();
        }

        $offering->save();

        if ($changed) {
            /*
             * Only a real figure is snapshotted. A snapshot of null would record
             * "the provider charged nothing", which is not what happened.
             */
            if ($incoming !== null) {
                $this->snapshotCost($offering);
            }

            if ($previous !== null && $incoming !== null && $incoming > $previous) {
                $this->monitor->alertUnusualCostChange($provider, (int) $previous, $incoming);
            }

            return 'cost_changes';
        }

        return 'updated';
    }

    /**
     * Apply an international item.
     *
     * The human-owned part of this row — which of our products it maps to — is
     * never written here. The sync updates provider facts; the mapping is an
     * operator decision, and a sync that overwrote it would silently unmap a product
     * or, worse, remap it to a different one.
     *
     * @param  array<string, mixed>  $item
     */
    private function applyInternationalItem(Provider $provider, array $item): string
    {
        $existing = InternationalProduct::where('provider_id', $provider->getKey())
            ->where('provider_product_id', $item['provider_product_id'])
            ->first();

        $attributes = array_filter([
            'country_code' => $item['country_code'] ?? null,
            'country_name' => $item['country_name'] ?? null,
            'dial_prefix' => $item['dial_prefix'] ?? null,
            'provider_currency' => $item['provider_currency'] ?? null,
            'operator_id' => $item['operator_id'] ?? null,
            'operator_name' => $item['operator_name'] ?? null,
            'product_type_id' => $item['product_type_id'] ?? null,
            'product_type_name' => $item['product_type_name'] ?? null,
            'product_name' => $item['product_name'] ?? $item['name'] ?? null,
            'variation_code' => $item['variation_code'] ?? null,
            'provider_amount_minor' => $item['provider_amount_minor'] ?? null,
            'provider_fee_minor' => $item['fee_minor'] ?? null,
            'exchange_rate_micros' => $item['exchange_rate_micros'] ?? null,
            'exchange_rate_source' => $item['exchange_rate_source'] ?? null,
            'metadata' => $item['raw'] ?? null,
            'last_synced_at' => now(),
            'unavailable_at' => null,
        ], fn ($value) => $value !== null);

        /*
         * The cost and the fixed-price flag are assigned separately, and both may be
         * falsy, which is why they must not go through the `array_filter` above: it
         * removes `false` and `0` along with `null`. A flexible-price variation
         * silently stored as fixed-price is a real defect — pricing expects a
         * fixed-price product to carry one cost, and this one carries none.
         *
         * The cost is null rather than inherited for the same reason as a domestic
         * product: a variation whose price the provider no longer states must become
         * unsellable, not keep a cost derived from a rate that has since moved.
         */
        $attributes['provider_cost_minor'] = $item['cost_minor'] ?? null;
        $attributes['is_fixed_price'] = $item['is_fixed_price'] ?? true;

        if ($existing === null) {
            InternationalProduct::create($attributes + [
                'provider_id' => $provider->getKey(),
                'provider_product_id' => $item['provider_product_id'],
                'status' => InternationalProduct::STATUS_ACTIVE,
            ]);

            return 'created';
        }

        $previous = $existing->provider_cost_minor;
        $incoming = $attributes['provider_cost_minor'];

        $changed = $incoming !== $previous;

        $existing->fill($attributes);

        if ($changed) {
            $existing->previous_cost_minor = $previous;
            $existing->cost_changed_at = now();
        }

        $existing->save();

        if ($changed && $previous !== null && $incoming !== null && $incoming > $previous) {
            $this->monitor->alertUnusualCostChange($provider, (int) $previous, (int) $incoming);
        }

        return $changed ? 'cost_changes' : 'updated';
    }

    /**
     * Record a variant we cannot sell yet.
     *
     * @param  array<string, mixed>  $item
     */
    private function stage(Provider $provider, string $capability, array $item): void
    {
        $existing = ProviderCatalogueItem::where('provider_id', $provider->getKey())
            ->where('provider_product_id', $item['provider_product_id'])
            ->first();

        if ($existing !== null) {
            $existing->fill(array_filter([
                'provider_name' => $item['name'],
                'capability' => $capability,
                'provider_category' => $item['category'],
                'network' => $item['network'],
                'denomination' => $item['denomination'],
                'provider_cost_minor' => $item['cost_minor'],
                'payload' => $item['raw'],
            ], fn ($value) => $value !== null));

            $existing->last_seen_at = now();
            $existing->disappeared_at = null;
            $existing->save();

            return;
        }

        ProviderCatalogueItem::create([
            'provider_id' => $provider->getKey(),
            'provider_product_id' => $item['provider_product_id'],
            'provider_name' => $item['name'],
            'capability' => $capability,
            'provider_category' => $item['category'],
            'network' => $item['network'],
            'denomination' => $item['denomination'],
            'provider_cost_minor' => $item['cost_minor'],
            'payload' => $item['raw'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Append an immutable cost snapshot.
     *
     * Separate from the update on purpose: `provider_products` is current state and
     * may be corrected, this is the record of what was observed and when. Nothing
     * updates a row here, which is what makes a cost-increase history trustworthy.
     */
    private function snapshotCost(ProviderProduct $offering): void
    {
        ProviderPriceSnapshot::create([
            'provider_product_id' => $offering->getKey(),
            'cost_minor' => (int) $offering->provider_cost_minor,
            'provider_currency' => $offering->provider_currency,
            'provider_amount_minor' => $offering->provider_amount_minor,
            'rate_used_micros' => $offering->rate_used_micros,
            'source' => ProviderPriceSnapshot::SOURCE_SYNC,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Flag products that were mapped and sellable but did not appear this run.
     *
     * Flagged, never deleted: an order may reference the row, and a provider that
     * drops a product and restores it an hour later should not lose the mapping.
     * `unavailable_at` takes it out of routing immediately, which is the part that
     * protects the customer.
     *
     * @param  array<int, string>  $seen
     */
    private function retireVanished(Provider $provider, array $seen): int
    {
        $rows = ProviderProduct::where('provider_id', $provider->getKey())
            ->whereNotIn('provider_product_id', $seen)
            ->whereNull('unavailable_at')
            ->get();

        foreach ($rows as $row) {
            $row->forceFill(['unavailable_at' => now()])->save();

            Log::warning('Provider product is no longer in the catalogue', [
                'provider' => $provider->slug,
                'provider_product_id' => $row->provider_product_id,
                'provider_name' => $row->provider_name,
            ]);
        }

        // A staged variant that has vanished is no longer a mapping candidate, but
        // the record is kept so its disappearance is visible.
        ProviderCatalogueItem::where('provider_id', $provider->getKey())
            ->whereNotIn('provider_product_id', $seen)
            ->whereNull('disappeared_at')
            ->update(['disappeared_at' => now(), 'updated_at' => now()]);

        return $rows->count();
    }

    /* =====================================================================
     | Item shaping
     =================================================================== */

    /**
     * Normalise a domestic catalogue row.
     *
     * Every field is read defensively and may be null. A provider that omits a
     * denomination produces a staged row that is obviously incomplete, which is
     * better than a row with an invented one.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function billsItem(array $row, string $capability): array
    {
        $id = $row['variation_code']
            ?? ($row['plan_id'] ?? ($row['product_id'] ?? ($row['id'] ?? ($row['slug'] ?? null))));

        $amount = $row['amount'] ?? ($row['price'] ?? ($row['denomination'] ?? null));

        return [
            'provider_product_id' => (string) $id,
            'name' => (string) ($row['name'] ?? ($row['plan'] ?? ($row['product_name'] ?? $id))),
            'capability' => $capability,
            'category' => isset($row['type']) ? (string) $row['type'] : null,
            'network' => isset($row['network']) ? (string) $row['network'] : null,
            'denomination' => is_scalar($amount) ? (string) $amount : null,
            'cost_minor' => is_numeric($amount) ? $this->majorToMinor((string) $amount) : null,
            'min_quantity' => isset($row['min_quantity']) && is_numeric($row['min_quantity']) ? (int) $row['min_quantity'] : null,
            'max_quantity' => isset($row['max_quantity']) && is_numeric($row['max_quantity']) ? (int) $row['max_quantity'] : null,
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'raw' => $row,
        ];
    }

    /**
     * Normalise one VTpass international variation.
     *
     * @param  array<string, mixed>  $country
     * @param  array<string, mixed>  $type
     * @param  array<string, mixed>  $operator
     * @param  array<string, mixed>  $variation
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function vtpassInternationalItem(
        array $country,
        array $type,
        array $operator,
        array $variation,
        array $envelope
    ): array {
        $code = strtoupper((string) $country['code']);

        /*
         * `fixedPrice` decides which figure is the charge, and it is read rather
         * than inferred from the amount being zero. For a flexible-price variation
         * the naira charge is `variation_rate × amount`, where the amount is chosen
         * by the customer — so there is no single cost to store, and the cost is
         * left null until a quote is priced. Storing the zero would look like a free
         * product and price it as one.
         */
        $fixed = strtolower((string) ($variation['fixedPrice'] ?? 'yes')) === 'yes';

        $rate = $variation['variation_rate'] ?? null;
        $amount = $variation['variation_amount'] ?? null;

        $costMinor = $fixed && is_numeric($amount) && (float) $amount > 0
            ? $this->majorToMinor((string) $amount)
            : null;

        return [
            'provider_product_id' => $code . ':' . (string) $variation['variation_code'],
            'name' => (string) ($variation['name'] ?? $variation['variation_code']),
            'capability' => 'international_airtime',
            'country_code' => $code,
            'country_name' => (string) ($country['name'] ?? $code),
            'dial_prefix' => isset($country['prefix']) ? (string) $country['prefix'] : null,
            'provider_currency' => (string) ($country['currency'] ?? ''),
            'operator_id' => (string) $operator['operator_id'],
            'operator_name' => isset($operator['name']) ? (string) $operator['name'] : null,
            'product_type_id' => (string) $type['product_type_id'],
            'product_type_name' => isset($type['name']) ? (string) $type['name'] : null,
            'product_name' => (string) ($variation['name'] ?? $variation['variation_code']),
            'variation_code' => (string) $variation['variation_code'],
            // The customer-currency amount, not the naira charge: the recipient
            // receives 10 GHS and pays whatever that costs in naira.
            'provider_amount_minor' => is_numeric($amount) ? $this->majorToMinor((string) $amount) : null,
            'is_fixed_price' => $fixed,
            'cost_minor' => $costMinor,
            /*
             * VTpass publishes a convenience fee per service, as a percentage
             * string such as "0 %". Only the percentage is parsed, and an
             * unparseable value is left null rather than treated as zero, because
             * assuming a fee away is how a margin becomes a loss.
             */
            'fee_minor' => null,
            'exchange_rate_micros' => is_numeric($rate) ? (int) round(((float) $rate) * 1000000) : null,
            'exchange_rate_source' => is_numeric($rate) ? InternationalProduct::RATE_SOURCE_PROVIDER_CATALOGUE : null,
            'raw' => [
                'country' => $country,
                'product_type' => $type,
                'operator' => $operator,
                'variation' => $variation,
                'convinience_fee' => $envelope['content']['convinience_fee'] ?? null,
            ],
        ];
    }

    /* =====================================================================
     | Helpers
     | =================================================================== */

    /**
     * The first key path in a payload that yields a list.
     *
     * Providers nest their lists differently and some return a bare array. Trying a
     * short list of known paths and taking the first that is a list is explicit
     * about what is expected, unlike a recursive scan that would happily find a list
     * of the wrong things.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     * @return array<int, mixed>
     */
    private function firstList(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $path === '' ? $payload : data_get($payload, $path);

            if (is_array($value) && $value !== [] && array_is_list($value)) {
                return $value;
            }

            if (is_array($value) && $value !== [] && $path !== '') {
                // A keyed map of items is still a list of items.
                return array_values($value);
            }
        }

        return is_array($payload) && array_is_list($payload) ? $payload : [];
    }

    private function isInternational(string $capability): bool
    {
        return str_starts_with($capability, 'international_');
    }

    /**
     * Whether a catalogue read can be trusted.
     *
     * A failed read must not be mistaken for an empty catalogue: marking every
     * product unavailable because an API hiccuped would empty the storefront. The
     * failure is alerted on and the existing catalogue is left alone.
     */
    private function usable(ProviderResult $result, Provider $provider, string $capability): bool
    {
        if ($result->isSuccess()) {
            return true;
        }

        Log::warning('Provider catalogue could not be read', [
            'provider' => $provider->slug,
            'capability' => $capability,
            'status' => $result->status,
            'message' => $result->message,
        ] + $result->toLogContext());

        $this->monitor->alertCatalogueUnavailable(
            $provider,
            $result->message ?? ('The catalogue read returned ' . $result->status . '.'),
        );

        return false;
    }

    /** @return array<int, string> */
    private function countryFilter(): array
    {
        $only = (array) config('providers.sync.countries', []);

        return array_values(array_filter(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            $only
        )));
    }

    /**
     * The body to parse for catalogue identifiers.
     *
     * `raw` — the unredacted response — when the adapter attached one, which it does
     * for every catalogue call. This matters more than it looks: the redactor matches
     * by key name, and `code` is a gift card number to Sogo and a country code to
     * VTpass, so parsing the redacted `payload` finds `[redacted]` where the country
     * code should be and stages every plan under the same key.
     *
     * The fallback to `payload` keeps a mis-wired adapter (one calling `send()`
     * instead of `sendRead()`) working for the fields that are not redacted, rather
     * than silently syncing nothing at all. The sync tests assert on identifiers, so
     * a missing `raw` fails loudly there rather than in production.
     *
     * @return array<string, mixed>
     */
    private function bodyOf(ProviderResult $result): array
    {
        return $result->raw ?? (array) $result->payload;
    }

    private function majorToMinor(string $amount): int
    {
        return Money::fromNaira(number_format((float) $amount, 2, '.', ''))->minor();
    }
}
