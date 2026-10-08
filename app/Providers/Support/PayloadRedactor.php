<?php

namespace App\Providers\Support;

/**
 * Redacts credentials and sensitive delivery tokens from anything on its way to
 * a log file or a `provider_transactions` row.
 *
 * Two distinct classes of secret travel through a provider adapter:
 *
 *   1. **Credentials** — API keys, OAuth client secrets, bearer tokens. These
 *      arrive from the environment and must never be written anywhere.
 *   2. **Delivery tokens** — gift card codes and PINs, recharge-card e-PINs,
 *      exam PINs, electricity tokens, eSIM activation codes and ICCIDs. These
 *      are the thing the customer paid for; logging one means anyone with log
 *      access can redeem it, and it is exactly the mistake Sogo's own webhook
 *      design avoids by omitting them from payloads.
 *
 * The redactor is applied to every request and response payload the adapters
 * persist, so a provider that starts returning a token in a field this
 * application has not seen before is still protected: the token-shaped *keys*
 * are matched rather than the values.
 */
final class PayloadRedactor
{
    /**
     * Key fragments that mark a value as a credential.
     *
     * Matched as substrings so that `access_token`, `x-api-key` and
     * `paystack_secret_key` are all caught, but combined with the exact delivery
     * check below so `token` is classified as the credential it usually is.
     */
    private const CREDENTIAL_KEYS = [
        'api_key', 'apikey', 'api_secret', 'secret', 'client_secret', 'clientsecret',
        'password', 'passwd', 'token', 'credential', 'authorization',
        'private_key', 'signature', 'webhook_secret',
    ];

    /**
     * Key fragments that mark a value as a purchasable delivery token.
     *
     * Matched exactly, not as substrings, because the short names here collide
     * with innocuous fields: `code` is a substring of `countryCode`, `pin` of
     * `shipping`, and `ac` of almost everything. A substring match on these
     * would redact an entire response and leave the log useless.
     */
    private const DELIVERY_KEYS_EXACT = [
        'pin', 'pincode', 'pin_code', 'code', 'redeem_code', 'vouchercode',
        'card_number', 'cardnumber', 'serial', 'serial_number', 'serialnumber',
        'epin', 'e_pin', 'ac', 'lpa', 'qr', 'qrcode', 'qr_code', 'token',
        'iccid', 'smdp', 'smdp_address', 'matching_id',
    ];

    /**
     * Longer, unambiguous delivery-token fragments, safe to match as substrings.
     */
    private const DELIVERY_KEY_FRAGMENTS = [
        'giftcardcode', 'gift_card_code', 'activationcode', 'activation_code',
        'activationdata', 'activation_data', 'matchingid', 'matching_id',
        'smdpaddress', 'sm_dp_address', 'electricitytoken', 'electricity_token',
        'unitstoken', 'units_token', 'redemptioncode', 'redemption_code',
    ];

    /** Fields whose value is a phone number or account identifier. */
    private const PERSONAL_KEYS = [
        'phone', 'mobile', 'msisdn', 'recipient_phone', 'sender_phone',
        'meter_number', 'smartcard', 'smartcard_number', 'account_id',
        'customer_id', 'profile_id', 'iccid', 'link', 'target',
    ];

    public const REDACTED = '[redacted]';

    /**
     * Redact a payload recursively.
     *
     * @param  mixed  $payload
     * @return mixed
     */
    public static function redact($payload)
    {
        if (is_array($payload)) {
            $result = [];

            foreach ($payload as $key => $value) {
                $result[$key] = self::redactValue((string) $key, $value);
            }

            return $result;
        }

        return $payload;
    }

    /**
     * Redact a single key/value pair.
     *
     * `$depth` bounds the recursion so a self-referential structure cannot hang
     * the request that is trying to log a failure.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function redactValue(string $key, $value, int $depth = 0)
    {
        if ($depth > 12) {
            return self::REDACTED;
        }

        /*
         * Delivery tokens and credentials are checked before the personal-
         * identifier mask, because the lists overlap. `pin` is a delivery token
         * and is also matched by the personal-key fragment list; masking it would
         * leave four characters of a real PIN in the payload. Redacting is the
         * safe outcome when a key matches both.
         */
        if (self::isDeliveryToken($key) || self::isCredential($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = self::redactValue((string) $childKey, $childValue, $depth + 1);
            }

            return $result;
        }

        /*
         * Identifiers are masked rather than removed: support needs to correlate
         * a request with a customer, and a masked tail does that without writing
         * a full phone number to disk.
         */
        if (self::matches($key, self::PERSONAL_KEYS) && is_scalar($value) && $value !== '') {
            return self::mask((string) $value);
        }

        if (is_string($value)) {
            // A credential can also appear inside a free-text value (a URL with a
            // query string, a provider echoing the request in an error message).
            return self::redactString($value);
        }

