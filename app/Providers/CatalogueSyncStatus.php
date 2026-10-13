<?php

namespace App\Providers;

use App\Models\ProductAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The last successful catalogue synchronisation, and how stale it is.
 *
 * ## Why this is a separate record rather than a timestamp on a row
 *
 * The data-bundle catalogue has no row of its own. It is a provider payload we
 * cache, and the question an operator needs answered is not "when did we write
 * something" but "when did a *complete* provider response last replace it".
 * Those are different: a fetch that timed out writes nothing, and a malformed
 * response writes nothing either — but both are events somebody has to see.
 *
 * So every attempt is recorded, successes and failures alike. A failure that
 * overwrote the success timestamp would make an outage look like a healthy but
 * very old catalogue — which is exactly the state that lets a stale price reach
 * a customer without anybody noticing.
 *
 * ## Why it is not a database table
 *
 * The cached catalogue itself lives in the cache, so the record of its freshness
 * lives with it. One store, one failure mode, one thing to clear. A table would
 * add a write on every sync and still be able to disagree with the cache it
 * describes.
 *
 * ## What is deliberately not stored
 *
 * No credentials and no raw payload. `error_message` is a short, already-typed
 * description the provider gave — the transport message is scrubbed of the
 * client id before it reaches here, because the ClubKonnect request carries the
 * credential in its query string.
 */
class CatalogueSyncStatus
{
    /** The cached catalogue. Replaced only by a complete, usable response. */
    public const DATA_KEY = 'clubkonnect_data_plans';

    /** The last good copy, kept so a failed refresh cannot empty the storefront. */
    public const FALLBACK_KEY = 'clubkonnect_data_plans_fallback';

    /** The record of the most recent sync attempt, successful or not. */
    public const STATUS_KEY = 'clubkonnect_data_sync_status';

    /**
     * The lock a synchronisation holds while it runs.
     *
     * An application lock rather than `withoutOverlapping()` alone: the schedule
     * entry protects the *scheduled* run, and this protects the scheduled run
     * from the manual one an operator triggers while a page is refreshing. Both
     * are real, and two concurrent full-catalogue fetches would spend twice the
     * provider's rate limit for one result.
     */
    public const LOCK_KEY = 'clubkonnect_data_sync_lock';

    public const ERROR_NOT_CONFIGURED = 'not_configured';

    public const ERROR_TIMEOUT = 'timeout';

    public const ERROR_RATE_LIMITED = 'rate_limited';

    public const ERROR_HTTP = 'http_error';

    public const ERROR_MALFORMED = 'malformed_response';

    public const ERROR_EMPTY = 'empty_catalogue';

    public const ERROR_UNKNOWN = 'unknown';

    /**
     * Read the current record.
     *
     * Never throws and never returns anything but an array: every caller is a
     * page render or a diagnostic, and a sync record that can break the admin
     * console would be worse than no record at all.
     *
     * @return array{
     *     last_attempt_at:?string,
     *     last_success_at:?string,
     *     last_failure_at:?string,
     *     last_duration_ms:?int,
     *     plan_count:int,
     *     attempts:int,
     *     failures:int,
     *     consecutive_failures:int,
     *     error_type:?string,
     *     error_message:?string,
     *     http_status:?int,
     *     age_seconds:?int,
     *     stale:bool
     * }
     */
    public function current(): array
    {
        return $this->summarise(Cache::get(self::STATUS_KEY));
    }

    /**
     * Whether the catalogue we hold is older than the configured staleness limit.
     *
     * A catalogue with no success recorded at all is stale, not fresh: "we have
     * never synchronised" is the strongest possible reason to distrust it.
     */
    public function isStale(): bool
    {
        return (bool) $this->current()['stale'];
    }

    /**
     * Seconds since the last complete provider response was applied, or null when
     * there has never been one.
     */
    public function ageSeconds(): ?int
    {
        return $this->current()['age_seconds'];
    }

    /**
     * How stale the catalogue is allowed to get before it is flagged.
     *
     * Default 30 minutes against a 10-minute sync: three missed runs, which is a
     * provider problem rather than a hiccup, and short enough that a stale price
     * is a customer-visible condition rather than a silent one.
     */
    public function staleAfterSeconds(): int
    {
        return max(60, (int) config('pricing.catalogue.stale_after_seconds', 1800));
    }

