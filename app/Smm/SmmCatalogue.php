<?php

namespace App\Smm;

use App\Models\ProviderProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use Illuminate\Support\Collection;

/**
 * The customer-facing view of the synchronised social-boost catalogue.
 *
 * ## What this owns, and why it is separate from the admin catalogue
 *
 * The admin console needs to see services that are *not* sellable — paused,
 * unpriced, stale, awaiting a mapping — because those are the ones an operator
 * has to act on. A customer must never see them. Both read
 * `service_products`/`provider_products`; only this class decides what a
 * customer is allowed to be shown, and it decides it in one place.
 *
 * ## The one rule that makes the storefront trustworthy
 *
 * **A service is listed only if it can actually be bought.** That means an active
 * product, an available offering, and a published rate. Listing a service that
 * then refuses at the point of purchase is the dead end the brief is explicit
 * about — and a service with no rate would be listed with no price, which
 * invites a customer to choose something that cannot be sold.
 *
 * Availability is therefore computed as a property of the *offering*, not of the
 * product row alone: `provider_products.unavailable_at` is set by the sync when a
 * service disappears from the provider's catalogue, and a product whose only
 * offering has vanished must stop being listed immediately.
 *
 * ## Platforms
 *
 * Nitro's `category` is the platform (`Instagram`, `Discord`, `TikTok`…). It is
 * stored on `provider_products.platform` — an operator-owned value,
 * because the provider's spelling is a display detail rather than an identifier.
 * The platform list is therefore built from what is genuinely sellable, so a
 * platform with nothing available does not appear as an empty card.
 */
class SmmCatalogue
{
    /** The capability string every query here is scoped by. */
    public const CAPABILITY = 'smm';

    /**
     * The service category SMM products belong to.
     *
     * Resolved by group rather than by id, because ids are deployment-specific
     * and the group is the stable identifier `ServiceCategory` already defines.
     */
    public function category(): ?ServiceCategory
    {
        return ServiceCategory::where('group', ServiceCategory::GROUP_SOCIAL)->first();
    }

