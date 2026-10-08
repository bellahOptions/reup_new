<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The international catalogue, and the history behind the provider dashboard.
 *
 * ## `international_products`
 *
 * International top-up is the one Wave 1 product whose price is not a number the
 * provider publishes in naira. VTUGate and VTpass both quote in the recipient's
 * currency, converted at a rate that moves, and both charge a fee or pay a
 * commission on top. A single `provider_cost_minor` column on `provider_products`
 * cannot express that without throwing away the rate it came from — and a price
 * whose rate is not recorded cannot be explained to a customer, audited later, or
 * defended when the rate moves against us.
 *
 * So the international catalogue is its own table, and it stores the whole
 * derivation: the provider's own quoted amount in its own currency, the rate and
 * where the rate came from, the fee, the resulting naira cost, and the customer
 * price that was derived at the time of the last sync. `provider_products` remains
 * the routing row — "this provider can serve this service product at this cost" —
 * and this table is the detail behind it.
 *
 * The unique index on `(provider_id, provider_product_id)` is what makes the sync
 * idempotent: re-running it updates rows rather than duplicating a catalogue, so a
 * scheduled sync that runs twice in a minute is harmless.
 *
 * ## `provider_health_checks`
 *
 * `providers.health_status` holds only the latest state, which is what the
 * storefront needs and is not enough to answer the question an operator actually
 * asks during an incident: *when did this start?* A single column cannot show that
 * a provider has been flapping for forty minutes.
 *
 * Each probe appends a row here. The table is append-only and written by a
 * scheduled command, and it is pruned by retention rather than updated, so a
 * dashboard can chart availability and a "went down at" timestamp is always
 * available.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createInternationalProducts();
        $this->createProviderHealthChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_health_checks');
        Schema::dropIfExists('international_products');
    }

    private function createInternationalProducts(): void
    {
        if (Schema::hasTable('international_products')) {
            return;
        }

        Schema::create('international_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();

            /*
             * Nullable: a provider may offer an international product before an
             * operator has mapped it to one of our own sellable products, and that
             * intermediate state is exactly what the sync screen is for. A row with
             * no mapping is visible and unsellable rather than invisible.
             */
            $table->foreignId('service_product_id')->nullable()->constrained('service_products')->nullOnDelete();

            /* The provider's own identifier for this offering. */
            $table->string('provider_product_id', 191);

            /* Where it can be delivered. */
            $table->char('country_code', 2);
            $table->string('country_name');
            $table->string('dial_prefix', 8)->nullable();
            $table->string('provider_currency', 8);

            /* Who receives it. */
            $table->string('operator_id', 64)->nullable();
            $table->string('operator_name')->nullable();

            /* What kind of thing it is: Mobile Top Up, Mobile Data, a PIN. */
            $table->string('product_type_id', 64)->nullable();
            $table->string('product_type_name')->nullable();

            $table->string('product_name');

            /*
             * The variation code a purchase is actually addressed with. Stored
             * separately from the product name because a name is display text that
             * a provider rewords, and addressing a purchase by display text is how
             * the wrong bundle gets bought.
             */
            $table->string('variation_code', 191)->nullable();

            /*
             * The face value in the provider's own currency. Fixed-price variations
             * publish one; flexible-price variations publish a rate instead and the
             * customer chooses the amount, which is why this is nullable and
             * `rate_per_unit_micros` exists alongside it.
             */
            $table->unsignedBigInteger('provider_amount_minor')->nullable();
            $table->boolean('is_fixed_price')->default(true);

            /*
             * Provider cost in kobo, ready for the pricing engine, together with the
             * parts it was derived from. All three are kept: the derived cost is what
             * pricing uses, and the parts are what makes it auditable when a margin
             * looks wrong.
             */
            $table->unsignedBigInteger('provider_cost_minor')->nullable();
            $table->unsignedBigInteger('provider_fee_minor')->default(0);

            /*
             * The exchange rate as an integer scaled by 1,000,000. A float would
             * round differently in two places and produce a price that does not
             * reconcile; an integer is exact and comparable.
             */
            $table->unsignedBigInteger('exchange_rate_micros')->nullable();

            /*
             * Where the rate came from — 'provider_preview', 'provider_catalogue',
             * 'manual'. A rate with no provenance cannot be reviewed, and a manual
             * rate is a decision somebody must own.
             */
            $table->string('exchange_rate_source', 32)->nullable();

            /* The price derived at the last sync, in kobo, for drift detection. */
            $table->unsignedBigInteger('customer_price_minor')->nullable();

            /* Previous cost, so a jump can be alerted on rather than discovered. */
            $table->unsignedBigInteger('previous_cost_minor')->nullable();
            $table->timestamp('cost_changed_at')->nullable();

            /* 'active' | 'inactive'. Inactive is retained rather than deleted. */
            $table->string('status', 16)->default('active');

            /* Provider-specific detail that has no column of its own. */
            $table->json('metadata')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('unavailable_at')->nullable();
            $table->timestamps();

            /* Re-running a sync must update, never duplicate. */
            $table->unique(['provider_id', 'provider_product_id'], 'international_products_provider_unique');

            /* How a customer-facing catalogue is listed. */
            $table->index(['country_code', 'status'], 'international_products_country_status');
            $table->index(['service_product_id', 'status'], 'international_products_product_status');

            /* How the sync finds rows that disappeared upstream. */
            $table->index(['provider_id', 'last_synced_at'], 'international_products_sync');
        });
    }

    private function createProviderHealthChecks(): void
    {
        if (Schema::hasTable('provider_health_checks')) {
            return;
        }

        Schema::create('provider_health_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();

            $table->timestamp('checked_at');

            /*
             * Whether the provider answered an authenticated read. This is not the
             * same as "healthy": a provider can answer and still be unusable, which
             * `error_code` records.
             */
            $table->boolean('available');

            $table->unsignedSmallInteger('http_status')->nullable();

            /* How long the probe took, in milliseconds. A slow provider is a warning. */
            $table->unsignedInteger('latency_ms')->nullable();

            $table->unsignedBigInteger('balance_minor')->nullable();
            $table->string('currency', 8)->nullable();

            /*
             * The provider's own error token — 'invalid_api_key', 'account_suspended'
             * — kept verbatim so an operator can match it against the vendor's
             * documentation without a translation step.
             */
            $table->string('error_code', 64)->nullable();

            $table->string('message', 500)->nullable();

            /*
             * Recorded per check, not read from config at display time: a dashboard
             * that shows historical probes must not relabel them after somebody
             * flips the sandbox switch.
             */
            $table->boolean('is_sandbox')->default(false);

            $table->timestamps();

            /* "The last N checks for this provider" — the only query shape. */
            $table->index(['provider_id', 'checked_at'], 'provider_health_checks_provider_time');
        });
    }
};