    /**
     * The smallest interval between two provider fetches.
     *
     * This is the provider's rate limit expressed as policy. ClubKonnect's
     * catalogue endpoint is a full price list, so it is the most expensive read
     * this application makes; hammering it on a short schedule would spend the
     * allowance that purchases need.
     */
    public function minIntervalSeconds(): int
    {
        return max(60, (int) config('pricing.catalogue.sync_interval_seconds', 600));
    }

    /** Whether enough time has passed for another fetch to be worth making. */
    public function isDue(): bool
    {
        $age = $this->ageSeconds();

        if ($age === null) {
            return true;
        }

        return $age >= $this->minIntervalSeconds();
    }

    /**
     * Whether a run should attempt a fetch *now*.
     *
     * `isDue()` plus one exception: an attempt that has begun and not finished
     * also makes a run attempt. Without it, a sync killed mid-fetch — a deploy, an
     * OOM, a Ctrl-C — would leave `recordAttempt()` written and nothing else, and
     * the next tick would skip with "not due yet", leaving the catalogue stale for
     * a full interval with no failure recorded anywhere.
     *
     * Attempting in that state is safe: what stops two fetches overlapping is the
     * command's lock, not this check.
     */
    public function shouldAttempt(): bool
    {
        return $this->isDue() || $this->isRunning() || $this->current()['last_success_at'] === null;
    }

    /* =====================================================================
     | Recording
     |=================================================================== */

    /**
     * Record the start of an attempt.
     *
     * Written before the provider is called, so a run that is killed by a timeout
     * still leaves evidence that it happened. A record that only appears on
     * completion cannot distinguish "the sync never ran" from "the sync is
     * hanging", and the second is the one that needs action.
     */
    public function recordAttempt(): void
    {
        $this->write(['last_attempt_at' => now()->toDateTimeString()]);
    }

    /**
     * Record a complete, usable provider response.
     *
     * @param  int  $planCount  bundles in the response that were actually priced
     */
    public function recordSuccess(int $planCount, ?int $durationMs = null): void
    {
        $this->write([
            'last_success_at' => now()->toDateTimeString(),
            'last_duration_ms' => $durationMs,
            'plan_count' => $planCount,
            'consecutive_failures' => 0,
            'error_type' => null,
            'error_message' => null,
            'http_status' => null,
        ]);

        $this->resolveAlerts();
    }

    /**
     * Record a failed attempt.
     *
     * Deliberately does **not** clear `last_success_at`. A failed refresh leaves
     * the previous catalogue in place, so the previous success is still the truth
     * about what we are serving — and the failure is visible beside it.
     */
    public function recordFailure(string $type, ?string $message = null, ?int $httpStatus = null): void
    {
        $current = $this->current();

        $failures = ((int) $current['consecutive_failures']) + 1;

        $this->write([
            'last_failure_at' => now()->toDateTimeString(),
            'consecutive_failures' => $failures,
            'error_type' => $type,
            // Bounded, because a provider can return a paragraph and this record
            // is rendered on a dashboard.
            'error_message' => $message === null ? null : mb_substr($message, 0, 300),
            'http_status' => $httpStatus,
        ]);

        /*
         * A pattern, not a single observation. One dropped connection is not an
         * outage, and a monitor that raises an alert for it teaches an operator to
         * ignore the alert — which is worse than no alert, because it looks like
         * coverage.
         */
        if ($failures >= $this->failuresBeforeAlert()) {
            $this->raiseUnavailableAlert($type, $message, $failures, $httpStatus);
        }
    }

    /**
     * Whether an attempt is in progress right now.
     *
     * Kept on the same record rather than in a second key so that "the sync is
     * running" and "the sync finished" cannot disagree.
     */
    public function markRunning(bool $running): void
    {
        $this->write(['running' => $running, 'running_since' => $running ? now()->toDateTimeString() : null]);
    }

    public function isRunning(): bool
    {
        return (bool) (Cache::get(self::STATUS_KEY)['running'] ?? false);
    }

    /* =====================================================================
     | Operator visibility
     |=================================================================== */

    /**
     * How many consecutive failures make this an outage rather than a bad minute.
     */
    public function failuresBeforeAlert(): int
    {
        return max(1, (int) config('pricing.catalogue.failures_before_alert', 3));
    }

