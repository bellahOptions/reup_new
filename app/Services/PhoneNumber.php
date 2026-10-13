<?php

namespace App\Services;

/**
 * Nigerian mobile number parsing, and the network detection built on it.
 *
 * ## Why this is one class and not a validation rule plus a helper
 *
 * "Which network is this number?" is asked in three different places — the page as
 * the customer types, the detection endpoint, and the purchase handlers — and each
 * one needs the same two things: a number parsed the same way, and a detection
 * whose uncertainty is represented rather than hidden. Splitting those across a
 * regex in a controller and a lookup in a helper is how the page and the server end
 * up disagreeing about which network a number is on, which is precisely the defect
 * this class exists to close.
 *
 * ## The detection is a prefix hint, and says so
 *
 * See `detect()`. Nigerian mobile number portability means a prefix does not
 * determine the serving network, so nothing here returns a bare network key that a
 * caller could mistake for a fact. It returns a *candidate* with a confidence, and
 * the confidence for a prefix is always `uncertain`.
 *
 * ## Nothing here contacts anyone
 *
 * ClubKonnect publishes no number-to-network endpoint, and `AbstractProviderAdapter`
 * has no method for one, so no detection result may claim to be a provider
 * verification — see `detect()`'s `source` field. Inventing such a call is exactly
 * what this class refuses to do.
 */
final class PhoneNumber
{
    /** Nigeria's country calling code, without the plus. */
    public const COUNTRY_CODE = '234';

    /** Nigerian mobile numbers are ten digits after the leading zero. */
    public const NATIONAL_LENGTH = 11;

    /**
     * A detection based on the number's prefix.
     *
     * The confidence value is a string rather than a bool so a caller can word a
     * message from it without a second lookup.
     */
    public const CONFIDENCE_CERTAIN = 'certain';

    /** A prefix hint. Real, useful, and not proof — see the class docblock. */
    public const CONFIDENCE_UNCERTAIN = 'uncertain';

    public const SOURCE_PREFIX = 'phone_prefix';

    /** The customer told us which network it is. */
    public const SOURCE_CUSTOMER = 'customer';

    /* =====================================================================
     | Parsing
     |=================================================================== */

    /**
     * Parse a number into its canonical form, or explain why it cannot be.
     *
     * Accepts the spellings a Nigerian customer actually types:
     *
     *   08012345678      local, the usual form
     *   +2348012345678   international with the plus
     *   2348012345678    international without it
     *   0801 234 5678    spaced, bracketed or hyphenated
     *
     * @return array{valid:bool,reason:?string,national:?string,international:?string,network_hint:?array}
     */
    public static function parse(?string $input): array
    {
        $raw = trim((string) $input);

        // Digits only: the separators a customer may type carry no information, and
        // a number is not rejected for containing them.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return self::invalid('Enter your phone number.');
        }

