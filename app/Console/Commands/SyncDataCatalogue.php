<?php

namespace App\Console\Commands;

use App\Providers\CatalogueSyncStatus;
use App\Services\CatalogueSyncFailed;
use App\Services\ClubKonnectCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Refresh the data-bundle catalogue and the provider costs behind every price.
 *
 * ## What this is, and what it is not
 *
 * This is the *data* catalogue — the bundles customers actually buy and the
 * price the provider charges for each of them. It is not
 * `providers:sync-catalogues`, which walks the Wave-1 adapter catalogues (Sogo,
 * VTpass, VTUGate, Nitro) into `provider_products`. The two are separate because
 * they are separate commercial relationships with separate APIs, and both end up
 * pricing through the same `PricingEngine`.
 *
 * ## Why a scheduled command rather than a request
 *
 * The page used to fetch the provider catalogue from cache and re-fill it
 * whenever the cache expired, which made the provider's rate limit a function of
 * customer traffic: a quiet hour meant no refresh, a busy one meant a fetch on
 * whichever request happened to arrive at the expiry boundary. A scheduled run
 * makes the cadence a decision, and lets every request read a cache that is
 * already warm.
 *
 * ## Rate limits
 *
 * The catalogue endpoint returns the provider's entire price list, so it is the
 * most expensive read this application makes. Three controls bound it:
 *
 *   * `--min-age` skips a run whose last success is newer than the configured
 *     interval, so running this every minute is harmless;
 *   * a cache lock makes two concurrent runs — the scheduled one and an
 *     operator's manual one — fetch once between them;
 *   * `withoutOverlapping` on the schedule entry covers the case where the
 *     previous run has not finished.
 *
 * ## Failure is reported, never absorbed
 *
 * A failed run exits non-zero and leaves the previous catalogue exactly as it
 * was. That is the whole safety property: a timeout, a rate limit, an HTTP error
 * or a malformed body must not be allowed to empty a working price list, and it
 * must not be recorded as a successful synchronisation either — a sync that
 * reports success for a provider that never answered is worse than no sync at
 * all, because it hides the staleness it was introduced to prevent.
 *
 * ## Usage
 *
 * ```
 * php artisan data:sync-catalogue              # respects the configured interval
 * php artisan data:sync-catalogue --force      # ignores the interval (one fetch)
 * php artisan data:sync-catalogue --status     # report without fetching
 * php artisan data:sync-catalogue --json
 * ```
 */
class SyncDataCatalogue extends Command
{
    protected $signature = 'data:sync-catalogue
                            {--force : Fetch even when the last success is newer than the configured interval}
                            {--status : Report the current state of the catalogue without fetching}
                            {--json : Emit machine-readable output}';

    protected $description = 'Synchronise the data-bundle catalogue and provider costs from the upstream price list';

    public function handle(ClubKonnectCatalogue $catalogue, CatalogueSyncStatus $status): int
    {
        if ($this->option('status')) {
            return $this->reportStatus($status);
        }

        /*
         * The lock is taken **before** the freshness check, and that order is
         * deliberate.
         *
         * If the freshness check came first, a manual run arriving while a
         * scheduled one was in flight would read the *previous* success, decide
         * the catalogue was due, and queue a second full fetch behind the first —
         * two provider requests for one result. Taking the lock first makes
         * "another run is in flight" the first thing this command can learn, so
         * the expensive read is refused rather than deferred.
         *
         * Atomic add-and-release rather than a check-then-act: two runs that both
         * read "no lock" would both fetch.
         */
        $lock = Cache::lock(CatalogueSyncStatus::LOCK_KEY, max(60, $status->minIntervalSeconds() * 2));

        if (! $lock->get()) {
            $this->warn('Another catalogue synchronisation is already running. Nothing was fetched.');

            return self::SUCCESS;
        }

        try {
            if (! $this->option('force') && ! $status->shouldAttempt()) {
                $age = $status->ageSeconds();

                $this->info(sprintf(
                    'Skipped: the catalogue was synchronised %s ago and the interval is %s.',
                    $age === null ? 'never' : $this->humanSeconds($age),
                    $this->humanSeconds($status->minIntervalSeconds()),
                ));

                return self::SUCCESS;
            }

            $status->markRunning(true);

            try {
                $payload = $catalogue->forceRefresh();
            } catch (CatalogueSyncFailed $e) {
                /*
                 * The previous catalogue is untouched. That is asserted by the tests
                 * rather than assumed here — see `DataCatalogueSyncTest`.
                 */
                $this->error('Synchronisation failed: ' . $e->getMessage());

                if ($this->option('json')) {
                    $this->line(json_encode([
                        'ok' => false,
                        'error_type' => $e->reason,
                        'message' => $e->getMessage(),
                        'http_status' => $e->httpStatus,
                        // Stated explicitly because it is the property an operator
                        // actually cares about: did this break the live price list?
                        'catalogue_replaced' => false,
                        'status' => $status->current(),
                    ], JSON_PRETTY_PRINT));
                } else {
                    $this->warn('The existing catalogue was left in place. No prices were changed.');
                }

                return self::FAILURE;
            }
        } finally {
            $status->markRunning(false);
            $lock->release();
        }

        $current = $status->current();

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'plans' => $payload['total_plans'],
                'last_updated' => $payload['last_updated'],
                'duration_ms' => $current['last_duration_ms'],
                'status' => $current,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Plans', 'Fetched', 'Duration', 'Last success', 'Stale'],
            [[
                number_format((int) $payload['total_plans']),
                $payload['last_updated'],
                $current['last_duration_ms'] === null ? '—' : $current['last_duration_ms'] . ' ms',
                $current['last_success_at'] ?? '—',
                $current['stale'] ? 'YES' : 'no',
            ]],
        );

        return self::SUCCESS;
    }

    /**
     * Report without fetching.
     *
     * Exists so `schedule:health` and an operator can answer "is the price list
     * current" without spending a provider request — which is the question asked
     * during an incident, when spending one is least welcome.
     */
    private function reportStatus(CatalogueSyncStatus $status): int
    {
        $current = $status->current();

        if ($this->option('json')) {
            $this->line(json_encode($current, JSON_PRETTY_PRINT));

            return $current['stale'] ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Last attempt', 'Last success', 'Last failure', 'Plans', 'Age', 'Consecutive failures', 'Error'],
            [[
                $current['last_attempt_at'] ?? '—',
                $current['last_success_at'] ?? '—',
                $current['last_failure_at'] ?? '—',
                number_format((int) $current['plan_count']),
                $current['age_seconds'] === null ? 'never' : $this->humanSeconds((int) $current['age_seconds']),
                (int) $current['consecutive_failures'],
                $current['error_type'] === null
                    ? '—'
                    : $current['error_type'] . ($current['error_message'] ? ': ' . $current['error_message'] : ''),
            ]],
        );

        if ($current['stale']) {
            $this->warn(
                'The catalogue is stale. Prices are being derived from provider costs that have not been '
                . 're-confirmed within the configured window.'
            );
        }

        // Non-zero when stale, so a scheduler health check that only reads exit
        // codes still learns about it.
        return $current['stale'] ? self::FAILURE : self::SUCCESS;
    }

    private function humanSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }

        return round($seconds / 3600, 1) . 'h';
    }
}
