<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One provider probe, as a historical fact.
 *
 * `providers.health_status` holds the latest state, which is all the storefront
 * needs. It cannot answer the question an operator asks during an incident — *when
 * did this start?* — because a single column has no past. A provider that has been
 * flapping for forty minutes looks identical to one that just failed.
 *
 * So each probe appends a row. The table is append-only, written only by the
 * monitoring command, and pruned by retention rather than updated: a health record
 * that can be edited is not evidence.
 *
 * `is_sandbox` is recorded per check rather than read from configuration at display
 * time. A dashboard that labels history using today's config would relabel last
 * week's production probes as sandbox the moment somebody flipped the switch.
 */
class ProviderHealthCheck extends Model
{
    protected $fillable = [
        'provider_id',
        'checked_at',
        'available',
        'http_status',
        'latency_ms',
        'balance_minor',
        'currency',
        'error_code',
        'message',
        'is_sandbox',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'available' => 'boolean',
        'http_status' => 'integer',
        'latency_ms' => 'integer',
        'balance_minor' => 'integer',
        'is_sandbox' => 'boolean',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Record a probe.
     *
     * The single write path, so that `checked_at` and the sandbox flag can never be
     * forgotten by a caller — both are facts about *when and where* the observation
     * was made, and a health row without them cannot be interpreted later.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(int $providerId, bool $available, array $attributes = []): self
    {
        return static::create(array_merge([
            'provider_id' => $providerId,
            'checked_at' => now(),
            'available' => $available,
            'is_sandbox' => (bool) config('providers.sandbox', false),
        ], $attributes));
    }

    /**
     * Consecutive failures ending at the most recent check.
     *
     * The number an alert should be based on. Alerting on a single failed probe
     * produces noise, because one dropped connection is not an outage; alerting on
     * three in a row is a provider that is actually failing.
     */
    public static function consecutiveFailures(int $providerId): int
    {
        $recent = static::where('provider_id', $providerId)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('available');

        $failures = 0;

        foreach ($recent as $available) {
            if ($available) {
                break;
            }

            $failures++;
        }

        return $failures;
    }
}
