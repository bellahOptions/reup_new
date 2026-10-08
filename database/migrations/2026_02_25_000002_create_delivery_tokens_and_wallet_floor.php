<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only encrypted store for delivery tokens, plus a hard floor on wallet
 * balances.
 *
 * ## `delivery_tokens`
 *
 * A gift card code, recharge PIN, exam PIN, electricity token or eSIM activation
 * payload is the goods the customer paid for. It is kept in its own table rather
 * than as a column on `service_orders` for one reason: so that no `toArray()`, no
 * admin list query and no API serialisation of an order can accidentally include
 * it. Reading a token requires an explicit call to
 * `App\Providers\Support\DeliveryTokenStore::retrieve()` after an ownership or
 * permission check.
 *
 * The unique index on `service_order_id` is the idempotency guard: a webhook and
 * a reconciliation poll that both fetch the same delivery cannot produce two
 * rows.
 *
 * ## The wallet balance floor
 *
 * `WalletService` already refuses an overdraft under a row lock, and that is the
 * control that matters because it produces a clean error. This constraint is the
 * second lock: it makes a negative balance impossible at the storage layer, so a
 * future code path that bypasses the service fails loudly instead of quietly
 * creating a wallet that owes money.
 *
 * **Production safety.** The constraint is added only if no wallet currently
 * violates it. A pre-existing negative balance is a real financial state that
 * somebody has to resolve; silently clamping it to zero would erase a debt and
 * silently failing the migration would block the deploy with no explanation. So
 * the violation is detected, named and skipped-with-a-warning, and the runbook
 * says to investigate it.
 *
 * MySQL 8.0.16+ enforces `CHECK`; older versions parse and ignore it, which is
 * why the application-level lock remains the primary control.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createDeliveryTokens();
        $this->addWalletBalanceFloor();
        $this->addOrderReconciliationIndex();
    }

    public function down(): void
    {
        $this->dropWalletBalanceFloor();
        Schema::dropIfExists('delivery_tokens');
    }

    private function createDeliveryTokens(): void
    {
        if (Schema::hasTable('delivery_tokens')) {
            return;
        }

        Schema::create('delivery_tokens', function (Blueprint $table) {
            $table->id();

            /*
             * `restrictOnDelete`: a delivered token is evidence that goods were
             * handed over, and it must not be deleted out from under the order it
             * belongs to.
             */
            $table->foreignId('service_order_id')->unique()->constrained('service_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            /* 'gift_card' | 'epin' | 'exam_pin' | 'electricity_token' | 'esim' */
            $table->string('kind', 32);

            /* Laravel-encrypted JSON. See DeliveryTokenStore. */
            $table->text('ciphertext');

            /*
             * Field names and masked tails only. Safe to render in an admin list
             * so support can confirm a delivery happened without reading it.
             */
            $table->json('redacted_summary')->nullable();

            $table->timestamp('delivered_at');
            $table->timestamps();

            /* "Does this user have a delivery for this order" — the only list query. */
            $table->index(['user_id', 'delivered_at']);
        });
    }

    private function addWalletBalanceFloor(): void
    {
        if (! Schema::hasTable('wallets') || ! $this->isMySql()) {
            return;
        }

        $violations = DB::table('wallets')->where('balance', '<', 0)->count();

        if ($violations > 0) {
            /*
             * Named rather than swallowed. `php artisan wallet:verify` and the
             * pre-flight tool both surface these too; this is the last point at
             * which the deploy can be paused with the specific rows known.
             */
            logger()->warning(
                "Skipping the wallets.balance CHECK constraint: {$violations} wallet(s) currently hold a negative "
                . 'balance. Resolve them, then re-run `php artisan migrate`. No data was changed.'
            );

            return;
        }

        if ($this->constraintExists('wallets', 'wallets_balance_non_negative')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `wallets` ADD CONSTRAINT `wallets_balance_non_negative` CHECK (`balance` >= 0)'
        );
    }

    private function dropWalletBalanceFloor(): void
    {
        if (! Schema::hasTable('wallets') || ! $this->isMySql()) {
            return;
        }

        if (! $this->constraintExists('wallets', 'wallets_balance_non_negative')) {
            return;
        }

        DB::statement('ALTER TABLE `wallets` DROP CHECK `wallets_balance_non_negative`');
    }

    /**
     * The reconciliation sweep reads "unresolved orders that are due", ordered by
     * when they are next due. `service_orders_reconcile` covers status first,
     * which is not selective when most orders are resolved; this partial index
     * keeps the sweep proportional to the number of *open* orders rather than the
     * total.
     */
    private function addOrderReconciliationIndex(): void
    {
        if (! Schema::hasTable('service_orders')) {
            return;
        }

        if ($this->indexExists('service_orders', 'service_orders_open_reconcile')) {
            return;
        }

        Schema::table('service_orders', function (Blueprint $table) {
            $table->index(['next_reconcile_at', 'status'], 'service_orders_open_reconcile');
        });
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$connection->getDatabaseName(), $table, $indexName]
        );

        return $result !== null && (int) $result->total > 0;
    }

    private function constraintExists(string $table, string $constraintName): bool
    {
        $connection = Schema::getConnection();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = ? AND constraint_name = ?',
            [$connection->getDatabaseName(), $table, $constraintName]
        );

        return $result !== null && (int) $result->total > 0;
    }
};
