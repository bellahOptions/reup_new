<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Module settings, of which there is currently one that matters: the kill switch.
 *
 * ## Why a row and not a config value
 *
 * The global marketing kill switch has to be flippable *now*, by an administrator, in
 * the middle of an incident — a complaint spike, a broken template, a wrong audience.
 * A config value needs a deploy or a shell; an environment variable needs a restart.
 * A row is a checkbox.
 *
 * ## Why it is cached
 *
 * It is read on every single dispatch, and dispatches come in batches of hundreds.
 * The cache is invalidated on write, so the switch is still immediate — and if the
 * cache is cold the database is the answer, which means the failure mode of the cache
 * is slowness rather than a switch that silently stops working.
 */
class LifecycleSettings extends Model
{
    protected $table = 'lifecycle_settings';

    protected $fillable = ['key', 'value'];

    public const KEY_MARKETING_ENABLED = 'marketing_enabled';

    /** Same-batch as the highest frequency the module runs at. */
    private const CACHE_TTL = 60;

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember(
            'lifecycle.setting.' . $key,
            self::CACHE_TTL,
            fn () => static::where('key', $key)->value('value') ?? $default
        );
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget('lifecycle.setting.' . $key);
    }

    /**
     * Is marketing sending enabled?
     *
     * Defaults to **on** when nothing is configured, and that default is deliberate:
     * a deployment where the row is missing should not silently stop every marketing
     * email with nobody able to say why. The switch is for turning things *off* during
     * an incident, and its absence is not an incident.
     */
    public static function marketingEnabled(): bool
    {
        return static::get(self::KEY_MARKETING_ENABLED, '1') === '1';
    }

    public static function setMarketingEnabled(bool $enabled, ?int $actorId = null): void
    {
        static::put(self::KEY_MARKETING_ENABLED, $enabled ? '1' : '0');

        \Illuminate\Support\Facades\Log::warning('Lifecycle marketing kill switch changed', [
            'enabled' => $enabled,
            'actor_id' => $actorId,
        ]);
    }
}
