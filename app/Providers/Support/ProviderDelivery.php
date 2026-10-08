<?php

namespace App\Providers\Support;

use App\Support\Money;

/**
 * Something the customer paid for and must be able to see: a gift card code, a
 * recharge PIN, an exam PIN, an electricity token, or eSIM activation data.
 *
 * ## Why this is a separate type
 *
 * A delivery token is not a normal part of a provider response. It is the goods.
 * Sogo is explicit that webhooks omit it and that it must be fetched separately,
 * specifically so it does not end up in a log or a webhook archive. Modelling it
 * as its own value object makes that handling explicit: a caller that wants the
 * token has to reach for `$result->delivery`, and a caller that is merely logging
 * a result cannot accidentally serialise it alongside everything else.
 *
 * `toLogContext()` returns field *names* only — never a value — so a debugging
 * line can confirm a token was received without recording what it was.
 *
 * ## At rest
 *
 * The token is encrypted before it is persisted (see
 * `App\Providers\Support\DeliveryTokenStore`). This object holds it in memory for
 * the duration of the request that fetches it and is never cached.
 */
final class ProviderDelivery
{
    /**
     * @param  string  $kind        'gift_card' | 'epin' | 'exam_pin' | 'electricity_token' | 'esim'
     * @param  array<string, mixed>  $fields  the token fields, e.g. code, pin, serial, iccid
     * @param  array<int, array<string, mixed>>  $items  for a multi-unit purchase, one entry per unit
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $fields = [],
        public readonly array $items = [],
    ) {
    }

    public static function giftCard(string $code, ?string $pin = null, ?string $validity = null): self
    {
        return new self('gift_card', array_filter([
            'code' => $code,
            'pin' => $pin,
            'validity' => $validity,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public static function epin(string $pin, ?string $serial = null): self
    {
        return new self('epin', array_filter([
            'pin' => $pin,
            'serial' => $serial,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public static function examPin(string $pin, ?string $serial = null): self
    {
        return new self('exam_pin', array_filter([
            'pin' => $pin,
            'serial' => $serial,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public static function electricityToken(string $token, ?string $units = null): self
    {
        return new self('electricity_token', array_filter([
            'token' => $token,
            'units' => $units,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param  array<string, mixed>  $fields  iccid, smdp_address, activation_code, lpa, …
     */
    public static function esim(array $fields): self
    {
        return new self('esim', $fields);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->items === [];
    }

    /**
     * The fields, for the one code path allowed to render them: an
     * ownership-checked customer request.
     *
     * @return array<string, mixed>
     */
    public function reveal(): array
    {
        return [
            'kind' => $this->kind,
            'fields' => $this->fields,
            'items' => $this->items,
        ];
    }

    /**
     * Field names only, never values.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'delivery_kind' => $this->kind,
            'delivery_fields' => array_keys($this->fields),
            'delivery_items' => count($this->items),
        ];
    }

    /** A count of delivered units, for the order record. */
    public function itemCount(): int
    {
        return $this->items === [] ? ($this->fields === [] ? 0 : 1) : count($this->items);
    }

    /**
     * A redacted summary safe to store on the order row alongside the encrypted
     * token, so a support agent can confirm a delivery exists without reading it.
     *
     * @return array<string, mixed>
     */
    public function toRedactedSummary(): array
    {
        $summary = ['kind' => $this->kind, 'count' => $this->itemCount()];

        foreach ($this->fields as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $summary['masked'][$key] = PayloadRedactor::mask((string) $value);
            }
        }

        return $summary;
    }

    /**
     * The customer's money, where the delivery carries a face value. Used by the
     * fulfilment check, never for pricing.
     */
    public function faceValue(): ?Money
    {
        $value = $this->fields['face_value'] ?? null;

        return $value === null ? null : Money::fromNaira($value);
    }
}
