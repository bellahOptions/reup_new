<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Providers\ProviderMonitor;
use App\Providers\ProviderRegistry;
use Illuminate\Console\Command;

/**
 * Probe every provider's health and float, and record the result.
 *
 * ## Why this runs on a schedule rather than at purchase time
 *
 * Checking a provider's balance during a purchase would put a slow upstream read on
 * the critical path of a customer's checkout, and a provider whose balance endpoint
 * is slow would make the whole storefront slow. A scheduled probe puts the
 * observation where it belongs — in monitoring — and leaves the purchase path with
 * the two checks that must be synchronous: the customer's wallet, under a row lock,
 * and the provider's own response.
 *
 * ## What a failure here does *not* mean
 *
 * An unreadable balance is a monitoring problem, not proof that a purchase would
 * fail. Every adapter's `balance()` is a read, and this command never disables a
 * provider on the strength of one. What it does is make the situation visible: the
 * history is recorded, `providers.health_status` is updated, and an alert is raised
 * once the failure is a *pattern* rather than an incident.
 *
 * ## Usage
 *
 * ```
 * php artisan providers:check-balances              # every configured provider
 * php artisan providers:check-balances --provider=sogo
 * ```
 *
 * Exits non-zero when any provider is down, so a cron runner or a deploy check can
 * act on it.
 */
class CheckProviderBalances extends Command
{
    protected $signature = 'providers:check-balances
                            {--provider= : Check only this provider slug}
                            {--json : Emit machine-readable output}';

    protected $description = 'Probe each provider\'s health and wallet balance and record the history';

    public function handle(ProviderMonitor $monitor, ProviderRegistry $registry): int
    {
        $query = Provider::query()->orderBy('priority')->orderBy('id');

        if ($slug = $this->option('provider')) {
            $query->where('slug', $slug);
        }

        $providers = $query->get();

        if ($providers->isEmpty()) {
            $this->warn('No providers matched. Nothing was checked — which is not the same as everything being healthy.');

            // Non-zero on purpose: "nothing checked" must never read as success to a
            // monitor that only looks at the exit code.
            return self::FAILURE;
        }

        $results = [];

        foreach ($providers as $provider) {
            $results[] = $this->checkOne($monitor, $registry, $provider);
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));

            return $this->exitCode($results);
        }

        $this->table(
            ['Provider', 'Status', 'Balance', 'Latency', 'Message'],
            array_map(fn (array $row) => [
                $row['provider'],
                $row['status'],
                $row['balance'] === null ? '—' : number_format($row['balance'] / 100, 2),
                $row['latency_ms'] === null ? '—' : $row['latency_ms'] . 'ms',
                $row['message'] === null ? '' : mb_substr((string) $row['message'], 0, 60),
            ], $results),
        );

        $down = array_filter($results, fn (array $row) => in_array($row['status'], [
            Provider::HEALTH_DOWN,
            Provider::HEALTH_DEGRADED,
        ], true));

        if ($down !== []) {
            $this->newLine();
            $this->error(count($down) . ' provider(s) need attention: ' . implode(', ', array_column($down, 'provider')));
        }

        if (config('providers.sandbox')) {
            $this->newLine();
            $this->warn('PROVIDER_SANDBOX is on: these are sandbox balances and sandbox availability.');
        }

        return $this->exitCode($results);
    }

    /**
     * Check one provider.
     *
     * Delegates the decision of whether a probe is meaningful to
     * `ProviderMonitor::probe()`, so the command, the dashboard and the scheduled
     * sweep cannot disagree about what "not configured" means.
     *
     * @return array<string, mixed>
     */
    private function checkOne(ProviderMonitor $monitor, ProviderRegistry $registry, Provider $provider): array
    {
        $result = $monitor->probe($provider);

        return [
            'provider' => $result['provider'],
            'status' => $result['status'],
            'balance' => $result['balance_minor'],
            'latency_ms' => $result['latency_ms'],
            'message' => $result['message'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function exitCode(array $results): int
    {
        foreach ($results as $row) {
            if (in_array($row['status'], [Provider::HEALTH_DOWN, Provider::HEALTH_DEGRADED], true)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
