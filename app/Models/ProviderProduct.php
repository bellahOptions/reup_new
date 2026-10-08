<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One provider's offer of one product.
 *
 * This is where a provider's own identifier lives — Sogo's `variation_code`,
 * VTpass's `variation_code`, Nitro's `service` — so that no controller ever
 * contains one. When a provider renumbers a plan, the sync updates this row and
 * nothing else changes.
 */
class ProviderProduct extends Model
{
    protected $fillable = [
        'uuid',
        'provider_id',
        'service_product_id',
        'provider_product_id',
        'provider_name',
        'provider_cost_minor',
        'provider_currency',
        'provider_amount_minor',
        'rate_used_micros',
        'provider_rate_minor',
        'rate_divisor',
        'denomination',
        'min_quantity',
        'max_quantity',
        'description',
        'service_type',
        'platform',
        'supports_refill',
        'supports_cancel',
        'is_active',
        'required_scope',
        'last_seen_at',
        'unavailable_at',
        'cost_synced_at',
        'previous_cost_minor',
        'cost_changed_at',
    ];

    protected $casts = [
        'provider_cost_minor' => 'integer',
        'provider_amount_minor' => 'integer',
        'rate_used_micros' => 'integer',
        'provider_rate_minor' => 'integer',
        'rate_divisor' => 'integer',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'supports_refill' => 'boolean',
        'supports_cancel' => 'boolean',
        'is_active' => 'boolean',
        'previous_cost_minor' => 'integer',
        'last_seen_at' => 'datetime',
        'unavailable_at' => 'datetime',
        'cost_synced_at' => 'datetime',
        'cost_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $offering) {
            if (empty($offering->uuid)) {
                $offering->uuid = (string) Str::uuid();
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

    public function priceSnapshots()
    {
        return $this->hasMany(ProviderPriceSnapshot::class);
    }

    /** The cost as an exact value object. */
    public function cost(): Money
    {
        return Money::fromMinor((int) $this->provider_cost_minor);
    }

    public function hasCost(): bool
    {
        return $this->provider_cost_minor !== null;
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->unavailable_at === null;
    }

    /**
     * Whether a requested quantity is inside the provider's published bounds.
     *
     * Both bounds are nullable because most providers publish one, the other, or
     * neither for a given product; a null bound means "the provider did not
     * state one" and is therefore not enforced here.
     */
    public function quantityIsWithinBounds(int $quantity): bool
    {
        if ($quantity < 1) {
            return false;
        }

        if ($this->min_quantity !== null && $quantity < $this->min_quantity) {
            return false;
        }

        if ($this->max_quantity !== null && $quantity > $this->max_quantity) {
            return false;
        }

        return true;
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)->whereNull('unavailable_at');
    }
}
