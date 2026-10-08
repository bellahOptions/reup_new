<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A Wave 1 upstream, as configured by the Super Admin.
 *
 * This row is *configuration*, never credentials. The credentials live in the
 * environment; `credential_env_prefix` records which environment variable group
 * belongs to this provider so the adapter can resolve them, and the admin
 * console can display "configured / not configured" without ever handling a
 * secret value.
 */
class Provider extends Model
{
    public const CAPABILITY_BILLS = 'bills';
    public const CAPABILITY_GIFT_CARDS = 'gift_cards';
    public const CAPABILITY_INTERNATIONAL_TOPUP = 'international_topup';
    public const CAPABILITY_SMM = 'smm';

    public const HEALTH_UNKNOWN = 'unknown';
    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_DOWN = 'down';
    public const HEALTH_NOT_CONFIGURED = 'not_configured';

    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'driver',
        'capabilities',
        'is_active',
        'is_primary',
        'priority',
        'credential_env_prefix',
        'credential_scopes',
        'docs_url',
        'low_balance_threshold_minor',
        'health_status',
        'health_checked_at',
        'health_message',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'credential_scopes' => 'array',
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'priority' => 'integer',
        'low_balance_threshold_minor' => 'integer',
        'health_checked_at' => 'datetime',
    ];

    protected $hidden = [
        // Nothing secret is stored here, but the env prefix is an operational
        // detail that has no business in a customer-facing serialisation.
        'credential_env_prefix',
        'credential_scopes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $provider) {
            if (empty($provider->uuid)) {
                $provider->uuid = (string) Str::uuid();
            }
        });
    }

    public function providerProducts()
    {
        return $this->hasMany(ProviderProduct::class);
    }

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, (array) $this->capabilities, true);
    }

    public function isConfigured(): bool
    {
        return $this->health_status !== self::HEALTH_NOT_CONFIGURED;
    }

    /** Whether a low-balance threshold is configured for this provider. */
    public function hasBalanceThreshold(): bool
    {
        return $this->low_balance_threshold_minor !== null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithCapability($query, string $capability)
    {
        // JSON_CONTAINS is MySQL-only; the app runs MySQL (see phpunit.xml), and
        // `whereJsonContains` would emit the same function on this driver.
        return $query->whereJsonContains('capabilities', $capability);
    }
}
