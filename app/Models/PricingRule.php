<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A pricing rule.
 *
 * ## Hierarchy
 *
 * Resolution is cheapest-to-most-specific, and the most specific *active* rule
 * wins:
 *
 *   global  →  category  →  provider  →  product  →  provider_product
 *
 * So a global 10% markup, a 20% SMM category rule, a 25% Instagram-product rule
 * and a ₦500 fixed rule on one Nitro service all coexist, and the service rule
 * applies to that service alone.
 *
 * ## No floats
 *
 * Percentages are stored in basis points (`2000` = 20.00%) and amounts in kobo,
 * as integers. A stored percentage that is really a binary approximation of
 * 20% is how a pricing rule drifts by a kobo per order.
 */
class PricingRule extends Model
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PROVIDER = 'provider';
    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_PROVIDER_PRODUCT = 'provider_product';

    /** Broadest to narrowest — resolution walks this in reverse. */
    public const SCOPE_ORDER = [
        self::SCOPE_GLOBAL,
        self::SCOPE_CATEGORY,
        self::SCOPE_PROVIDER,
        self::SCOPE_PRODUCT,
        self::SCOPE_PROVIDER_PRODUCT,
    ];

    public const MARKUP_PERCENTAGE = 'percentage';
    public const MARKUP_FIXED = 'fixed';
    public const MARKUP_PERCENTAGE_PLUS_FIXED = 'percentage_plus_fixed';
    public const MARKUP_NONE = 'none';

    public const ON_UNPROFITABLE_UNAVAILABLE = 'unavailable';
    public const ON_UNPROFITABLE_WARNING = 'warning';
    public const ON_UNPROFITABLE_FALLBACK = 'fallback';
    public const ON_UNPROFITABLE_REQUIRE_APPROVAL = 'require_approval';

    public const ROUNDING_NEAREST = 'nearest';
    public const ROUNDING_UP = 'up';
    public const ROUNDING_DOWN = 'down';

    protected $fillable = [
        'uuid',
        'name',
        'scope',
        'category_id',
        'provider_id',
        'service_product_id',
        'provider_product_id',
        'markup_type',
        'markup_percentage_bps',
        'markup_fixed_minor',
        'minimum_profit_minor',
        'maximum_markup_minor',
        'minimum_selling_price_minor',
        'maximum_selling_price_minor',
        'minimum_margin_bps',
        'customer_fee_enabled',
        'customer_fee_type',
        'customer_fee_minor',
        'customer_fee_bps',
        'discount_bps',
        'discount_fixed_minor',
        'promotion_starts_at',
        'promotion_ends_at',
        'allow_negative_margin',
        'rounding_step_minor',
        'rounding_mode',
        'on_unprofitable',
        'fallback_rule_id',
        'priority',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'markup_percentage_bps' => 'integer',
        'markup_fixed_minor' => 'integer',
        'minimum_profit_minor' => 'integer',
        'maximum_markup_minor' => 'integer',
        'minimum_selling_price_minor' => 'integer',
        'maximum_selling_price_minor' => 'integer',
        'minimum_margin_bps' => 'integer',
        'customer_fee_enabled' => 'boolean',
        'customer_fee_minor' => 'integer',
        'customer_fee_bps' => 'integer',
        'discount_bps' => 'integer',
        'discount_fixed_minor' => 'integer',
        'allow_negative_margin' => 'boolean',
        'rounding_step_minor' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'promotion_starts_at' => 'datetime',
        'promotion_ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rule) {
            if (empty($rule->uuid)) {
                $rule->uuid = (string) Str::uuid();
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function serviceProduct()
    {
        return $this->belongsTo(ServiceProduct::class, 'service_product_id');
    }

    public function providerProduct()
    {
        return $this->belongsTo(ProviderProduct::class);
    }

    public function versions()
    {
        return $this->hasMany(PricingRuleVersion::class)->orderBy('version');
    }

    public function fallbackRule()
    {
        return $this->belongsTo(self::class, 'fallback_rule_id');
    }

    /**
     * Whether this rule may be selected right now.
     *
     * An inactive rule is never eligible. A rule with a promotional window is
     * eligible only inside it — outside the window the rule still applies but its
     * discount does not (see `activeDiscountBps`).
     */
    public function isSelectableAt(?\Illuminate\Support\Carbon $at = null): bool
    {
        return $this->is_active;
    }

    /** The discount in basis points that applies at a given moment. */
    public function activeDiscountBps(?\Illuminate\Support\Carbon $at = null): int
    {
        $at = $at ?? now();

        if (! $this->promotionIsRunning($at)) {
            return 0;
        }

        return (int) $this->discount_bps;
    }

    /** The fixed promotional discount that applies at a given moment. */
    public function activeDiscountMinor(?\Illuminate\Support\Carbon $at = null): int
    {
        $at = $at ?? now();

        if (! $this->promotionIsRunning($at)) {
            return 0;
        }

        return (int) $this->discount_fixed_minor;
    }

    public function promotionIsRunning(?\Illuminate\Support\Carbon $at = null): bool
    {
        $at = $at ?? now();

        /*
         * A promotion is defined by its window. With no window configured there
         * is no promotion, and the discount columns are inert — which is what
         * makes "discount 10% but no dates" harmless rather than an permanent
         * unadvertised cut.
         */
        if (! $this->promotion_starts_at && ! $this->promotion_ends_at) {
            return false;
        }

        if ($this->promotion_starts_at && $this->promotion_starts_at->greaterThan($at)) {
            return false;
        }

        if ($this->promotion_ends_at && $this->promotion_ends_at->lessThan($at)) {
            return false;
        }

        return true;
    }

    /**
     * The most specific subject this rule is attached to, for display in the
     * admin console ("Instagram Followers" rather than "product rule #4").
     */
    public function subjectLabel(): string
    {
        return match ($this->scope) {
            self::SCOPE_CATEGORY => $this->category?->name ?? 'Category (deleted)',
            self::SCOPE_PROVIDER => $this->provider?->name ?? 'Provider (deleted)',
            self::SCOPE_PRODUCT => $this->serviceProduct?->name ?? 'Product (deleted)',
            self::SCOPE_PROVIDER_PRODUCT => $this->providerProduct?->provider_name ?? 'Provider product (deleted)',
            default => 'All services',
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForScope($query, string $scope)
    {
        return $query->where('scope', $scope);
    }
}
