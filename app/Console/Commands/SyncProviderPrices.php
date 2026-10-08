<?php

namespace App\Console\Commands;

use App\Models\ProviderPriceSnapshot;
use App\Models\ProviderProduct;
use App\Providers\CatalogueSync;
use App\Providers\ProviderMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-read provider costs and show what moved.
 *
 * This is `providers:sync-catalogues` with a *reporting* focus rather than a
 * different operation: the cost figures and the immutability rules are identical,
 * and having two code paths that update a provider cost would be two places for the
 * snapshot rule to be forgotten. What differs is what it tells you — a price-movement
 * report, which is the thing an operator actually reads before deciding whether a
 * margin needs defending.
 *
 * Every movement is read back from `provider_price_snapshots`, which is append-only.
 * That matters here: a report built from the mutable `provider_products` column
 * could not distinguish "the cost moved" from "somebody edited the row", and the
 * whole purpose of the snapshot table is to make that distinction available.
 *
 * ## Usage
 *
 * ```
 * php artisan providers:sync-prices
 * php artisan providers:sync-prices --provider=sogo --since=7
 * ```
 *
 * `--since` limits the report to movements in the last N days. The sync itself is
 * always complete; the window is a view, never a partial update.
 */
class SyncProviderPrices extends Command
{
    protected $signature = 'providers:sync-prices
                            {--provider= : Sync only this provider slug}
                            {--since=30 : Report movements from the last N days}
                            {--json : Emit machine-readable output}';

    protected $description = 'Refresh provider costs and report what moved, ordered by size';

    public function handle(CatalogueSync $sync, ProviderMonitor $monitor): int
    {
        // One engine for both commands, so the snapshot rule cannot drift.
        $exitCode = $this->call('providers:sync-catalogues', array_filter([
            '--provider' => $this->option('provider'),
            '--json' => false,
        ], fn ($value) => $value !== null && $value !== false));

        $since = max(1, (int) $this->option('since'));

        $movements = $this->movements($since);

        if ($this->option('json')) {
            $this->line(json_encode([
                'since_days' => $since,
                'movements' => $movements,
            ], JSON_PRETTY_PRINT));

            return $exitCode;
        }

        $this->newLine();
        $this->info("Cost movements in the last {$since} day(s): " . count($movements));

        if ($movements === []) {
            return $exitCode;
        }

        $this->table(
            ['Provider', 'Product', 'Was', 'Now', 'Change'],
            array_map(fn (array $row) => [
                $row['provider'],
                mb_substr($row['product'], 0, 40),
                number_format($row['previous_minor'] / 100, 2),
                number_format($row['current_minor'] / 100, 2),
                ($row['change_bps'] >= 0 ? '+' : '') . number_format($row['change_bps'] / 100, 2) . '%',
            ], $movements),
        );

        $rises = array_filter($movements, fn (array $row) => $row['change_bps'] > 0);

        if ($rises !== []) {
            $this->newLine();
            /*
             * Stated plainly because it is the whole point of the report. A cost rise
             * that is not followed by a repricing turns a profitable product into a
             * loss-making one, and it does so silently — every sale still succeeds,
             * and the loss only appears in the accounts.
             */
            $this->warn(
                count($rises) . ' cost increase(s). Each one reduces the margin on an existing price until the '
                . 'pricing rules are reviewed — the customer price does not move on its own.'
            );
        }

        return $exitCode;
    }

    /**
     * Cost movements, largest first.
     *
     * Built from consecutive snapshot pairs so it reflects what was *observed*, and
     * joined to the provider so a movement can be attributed without a second query
     * per row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function movements(int $sinceDays): array
    {
        $rows = DB::table('provider_price_snapshots as snap')
            ->join('provider_products as pp', 'pp.id', '=', 'snap.provider_product_id')
            ->join('providers as p', 'p.id', '=', 'pp.provider_id')
            ->where('snap.recorded_at', '>=', now()->subDays($sinceDays))
            ->where('snap.source', ProviderPriceSnapshot::SOURCE_SYNC)
            ->orderByDesc('snap.recorded_at')
            ->get([
                'pp.id as provider_product_id',
                'pp.provider_name',
                'p.slug as provider_slug',
                'snap.cost_minor',
                'snap.recorded_at',
            ]);

        // One movement per product: the newest observation, compared against the one
        // before it. Grouping here keeps the query shape simple and the ordering
        // explicit.
        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->provider_product_id] ??= [];
            $latest[$row->provider_product_id][] = $row;
        }

        $movements = [];

        foreach ($latest as $observations) {
            if (count($observations) < 2) {
                continue;
            }

            $current = $observations[0];
            $previous = $observations[1];

            if ((int) $previous->cost_minor === (int) $current->cost_minor) {
                continue;
            }

            $movements[] = [
                'provider' => $current->provider_slug,
                'product' => (string) $current->provider_name,
                'previous_minor' => (int) $previous->cost_minor,
                'current_minor' => (int) $current->cost_minor,
                'change_bps' => (int) $previous->cost_minor === 0
                    ? 0
                    : (int) round(((int) $current->cost_minor - (int) $previous->cost_minor) * 10000 / (int) $previous->cost_minor),
                'recorded_at' => (string) $current->recorded_at,
            ];
        }

        usort($movements, fn ($a, $b) => abs($b['change_bps']) <=> abs($a['change_bps']));

        return $movements;
    }
}
