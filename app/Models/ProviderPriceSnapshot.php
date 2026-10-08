<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of what a provider charged at a point in time.
 *
 * `provider_products.provider_cost_minor` answers "what does it cost now". This
 * table answers "what did it cost then", which is what a cost-increase alert and
 * a provider cost-change history are built from. Nothing updates a row here.
 */
class ProviderPriceSnapshot extends Model
{
    public const SOURCE_SYNC = 'sync';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ORDER = 'order';

    public $timestamps = false;

    protected $fillable = [
        'provider_product_id',
        'cost_minor',
        'provider_currency',
        'provider_amount_minor',
        'rate_used_micros',
        'source',
        'recorded_at',
    ];

    protected $casts = [
        'cost_minor' => 'integer',
        'provider_amount_minor' => 'integer',
        'rate_used_micros' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function providerProduct()
    {
        return $this->belongsTo(ProviderProduct::class);
    }

    public function cost(): Money
    {
        return Money::fromMinor((int) $this->cost_minor);
    }
}
