<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One international top-up product as a provider offers it.
 *
 * ## Why this is not a `provider_products` row
 *
 * Every other Wave 1 product has a cost the provider states in naira, so
 * `provider_products.provider_cost_minor` is the whole story. An international
 * product does not: the provider quotes in the recipient's currency and converts
 * at a rate that moves, and it takes a fee or pays a commission on top. Storing only
 * a naira figure would throw away the rate it came from, and a price whose rate is
 * unrecorded cannot be explained to a customer or defended when the rate moves.
 *
 * This row therefore keeps the whole derivation — provider amount, rate, rate
 * source, fee, resulting cost — so the pricing engine has exact inputs and an
 * auditor has the reasoning.
 *
 * ## The unique index is the idempotency guard
 *
 * `(provider_id, provider_product_id)` is unique. A catalogue sync that runs twice,
 * or two syncs that overlap, update one row rather than creating two products with
 * two prices. The same reasoning as `service_orders.provider_idempotency_key`: the
 * database is the authority, not the code that hopes it ran once.
 */
class InternationalProduct extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const RATE_SOURCE_PROVIDER_PREVIEW = 'provider_preview';
    public const RATE_SOURCE_PROVIDER_CATALOGUE = 'provider_catalogue';
    public const RATE_SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'uuid',
        'provider_id',
        'service_product_id',
        'provider_product_id',
        'country_code',
        'country_name',
        'dial_prefix',
        'provider_currency',
        'operator_id',
        'operator_name',
        'product_type_id',
        'product_type_name',
        'product_name',
        'variation_code',
        'provider_amount_minor',
        'is_fixed_price',
        'provider_cost_minor',
        'provider_fee_minor',
        'exchange_rate_micros',
        'exchange_rate_source',
        'customer_price_minor',
        'previous_cost_minor',
        'cost_changed_at',
        'status',
        'metadata',
        'last_synced_at',
        'unavailable_at',
    ];

    protected $casts = [
        'provider_amount_minor' => 'integer',
        'is_fixed_price' => 'boolean',
        'provider_cost_minor' => 'integer',
        'provider_fee_minor' => 'integer',
        'exchange_rate_micros' => 'integer',
        'customer_price_minor' => 'integer',
        'previous_cost_minor' => 'integer',
        'metadata' => 'array',
        'cost_changed_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'unavailable_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function serviceProduct()
    {
        return $this->belongsTo(ServiceProduct::class);
    }

    /**
     * Whether this row can be sold.
     *
     * Three conditions, and all three matter: the row is active, the provider has
     * not removed it, and it has been mapped to one of our own sellable products. An
     * unmapped row is a catalogue entry an operator has not yet decided how to sell
     * — visible on the sync screen and deliberately not purchasable.
     */
    public function isSellable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->unavailable_at === null
            && $this->service_product_id !== null
            && $this->provider_cost_minor !== null;
    }

    /** The exchange rate as a decimal string, e.g. '15.500000'. */
    public function exchangeRate(): ?string
    {
        if ($this->exchange_rate_micros === null) {
            return null;
        }

        return number_format($this->exchange_rate_micros / 1000000, 6, '.', '');
    }

    public function scopeSellable($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNull('unavailable_at')
            ->whereNotNull('service_product_id')
            ->whereNotNull('provider_cost_minor');
    }

    public function scopeForCountry($query, string $countryCode)
    {
        return $query->where('country_code', strtoupper($countryCode));
    }
}
