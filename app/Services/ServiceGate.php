<?php

namespace App\Services;

use Illuminate\Support\Facades\Route;

/**
 * Which services are live, coming soon, or disabled.
 *
 * ## One authority
 *
 * The route guard, the catalogue, the dashboard and the coming-soon page all ask this
 * class the same question, so a service cannot be hidden on one surface and sold on
 * another. That is the failure this class exists to prevent: a flag checked in the
 * middleware but not in the catalogue leaves a dead link, and one checked in the
 * catalogue but not the middleware leaves a live page for a service nobody meant to
 * offer. The second is the expensive one — it takes money.
 *
 * ## The default is "live", and that is the safe direction
 *
 * See `config/features.php`. An unset variable must reproduce the behaviour that
 * existed before the variables did. A flag system that defaults to off turns a
 * forgotten `REUP_SERVICES` line into a site with nothing to sell, which is a worse
 * outcome than a service being briefly visible when somebody meant to hide it — and
 * the latter is recoverable in one edit.
 *
 * Setting `REUP_SERVICES` opts into strict behaviour: it becomes the authoritative
 * live list, and everything else not separately declared falls to coming soon.
 */
class ServiceGate
{
    /** The service works and its page sells. */
    public const LIVE = 'live';

    /** The page exists and announces the feature; nothing can be bought. */
    public const COMING_SOON = 'coming_soon';

    /** The page exists and refuses; there is nothing to announce. */
    public const DISABLED = 'disabled';

    /** Resolved states, keyed by service. Memoised per request. */
    private ?array $states = null;

    /**
     * The state of one service.
     *
     * An unknown key is **disabled**, not live. A caller asking about a service that
     * is not in the config has made a mistake, and the safe reading of a mistake is
     * "do not sell" rather than "sell it".
     */
    public function state(string $service): string
    {
        return $this->states()[$service] ?? self::DISABLED;
    }

    public function isLive(string $service): bool
    {
        return $this->state($service) === self::LIVE;
    }

    public function isComingSoon(string $service): bool
    {
        return $this->state($service) === self::COMING_SOON;
    }

    public function isDisabled(string $service): bool
    {
        return $this->state($service) === self::DISABLED;
    }

    /** Whether the service may be sold — the question the purchase paths ask. */
    public function isSellable(string $service): bool
    {
        return $this->isLive($service);
    }

    /**
     * Every service and its state, in config order.
     *
     * @return array<string,string>
     */
    public function states(): array
    {
        return $this->states ??= $this->resolve();
    }

    /**
     * The configured metadata for a service, or null when it is not configured.
     *
     * @return array<string,mixed>|null
     */
    public function definition(string $service): ?array
    {
        $definition = config("features.services.{$service}");

        return is_array($definition) ? $definition : null;
    }

    /** The customer-facing name, falling back to the key so a page never reads "". */
    public function label(string $service): string
    {
        return (string) ($this->definition($service)['label'] ?? $service);
    }

    /**
     * The services a product belongs to.
     *
     * ## Why this returns a list, not one key
     *
     * A category can be shared. `education` holds both the WAEC result checker and
     * the JAMB e-PIN, so a product's category does not identify one service — and a
     * mapping that assumed it did would have silently pick one of the two and gate
     * the product by a service it does not belong to.
     *
     * ## Why a product has to be mapped at all
     *
     * The catalogue is a list of *products* — `MTN 1GB Monthly`, `Instagram
     * Followers` — and the gate is about *services*. Something has to connect them,
     * or a service switched off in configuration stays purchasable through its
     * product rows, which is the expensive failure: money taken for something nobody
     * meant to sell.
     *
     * The mapping is by the product's category, and deliberately so. A category is a
     * thing an operator creates once and a product inherits, so a new bundle added to
     * the Data category is gated automatically. Mapping by product key would mean
     * every new product needed a line here, and the one that was forgotten would be
     * the one that shipped.
     *
     * @param  \App\Models\ServiceProduct  $product
     * @return array<int,string>
     */
    public function forProduct($product): array
    {
        $category = $product->relationLoaded('category')
            ? $product->getRelation('category')
            : ($product->category_id === null ? null : $product->category()->first());

        if ($category === null) {
            return [];
        }

        return $this->categoryMap()[(string) $category->slug] ?? [];
    }

