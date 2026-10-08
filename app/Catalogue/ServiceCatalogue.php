<?php

namespace App\Catalogue;

use App\Models\ProviderProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Support\UiCopy;
use Illuminate\Support\Collection;

/**
 * What the customer can actually buy, assembled from the database.
 *
 * ## The rule this exists to satisfy
 *
 * §51.42: the frontend must not hardcode service availability, providers, prices,
 * fees, catalogues or status. Every one of those has to arrive from the backend. This
 * class is that backend for the parts a customer *browses* — the categories, the
 * services inside them, and whether each one can be bought right now.
 *
 * A Blade template that lists "Airtime, Data, Electricity, Cable TV" by hand is a
 * template that keeps listing them after the last provider for one of them is
 * switched off, and the customer discovers it at the payment step. Here the list is
 * a query.
 *
 * ## What is deliberately absent from every payload
 *
 * Provider names, provider costs, margins and profit. §51.36 says not to expose
 * internal provider names, and §51.37 says not to expose cost or profit to customers.
 * So the payloads below carry a customer-facing name, a state and a reason — nothing
 * that describes ReUp's economics or its suppliers. The absence is load-bearing, and
 * `ServiceCatalogueTest` asserts it rather than trusting it.
 *
 * ## Availability is computed, never stored
 *
 * A service is offered when it has a published, purchasable product *and* at least
 * one provider offering that is still present in that provider's catalogue and has a
 * usable cost. That is derived on every read rather than written to a flag, so a
 * provider withdrawal, a paused product and a product withheld for pricing are all
 * reflected immediately and cannot drift out of sync with reality.
 */
class ServiceCatalogue
{
    /**
     * The dashboard sections, in the approved order (§51.2 and §51.8).
     *
     * `my_reup` is not a sellable group — it links to the customer's own saved bills,
     * reminders and history — so it is assembled separately.
     */
    public const SECTIONS = [
        ServiceCategory::GROUP_PAY_BILLS,
        ServiceCategory::GROUP_DIGITAL,
        ServiceCategory::GROUP_SOCIAL,
    ];

    /**
     * The sections a customer should see, ready to render.
     *
     * ## What is shown, and what is not
     *
     * A browsing surface shows what can be **bought**. So by default:
     *
     *   * a section with no buyable service is **omitted entirely** — not rendered as a
     *     heading above a card saying the service is unavailable. Three of those on a
     *     dashboard is not information, it is noise, and it makes a working product look
     *     broken;
     *   * an unavailable service is omitted from a section that has others available.
     *
     * That is a deliberate change from "show it with its reason". The §51.36 copy —
     * *"This service is temporarily unavailable. Please try again later."* — belongs to
     * the moment a customer **reaches for a specific service**, which is that service's
     * own page, not a list they are browsing. A list that advertises what it cannot sell
     * is worse than a shorter list.
     *
     * `$onlyAvailable: false` returns the whole picture, with each service's reason, so
     * that "why is this product not on the dashboard?" has an answer. Nothing
     * customer-facing uses it — `unavailable_reason` is null on every available service
     * and no customer surface reads it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sections(bool $onlyAvailable = true): array
    {
        $sections = [];

        foreach (self::SECTIONS as $group) {
            $services = $this->servicesInGroup($group);

            /*
             * A section with no products at all is never returned, in either mode.
             * There is genuinely nothing to say about it, and a heading over nothing is
             * the empty placeholder this method exists to avoid.
             */
            if ($services === []) {
                continue;
            }

            if ($onlyAvailable) {
                $services = array_values(array_filter($services, fn (array $service) => $service['available']));

                // Products exist but none can be bought. Omitted from anything a
                // customer sees; kept in the diagnostic form, with the reason.
                if ($services === []) {
                    continue;
                }
            }

