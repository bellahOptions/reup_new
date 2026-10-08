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
        ]);
    }
}