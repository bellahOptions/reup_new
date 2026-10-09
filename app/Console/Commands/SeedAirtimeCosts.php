<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Models\ProviderNetworkCost;
use App\Services\NetworkResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enter the airtime provider discounts as configured, auditable terms.
 *
 * ## Why this is a command and not a constant
 *
 * "ClubKonnect gives us 3% off airtime" is a commercial assumption, not a fact
 * this codebase can verify. It is therefore recorded as a *row* an administrator
 * can see, change and audit — and, crucially, one that is marked **unverified**
 * until somebody confirms it against a real invoice or statement.
 *
 * That distinction is what keeps the profit reporting honest: a purchase priced
 * against an unverified rate records its margin as *estimated*, never as
 * realised.
 *
 * ## Safe to re-run
 *
 * Existing rows are never overwritten unless `--force` is given, so re-running
 * after a rate has been tuned in the console does not silently revert it. The
 * default is to report what would change and do nothing.
 */
class SeedAirtimeCosts extends Command
{
    protected $signature = 'pricing:seed-airtime-costs
                            {--provider=clubkonnect : The provider slug to seed terms for}
                            {--discount-bps= : Override the default discount, in basis points (300 = 3%)}
                            {--verify : Mark the terms as verified now (only if you have confirmed the rate)}
                            {--note= : Evidence for the verification, e.g. an invoice reference}
                            {--force : Overwrite terms that already exist}';

    protected $description = 'Record each provider\'s airtime discount per network as a configurable, auditable pricing term';

    public function handle(): int
    {
        $providerSlug = (string) $this->option('provider');
        $provider = Provider::where('slug', $providerSlug)->first();

        if (! $provider) {
            $this->error("No provider with slug [{$providerSlug}]. Run `php artisan db:seed` or the provider sync first.");

            return self::FAILURE;
        }

        $defaultBps = $this->option('discount-bps') !== null
            ? (int) $this->option('discount-bps')
            : (int) config('pricing.airtime_default_discount_bps', 300);

        $overrides = (array) config('pricing.airtime_discount_bps_by_network', []);
        $networks = NetworkResolver::options();

        $verify = (bool) $this->option('verify');
        $note = $this->option('note');
        $force = (bool) $this->option('force');

        $this->info("Airtime discount terms for {$provider->name} ({$providerSlug})");
        $this->line($verify
            ? 'Marking terms VERIFIED — profit booked against them will count as realised.'
            : 'Marking terms UNVERIFIED — profit booked against them will be recorded as estimated.');

        $rows = [];

        foreach ($networks as $key => $label) {
            $bps = (int) ($overrides[$key] ?? $defaultBps);

            $rows[] = [
                'network' => $key,
                'label' => $label,
                'discount_bps' => $bps,
            ];
        }

        $this->newLine();
        $this->table(
            ['Network', 'Discount', 'Effective cost of ₦1,000', 'Status'],
            array_map(fn ($row) => [
                $row['label'],
                number_format($row['discount_bps'] / 100, 2) . '%',
                '₦' . number_format(1000 - (1000 * $row['discount_bps'] / 10000), 2),
                $verify ? 'verified' : 'assumption',
            ], $rows)
        );

        if ($this->input->isInteractive() && ! $this->option('force')
            && ! $this->confirm('Write these terms?', true)) {
            $this->warn('Nothing written.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;

        DB::transaction(function () use ($provider, $rows, $verify, $note, $force, &$written, &$skipped) {
            foreach ($rows as $row) {
                $existing = ProviderNetworkCost::where('provider_id', $provider->getKey())
                    ->where('capability', 'airtime')
                    ->where('network', $row['network'])
                    ->first();

                if ($existing && ! $force) {
                    $skipped++;

                    continue;
                }

                ProviderNetworkCost::updateOrCreate(
                    [
                        'provider_id' => $provider->getKey(),
                        'capability' => 'airtime',
                        'network' => $row['network'],
                    ],
                    [
                        'discount_bps' => $row['discount_bps'],
                        'provider_fee_minor' => 0,
                        'currency' => 'NGN',
                        'verified_at' => $verify ? now() : null,
                        'verified_by' => $verify ? auth()->id() : null,
                        'verification_note' => $verify ? $note : null,
                        'is_active' => true,
                    ]
                );

                $written++;
            }
        });

        $this->newLine();
        $this->info("Written: {$written}.  Left untouched: {$skipped}.");

        if ($skipped > 0) {
            $this->line('Existing terms kept. Pass --force to overwrite them.');
        }

        if (! $verify) {
            $this->newLine();
            $this->warn('These rates are assumptions. Profit on sales priced against them is recorded as');
            $this->warn('ESTIMATED, not realised. Confirm the real rate against a ClubKonnect statement and');
            $this->warn('re-run with --verify --note="invoice reference" to promote it.');
        }

        return self::SUCCESS;
    }
}
