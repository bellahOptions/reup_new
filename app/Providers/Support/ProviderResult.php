<?php

namespace App\Providers\Support;

use App\Support\Money;

/**
 * What a provider call returned, in a shape the pipeline can act on without
 * knowing which provider produced it.
 *
 * The important part is that `status` is always one of the canonical
 * `ProviderStatus` values, and that a *transport* failure and a *provider*
 * rejection are distinguishable. `fromTransportFailure()` produces UNKNOWN,
 * never FAILED: a call that never completed may still have been processed.
 */
final class ProviderResult
{
    public function __construct(
        public readonly string $status,
        /** The provider's own status string, verbatim, for support and audit. */
        public readonly ?string $providerStatus = null,
        /** The provider's identifier for this transaction. */
        public readonly ?string $providerReference = null,
        /**
         * What the provider says it charged, in minor units. Compared against the
         * cost we calculated — never trusted as the price.
         */
        public readonly ?int $providerCostMinor = null,
        public readonly ?string $providerCurrency = null,
        /** The provider's own amount field, in minor units, for cross-checking. */
        public readonly ?int $providerAmountMinor = null,
        /** The full response, already redacted of credentials and delivery tokens. */
        public readonly ?array $payload = null,
        public readonly ?string $message = null,
        /** Provider error code, where it publishes one. */
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        /** True when the request was rejected for rate limiting and was backed off. */
        public readonly bool $rateLimited = false,
        /** The delivery token (PIN, gift card code, eSIM data), if one was returned. */
        public readonly ?ProviderDelivery $delivery = null,
        /**
         * The decoded response body **before** redaction, and only for the calls that
         * ask for it.
         *
         * ## Why this exists
         *
         * `payload` is redacted, which is right for anything persisted or logged but
         * makes it unusable for reading a *catalogue*: the redactor redacts by key
         * name, and the same key name means different things to different providers.
         * VTpass uses `code` for a country code; Sogo uses `code` for a gift card
         * number. A path-blind redactor cannot tell them apart, so it redacts both —
         * and a catalogue sync reading `payload` sees `[redacted]` where the country
         * code should be.
         *
         * ## The rule for using it
         *
         * **Never persist, log, serialise or return this.** It is populated only for
         * read-only catalogue calls (`sendRead()`), precisely so the order pipeline
         * never holds one — a purchase result's `raw` is null by construction. Code
         * that touches this reads identifiers and nothing else.
         *
         * `toLogContext()` deliberately does not include it.
         */
        public readonly ?array $raw = null,
    ) {
    }

    /**
     * A copy carrying the unredacted body.
     *
     * @param  array<string, mixed>  $raw
     */
    public function withRaw(array $raw): self
    {
        return new self(
            status: $this->status,
            providerStatus: $this->providerStatus,
            providerReference: $this->providerReference,
            providerCostMinor: $this->providerCostMinor,
            providerCurrency: $this->providerCurrency,
            providerAmountMinor: $this->providerAmountMinor,
            payload: $this->payload,
            message: $this->message,
            errorCode: $this->errorCode,
            httpStatus: $this->httpStatus,
            rateLimited: $this->rateLimited,
            delivery: $this->delivery,
            raw: $raw,
        );
    }

    /* =====================================================================
     | Constructors
     |=================================================================== */

    /**
     * A transport failure — timeout, DNS failure, TLS error, malformed body.
     *
     * Always UNKNOWN. The request left this process; whether the provider acted
     * on it is unknowable from here, and guessing is what causes double vends and
     * give-away refunds.
     */
    public static function fromTransportFailure(string $message, ?string $errorCode = null): self
    {
        return new self(
            status: ProviderStatus::UNKNOWN,
            message: $message,
            errorCode: $errorCode,
        );
    }

    /**
     * A rate limit. Explicitly retryable, because the provider has told us it
     * did not process the request.
     */
    public static function rateLimited(?string $message = null): self
    {
        return new self(
            status: ProviderStatus::RETRYABLE,
            message: $message ?? 'The provider is rate limiting us.',
            errorCode: 'rate_limited',
            httpStatus: 429,
            rateLimited: true,
        );
    }

    /**
     * A response this application cannot interpret — a malformed body, or a
     * status string no mapping covers.
     *
     * UNKNOWN, never FAILED. An unrecognised status is not evidence that the
     * customer was not served.
     */
    public static function fromUnrecognised(string $rawStatus, ?array $payload = null, ?int $httpStatus = null): self
    {
        return new self(
            status: ProviderStatus::UNKNOWN,
            providerStatus: $rawStatus,
            payload: $payload,
            message: "Unrecognised provider status [{$rawStatus}].",
            httpStatus: $httpStatus,
        );
    }

    /* =====================================================================
     | Predicates
     |=================================================================== */

    public function isSuccess(): bool
    {
        return $this->status === ProviderStatus::SUCCESS;
    }

    public function isUnresolved(): bool
    {
        return ! ProviderStatus::isResolved($this->status);
    }

    public function isUnknown(): bool
    {
        return $this->status === ProviderStatus::UNKNOWN;
    }

    /** Only this permits another provider to be tried. */
    public function permitsFailover(): bool
    {
        return ProviderStatus::permitsFailover($this->status);
    }

    public function warrantsRefund(): bool
    {
        return ProviderStatus::warrantsRefund($this->status);
    }

    /** The provider's cost as an exact value, or null when it did not report one. */
    public function cost(): ?Money
    {
        return $this->providerCostMinor === null ? null : Money::fromMinor($this->providerCostMinor);
    }

    /**
     * Whether the provider's reported charge agrees with the cost we calculated.
     *
     * Nitro returns the charge it applied; if that disagrees with
     * `rate × quantity ÷ 1000` then either the rate changed under us or the
     * divisor is wrong, and in both cases the margin we quoted is not the margin
     * we earned. A mismatch is logged loudly rather than silently accepted.
     *
     * A null on either side means "the provider did not tell us", which is not a
     * mismatch.
     */
    public function costMatches(int $expectedCostMinor): ?bool
    {
        if ($this->providerCostMinor === null) {
            return null;
        }

        return $this->providerCostMinor === $expectedCostMinor;
    }

    /**
     * A safe description for a log line. Credentials and delivery tokens are
     * already absent from `payload`; the message is scrubbed again defensively.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return array_filter([
            'status' => $this->status,
            'provider_status' => $this->providerStatus,
            'provider_reference' => $this->providerReference,
            'error_code' => $this->errorCode,
            'http_status' => $this->httpStatus,
            'rate_limited' => $this->rateLimited ?: null,
            'message' => $this->message ? PayloadRedactor::redactString($this->message) : null,
        ], fn ($value) => $value !== null && $value !== false);
    }
}
