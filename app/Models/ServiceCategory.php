<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A customer-facing grouping: Pay Bills, Digital, Social.
 *
 * The `group` is the navigation level the approved frontend copy uses; a
 * category is the thing inside it (Internet, Gift Cards, Social Boost).
 */
class ServiceCategory extends Model
{
    public const GROUP_PAY_BILLS = 'pay_bills';
    public const GROUP_DIGITAL = 'digital';
    public const GROUP_SOCIAL = 'social';

    /** The approved top-level navigation, in order, with its copy. */
    public const GROUPS = [
        self::GROUP_PAY_BILLS => [
            'name' => 'Pay Bills',
            'heading' => 'Pay Your Bills',
            'description' => 'Handle your everyday payments from one place.',
            'dashboard_description' => 'Everyday payments, made simple.',
        ],
        self::GROUP_DIGITAL => [
            'name' => 'Digital',
            'heading' => 'Go Digital',
            'description' => 'Get digital products and services whenever you need them.',
            'dashboard_description' => 'Digital products and services, all in one place.',
        ],
        self::GROUP_SOCIAL => [
            'name' => 'Social',
            'heading' => 'Grow Your Social Presence',
            'description' => 'Boost your social media presence with simple, fast and trackable campaigns.',
            'dashboard_description' => 'Boost your social presence.',
        ],
    ];

    protected $fillable = [
        'uuid',
        'group',
        'slug',
        'name',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $category) {
            if (empty($category->uuid)) {
                $category->uuid = (string) Str::uuid();
            }
        });
    }

    public function products()
    {
        return $this->hasMany(ServiceProduct::class, 'category_id');
    }

    /** Active products only — what a customer can actually see. */
    public function activeProducts()
    {
        return $this->products()->where('status', ServiceProduct::STATUS_ACTIVE)->orderBy('sort_order');
    }

    public function pricingRules()
    {
        return $this->hasMany(PricingRule::class, 'category_id');
    }

    public function groupName(): string
    {
        return self::GROUPS[$this->group]['name'] ?? ucfirst($this->group);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInGroup($query, string $group)
    {
        return $query->where('group', $group);
    }
}
