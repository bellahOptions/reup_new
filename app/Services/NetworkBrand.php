<?php

namespace App\Services;

/**
 * The one place a mobile network's logo and display name are decided.
 *
 * ## The defect this replaces
 *
 * Network marks were assembled independently in two views, from two different
 * lookups: the airtime page keyed `config('bills.networks')` by ClubKonnect's
 * numeric code, and the pricelist re-keyed the same array by canonical key. Both
 * pointed at `images/networks/*.svg` — placeholder monograms rather than brand
 * artwork — while the actual brand images (`mtn.jpeg`, `airtel.jpeg`, `glo.png`,
 * `t2.png`) sat unused in `public/`. Adding a network mark anywhere meant
 * touching every template, and each one that was missed silently rendered
 * nothing.
 *
 * ## What this owns
 *
 *   * **Which asset** represents a network, keyed by canonical key.
 *   * **What the customer reads**, which is not always the canonical key: 9mobile
 *     trades as **T2mobile**, so the label is derived from configuration rather
 *     than from the key.
 *   * **Which aliases resolve to it** — `9mobile`, `9 Mobile`, `T2`, `T2mobile`,
 *     `etisalat` — so a value from a provider, a legacy transaction row or a
 *     customer's typing all land on one entry.
 *
 * ## What this deliberately does not touch
 *
 * The canonical *key*. A transaction stores `network_code = '9mobile'`, a pricing
 * rule is scoped to `'9mobile'`, and a provider speaks `'03'`. Those are
 * identifiers, not branding: changing one would orphan historical rows and
 * per-network rules, which is why the rebrand is a change to the label and the
 * mark only. Historical transactions keep saying whatever they said — the stored
 * `network_name` wins for display (see `Transactions::getNetworkDisplayAttribute`).
 *
 * ## Where the lookup comes from
 *
 * `config/networks.php` is the single source: it already owns the canonical keys,
 * the provider code maps and the aliases. The asset path lives there too, so
 * there is one file to edit and no second list that can disagree with it.
 */
class NetworkBrand
{
    /**
     * The canonical key for any network identifier or alias, or null.
     *
     * A thin, named pass-through to `NetworkResolver::key()` — which already
     * normalises case, spacing and punctuation and already knows every provider's
     * code space. Reimplementing it here would be the second lookup this class
     * exists to remove, and it would eventually resolve `9 MOBILE` differently.
     */
    public static function key(?string $value, ?string $providerSlug = null): ?string
    {
        return NetworkResolver::key($value, $providerSlug);
    }

    /**
     * The customer-facing name for a canonical key.
     *
     * Falls back to the key itself so a newly added network renders something
     * readable before its label is configured, and null when there is no key at
     * all so the caller can decide what to say.
     */
    public static function label(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return NetworkResolver::labelFor($key) ?? $key;
    }

    /**
     * The label for any identifier or alias, with the neutral fallback.
     *
     * `T2mobile` is what a customer reads for canonical `9mobile`; a value that
     * resolves to nothing reads `Network unavailable` rather than a confidently
     * wrong name.
     */
    public static function labelFor(?string $value, ?string $providerSlug = null): string
    {
        return self::label(self::key($value, $providerSlug)) ?? NetworkResolver::unavailableLabel();
    }

    /**
     * The public URL of a network's logo, or null when it has none configured.
     *
     * `asset()` rather than a bare path so the URL is correct under a subdirectory
     * deployment and behind a CDN — the previous hard-coded `images/networks/…`
     * strings were correct at the domain root and nowhere else.
     */
    public static function logoUrl(?string $key): ?string
    {
        $path = self::logoPath($key);

        return $path === null ? null : asset($path);
    }

    /**
     * The logo path relative to `public/`, as configured.
     *
     * Used by the deployment check in the tests, which asserts every configured
     * asset actually exists — a mark that 404s is the failure this whole class is
     * meant to prevent, and it is invisible in a browser cache.
     */
    public static function logoPath(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $path = config("networks.networks.{$key}.logo");

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The brand colour, for the fallback mark and for accents.
     *
     * A network whose image fails to load is still recognisable by its colour, and
     * a coloured monogram reads as "this is MTN" rather than as a broken image.
     */
    public static function colour(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $colour = config("networks.networks.{$key}.colour");

        return is_string($colour) && $colour !== '' ? $colour : null;
    }

    /**
     * Whether a value is the network it appears to be.
     *
     * Deliberately tolerant: `9mobile`, `9 MOBILE` and `T2mobile` are all yes.
     */
    public static function is(?string $value, string $canonicalKey): bool
    {
        return self::key($value) === $canonicalKey;
    }

    /**
     * Every canonical network with its label and logo, for a picker or a filter.
     *
     * @return array<int, array{key:string,label:string,logo:?string,fallback_logo:?string,colour:?string}>
     */
    public static function all(): array
    {
        $options = [];

        foreach (array_keys((array) config('networks.networks', [])) as $key) {
            $options[] = self::for($key);
        }

        return $options;
    }

    /**
     * One canonical network's presentation.
     *
     * `alt` is the label, not an empty string: the mark sits beside the network
     * name in most of the interface, but it is also used on its own in a filter
     * chip, and a screen reader announcing "graphic" there is a worse experience
     * than announcing the network twice.
     *
     * @return array{key:string,label:string,logo:?string,fallback_logo:?string,colour:?string,alt:string}
     */
    public static function for(?string $key): array
    {
        $label = self::label($key) ?? NetworkResolver::unavailableLabel();

        return [
            'key' => (string) $key,
            'label' => $label,
            'logo' => self::logoUrl($key),
            /*
             * A second asset to try before falling back to a monogram. Kept
             * because a deployment may have one format and not another, and
             * `onerror` can only try one alternative.
             */
            'fallback_logo' => ($alt = self::fallbackLogoPath($key)) === null ? null : asset($alt),
            'colour' => self::colour($key),
            'alt' => $label,
        ];
    }

    /**
     * A secondary asset for a network, if one is configured.
     */
    private static function fallbackLogoPath(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $path = config("networks.networks.{$key}.fallback_logo");

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The URL for a network identifier or alias, resolving through the alias table.
     *
     * The convenience most callers want: they hold a provider code or a stored
     * name, not a canonical key.
     */
    public static function logoUrlFor(?string $value, ?string $providerSlug = null): ?string
    {
        return self::logoUrl(self::key($value, $providerSlug));
    }
}
