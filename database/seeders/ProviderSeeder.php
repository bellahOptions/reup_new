<?php

namespace Database\Seeders;

use App\Models\Provider;
use Illuminate\Database\Seeder;

/**
 * The upstream provider rows.
 *
 * ## Why this is needed
 *
 * `config/bills.php` has always described the providers — their labels and which
 * products each one can sell — and `ProviderManager` routes purchases using that
 * config. But the Wave 1 `providers` *table* is what `provider_network_costs` and
 * `provider_products` hang off, and nothing populated it. So on a fresh install the
 * table was empty, which meant:
 *
 *   * no provider cost term could be recorded (the foreign key had no target);
 *   * without a cost term, airtime pricing correctly **refuses** every sale.
 *
 * That is safe but useless, and the failure is silent — airtime simply says it is
 * temporarily unavailable. Seeding the rows from the config closes it.
 *
 * ## Derived, not duplicated
 *
 * The rows come from `config('bills.providers')` rather than a second hard-coded
 * list, so the routing config and the database cannot disagree about which
 * providers exist or what they can sell. `updateOrCreate` makes this safe to
 * re-run, and it will not clobber an operator's `is_active`, `priority` or
 * `is_primary` choices on a provider that already exists.
 */
class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $order = (array) config('bills.provider_order', []);
        $providers = (array) config('bills.providers', []);

        foreach ($providers as $slug => $definition) {
            $existing = Provider::where('slug', $slug)->first();

            /*
             * Only the identity and capability set are asserted. Routing state
             * (active, primary, priority) belongs to the operator, so it is set on
             * creation only — re-running the seeder must not switch a provider back
             * on after somebody deliberately turned it off.
             */
            $attributes = [
                'name' => (string) ($definition['label'] ?? ucfirst($slug)),
                'driver' => (string) $slug,
                'capabilities' => array_values((array) ($definition['products'] ?? [])),
                'credential_env_prefix' => strtoupper((string) $slug),
            ];

            if ($existing) {
                $existing->fill($attributes)->save();

                $this->command?->line("  Provider kept: {$attributes['name']}");

                continue;
            }

            /*
             * Built with `forceFill` rather than `Provider::create()` so `slug` is
             * always written. The column has no default, so relying on mass
             * assignment for it fails outright if the model's `$fillable` is ever
             * trimmed — and the error ("Field 'slug' doesn't have a default value")
             * points at the INSERT, not at the missing fillable entry.
             */
            $provider = new Provider();
            $provider->forceFill($attributes + ['slug' => (string) $slug]);

            // Position in the configured failover order is the sensible default
            // priority; the operator can reorder from the console.
            $provider->priority = array_search($slug, $order, true) === false
                ? 100
                : ((int) array_search($slug, $order, true) + 1) * 10;
            $provider->is_active = true;
            $provider->is_primary = false;
            $provider->health_status = 'unknown';
            $provider->save();

            $this->command?->info("  Provider created: {$attributes['name']}");
        }
    }
}