    /**
     * Whether a product may be sold right now.
     *
     * **Any live service is enough.** A product reachable through two services is
     * sellable while either of them is, because the customer is buying the product
     * and the page they reached it from works. Requiring every service to be live
     * would take the WAEC checker off sale because JAMB was paused.
     *
     * A product belonging to no gated service at all is sellable — the gate only
     * speaks about services it knows, and refusing everything it does not know would
     * make adding a service a prerequisite for selling anything.
     *
     * @param  \App\Models\ServiceProduct  $product
     */
    public function isProductSellable($product): bool
    {
        $services = $this->forProduct($product);

        if ($services === []) {
            return true;
        }

        foreach ($services as $service) {
            if ($this->isSellable($service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Category slug to the services that claim it.
     *
     * Built from the configured services' own `categories` names, so the two cannot
     * drift: adding `'categories' => ['insurance']` to a service in
     * `config/features.php` is the whole change needed to gate a new category.
     *
     * @return array<string,array<int,string>>
     */
    private function categoryMap(): array
    {
        return $this->categoryMap ??= (function () {
            $map = [];

            foreach ((array) config('features.services', []) as $service => $definition) {
                foreach ((array) ($definition['categories'] ?? []) as $slug) {
                    $map[(string) $slug][] = $service;
                }
            }

            return $map;
        })();
    }

    /** @var array<string,array<int,string>>|null */
    private ?array $categoryMap = null;

    /**
     * Services that may be linked to: live ones, plus the ones being announced.
     *
     * A coming-soon service is deliberately included. Its page is worth reaching —
     * it is where a customer learns the feature is coming, and a link in an email or
     * an advertisement should land somewhere that explains itself rather than on a
     * 404.
     *
     * @return array<int,string>
     */
    public function linkable(): array
    {
        return array_values(array_filter(
            array_keys((array) config('features.services', [])),
            fn (string $service) => $this->state($service) !== self::DISABLED,
        ));
    }

    /** Services that can be sold right now. */
    public function sellable(): array
    {
        return array_values(array_filter(
            array_keys((array) config('features.services', [])),
            fn (string $service) => $this->isLive($service),
        ));
    }

    /**
     * The landing page for a service, or null when it is not wired.
     *
     * Returns null for a service whose route does not exist, which is the check that
     * makes a configuration typo visible rather than producing a dead link.
     */
    public function landingRoute(string $service): ?string
    {
        $route = $this->definition($service)['route'] ?? null;

        return is_string($route) && Route::has($route) ? $route : null;
    }

    /**
     * Where a "coming soon" page's call to action points.
     *
     * The dashboard, when it is reachable, because that is the list of what *is*
     * available. The home page otherwise, since a signed-out visitor has no dashboard.
     */
    public function availableNowRoute(): string
    {
        if (auth()->check() && Route::has('dashboard')) {
            return 'dashboard';
        }

        return Route::has('home') ? 'home' : 'login';
    }

    /**
     * Resolve every service's state once.
     *
     * The order of the three checks encodes the precedence, and it is deliberate:
     * an explicit `disabled` beats everything, then an explicit `enabled`, then
     * `coming_soon`. A service named in two variables is a configuration mistake,
     * and the resolution is "the most restrictive wins" — refusing to sell is always
     * the safe outcome of an ambiguous instruction.
     *
     * @return array<string,string>
     */
    private function resolve(): array
    {
        $enabled = $this->list('features.enabled');
        $comingSoon = $this->list('features.coming_soon');
        $disabled = $this->list('features.disabled');

        /*
         * Whether the operator has taken control of the list. An empty
         * `REUP_SERVICES` is indistinguishable from an unset one after `explode`, so
         * "did they set it" is really "did it produce any names".
         */
        $strict = $enabled !== [];

        $states = [];

        foreach (array_keys((array) config('features.services', [])) as $service) {
            $states[$service] = match (true) {
                in_array($service, $disabled, true) => self::DISABLED,
                in_array($service, $enabled, true) => self::LIVE,
                in_array($service, $comingSoon, true) => self::COMING_SOON,
                // Strict mode: the operator named what is live, so anything else is
                // announced but not sold rather than switched on.
                $strict => self::COMING_SOON,
                default => self::LIVE,
            };
        }

        return $states;
    }

    /**
     * One of the three configuration lists.
     *
     * @return array<int,string>
     */
    private function list(string $key): array
    {
        return array_values(array_filter(
            array_map('trim', (array) config($key, [])),
            fn ($value) => is_string($value) && $value !== '',
        ));
    }
}
