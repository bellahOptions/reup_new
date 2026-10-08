<?php

namespace App\Console\Commands;

use App\Models\ProviderHealthCheck;
use Illuminate\Console\Command;

/**
 * Prune provider probe history past its retention window.
 *
 * The history table is append-only because that is what makes "when did this start"
 * answerable, and an append-only table on a five-minute schedule grows without bound.
 * Retention is therefore part of the design rather than an afterthought: the column
 * exists to answer an incident question about the recent past, not to be an archive.
 *
 * ## What it deletes, and what it does not
 *
 * Only `provider_health_checks` rows older than the window. It never touches
 * `provider_price_snapshots` or `pricing_snapshots`: a provider's *cost* history and
 * the price a customer agreed to are financial records with their own retention
 * requirement, and pruning them on a monitoring schedule would destroy evidence.
 *
 * `providers.health_status` — the current state the storefront reads — is untouched,
 * because it is not history.
 *
 * ## Usage
 *
 * ```
 * php artisan providers:prune-health
 * php artisan providers:prune-health --days=30 --dry-run
 * ```
 */
class PruneProviderHealth extends Command
{
    protected $signature = 'providers:prune-health
                            {--days= : Override the configured retention window}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete provider probe history older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('providers.sync.health_history_days', 90));

        if ($days < 1) {
            $this->error('A retention window of at least one day is required. Nothing was deleted.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = ProviderHealthCheck::where('checked_at', '<', $cutoff);

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} provider health check(s) older than {$days} day(s).");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info("No provider health checks are older than {$days} day(s).");

            return self::SUCCESS;
        }

        // Chunked so a long-neglected table does not hold a lock long enough to
        // block the scheduled probe that is writing to it.
        $deleted = 0;

        do {
            $batch = ProviderHealthCheck::where('checked_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Deleted {$deleted} provider health check(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
