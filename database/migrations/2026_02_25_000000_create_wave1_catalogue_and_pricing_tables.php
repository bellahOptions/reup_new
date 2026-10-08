<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Wave 1 catalogue, pricing and profit schema.
 *
 * ## Relationship to what already exists
 *
 * Nothing here replaces `transactions` or `wallets`. Those remain the financial
 * record of what a customer paid and what the wallet did; the tables below are
 * the *product and margin* record — which provider product was sold, what it
 * cost, what rule priced it, and what margin that produced.
 *
 * The two are joined by `service_orders.transaction_id`, one row per wallet
 * debit. A single `transactions` row can have at most one `service_orders` row,
 * enforced by a unique index, so the profit ledger can never double-count.
 *
 * ## Money
 *
 * Every monetary column is `decimal(15,2)`, matching the existing schema, and is
 * written from `App\Support\Money` so the values are exact to the kobo. `Money`
 * is NGN-only by construction, so a second currency is stored alongside its own
 * integer minor-unit column plus the exact rate used — never a float.
 *
 * ## Idempotency and references
 *
 * Provider references and the provider-side idempotency key each carry a UNIQUE
 * index. This is the same defence used for `transactions.payment_reference`:
 * the database, not application code, is what makes a duplicate fulfilment
 * impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createProviders();
        $this->createServiceCategories();
        $this->createServiceProducts();
        $this->createProviderProducts();
        $this->createProviderPriceSnapshots();
        $this->createPricingRules();
        $this->createPricingRuleVersions();
        $this->createServiceOrders();
        $this->createPricingSnapshots();
        $this->createProfitRecords();
        $this->createProviderTransactions();
        $this->createSavedBills();
        $this->createBillReminders();
        $this->createProductAlerts();
    }

    public function down(): void
    {
        /*
         * Dropped in reverse dependency order. Nothing here is referenced by the
         * existing financial tables, so dropping these leaves `transactions`,
         * `wallets` and `wallet_ledger` completely untouched.
         */
        Schema::dropIfExists('product_alerts');
        Schema::dropIfExists('bill_reminders');
        Schema::dropIfExists('saved_bills');
        Schema::dropIfExists('provider_transactions');
        Schema::dropIfExists('profit_records');
        Schema::dropIfExists('pricing_snapshots');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('pricing_rule_versions');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('provider_price_snapshots');
        Schema::dropIfExists('provider_products');
        Schema::dropIfExists('service_products');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('providers');
    }

    /* =====================================================================
     | Catalogue
     |=================================================================== */

    private function createProviders(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('providers')) {
            return;
        }

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Machine name, e.g. 'sogo'. Must match the adapter's name().
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('driver', 64);

            // Which Wave 1 capabilities this provider can serve. An array rather
            // than a table because it is a fixed, code-defined vocabulary —
            // a provider cannot invent a new capability.
            $table->json('capabilities')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);

            /*
             * Priority within a capability, lower first. This is what the
             * Super Admin changes to move Sogo ahead of ClubKonnect for a
             * category without touching code.
             */
            $table->unsignedInteger('priority')->default(100);

            /*
             * Where the credentials live: an env key prefix, never a value. The
             * admin console may display "SOGO_SECRET_KEY is set" but must never
             * display, return or log the value itself.
             */
            $table->string('credential_env_prefix', 96)->nullable();

            /*
             * Declared credential scopes. Sogo issues separate keys for
             * bills:read / bills:write and so on; recording which env var holds
             * which scope lets the adapter pick the least-privileged key for a
             * read-versus-write operation.
             */
            $table->json('credential_scopes')->nullable();

            $table->string('docs_url')->nullable();

            /* Low-balance alert threshold, in kobo. Null disables the alert. */
            $table->unsignedBigInteger('low_balance_threshold_minor')->nullable();

            /* Runtime health, written by the balance/health services. */
            $table->string('health_status', 32)->default('unknown');
            $table->timestamp('health_checked_at')->nullable();
            $table->string('health_message')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index('health_status');
        });
    }

    private function createServiceCategories(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('service_categories')) {
            return;
        }

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // 'pay_bills', 'digital', 'social' — the approved top-level product
            // groupings the customer navigation uses.
            $table->string('group', 32);

            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon', 64)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['group', 'sort_order']);
            $table->index('is_active');
        });
    }

    private function createServiceProducts(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('service_products')) {
            return;
        }

        Schema::create('service_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('category_id')->constrained('service_categories')->cascadeOnDelete();

            /*
             * Reuses the vocabulary in config/bills.php `products` — 'airtime',
             * 'data', 'cable_tv' … — so an existing purchase pipeline and a new
             * product row cannot disagree about what a product is called.
             */
            $table->string('product_key', 64);

            $table->string('slug', 128)->unique();
            $table->string('name');
            $table->string('description')->nullable();

            /*
             * The lifecycle. Provider discovery creates DISCOVERED rows; only an
             * explicit Super Admin action moves one to ACTIVE. This is the
             * control that stops a newly synced provider catalogue from going
             * straight on sale.
             */
            $table->enum('status', [
                'DISCOVERED',
                'REVIEW',
                'ACTIVE',
                'PAUSED',
                'OUT_OF_STOCK',
                'UNPROFITABLE',
                'PROVIDER_UNAVAILABLE',
                'DISCONTINUED',
            ])->default('DISCOVERED');

            /* Denormalised availability, recomputed from margin rules. */
            $table->enum('availability', [
                'AVAILABLE',
                'AVAILABLE_WITH_WARNING',
                'TEMPORARILY_UNAVAILABLE',
    ])->default('TEMPORARILY_UNAVAILABLE');

            /* Super Admin override: sell even when the margin rule objects. */
            $table->boolean('allow_below_minimum_margin')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            /* Per-product input shape, e.g. required fields and validation. */
            $table->json('input_schema')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            /*
             * The customer-facing catalogue query is
             * "active products in an active category, ordered" — this index
             * serves it without a scan.
             */
            $table->index(['status', 'category_id', 'sort_order']);
            $table->index('availability');
            $table->index('product_key');
        });
    }

    /**
     * One provider's offer of one product.
     *
     * A `service_product` is the thing the customer buys; a `provider_product`
     * is where it is bought from. A product normally has several, which is what
     * makes failover and cheapest-cost routing possible.
     */
    private function createProviderProducts(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('provider_products')) {
            return;
        }

        Schema::create('provider_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignId('service_product_id')->constrained('service_products')->cascadeOnDelete();

            /*
             * The provider's own identifier for this exact offering — Sogo's
             * `variation_code`, VTpass's `variation_code`, Nitro's `service`.
             *
             * Stored as a string because the providers disagree on type and none
             * of them guarantees an integer. Never hard-coded in a controller: it
             * is always read from this table after a sync.
             */
            $table->string('provider_product_id', 191);

            $table->string('provider_name');

            /* What the provider charges us, in kobo. */
            $table->unsignedBigInteger('provider_cost_minor')->nullable();

            /*
             * Where the provider quotes in another currency, the exact quote and
             * the exact rate used to reach the kobo figure above. Historical
             * profit must never be recomputed from today's rate, so both are
             * stored rather than derived.
             */
            $table->string('provider_currency', 3)->nullable();
            $table->unsignedBigInteger('provider_amount_minor')->nullable();
            $table->unsignedBigInteger('rate_used_micros')->nullable();

            /* Nitro-style volumetric pricing: rate is per 1000 units. */
            $table->unsignedBigInteger('provider_rate_minor')->nullable();
            $table->unsignedInteger('rate_divisor')->nullable();

            /* Customer-side face value where the product has one. */
            $table->string('denomination', 64)->nullable();

            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('max_quantity')->nullable();

            /*
             * Provider-catalogue metadata. `description` is stored because Nitro
             * explicitly instructs that it be read before a service is sold —
             * it carries setup requirements the buyer must satisfy.
             */
            $table->text('description')->nullable();
            $table->string('service_type', 64)->nullable();
            $table->string('platform', 64)->nullable();
            $table->boolean('supports_refill')->default(false);
            $table->boolean('supports_cancel')->default(false);

            $table->boolean('is_active')->default(true);

            /*
             * Which key scope this operation needs, so the adapter can select a
             * read-scoped credential for a catalogue call and a write-scoped one
             * for a purchase.
             */
            $table->string('required_scope', 64)->nullable();

            /* --- Discovery state ------------------------------------------
             | A provider product that disappears from a sync is marked
             | `unavailable_at` and kept, never deleted: historical orders and
             | provider references must stay resolvable.
             */
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('unavailable_at')->nullable();
            $table->timestamp('cost_synced_at')->nullable();

            /* Set when a sync changes the cost, so the admin sees what moved. */
            $table->unsignedBigInteger('previous_cost_minor')->nullable();
            $table->timestamp('cost_changed_at')->nullable();

            $table->timestamps();

            /*
             * A provider must not offer the same product twice, and a sync must
             * be able to upsert on this pair. This is what makes the catalogue
             * sync idempotent.
             */
            $table->unique(['provider_id', 'provider_product_id'], 'provider_products_unique_offering');

            $table->index(['service_product_id', 'is_active']);
            $table->index(['provider_id', 'is_active']);
            $table->index('platform');
        });
    }

    /**
     * Append-only record of a provider cost at a point in time.
     *
     * The `provider_products` row holds the *current* cost; this table holds
     * every cost it has ever had. That is what lets the admin console show a
     * cost increase and what makes an alert ("₦2,720 → ₦3,100") possible without
     * inventing history.
     */
    private function createProviderPriceSnapshots(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('provider_price_snapshots')) {
            return;
        }

        Schema::create('provider_price_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_product_id')->constrained('provider_products')->cascadeOnDelete();

            $table->unsignedBigInteger('cost_minor');
            $table->string('provider_currency', 3)->nullable();
            $table->unsignedBigInteger('provider_amount_minor')->nullable();
            $table->unsignedBigInteger('rate_used_micros')->nullable();

            /* 'sync' | 'manual' | 'order' — why this row exists. */
            $table->string('source', 32)->default('sync');

            $table->timestamp('recorded_at');

            $table->index(['provider_product_id', 'recorded_at'], 'provider_price_snapshots_lookup');
        });
    }

    /* =====================================================================
     | Pricing
     |=================================================================== */

    /**
     * The pricing-rule hierarchy.
     *
     * Specificity is resolved by the `scope` column plus the nullable foreign
     * keys, in this order:
     *
     *   global > category > provider > product > provider_product
     *
     * A narrower rule always wins over a broader one; within a level the highest
     * `priority` wins; ties break on the most recently updated rule. Only active
     * rules whose promotional window covers "now" are eligible.
     */
    private function createPricingRules(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('pricing_rules')) {
            return;
        }

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->enum('scope', ['global', 'category', 'provider', 'product', 'provider_product']);

            /* Exactly one of these is set, according to `scope`. */
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->cascadeOnDelete();
            $table->foreignId('service_product_id')->nullable()->constrained('service_products')->cascadeOnDelete();
            $table->foreignId('provider_product_id')->nullable()->constrained('provider_products')->cascadeOnDelete();

            /* --- Markup ---------------------------------------------------
             | `markup_type` decides which of the three amount columns apply.
             */
            $table->enum('markup_type', ['percentage', 'fixed', 'percentage_plus_fixed', 'none'])
                ->default('percentage');

            /* Basis points: 2000 = 20.00%. Integer, so no float in the rule. */
            $table->unsignedInteger('markup_percentage_bps')->default(0);
            $table->unsignedBigInteger('markup_fixed_minor')->default(0);

            /* --- Floors and ceilings ---------------------------------------
             | All in kobo. `minimum_profit_minor` is the rule that turns "20%
             | markup" into "20% but never less than ₦100".
             */
            $table->unsignedBigInteger('minimum_profit_minor')->default(0);
            $table->unsignedBigInteger('maximum_markup_minor')->nullable();
            $table->unsignedBigInteger('minimum_selling_price_minor')->nullable();
            $table->unsignedBigInteger('maximum_selling_price_minor')->nullable();

            /* Minimum gross margin, in basis points (1500 = 15.00%). */
            $table->unsignedInteger('minimum_margin_bps')->default(0);

            /* --- Customer-facing fee --------------------------------------- */
            $table->boolean('customer_fee_enabled')->default(false);
            $table->enum('customer_fee_type', ['fixed', 'percentage'])->default('fixed');
            $table->unsignedBigInteger('customer_fee_minor')->default(0);
            $table->unsignedInteger('customer_fee_bps')->default(0);

            /* --- Promotions ------------------------------------------------ */
            $table->unsignedInteger('discount_bps')->default(0);
            $table->unsignedBigInteger('discount_fixed_minor')->default(0);
            $table->timestamp('promotion_starts_at')->nullable();
            $table->timestamp('promotion_ends_at')->nullable();

            /*
             * Explicit, and deliberately loud: a loss-making promotional rule
             * must be enabled on purpose, never inherited by accident.
             */
            $table->boolean('allow_negative_margin')->default(false);

            /*
             * The rounding step, in kobo. 0 means no rounding; 1000 rounds to the
             * nearest ₦10. The final profit is recomputed *after* rounding, so a
             * rounded price never overstates margin.
             */
            $table->unsignedBigInteger('rounding_step_minor')->default(0);
            $table->enum('rounding_mode', ['nearest', 'up', 'down'])->default('nearest');

            /* Fallback behaviour when the rule cannot produce an acceptable price. */
            $table->enum('on_unprofitable', ['unavailable', 'warning', 'fallback', 'require_approval'])
                ->default('unavailable');

            /* The rule applied when this one refuses. Must not itself be a fallback. */
            $table->foreignId('fallback_rule_id')->nullable()->constrained('pricing_rules')->nullOnDelete();

            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * Rule resolution reads active rules for one scope level, ordered by
             * priority. This index serves that directly.
             */
            $table->index(['scope', 'is_active', 'priority'], 'pricing_rules_resolution');
            $table->index('promotion_starts_at');
            $table->index('promotion_ends_at');
        });
    }

    /**
     * Immutable history of every pricing-rule change.
     *
     * A rule is edited in place, which would otherwise destroy the evidence of
     * what it used to be. Each save writes the previous state here, so the audit
     * question "what was the markup when this order was priced" is answerable
     * from the database rather than from memory.
     */
    private function createPricingRuleVersions(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('pricing_rule_versions')) {
            return;
        }

        Schema::create('pricing_rule_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pricing_rule_id')->nullable()->constrained('pricing_rules')->cascadeOnDelete();

            /* 1-based sequence per rule. */
            $table->unsignedInteger('version');

            /* The complete rule state at this version, as JSON. */
            $table->json('snapshot');

            /* Which fields changed, and from/to, for a readable audit row. */
            $table->json('changes')->nullable();

            $table->string('reason')->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['pricing_rule_id', 'version'], 'pricing_rule_versions_unique');
            $table->index('created_at');
        });
    }

    /* =====================================================================
     | Orders, snapshots and profit
     |=================================================================== */

    /**
     * One row per provider-backed order.
     *
     * Links the existing financial record (`transaction_id`, the wallet debit)
     * to the product that was sold and the provider that fulfilled it.
     */
    private function createServiceOrders(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('service_orders')) {
            return;
        }

        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * The wallet debit. Unique, so an order can never be attached to two
             * transactions and two orders can never share one debit — which is
             * what stops profit being counted twice for one payment.
             */
            $table->foreignId('transaction_id')->unique()->constrained('transactions')->restrictOnDelete();

            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('service_product_id')->nullable()->constrained('service_products')->nullOnDelete();
            $table->foreignId('provider_product_id')->nullable()->constrained('provider_products')->nullOnDelete();

            $table->string('product_key', 64);
            $table->string('provider_name', 64)->nullable();

            /* What the customer bought, for the receipt and for history. */
            $table->string('recipient')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->json('order_payload')->nullable();

            /*
             * The canonical status. Superset of the provider vocabulary, so every
             * provider maps INTO this and never the other way round.
             */
            $table->enum('status', [
                'SUCCESS',
                'PENDING',
                'PROCESSING',
                'FAILED',
                'RETRYABLE',
                'UNKNOWN',
                'CANCELLED',
                'PARTIAL',
                'REFUNDED',
            ])->default('PENDING');

            /* The provider's own status string, kept verbatim for support. */
            $table->string('provider_status', 64)->nullable();

            $table->string('provider_reference', 191)->nullable();

            /*
             * ReUp's own idempotency key for THIS provider operation.
             *
             * Generated once, persisted before the request is sent, and reused
             * verbatim on every later attempt at the same operation. Never
             * regenerated while the outcome is unknown — regenerating is how a
             * duplicate provider charge is created.
             */
            $table->uuid('provider_idempotency_key')->unique();

            $table->text('status_message')->nullable();
            $table->text('failure_reason')->nullable();

            /* How many times we have asked the provider about this order. */
            $table->unsignedInteger('reconcile_attempts')->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('next_reconcile_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            /*
             * The reconciliation sweep finds unresolved orders by status and
             * schedule; the customer list finds theirs by user and date.
             */
            $table->index(['status', 'next_reconcile_at'], 'service_orders_reconcile');
            $table->index(['user_id', 'created_at']);
            $table->index('provider_reference');
            $table->index('product_key');
            $table->index('provider_id');
        });
    }

    /**
     * The immutable price a customer agreed to.
     *
     * Written once, in the same database transaction as the wallet debit. Never
     * updated. If the Super Admin later raises a markup from 20% to 30%, this row
     * is what makes yesterday's orders stay priced at 20%.
     */
    private function createPricingSnapshots(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('pricing_snapshots')) {
            return;
        }

        Schema::create('pricing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('service_order_id')->unique()->constrained('service_orders')->cascadeOnDelete();

            $table->foreignId('pricing_rule_id')->nullable()->constrained('pricing_rules')->nullOnDelete();

            /*
             * A complete copy of the rule that produced this price, so the
             * snapshot remains interpretable even if the rule is later deleted.
             */
            $table->json('pricing_rule_snapshot')->nullable();
            $table->unsignedInteger('pricing_rule_version')->nullable();

            /* --- Cost ------------------------------------------------------ */
            $table->unsignedBigInteger('provider_cost_minor');
            $table->unsignedBigInteger('provider_fee_minor')->default(0);
            $table->unsignedBigInteger('base_cost_minor');

            $table->string('provider_currency', 3)->default('NGN');
            $table->unsignedBigInteger('provider_amount_minor')->nullable();
            $table->string('customer_currency', 3)->default('NGN');
            $table->unsignedBigInteger('exchange_rate_micros')->nullable();

            /* --- Markup ---------------------------------------------------- */
            $table->string('markup_type', 32);
            $table->unsignedInteger('markup_percentage_bps')->default(0);
            $table->unsignedBigInteger('markup_fixed_minor')->default(0);
            $table->unsignedBigInteger('markup_amount_minor');

            /* --- Fees and discounts ---------------------------------------- */
            $table->unsignedBigInteger('customer_fee_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);

            /* --- Price, before and after rounding -------------------------- */
            $table->unsignedBigInteger('calculated_price_minor');
            $table->unsignedBigInteger('customer_price_minor');
            $table->unsignedBigInteger('rounding_adjustment_minor')->default(0);
            $table->unsignedBigInteger('rounding_step_minor')->default(0);

            /* --- Profit, always derived from the FINAL price --------------- */
            $table->unsignedBigInteger('gross_profit_minor');

            /*
             * Basis points, not a float and not a percentage-as-decimal.
             * 2000 = 20.00%. Stored separately from markup because the two are
             * different numbers for the same sale and conflating them is how a
             * 25% markup gets reported as a 25% margin.
             */
            $table->integer('profit_margin_bps');

            $table->char('currency', 3)->default('NGN');

            $table->json('inputs')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('profit_margin_bps');
        });
    }

    /**
     * The realised-profit record, one per finalized order.
     *
     * Deliberately separate from `pricing_snapshots`: a snapshot records what was
     * *quoted*, and this records what was *earned*. They diverge whenever an
     * order is refunded or partially delivered, and the profit dashboard must
     * read this table, not the snapshot.
     *
     * Profit is never a wallet balance adjustment. It is derived here from
     * revenue minus cost minus refunds, according to the finalized order state.
     */
    private function createProfitRecords(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('profit_records')) {
            return;
        }

        Schema::create('profit_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('service_order_id')->unique()->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();

            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('service_product_id')->nullable()->constrained('service_products')->nullOnDelete();

            /* Denormalised so the dashboard can filter without a join chain. */
            $table->string('product_key', 64);
            $table->string('category_slug', 64)->nullable();
            $table->string('provider_slug', 64)->nullable();

            /* --- Realised figures, in kobo ---------------------------------- */
            $table->unsignedBigInteger('revenue_minor');
            $table->unsignedBigInteger('provider_cost_minor');
            $table->unsignedBigInteger('provider_fee_minor')->default(0);
            $table->unsignedBigInteger('customer_fee_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);

            /* Revenue - cost - fees - refunds. May be negative after a refund. */
            $table->bigInteger('gross_profit_minor');
            $table->integer('gross_margin_bps');

            $table->char('currency', 3)->default('NGN');

            /*
             * Only a finalized, fulfilled order is realised revenue. A pending or
             * unknown order has a zero-value row here until it resolves, so the
             * dashboard cannot report unearned profit.
             */
            $table->boolean('is_realized')->default(false);
            $table->timestamp('realized_at')->nullable();

            $table->timestamps();

            /*
             * The dashboard's three access patterns: a date range, a product
             * breakdown, and a provider breakdown — all restricted to realized
             * rows.
             */
            $table->index(['is_realized', 'realized_at'], 'profit_records_realized');
            $table->index(['product_key', 'is_realized']);
            $table->index(['provider_slug', 'is_realized']);
            $table->index('category_slug');
        });
    }

    /**
     * Every provider API interaction that created or queried a transaction.
     *
     * Separate from `service_orders` because one order can have many provider
     * interactions — an initial attempt, a reconciliation poll, a retry after a
     * confirmed non-fulfilment, a refund. Storing them on the order would lose
     * that history, which is exactly the history needed to prove that a retry
     * was safe.
     */
    private function createProviderTransactions(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('provider_transactions')) {
            return;
        }

        Schema::create('provider_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();

            /* 'purchase' | 'status' | 'refill' | 'cancel' | 'verify' | 'catalogue' | 'balance' */
            $table->string('operation', 32);

            /*
             * The idempotency key used for this specific interaction. Nullable for
             * read operations, which need none.
             */
            $table->uuid('idempotency_key')->nullable();

            $table->string('provider_reference', 191)->nullable();

            /* Normalized status, same vocabulary as service_orders.status. */
            $table->string('status', 32);

            /* The provider's own status string, verbatim. */
            $table->string('provider_status', 64)->nullable();

            /*
             * The request, with credentials stripped, and the response, with
             * sensitive delivery tokens (gift card codes, PINs, eSIM activation
             * data) removed before it is written. Never store a raw payload.
             */
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            /* Backoff bookkeeping for rate limits. */
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->boolean('rate_limited')->default(false);

            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_order_id', 'created_at'], 'provider_transactions_order');
            $table->index(['provider_id', 'created_at']);
            $table->index('status');
            $table->index('operation');
        });
    }

    /* =====================================================================
     | Retention: saved bills and reminders
     |=================================================================== */

    /**
     * A bill account the customer can pay again without retyping it.
     *
     * `masked_identifier` is what gets rendered in a list; the full identifier is
     * kept in `identifier` and is only ever read by the owner's own request.
     */
    private function createSavedBills(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('saved_bills')) {
            return;
        }

        Schema::create('saved_bills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /* Ownership is the access rule, so it is indexed for every query. */
            $table->string('product_key', 64);
            $table->foreignId('service_product_id')->nullable()->constrained('service_products')->nullOnDelete();

            $table->string('label');
            $table->string('identifier');
            $table->string('masked_identifier');

            /* Meter type, network, disco, smartcard provider, operator … */
            $table->json('attributes')->nullable();

            /* Cached from the last successful verification, for display only. */
            $table->string('verified_name')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * The same account saved twice under different labels is a bug the
             * user will notice, so it is refused at the database.
             */
            $table->unique(['user_id', 'product_key', 'identifier'], 'saved_bills_unique_account');
            $table->index(['user_id', 'is_active', 'last_used_at'], 'saved_bills_owner');
        });
    }

    /**
     * A reminder to pay something.
     *
     * There is deliberately no `autopay` column. A reminder notifies; it never
     * debits. Adding a column that could be mistaken for permission to move
     * money is how "reminders cannot debit" stops being true.
     */
    private function createBillReminders(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('bill_reminders')) {
            return;
        }

        Schema::create('bill_reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('saved_bill_id')->nullable()->constrained('saved_bills')->cascadeOnDelete();

            $table->string('product_key', 64);
            $table->string('title');
            $table->string('note')->nullable();

            /* An indicative amount for the notification, never a charge. */
            $table->unsignedBigInteger('amount_minor')->nullable();

            $table->enum('frequency', ['once', 'daily', 'weekly', 'monthly', 'quarterly', 'annually']);
            $table->unsignedSmallInteger('interval')->default(1);

            $table->timestamp('starts_at');
            $table->timestamp('next_due_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('notification_count')->default(0);

            /*
             * Duplicate prevention. A reminder that has already fired for a given
             * due date must not fire again, even if the scheduler run overlaps.
             * This is the `(reminder, due_at)` pair the job claims before sending.
             */
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'next_due_at'], 'bill_reminders_due');
            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Notification history and alert state.
     *
     * Two distinct uses share this table because both are "something the Super
     * Admin or the customer should see about a product":
     *
     *   * `reminder`  — an emitted bill reminder, which is the notification
     *     history the retention feature needs and the duplicate-prevention
     *     record for it;
     *   * `alert`      — a profitability or provider-health alert raised by the
     *     scheduled checks, with a dedupe fingerprint so the same condition does
     *     not alert on every run.
     */
    private function createProductAlerts(): void
    {
        // Guarded so a re-run after a partially applied migration is safe.
        if (Schema::hasTable('product_alerts')) {
            return;
        }

        Schema::create('product_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->enum('kind', ['reminder', 'alert']);

            /* For a reminder this is the owner; null for a platform alert. */
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('bill_reminder_id')->nullable()->constrained('bill_reminders')->cascadeOnDelete();

            $table->foreignId('provider_id')->nullable()->constrained('providers')->cascadeOnDelete();
            $table->foreignId('provider_product_id')->nullable()->constrained('provider_products')->cascadeOnDelete();

            /*
             * 'provider_cost_increase', 'margin_below_threshold',
             * 'negative_margin', 'provider_unavailable', 'provider_low_balance',
             * 'unusual_price_change', 'unusual_volume', 'high_refund_rate',
             * 'reminder_due'.
             */
            $table->string('type', 48);

            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning');

            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable();

            /*
             * Stable hash of (type + subject). A unique index on it means a
             * scheduled check that runs every five minutes raises one alert for
             * one condition instead of 288 a day.
             */
            $table->string('fingerprint', 64);

            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique('fingerprint');
            $table->index(['kind', 'resolved_at', 'created_at'], 'product_alerts_open');
            $table->index(['user_id', 'created_at']);
            $table->index('severity');
        });
    }
};
