<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A customer notification record, or a Super Admin alert.
 *
 * The `fingerprint` unique index is the important part. A profitability check
 * that runs every five minutes would otherwise raise 288 identical alerts a day
 * for one stale provider cost, and an operator who sees 288 alerts stops reading
 * them. The fingerprint makes one condition produce one open alert, which can be
 * acknowledged or resolved.
 */
class ProductAlert extends Model
{
    public const KIND_REMINDER = 'reminder';
    public const KIND_ALERT = 'alert';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /* Alert types, matching the configured alert categories. */
    public const TYPE_PROVIDER_COST_INCREASE = 'provider_cost_increase';
    public const TYPE_MARGIN_BELOW_THRESHOLD = 'margin_below_threshold';
    public const TYPE_NEGATIVE_MARGIN = 'negative_margin';
    public const TYPE_PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const TYPE_PROVIDER_LOW_BALANCE = 'provider_low_balance';
    public const TYPE_UNUSUAL_PRICE_CHANGE = 'unusual_price_change';
    public const TYPE_UNUSUAL_VOLUME = 'unusual_volume';
    public const TYPE_HIGH_REFUND_RATE = 'high_refund_rate';
    public const TYPE_REMINDER_DUE = 'reminder_due';

    /*
     * The remaining provider conditions an operator has to act on. Each is a
     * distinct type rather than a variation on `provider_unavailable`, because the
     * action differs: a suspended account needs the vendor telephoned, an expired
     * key needs the environment variable rotated, and a missing catalogue is
     * usually the vendor's own incident.
     */
    public const TYPE_PROVIDER_AUTH_FAILURE = 'provider_auth_failure';
    public const TYPE_PROVIDER_SUSPENDED = 'provider_suspended';
    public const TYPE_CATALOGUE_UNAVAILABLE = 'catalogue_unavailable';
    public const TYPE_HIGH_FAILURE_RATE = 'high_failure_rate';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'uuid',
        'kind',
        'user_id',
        'bill_reminder_id',
        'provider_id',
        'provider_product_id',
        'type',
        'severity',
        'title',
        'message',
        'context',
        'fingerprint',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $alert) {
            if (empty($alert->uuid)) {
                $alert->uuid = (string) Str::uuid();
            }

            if (empty($alert->fingerprint)) {
                $alert->fingerprint = self::fingerprintFor(
                    $alert->type,
                    $alert->provider_product_id,
                    $alert->provider_id,
                    $alert->bill_reminder_id,
                );
            }
        });
    }

    /**
     * The stable identity of a condition.
     *
     * Derived from the type and the subject only. Deliberately excludes the
     * message and any figure: a provider cost that rises twice in an hour is the
     * *same open condition*, not two alerts, and the second rise updates the
     * existing row's context rather than creating a sibling.
     *
     * @param  mixed  $subjectId
     */
    public static function fingerprintFor(string $type, $providerProductId = null, $providerId = null, $reminderId = null): string
    {
        return hash('sha256', implode('|', [
            $type,
            (string) ($providerProductId ?? ''),
            (string) ($providerId ?? ''),
            (string) ($reminderId ?? ''),
        ]));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function providerProduct()
    {
        return $this->belongsTo(ProviderProduct::class);
    }

    public function billReminder()
    {
        return $this->belongsTo(BillReminder::class);
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function acknowledge(int $adminId): void
    {
        $this->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by' => $adminId,
        ])->save();
    }

    public function resolve(): void
    {
        $this->forceFill(['resolved_at' => now()])->save();
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeAlerts($query)
    {
        return $query->where('kind', self::KIND_ALERT);
    }

    public function scopeReminders($query)
    {
        return $query->where('kind', self::KIND_REMINDER);
    }
}