    /**
     * Raise the alert an operator sees on the provider dashboard.
     *
     * Written through `ProductAlert` — the same table the provider health monitor
     * uses — rather than to a log line, because the question "why is every data
     * price old" should be answerable from the console and not only from the
     * application log. The alert is keyed by `provider_id = null` and a type of
     * its own, so it appears as one row that persists until it clears rather than
     * as one row per failed tick.
     *
     * Never throws. A monitoring write must not be the reason a synchronisation
     * command fails — the command's exit code is what the scheduler reads.
     */
    private function raiseUnavailableAlert(string $type, ?string $message, int $failures, ?int $httpStatus): void
    {
        try {
            $fingerprint = ProductAlert::fingerprintFor(ProductAlert::TYPE_CATALOGUE_UNAVAILABLE, null, null);

            $existing = ProductAlert::where('fingerprint', $fingerprint)->whereNull('resolved_at')->first();

            $attributes = [
                'kind' => ProductAlert::KIND_ALERT,
                'provider_id' => null,
                'type' => ProductAlert::TYPE_CATALOGUE_UNAVAILABLE,
                'severity' => ProductAlert::SEVERITY_CRITICAL,
                'title' => 'The data-bundle catalogue has not synchronised',
                'message' => 'The last ' . $failures . ' attempts failed (' . $type . '). Every data price in the '
                    . 'storefront is derived from the last catalogue that succeeded, so a cost the provider has '
                    . 'since changed will not be reflected until this clears.',
                'context' => [
                    'error_type' => $type,
                    'error_message' => $message,
                    'http_status' => $httpStatus,
                    'consecutive_failures' => $failures,
                    'last_success_at' => $this->current()['last_success_at'],
                ],
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                return;
            }

            ProductAlert::create($attributes + ['fingerprint' => $fingerprint]);
        } catch (\Throwable $e) {
            Log::warning('Could not record a catalogue synchronisation alert', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear the alert once a synchronisation succeeds.
     *
     * Resolution re-arms the condition: the unique index only covers *open*
     * alerts, so a later outage raises a new row rather than being suppressed by
     * this one.
     */
    private function resolveAlerts(): void
    {
        try {
            ProductAlert::where(
                'fingerprint',
                ProductAlert::fingerprintFor(ProductAlert::TYPE_CATALOGUE_UNAVAILABLE, null, null)
            )
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Could not resolve a catalogue synchronisation alert', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =====================================================================
     | Internals
     |=================================================================== */

    /**
     * Merge values into the record.
     *
     * A read-modify-write rather than a replace: the attempt counter and the
     * success time are written by different calls, and overwriting the whole
     * record on each one would drop whichever was not passed here.
     *
     * Kept for a year because "when did this last synchronise" is a question
     * asked during an incident, which is exactly when the cache has been cleared.
     *
     * @param  array<string,mixed>  $values
     */
    private function write(array $values): void
    {
        $record = Cache::get(self::STATUS_KEY);

        if (! is_array($record)) {
            $record = [];
        }

        if (array_key_exists('last_attempt_at', $values) && $values['last_attempt_at'] !== null) {
            $values['attempts'] = ((int) ($record['attempts'] ?? 0)) + 1;
        }

        $record = array_merge($record, $values);

        // Never allowed to expire mid-incident, and bounded so a stale record
        // cannot be mistaken for a current one — `stale` is derived from the
        // timestamps, not from the key's existence.
        Cache::put(self::STATUS_KEY, $record, now()->addDays(30));
    }

    /**
     * @param  mixed  $record
     * @return array<string,mixed>
     */
    private function summarise($record): array
    {
        $record = is_array($record) ? $record : [];

        $successAt = $this->parse($record['last_success_at'] ?? null);
        $age = $successAt === null ? null : (int) $successAt->diffInSeconds(now(), false);

        return [
            'last_attempt_at' => $record['last_attempt_at'] ?? null,
            'last_success_at' => $record['last_success_at'] ?? null,
            'last_failure_at' => $record['last_failure_at'] ?? null,
            'last_duration_ms' => isset($record['last_duration_ms']) ? (int) $record['last_duration_ms'] : null,
            'plan_count' => (int) ($record['plan_count'] ?? 0),
            'attempts' => (int) ($record['attempts'] ?? 0),
            'failures' => (int) ($record['failures'] ?? 0),
            'consecutive_failures' => (int) ($record['consecutive_failures'] ?? 0),
            'error_type' => $record['error_type'] ?? null,
            'error_message' => $record['error_message'] ?? null,
            'http_status' => isset($record['http_status']) ? (int) $record['http_status'] : null,
            'running' => (bool) ($record['running'] ?? false),
            'running_since' => $record['running_since'] ?? null,
            'age_seconds' => $age,
            // A catalogue that has never synced is stale, not fresh.
            'stale' => $age === null || $age > $this->staleAfterSeconds(),
        ];
    }

    private function parse(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
