<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            AdminSeeder::class,
            PromotionNotificationSeeder::class,

            /*
             * The approved category structure (§51.2). Deliberately idempotent and
             * deliberately product-free: it seeds navigation, not offerings — see the
             * seeder's own docblock for why creating a product must remain an
             * operator's decision.
             */
            ServiceCatalogueSeeder::class,

            /*
             * The upstream provider rows, derived from config/bills.php.
             *
             * These must exist before any provider cost term can be recorded — the
             * `provider_network_costs` table references them — and without a cost term
             * airtime pricing refuses every sale. Runs before the pricing defaults
             * because a rule is only useful once something can be priced.
             */
            ProviderSeeder::class,

            /*
             * The pricing rules without which nothing can be sold. The engine
             * refuses to sell a product no active rule covers — correctly, since
             * the alternative is selling at cost — so a fresh install needs these
             * before airtime or data works at all.
             *
             * Runs last: it attributes each rule to the seeded administrator.
             */
            PricingDefaultsSeeder::class,
        ]);
    }
}