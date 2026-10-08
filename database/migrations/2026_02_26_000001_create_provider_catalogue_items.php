<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staging area for products a provider offers that we have not decided how to
 * sell.
 *
 * ## Why this table has to exist, and why the sync cannot simply write products
 *
 * `provider_products.service_product_id` is non-nullable, and that is deliberate:
 * a row there means "this provider can serve this product of ours at this cost",
 * which is a routing decision somebody made. A catalogue sync that could write such
 * a row would be inventing that decision from a provider's product name.
 *
 * That invention is the failure mode this table prevents. Sogo's `variation_code`
 * `MTN-1GB-30D` and its display name `MTN 1GB Monthly` are provider vocabulary; a
 * fuzzy match against our own product names would look right on the day it was
 * written and sell the wrong bundle the first time a provider renamed something.
 * Selling the wrong product is not a display bug — the customer is charged for
 * something they did not choose.
 *
 * So the sync records what it found, and a human decides. Rows here are visible on
 * the mapping screen and are not purchasable by construction: there is no path from
 * this table to an order.
 *
 * ## The unique index
 *
 * `(provider_id, provider_product_id)` is unique, so re-running a sync updates one
 * row per provider variant rather than accumulating a row per run. `first_seen_at`
 * and `last_seen_at` are what make a variant's history visible — a product that
 * appeared three syncs ago and keeps appearing is a decision somebody is deferring,
 * and one that stopped appearing is a product that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_catalogue_items')) {
            return;
        }

        Schema::create('provider_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();

            /* The provider's own identifier for this variant. */
            $table->string('provider_product_id', 191);

            /* Provider display text, stored verbatim and never parsed for meaning. */
            $table->string('provider_name')->nullable();

            /* Which of our capabilities this looks like it belongs to. */
            $table->string('capability', 64)->nullable();

            /* The provider's category for it — 'data', 'cable_tv', 'epin', … */
            $table->string('provider_category', 64)->nullable();

            /* Network or operator, where the provider states one. */
            $table->string('network', 64)->nullable();

            /* Denomination, as the provider states it, in its own units. */
            $table->string('denomination', 64)->nullable();

            /*
             * What the provider charges us, in kobo, where the catalogue states it.
             * Nullable because many catalogues publish a price only for a specific
             * customer amount, which a staging row has no way to know.
             */
            $table->unsignedBigInteger('provider_cost_minor')->nullable();

            /* The raw provider payload, so a mapping decision can be reviewed later. */
            $table->json('payload')->nullable();

            /*
             * Set when an operator maps this variant to a `provider_products` row.
             * Kept rather than deleted: "we saw this and decided it was that" is a
             * decision worth being able to read back.
             */
            $table->foreignId('mapped_provider_product_id')->nullable()
                ->constrained('provider_products')->nullOnDelete();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            /* Set when a sync no longer finds the variant upstream. */
            $table->timestamp('disappeared_at')->nullable();

            $table->timestamps();

            $table->unique(['provider_id', 'provider_product_id'], 'provider_catalogue_items_unique');

            /* "What is waiting to be mapped", which is the only list an operator reads. */
            $table->index(['provider_id', 'mapped_provider_product_id'], 'provider_catalogue_items_mapping');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_catalogue_items');
    }
};