        /*
         * `+234…` and `234…` are the same number. The country code is stripped
         * rather than preserved so that only one shape reaches the prefix lookup —
         * a lookup that had to try both is one that will eventually try only one.
         */
        if (str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) === 13) {
            $digits = '0' . substr($digits, 3);
        }

        /*
         * A number that begins with the country code but has the wrong length is
         * reported as such rather than falling through to "not a Nigerian number",
         * which would send the customer looking for a different mistake.
         */
        if (str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) > self::NATIONAL_LENGTH + 1) {
            return self::invalid('That number looks too long. Check it and try again.');
        }

        /*
         * A leading `+` with any other country code is a real number in a country
         * ReUp does not sell mobile top-ups in. Saying so is more useful than
         * "invalid", and it is *not* an invitation to guess a Nigerian network for
         * it.
         */
        if (str_starts_with($raw, '+') && ! str_starts_with($digits, '0')) {
            return self::invalid('ReUp sells Nigerian mobile services, so we can only send to Nigerian numbers.');
        }

        if (strlen($digits) !== self::NATIONAL_LENGTH) {
            return self::invalid(
                'A Nigerian mobile number has 11 digits. You entered ' . strlen($digits) . '.'
            );
        }

        if (! str_starts_with($digits, '0')) {
            return self::invalid('A Nigerian mobile number starts with 0. Check it and try again.');
        }

        /*
         * `0800` and `0700` are eleven digits and pass the shape test below, but
         * they are **not mobile numbers**: the NCC plan assigns 0800 to toll-free
         * and 0700 to toll numbers, and both are documented as ten-digit services.
         * A data bundle or a top-up cannot be delivered to one, so the number is
         * refused here rather than sent to a network the customer does not have.
         */
        if (preg_match('/^0[78]00/', $digits)) {
            return self::invalid('That is a toll-free or toll number, not a mobile number. Check it and try again.');
        }

        /*
         * Nigerian mobile numbers begin 070, 080, 081, 090 or 091. Anything else in
         * that position is a landline or a typo, and either way is not a number a
         * mobile bundle can be sent to.
         */
        if (! preg_match('/^0[789][01]\d{8}$/', $digits)) {
            return self::invalid('That is not a Nigerian mobile number. Check it and try again.');
        }

        return [
            'valid' => true,
            'reason' => null,
            'national' => $digits,
            'international' => self::COUNTRY_CODE . substr($digits, 1),
            'network_hint' => self::hint($digits),
        ];
    }

    /** The canonical `234…` form, or null when the number is not valid. */
    public static function international(?string $input): ?string
    {
        return self::parse($input)['international'];
    }

    /** The local `0801…` form, or null when the number is not valid. */
    public static function national(?string $input): ?string
    {
        return self::parse($input)['national'];
    }

    public static function isValid(?string $input): bool
    {
        return self::parse($input)['valid'];
    }

    /**
     * How a number is shown to a customer: `0801 234 5678`.
     *
     * A raw eleven-digit string is hard to check against a phone, and checking it
     * is how a wrong number is caught before money moves rather than after.
     */
    public static function format(?string $input): string
    {
        $national = self::national($input);

        if ($national === null) {
            return trim((string) $input);
        }

        return substr($national, 0, 4) . ' ' . substr($national, 4, 3) . ' ' . substr($national, 7);
    }

    /* =====================================================================
     | Detection
     |=================================================================== */

    /**
     * Which network a number is *probably* on.
     *
     * ## What this can and cannot establish
     *
     * It reads the four-digit prefix and matches it against
     * `networks.prefix_hints`. That is a genuine signal — Nigerian prefixes are
     * allocated per operator — and it is **not** proof, because a number may be
     * ported between operators while keeping its prefix. Nigeria has had mobile
     * number portability since 2013.
     *
     * ## Why no provider call appears here
     *
     * Neither integration ReUp holds publishes a number-to-network lookup.
     * ClubKonnect's documented surface is airtime, data, bills and its own
     * catalogue; Pairgate's verification endpoints are for meters and smartcards,
     * not mobile numbers. There is therefore **no authoritative lookup available**,
     * and the honest consequence is that detection stays a hint that the customer
     * must confirm. A `source` of `phone_prefix` is the whole truth of how the
     * answer was reached, and no caller may present it as a provider verification.
     *
     * @return array{key:?string,name:?string,confidence:string,source:string}|null
     *         null when the number itself is unusable; `key` is null when the
     *         prefix matches no network we hold, which is a real outcome a caller
     *         must handle rather than round to a default.
     */
    public static function detect(?string $input): ?array
    {
        $parsed = self::parse($input);

        if (! $parsed['valid']) {
            return null;
        }

        $hint = $parsed['network_hint'];

        if ($hint === null) {
            /*
             * A valid Nigerian mobile number on a prefix we do not hold. It may be a
             * newly allocated range, or a range we have not listed. Either way the
             * customer is asked to choose, and **no network is guessed** — silently
             * picking one is how an MTN bundle gets sent to a Glo number.
             */
            return [
                'key' => null,
                'name' => null,
                'confidence' => self::CONFIDENCE_UNCERTAIN,
                'source' => self::SOURCE_PREFIX,
            ];
        }

        return [
            'key' => $hint['key'],
            'name' => $hint['name'],
            'confidence' => self::CONFIDENCE_UNCERTAIN,
            'source' => self::SOURCE_PREFIX,
        ];
    }

    /**
     * A detection the customer has explicitly confirmed.
     *
     * Distinct from `detect()` because it carries a different claim. The key is
     * still resolved through `NetworkResolver`, so a confirmed value that is not a
     * network we hold returns null rather than being trusted.
     *
     * @return array{key:?string,name:?string,confidence:string,source:string}|null
     */
    public static function confirmed(?string $networkCode, ?string $providerSlug = null): ?array
    {
        $key = NetworkResolver::key($networkCode, $providerSlug);

        if ($key === null) {
            return null;
        }

        return [
            'key' => $key,
            'name' => NetworkResolver::labelFor($key) ?? $key,
            'confidence' => self::CONFIDENCE_CERTAIN,
            'source' => self::SOURCE_CUSTOMER,
        ];
    }

    /**
     * The prefix hint for an already-validated local number.
     *
     * @return array{key:string,name:string}|null
     */
    private static function hint(string $national): ?array
    {
        if (! config('networks.allow_prefix_inference', false)) {
            return null;
        }

        $prefix = substr($national, 0, 4);

        foreach ((array) config('networks.prefix_hints', []) as $key => $prefixes) {
            if (in_array($prefix, (array) $prefixes, true)) {
                return [
                    'key' => $key,
                    'name' => NetworkResolver::labelFor($key) ?? $key,
                ];
            }
        }

        return null;
    }

    /** @return array{valid:bool,reason:string,national:null,international:null,network_hint:null} */
    private static function invalid(string $reason): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
            'national' => null,
            'international' => null,
            'network_hint' => null,
        ];
    }
}
