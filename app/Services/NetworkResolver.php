<?php

namespace App\Services;

/**
 * Resolves mobile network identifiers to canonical, customer-facing names.
 *
 * ## Why this is a service and not a lookup in a view
 *
 * The receipt printed "ClubKonnect" under the heading "Network" because the only
 * network-ish value on the transaction was the *provider* label written by the
 * payment pipeline. ClubKonnect is the upstream API; it is not the customer's
 * mobile operator, and showing it tells the customer nothing about which network
 * their airtime went to.
 *
 * Three rules follow from that, and this class exists to enforce them:
 *
 *   1. a provider name is never an acceptable answer for a network;
 *   2. an unresolvable network yields a neutral label, not the provider;
 *   3. resolution happens once, server-side, from a validated provider code —
 *      never from the browser, which could name any network it liked.
 *
 * ## Portability
 *
 * A Nigerian number's prefix does not reliably indicate its serving network,
 * because a number can be ported between operators while keeping its prefix. A
 * prefix guess is therefore available as an explicitly-labelled hint for
 * advisory surfaces, and is never the basis for storing a network on a
 * transaction.
 */
class NetworkResolver
{
    /**
     * Resolve a provider-specific identifier to a canonical key.
     *
     * @param  string|null  $code  a provider code (`01`), a slug (`mtn`), or a
     *                             display name (`MTN`). Case is ignored.
     * @param  string|null  $providerSlug  which provider's code space `$code`
     *                                     belongs to, when known.
     * @return string|null the canonical key, or null when it cannot be resolved
     */
    public static function key(?string $code, ?string $providerSlug = null): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $normalised = self::normalise($code);

        /*
         * A provider-specific map is consulted first: numeric codes are
         * ambiguous between providers in principle, and the provider that
         * actually served the request is the authority on what its own code
         * meant.
         */
        if ($providerSlug !== null) {
            $mapped = config("networks.provider_codes.{$providerSlug}.{$code}");

            if (is_string($mapped) && $mapped !== '') {
                return $mapped;
            }

            // The map may be keyed by a normalised form rather than the raw code.
            foreach ((array) config("networks.provider_codes.{$providerSlug}", []) as $candidate => $key) {
                if (self::normalise((string) $candidate) === $normalised) {
                    return $key;
                }
            }
        }

        /*
         * Then any provider's map, for callers that hold a code without knowing
         * which provider produced it. Every current map agrees on the canonical
         * keys, so this cannot contradict a specific-provider lookup.
         */
        foreach ((array) config('networks.provider_codes', []) as $map) {
            foreach ((array) $map as $candidate => $key) {
                if (self::normalise((string) $candidate) === $normalised) {
                    return $key;
                }
            }
        }

        // Finally the canonical keys and their aliases.
        foreach ((array) config('networks.networks', []) as $key => $network) {
            if ($normalised === self::normalise((string) $key)) {
                return $key;
            }

            foreach ((array) ($network['aliases'] ?? []) as $alias) {
                if ($normalised === self::normalise((string) $alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The customer-facing name for a provider identifier.
     *
     * @return string|null null when the network cannot be resolved — callers that
     *                     display to a customer should use `displayName()` so the
     *                     neutral fallback is centralised.
     */
    public static function name(?string $code, ?string $providerSlug = null): ?string
    {
        $key = self::key($code, $providerSlug);

        return $key === null ? null : self::labelFor($key);
    }

    /**
     * The display label for a canonical key, or null if the key is not one.
     *
     * Used by admin screens and by `PricingRule::subjectLabel()`, which need the
     * label without a fallback so an unconfigured rule is visibly unconfigured.
     */
    public static function labelFor(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return config("networks.networks.{$key}.name");
    }

    /**
     * The name to print for a customer, with the neutral fallback.
     *
     * Never returns a provider name. An unresolvable network reads
     * "Network unavailable" rather than something confidently wrong.
     */
    public static function displayName(?string $code, ?string $providerSlug = null): string
    {
        return self::name($code, $providerSlug) ?? self::unavailableLabel();
    }

    /**
     * The neutral fallback label, from configuration.
     *
     * Deliberately not a provider name and deliberately not blank: a blank makes
     * a receipt look broken and invites someone to "fix" it by falling back to
     * whatever value is nearby — which is how the provider name got there.
     */
    public static function unavailableLabel(): string
    {
        return (string) config('networks.unavailable_label', 'Network unavailable');
    }

    /**
     * Whether a value is one of our own provider's names rather than a network.
     *
     * Guards the display path: if a legacy row has "ClubKonnect" sitting in a
     * network-shaped column, it is rejected and the neutral fallback is used.
     */
    public static function isProviderName(?string $value): bool
    {
        $normalised = self::normalise((string) $value);

        if ($normalised === '') {
            return false;
        }

        foreach ((array) config('bills.providers', []) as $slug => $provider) {
            if ($normalised === self::normalise((string) $slug)) {
                return true;
            }

            if ($normalised === self::normalise((string) ($provider['label'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * All canonical networks, for a picker or an admin filter.
     *
     * @return array<string,string> canonical key => customer-facing name
     */
    public static function options(): array
    {
        $options = [];

        foreach ((array) config('networks.networks', []) as $key => $network) {
            $options[$key] = (string) ($network['name'] ?? $key);
        }

        return $options;
    }

    /**
     * An advisory network hint from a Nigerian phone number's prefix.
     *
     * ## This is not an identification
     *
     * Number portability means the prefix proves nothing about the serving
     * network. The return value is therefore a *hint*, marked as such, and the
     * caller must present it that way. It is never written to a transaction's
     * network columns — only `key()` from a provider code may do that.
     *
     * @return array{key:string,name:string,source:string,confidence:string}|null
     */
    public static function inferFromPhone(?string $phone): ?array
    {
        if (! config('networks.allow_prefix_inference', false)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) < 4) {
            return null;
        }

        // Normalise 234803…/0803… to a four-digit prefix.
        if (str_starts_with($digits, '234')) {
            $digits = '0' . substr($digits, 3);
        }

        $prefix = substr($digits, 0, 4);

        foreach ((array) config('networks.prefix_hints', []) as $key => $prefixes) {
            if (in_array($prefix, (array) $prefixes, true)) {
                return [
                    'key' => $key,
                    'name' => self::labelFor($key) ?? $key,
                    'source' => 'phone_prefix',
                    'confidence' => 'low',
                ];
            }
        }

        return null;
    }

    private static function normalise(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)) ?? '');
    }
}
