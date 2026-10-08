<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A product ReUp sells, and its publication state.
 *
 * ## The publication gate
 *
 * A product discovered in a provider catalogue is created `DISCOVERED` and is
 * invisible to customers. It becomes visible only when a Super Admin sets it
 * `ACTIVE`. That gate is the whole point of the status column: it is what stops
 * a newly synced SMM catalogue — which routinely contains services with
 * purchaser pre-requisites, poor descriptions, or rates that cannot be sold at a
 * margin — from appearing on the storefront the moment a sync runs.
 *
 * ## Availability versus status
 *
 * `status` is a human decision. `availability` is computed from the margin: if a
 * provider raises its cost and the active pricing rule can no longer meet the
 * configured minimum profit, the product becomes `TEMPORARILY_UNAVAILABLE` (or
 * `AVAILABLE_WITH_WARNING`) without anyone editing it. The two are separate so
 * that an automatic margin change never silently overwrites an operator's
 * deliberate "paused".
 */
class ServiceProduct extends Model
{
    public const STATUS_DISCOVERED = 'DISCOVERED';
    public const STATUS_REVIEW = 'REVIEW';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_PAUSED = 'PAUSED';
    public const STATUS_OUT_OF_STOCK = 'OUT_OF_STOCK';
    public const STATUS_UNPROFITABLE = 'UNPROFITABLE';
    public const STATUS_PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';
    public const STATUS_DISCONTINUED = 'DISCONTINUED';

    public const AVAILABILITY_AVAILABLE = 'AVAILABLE';
    public const AVAILABILITY_WITH_WARNING = 'AVAILABLE_WITH_WARNING';
    public const AVAILABILITY_UNAVAILABLE = 'TEMPORARILY_UNAVAILABLE';

    /** Statuses a customer may actually buy. */
    public const PURCHASABLE_STATUSES = [self::STATUS_ACTIVE];

    protected $fillable = [
        'uuid',
        'category_id',
        'product_key',
        'slug',
        'name',
        'description',
        'status',
        'availability',
        'allow_below_minimum_margin',
        'sort_order',
        'input_schema',
        'published_at',
    ];

    protected $casts = [
        'input_schema' => 'array',
        'allow_below_minimum_margin' => 'boolean',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });

        /*
         * Stamping `published_at` when a product first goes active (and clearing
         * it when it is withdrawn) keeps the storefront's "new" ordering honest
         * without any caller having to remember to do it.
         */
        static::saving(function (self $product) {
            if ($product->isDirty('status')) {
                $product->published_at = $product->status === self::STATUS_ACTIVE
                    ? ($product->published_at ?? now())
                    : null;
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function providerProducts()
    {
        return $this->hasMany(ProviderProduct::class);
    }

    /** Provider offers that are still present in a provider's catalogue. */
    public function activeProviderProducts()
    {
        return $this->providerProducts()->where('is_active', true)->whereNull('unavailable_at');
    }

    public function pricingRules()
    {
        return $this->hasMany(PricingRule::class, 'service_product_id');
    }

    public function isPurchasable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->availability !== self::AVAILABILITY_UNAVAILABLE;
    }

    /**
     * Whether a customer may buy this *right now*.
     *
     * A product flagged `AVAILABLE_WITH_WARNING` is still purchasable — that is
     * what the flag is for: the margin is below target but above the hard floor,
     * and the operator has chosen to keep selling.
     */
    public function isCustomerVisible(): bool
    {
        return $this->isPurchasable();
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCustomerVisible($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('availability', '!=', self::AVAILABILITY_UNAVAILABLE);
    }

    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }
}