        return $value;
    }

    private static function isCredential(string $key): bool
    {
        return self::matches($key, self::CREDENTIAL_KEYS);
    }

    /**
     * Whether a key names a value the customer paid for.
     *
     * Two matching strategies, because the lists have different collision
     * profiles:
     *
     *   * the long fragments (`activation_code`, `matching_id`) are matched as
     *     substrings, which is safe because nothing innocuous contains them;
     *   * the short names (`pin`, `code`, `ac`, `qr`) are matched as whole
     *     normalised tokens with an explicit false-positive list, because
     *     `countryCode` contains `code` and `shipping` contains `pin`. A
     *     substring match there would redact an entire response and make the log
     *     useless, which is its own kind of failure.
     */
    private static function isDeliveryToken(string $key): bool
    {
        $normalised = ProviderStatus::normalise($key);

        foreach (self::DELIVERY_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalised, ProviderStatus::normalise($fragment))) {
                return true;
            }
        }

        if (in_array($normalised, self::DELIVERY_FALSE_POSITIVES, true)) {
            return false;
        }

        foreach (self::DELIVERY_KEYS_EXACT as $exact) {
            if ($normalised === $exact) {
                return true;
            }

            /*
             * A compound name such as `pinCode` or `giftCode` normalises to
             * `pincode`/`giftcode`. Requiring the exact token to sit at a word
             * boundary — which, after normalisation, means being the whole string
             * or being preceded/followed by a word that starts with it — is what
             * keeps `countryCode` out while catching `pinCode`.
             */
            if (str_starts_with($normalised, $exact) && self::startsWithDeliveryPrefix($normalised, $exact)) {
                return true;
            }

            if (str_ends_with($normalised, $exact) && self::endsWithDeliverySuffix($normalised, $exact)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compound delivery-key prefixes that are genuinely delivery fields.
     *
     * An allowlist rather than a heuristic: `pin` + anything is a PIN, but `code`
     * + anything is usually not a gift card code.
     */
    private const DELIVERY_PREFIXES = [
        'pin', 'gift', 'voucher', 'redeem', 'serial', 'activation', 'epin',
    ];

    /** Compound delivery-key suffixes that are genuinely delivery fields. */
    private const DELIVERY_SUFFIXES = [
        'pin', 'code', 'serial', 'iccid', 'lpa', 'token',
    ];

    /**
     * Keys that contain a delivery-token substring but are not delivery tokens.
     *
     * The `*_code` suffix rule is deliberately conservative — anything ending in
     * `code` is redacted unless it is named here — because a delivery token this
     * list has not seen is a gift card code written to disk. The cost of that
     * choice is that it also catches *catalogue identifiers*, which are not
     * secrets: they name a plan, not the goods.
     *
     * That distinction matters more than it looks. `provider_products` is keyed by
     * the provider's own variation code, so redacting `variation_code` does not
     * merely lose a field — every synced plan collapses onto the same
     * `[redacted]` key and the catalogue silently becomes one product. Found by the
     * catalogue sync tests, which is why they assert on the identifier rather than
     * on a count.
     *
     * Everything listed here is an identifier. The goods — a PIN, a serial, a
     * redemption code — remain redacted, and the prefixes list below still catches
     * `serialCode`, `pinCode`, `giftCode` and friends.
     */
    private const DELIVERY_FALSE_POSITIVES = [
        'countrycode', 'currencycode', 'errorcode', 'statuscode', 'responsecode',
        'zipcode', 'postalcode', 'sortcode', 'bankcode', 'typecode',
        'shipping', 'shippingaddress', 'mapping', 'capacity',

        /* Catalogue and routing identifiers. */
        'variationcode', 'plancode', 'productcode', 'servicecode', 'operatorcode',
        'networkcode', 'bundlecode', 'packagecode', 'discountcode', 'categorycode',
    ];

    private static function startsWithDeliveryPrefix(string $normalised, string $exact): bool
    {
        foreach (self::DELIVERY_PREFIXES as $prefix) {
            if ($exact === $prefix && str_starts_with($normalised, $exact)) {
                return true;
            }
        }

        return false;
    }

    private static function endsWithDeliverySuffix(string $normalised, string $exact): bool
    {
        foreach (self::DELIVERY_SUFFIXES as $suffix) {
            if ($exact === $suffix && str_ends_with($normalised, $exact)) {
                // `errorcode` and friends are caught by the false-positive list
                // before this point; this guard covers a compound the list has
                // not seen yet.
                return ! str_ends_with($normalised, 'status' . $exact)
                    && ! str_ends_with($normalised, 'error' . $exact)
                    && ! str_ends_with($normalised, 'country' . $exact);
            }
        }

        return false;
    }

    /**
     * Scrub credential-shaped text out of an arbitrary string.
     *
     * Handles the two shapes that actually occur: a URL query string (Sogo and
     * ClubKonnect both accept the key as a parameter on some endpoints), and an
     * inline `key=value` or `Bearer xyz`.
     */
    public static function redactString(string $value): string
    {
        // Any query string at all, rather than enumerating parameter names.
        $value = preg_replace('~\?[^\s\'"]+~', '?[redacted]', $value) ?? $value;

        foreach ([
            '/(Bearer\s+)[A-Za-z0-9\-\._~+\/]+=*/i',
            '/((?:api[_-]?key|api[_-]?secret|client[_-]?secret|secret|password|access[_-]?token|token)["\']?\s*[:=]\s*["\']?)[A-Za-z0-9\-\._~+\/]{6,}/i',
            '/\b(sk|pk|sogo_sk|sogo_pk)_(live|test)_[A-Za-z0-9]+/i',
        ] as $pattern) {
            $value = preg_replace($pattern, '$1' . self::REDACTED, $value) ?? $value;
        }

        return $value;
    }

    /** Keep the last four characters; enough to correlate, not enough to abuse. */
    public static function mask(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length === 0) {
            return '';
        }

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', max(1, $length - 4)) . substr($value, -4);
    }

    /**
     * @param  array<int, string>  $fragments
     */
    private static function matches(string $key, array $fragments): bool
    {
        $normalised = ProviderStatus::normalise($key);

        foreach ($fragments as $fragment) {
            if (str_contains($normalised, ProviderStatus::normalise($fragment))) {
                return true;
            }
        }

        return false;
    }
}