            $sections[] = [
                'group' => $group,
                'name' => ServiceCategory::GROUPS[$group]['name'] ?? ucfirst($group),
                'heading' => ServiceCategory::GROUPS[$group]['heading'] ?? ucfirst($group),
                'description' => ServiceCategory::GROUPS[$group]['description'] ?? '',
                'dashboard_description' => ServiceCategory::GROUPS[$group]['dashboard_description'] ?? '',
                'services' => $services,
                /*
                 * Always true in the filtered form this method defaults to, because a
                 * section that would be false is not returned at all. It stays because
                 * the unfiltered form needs it, and because a view that renders a
                 * section should not have to recompute the answer.
                 */
                'has_available' => (bool) collect($services)->contains(fn (array $s) => $s['available']),
            ];
        }

        return $sections;
    }

    /**
     * The services inside one navigation group.
     *
     * @return array<int, array<string, mixed>>
     */
    public function servicesInGroup(string $group): array
    {
        $categories = ServiceCategory::query()
            ->where('group', $group)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['products' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->get();

        $services = [];

        foreach ($categories as $category) {
            foreach ($category->products as $product) {
                $services[] = $this->describe($product, $category);
            }
        }

        return $services;
    }

    /**
     * One product, as a customer sees it.
     *
     * @return array<string, mixed>
     */
    public function describe(ServiceProduct $product, ?ServiceCategory $category = null): array
    {
        $available = $this->isAvailable($product);

        return [
            'key' => $product->product_key,
            'slug' => $product->slug,
            'name' => $product->name,
            'description' => $product->description,
            'group' => $category?->group,
            'category' => $category?->slug,
            'available' => $available,
            /*
             * The reason is only populated when the service is unavailable, and it is
             * the whole message the customer gets. It never names a provider, a cost
             * or a margin — see UiCopy::unavailableMessage().
             */
            'unavailable_reason' => $available ? null : UiCopy::unavailableMessage($product),
            /*
             * A warning is not a block. A product whose margin is below target but
             * above the floor is still sellable, and the customer is not told about
             * ReUp's economics either way (§51.37) — the flag drives internal
             * reporting, not customer copy.
             */
            'with_warning' => $available && $product->availability === ServiceProduct::AVAILABILITY_WITH_WARNING,
            /*
             * Prices are deliberately not included. A price is produced by the pricing
             * engine for a specific quantity and moment, and a catalogue that carried a
             * price would be a second, staler source of truth for the amount a customer
             * is charged (§51.38, §51.40).
             */
            'price' => null,
        ];
    }

    /**
     * Whether a product can be bought right now.
     *
     * Three conditions, each answering a different question:
     *
     *   1. **Published** — an operator has deliberately made it sellable. A synced
     *      catalogue that reached the storefront on its own would offer products
     *      nobody has priced.
     *   2. **Not withheld for pricing** — the margin check has not parked it. This is
     *      the profitability-protection gate: a cost rise that breaks the configured
     *      minimum withholds the product automatically rather than selling it at a
     *      loss.
     *   3. **Fundable** — at least one provider offer is still present with a known
     *      cost. Without this, the customer completes checkout and the order fails
     *      upstream, which is the worst possible place to discover it.
     */
    public function isAvailable(ServiceProduct $product): bool
    {
        if (! $product->isPurchasable()) {
            return false;
        }

        return $this->hasFundableOffer($product);
    }

    /**
     * Whether any provider can currently serve this product at a known cost.
     *
     * `ProviderProduct::available()` already excludes rows withdrawn from a
     * provider's catalogue and rows an operator deactivated; the cost check is added
     * because a row with no cost cannot be priced, and offering it would produce a
     * checkout the pricing engine has to refuse.
     */
    public function hasFundableOffer(ServiceProduct $product): bool
    {
        return ProviderProduct::query()
            ->where('service_product_id', $product->getKey())
            ->available()
            ->whereNotNull('provider_cost_minor')
            ->exists();
    }

    /**
     * Quick-action labels for the dashboard (§51.7).
     *
     * Derived from the catalogue rather than hardcoded, and filtered to what is
     * actually available — so a quick action can never lead to a dead end.
     *
     * @return array<int, array<string, string>>
     */
    public function quickActions(int $limit = 10): array
    {
        $actions = [];

        foreach (self::SECTIONS as $group) {
            foreach ($this->servicesInGroup($group) as $service) {
                if (! $service['available']) {
                    continue;
                }

                $actions[] = [
                    'label' => $service['name'],
                    'key' => (string) $service['key'],
                    'group' => (string) $service['group'],
                ];
            }
        }

        /*
         * Reordered by the operator's own `sort_order` rather than by an assumed
         * "most frequently used" ranking. A hardcoded popularity ordering would be a
         * hardcoded availability claim in disguise — and when space runs out the
         * order should be the operator's decision, not this class's.
         */
        return array_slice($actions, 0, max(1, $limit));
    }

    /**
     * The "My ReUp" section (§51.8): not sellable, so not a catalogue group.
     *
     * @return array<string, mixed>
     */
    public function myReupSection(): array
    {
        return [
            'group' => 'my_reup',
            'name' => UiCopy::get('dashboard.sections.my_reup'),
            'description' => UiCopy::get('dashboard.my_reup_description'),
        ];
    }

    /**
     * Everything the customer-facing catalogue contains, for the JSON endpoint.
     *
     * A flat list is included alongside the sections because search (§51.32) needs one
     * and the frontend should not have to flatten a nested structure it was handed.
     *
     * `$onlyAvailable` follows `sections()`: the customer-facing payload carries what
     * can be bought. The unfiltered form is for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function payload(bool $onlyAvailable = true): array
    {
        $sections = $this->sections($onlyAvailable);
        $flat = [];

        foreach ($sections as $section) {
            foreach ($section['services'] as $service) {
                $flat[] = $service + ['section' => $section['name']];
            }
        }

        return [
            'sections' => $sections,
            'my_reup' => $this->myReupSection(),
            'services' => $flat,
            'quick_actions' => $this->quickActions(),
            'search' => [
                'placeholder' => UiCopy::get('search.placeholder'),
                'hint' => UiCopy::get('search.hint'),
                'empty' => UiCopy::get('search.empty'),
                'empty_supporting' => UiCopy::get('search.empty_supporting'),
            ],
            'copy' => [
                'unavailable_heading' => UiCopy::get('availability.heading'),
                'unavailable_description' => UiCopy::get('availability.description'),
                'pricing_unavailable' => UiCopy::get('availability.pricing'),
                'price_change' => UiCopy::get('price_change.notice'),
                'price_change_supporting' => UiCopy::get('price_change.supporting'),
            ],
        ];
    }

    /**
     * Products grouped by their status, for the admin catalogue screen.
     *
     * Not customer-facing. Exists so the admin view does not have to reimplement the
     * availability rule and get a different answer from the storefront.
     *
     * @return Collection<string, Collection<int, ServiceProduct>>
     */
    public function byStatus(): Collection
    {
        return ServiceProduct::query()
            ->orderBy('sort_order')
            ->get()
            ->groupBy('status');
    }
}