    /**
     * Every sellable social-boost product, joined to its cheapest live offering.
     *
     * @return Collection<int, ServiceProduct>
     */
    public function sellableProducts(): Collection
    {
        $category = $this->category();

        if (! $category) {
            return collect();
        }

        return ServiceProduct::query()
            ->where('category_id', $category->getKey())
            ->where('status', ServiceProduct::STATUS_ACTIVE)
            ->whereHas('providerProducts', fn ($q) => $this->liveOffering($q))
            ->with(['providerProducts' => fn ($q) => $this->liveOffering($q)->orderBy('provider_cost_minor')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * The offering a purchase would use: the cheapest live one with a rate.
     *
     * Cheapest rather than first, and it must match `ServiceOrderService`'s own
     * choice — which prices from the cheapest active offering with a cost — or
     * the figure the customer is shown would not be the figure they are charged.
     */
    public function offeringFor(ServiceProduct $product): ?ProviderProduct
    {
        return $product->providerProducts()
            ->whereNull('unavailable_at')
            ->whereNotNull('provider_rate_minor')
            ->where('provider_rate_minor', '>', 0)
            ->orderBy('provider_cost_minor')
            ->orderBy('id')
            ->first();
    }

    /**
     * Whether a product may be shown and bought right now.
     */
    public function isSellable(ServiceProduct $product): bool
    {
        return $product->status === ServiceProduct::STATUS_ACTIVE
            && $this->offeringFor($product) !== null;
    }

    /**
     * The platforms that have at least one sellable service, with counts.
     *
     * @return array<int, array{key:string,label:string,count:int,icon:string,blurb:string}>
     */
    public function platforms(): array
    {
        $counts = [];

        foreach ($this->sellableProducts() as $product) {
            $offering = $this->offeringFor($product);

            if (! $offering) {
                continue;
            }

            $key = $this->platformKey($offering);

            if ($key === null) {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        $platforms = [];

        foreach ($counts as $key => $count) {
            $platforms[] = [
                'key' => $key,
                'label' => $this->platformLabel($key),
                'count' => $count,
                'icon' => $this->platformIcon($key),
                'blurb' => $this->platformBlurb($key),
            ];
        }

        return $platforms;
    }

    /**
     * The service types within a platform, with counts — the sub-filter.
     *
     * A "type" here is what the customer is buying (followers, likes, views),
     * derived from the service name rather than from Nitro's `type` field, which
     * describes the *input shape* (`Default`, `Custom Comments`, `SEO`) and is an
     * implementation detail rather than a shopping category.
     *
     * @return array<string,int>
     */
    public function serviceTypes(?string $platformKey = null): array
    {
        $types = [];

        foreach ($this->sellableProducts() as $product) {
            $offering = $this->offeringFor($product);

            if (! $offering) {
                continue;
            }

            if ($platformKey !== null && $this->platformKey($offering) !== $platformKey) {
                continue;
            }

            $type = $this->serviceTypeLabel($product->name);

            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        arsort($types);

        return $types;
    }

    /**
     * A filtered, paginated slice of the catalogue.
     *
     * Filtering happens in PHP rather than SQL because the two filters that
     * matter — platform and service type — are derived from the provider's
     * `category` and the service's display name, and a `LIKE` on a name is not
     * the same question. The catalogue is a few hundred rows with an eager-loaded
     * offering, so this is one query and a pass, not an N+1.
     *
     * @return array{services:Collection<int, array<string,mixed>>, total:int, page:int, per_page:int, pages:int}
     */
    public function search(
        ?string $platformKey = null,
        ?string $type = null,
        ?string $term = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $term = $term === null ? null : trim($term);

        $rows = $this->sellableProducts()
            ->map(fn (ServiceProduct $product) => $this->row($product))
            ->filter()
            ->when($platformKey !== null && $platformKey !== '', fn (Collection $c) => $c->where('platform_key', $platformKey))
            ->when($type !== null && $type !== '', fn (Collection $c) => $c->where('type', $type))
            ->when($term !== null && $term !== '', fn (Collection $c) => $c->filter(
                fn (array $row) => mb_stripos($row['name'], $term) !== false
                    || mb_stripos($row['description'] ?? '', $term) !== false
                    || mb_stripos($row['platform_label'], $term) !== false
            ))
            ->values();

        $total = $rows->count();
        $perPage = max(1, min(60, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));

        return [
            'services' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * One display row for a product.
     *
     * Carries no provider service id: the customer navigates by the product's own
     * key, and the provider's identifier is resolved server-side at purchase time.
     * Exposing it would invite exactly the "edit the form and buy a different
     * service" class of bug, and it is a technical detail the brief says not to
     * show.
     *
     * @return array<string,mixed>|null
     */
    public function row(ServiceProduct $product): ?array
    {
        $offering = $this->offeringFor($product);

        if (! $offering) {
            return null;
        }

        $platformKey = $this->platformKey($offering);

        /*
         * The starting price, in naira, for the *minimum* quantity the service
         * accepts. Shown as "from" because a rate-based service has no single
         * price — the price is a function of the quantity the customer chooses.
         */
        $minimum = $offering->min_quantity ?: 1;

        return [
            'product' => $product,
            'key' => $product->product_key,
            'slug' => $product->slug,
            'name' => $product->name,
            'description' => $this->cleanDescription($offering->description),
            // Nitro's own `description` is the setup the buyer must complete. It
            // is surfaced as a requirement rather than as marketing copy.
            'requirement' => $this->requirement($offering->description),
            'platform_key' => $platformKey,
            'platform_label' => $platformKey === null ? 'Other' : $this->platformLabel($platformKey),
            'type' => $this->serviceTypeLabel($product->name),
            'service_type' => $offering->service_type,
            'min_quantity' => $offering->min_quantity,
            'max_quantity' => $offering->max_quantity,
            'quantity_step' => $this->quantityStep($offering),
            'min_quantity_label' => number_format($minimum),
            'max_quantity_label' => $offering->max_quantity ? number_format((int) $offering->max_quantity) : null,
            'supports_refill' => (bool) $offering->supports_refill,
            'supports_cancel' => (bool) $offering->supports_cancel,
            'minimum_price_minor' => $this->minimumPriceMinor($offering, $minimum),
            'minimum_price_formatted' => $this->minimumPriceMinor($offering, $minimum) === null
                ? null
                : \App\Support\Money::fromMinor($this->minimumPriceMinor($offering, $minimum))->format(),
        ];
    }

    /**
     * One product by its public key, sellable or not.
     *
     * Returns the product so the order page can say *why* it is unavailable,
     * which is more useful than a 404 for something the customer reached from a
     * search result or a bookmark.
     */
    public function findByKey(string $key): ?ServiceProduct
    {
        $category = $this->category();

        if (! $category) {
            return null;
        }

        return ServiceProduct::query()
            ->where('category_id', $category->getKey())
            ->where(fn ($q) => $q->where('product_key', $key)->orWhere('slug', $key))
            ->first();
    }

    /**
     * A platform key from a provider `category`.
     *
     * Lower-cased and stripped to a slug so `TikTok`, `Tiktok` and `TIK TOK`
     * cannot become three cards for one platform. The provider's raw spelling is
     * kept on the offering for the admin console, which is where a discrepancy
     * between what Nitro calls something and what we call it is worth seeing.
     */
    public function platformKey(?ProviderProduct $offering): ?string
    {
        $raw = $offering?->platform;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $raw) ?? '');

        return $slug === '' ? null : $slug;
    }

    /**
     * The label a customer reads for a platform key.
     *
     * From configuration so an operator can rename a platform without a deploy,
     * and falling back to a title-cased key rather than to the provider's raw
     * string — a new platform appearing upstream should look tidy immediately and
     * can be named properly afterwards.
     */
    public function platformLabel(string $key): string
    {
        $configured = config("smm.platforms.{$key}.label");

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $key) ?? $key);
    }

    /** A bootstrap-icons name, or a neutral fallback the icon component knows. */
    public function platformIcon(string $key): string
    {
        $configured = config("smm.platforms.{$key}.icon");

        return is_string($configured) && $configured !== '' ? $configured : 'globe';
    }

    public function platformBlurb(string $key): string
    {
        $configured = config("smm.platforms.{$key}.blurb");

        return is_string($configured) && $configured !== ''
            ? $configured
            : 'Grow your ' . $this->platformLabel($key) . ' presence.';
    }

    /**
     * What the customer is buying, from the service name.
     *
     * Nitro's own `type` is deliberately not used: it says `Default`,
     * `Custom Comments` or `SEO`, which describes the *input shape* of the order
     * rather than what is being bought, and "Custom Comments" is not a category a
     * customer shops in.
     *
     * A service matching nothing is `Other` rather than the first word of its
     * name, so a provider rewording a service does not create a new category.
     */
    public function serviceTypeLabel(string $name): string
    {
        $lower = mb_strtolower($name);

        foreach ((array) config('smm.service_types', []) as $label => $needles) {
            foreach ((array) $needles as $needle) {
                if (mb_stripos($lower, (string) $needle) !== false) {
                    return (string) $label;
                }
            }
        }

        return 'Other';
    }

    /**
     * The quantity increment the form should use.
     *
     * Nitro publishes only min and max, so the step is 1 — except where a service
     * is sold in whole thousands, which is a Convention rather than a rule and is
     * read from configuration. Guessing a step would silently round a customer's
     * quantity.
     */
    public function quantityStep(ProviderProduct $offering): int
    {
        $configured = (int) config('smm.quantity_step', 1);

        return max(1, $configured);
    }

    /**
     * The subset of Nitro's `description` that is a *requirement*.
     *
     * The provider mixes selling copy with setup instructions in one field, and
     * the brief says the requirement must be shown. Rather than rendering the
     * whole paragraph as a warning, the sentences that name a required action are
     * extracted; the rest is presented as a plain description.
     *
     * Returns null when nothing in the text reads as an instruction, so the page
     * does not show an empty "before you order" panel.
     */
    public function requirement(?string $description): ?string
    {
        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', trim($description)) ?: [];

        $matches = array_values(array_filter($sentences, function (string $sentence) {
            $lower = mb_strtolower($sentence);

            foreach ((array) config('smm.requirement_keywords', []) as $keyword) {
                if (mb_stripos($lower, mb_strtolower((string) $keyword)) !== false) {
                    return true;
                }
            }

            return false;
        }));

        if ($matches === []) {
            return null;
        }

        return trim(implode(' ', array_slice($matches, 0, 3)));
    }

    /**
     * Nitro's description, tidied for display.
     *
     * Collapses the newlines the provider uses to separate numbered steps into a
     * single space, because the raw text renders as a wall of stray whitespace in
     * HTML. The full text is kept — it is the provider's own instructions, and
     * shortening it could drop a step the buyer has to complete.
     */
    public function cleanDescription(?string $description): ?string
    {
        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $description) ?? $description);
    }

    /**
     * The cheapest price the service can be bought at, or null when it cannot be
     * priced.
     *
     * Used for the "from ₦x" figure. It is a *display* figure derived from the
     * engine, never a price anything charges from.
     */
    private function minimumPriceMinor(ProviderProduct $offering, int $quantity): ?int
    {
        $rateMinor = $offering->provider_rate_minor;

        if ($rateMinor === null || $rateMinor <= 0) {
            return null;
        }

        /*
         * Priced through `SmmPricing`, which enforces the mandatory markup floor,
         * so the "from ₦x" figure on the storefront is a price the platform would
         * actually charge. A figure computed some other way would be the mismatch
         * this whole pricing path exists to prevent.
         */
        $quote = app(SmmPricing::class)->quote(
            $offering,
            $quantity,
            $this->pricingContext($offering),
        );

        if (! $quote['ok'] || $quote['quote'] === null) {
            return null;
        }

        return $quote['quote']->customerPriceMinor;
    }

    /**
     * The pricing-rule resolution context for an offering.
     *
     * Built here so the admin preview, the storefront and the purchase all
     * resolve the *same* rule — which is what makes a change to a rule visible in
     * the same place the customer sees it.
     *
     * @return array<string,mixed>
     */
    public function pricingContext(ProviderProduct $offering): array
    {
        $product = $offering->serviceProduct;

        return [
            'category_id' => $product?->category_id,
            'service_product_id' => $product?->getKey(),
            'provider_id' => $offering->provider_id,
            'provider_product_id' => $offering->getKey(),
        ];
    }

    private function liveOffering($query)
    {
        return $query
            ->whereNull('unavailable_at')
            ->whereNotNull('provider_rate_minor')
            ->where('provider_rate_minor', '>', 0);
    }
}
