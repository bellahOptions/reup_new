<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider costs per network, and network-scoped pricing rules.
 *
 * ## What this adds, and why it is additive
 *
 * Two things the airtime and data purchase paths need and the schema did not
 * have:
 *
 *   1. a place to record *what a provider charges us* for a network, with the
 *      time it was last verified — so "ClubKonnect gives 3% off airtime" is a
 *      row an administrator can see, change and audit rather than a constant in
 *      a controller;
 *   2. a `network` dimension on pricing rules, so MTN's markup can be configured
 *      independently of Airtel's without inventing a parallel pricing model.
 *
 * It also widens the two `pricing_rules` ENUMs that must accept the new
 * vocabulary: `scope` gains `network`, and `markup_type` gains `face_value` (the
 * airtime strategy — the customer pays the face value, not cost plus markup).
 *
 * Nothing existing is altered destructively. Both ENUM changes preserve every
 * existing row and every existing member; the previous values remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createProviderNetworkCosts();
        $this->addNetworkScopeToPricingRules();
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_network_costs');

        if (Schema::hasColumn('pricing_rules', 'network')) {
            Schema::table('pricing_rules', function (Blueprint $table) {
                // The index must go before the column it covers.
                $table->dropIndex(['scope', 'network']);
                $table->dropColumn('network');
            });
        }

        /*
         * `scope` is left as the widened enum on rollback, deliberately. Contracting
         * it back to its original members would silently rewrite any rule an
         * administrator created at the network scope — turning a rollback into data
         * loss. A boot-time check (`PricingRule::SCOPE_ORDER`) already refuses an
         * unknown scope, so a stale enum member is inert rather than harmful.
         */
    }

    /**
     * What a provider charges ReUp, per capability and network.
     *
     * A rate rather than an amount, because airtime is sold at any face value: the
     * provider's commercial term is "face value less 3%", not "₦970 for ₦1,000".
     * Storing the rate means one row prices every denomination, and a rate change is
     * one audited edit instead of thousands of price rows.
     *
     * Data bundles are deliberately *not* stored here: their cost is a real quoted
     * price per bundle, which already lives in `provider_products` and the cached
     * catalogue. This table is for rate-based commercial terms.
     */
    private function createProviderNetworkCosts(): void
    {
        if (Schema::hasTable('provider_network_costs')) {
            return;
        }

        Schema::create('provider_network_costs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // `provider_id` is a real FK so a re-synced provider keeps its costs.
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();

            // The ReUp product this term applies to: 'airtime', 'data', …
            $table->string('capability', 64);

            // Canonical network key from config/networks.php ('mtn', 'airtel'…).
            // Null means "any network this provider serves", which is how a
            // provider-wide term is expressed without duplicating a row per network.
            $table->string('network', 32)->nullable();

            /**
             * The discount off list/face value, in basis points. 300 = 3%.
             *
             * Basis points, not a percentage float: `0.03` is not representable in
             * binary floating point, and a discount applied with it drifts by a kobo
             * per order — the drift lands in reported margin.
             */
            $table->unsignedInteger('discount_bps')->default(0);

            /** Any provider-side fee on top of the discounted cost, in kobo. */
            $table->unsignedBigInteger('provider_fee_minor')->default(0);

            $table->char('currency', 3)->default('NGN');

            /**
             * When this term was last confirmed against a real invoice, statement or
             * provider response. Null means "configured but never verified", which
             * makes the resulting profit *estimated* rather than realised.
             */
            $table->timestamp('verified_at')->nullable();

            /** Who entered or last confirmed the figure, for the audit trail. */
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            /** Free-text evidence: an invoice number, a support email reference. */
            $table->string('verification_note', 191)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * One active term per provider + capability + network. A UNIQUE index
             * rather than an application check, for the same reason the idempotency
             * key is unique: the database is what makes a duplicate impossible, and a
             * second row would make the cost (and so the profit) ambiguous.
             *
             * MySQL treats NULLs as distinct in a UNIQUE index, so the "any network"
             * row is not covered by this. `is_active` plus resolution order handles
             * that case.
             */
            $table->unique(['provider_id', 'capability', 'network'], 'provider_cost_unique');
            $table->index(['capability', 'network', 'is_active'], 'provider_cost_lookup');
        });
    }

    /**
     * Widen the `pricing_rules` ENUMs to accept the new vocabulary and add the
     * column the network scope keys on.
     */
    private function addNetworkScopeToPricingRules(): void
    {
        if (! Schema::hasTable('pricing_rules')) {
            return;
        }

        if (! Schema::hasColumn('pricing_rules', 'network')) {
            Schema::table('pricing_rules', function (Blueprint $table) {
                // Canonical network key, or null for every other scope.
                $table->string('network', 32)->nullable()->after('provider_id');
                $table->index(['scope', 'network'], 'pricing_rules_scope_network_index');
            });
        }

        /*
         * Widened with raw DDL because `Blueprint::change()` on an enum needs
         * doctrine/dbal, and this must also work on the SQLite database some unit
         * runs use. SQLite stores these columns as text with no constraint, so the
         * new members need no DDL there at all.
         *
         * Omitting `face_value` here is not a cosmetic gap: MySQL rejects the value
         * with "Data truncated for column 'markup_type'", so every airtime rule would
         * fail to save.
         */
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE pricing_rules MODIFY COLUMN scope "
                . "ENUM('global','category','provider','network','product','provider_product') "
                . "NOT NULL"
            );

            DB::statement(
                "ALTER TABLE pricing_rules MODIFY COLUMN markup_type "
                . "ENUM('percentage','fixed','percentage_plus_fixed','none','face_value') "
                . "NOT NULL DEFAULT 'percentage'"
            );
        }
    }
};
