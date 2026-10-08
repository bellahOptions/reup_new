<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Something a provider offers that we have not decided how to sell.
 *
 * This is a *staging* record, not a product. There is deliberately no relation that
 * leads from here to an order: an operator maps a row to a `ProviderProduct`, and
 * only then does it become routable.
 *
 * The distinction matters because it is the boundary between "we noticed this" and
 * "we agreed to sell this". A catalogue sync can do the first safely and can never
 * do the second, which is why it writes here.
 */
class ProviderCatalogueItem extends Model
{
    protected $fillable = [
        'uuid',
        'provider_id',
        'provider_product_id',
        'provider_name',
        'capability',
        'provider_category',
        'network',
        'denomination',
        'provider_cost_minor',
        'payload',
        'mapped_provider_product_id',
        'first_seen_at',
        'last_seen_at',
        'disappeared_at',
    ];

    protected $casts = [
        'provider_cost_minor' => 'integer',
        'payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'disappeared_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function mappedProviderProduct()
    {
        return $this->belongsTo(ProviderProduct::class, 'mapped_provider_product_id');
    }

    public function isMapped(): bool
    {
        return $this->mapped_provider_product_id !== null;
    }

    public function isPresent(): bool
    {
        return $this->disappeared_at === null;
    }

    public function scopeAwaitingMapping($query)
    {
        return $query->whereNull('mapped_provider_product_id')->whereNull('disappeared_at');
    }

    public function scopePresent($query)
    {
        return $query->whereNull('disappeared_at');
    }
}
