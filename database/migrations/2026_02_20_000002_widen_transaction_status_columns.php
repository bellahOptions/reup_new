<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the transaction status vocabulary.
 *
 * ## The bug this fixes
 *
 * `transactions.payment_status` was created as
 * `ENUM('pending','processing','success','failed','cancelled')`. Application
 * code then wrote two values the enum does not carry:
 *
 *   * `'refunded'` — `BillPaymentService`, `PairgateWebhookController` and the
 *     admin refund action all set it after reversing a charge;
 *   * `'verifying'` — `WalletController::submitBankTransferProof()`, when a
 *     customer uploads proof of a bank transfer.
 *
 * On a server running the default strict SQL mode that is error 1265 and the
 * write **fails outright**, so a refund that has already moved money cannot be
 * recorded against the transaction — the wallet is credited and the transaction
 * still claims to be successful. On a lax server it silently truncates to the
 * empty string, which loses the state entirely and renders as a blank status in
 * the admin console.
 *
 * Both outcomes are unacceptable for a refund, so the column has to be able to
 * hold every state the application actually uses.
 *
 * ## Approach
 *
 * `ENUM` is kept (rather than converting to `string`) because the values are a
 * closed, application-defined set and the column is filtered and indexed on
 * heavily. Only the allowed list changes; no existing value is removed, so no
 * historical row is altered.
 *
 * `ALTER TABLE ... MODIFY` is used directly: Laravel 8's schema builder would
 * require doctrine/dbal for `change()`, and emitting the exact DDL is both
 * clearer about intent and avoids a dependency. This is a metadata-only
 * operation in MySQL 8 when the list is only being extended, so it does not
 * rewrite the table.
 *
 * ## Reversibility
 *
 * `down()` restores the original, narrower lists. It will fail if rows have
 * since been written with one of the new values — deliberately: silently
 * discarding `refunded` status would falsify a transaction's financial state.
 * Move such rows to a supported value first if a rollback is genuinely needed.
 */
return new class extends Migration
{
    /** Every value application code can write to `transactions.payment_status`. */
    private const PAYMENT_STATUSES = [
        'pending', 'processing', 'success', 'failed', 'cancelled',
        'verifying',   // customer uploaded bank-transfer proof, awaiting review
        'refunded',    // charge reversed in full
        'reversed',    // reversed by an administrator after being settled
    ];

    /** Every value application code can write to `transactions.status`. */
    private const STATUSES = [
        'pending', 'processing', 'success', 'failed', 'cancelled',
        'verifying',
        // The provider accepted the request but its outcome is not yet known.
        // Explicitly distinct from `failed`: see App\Services\BillPaymentService.
        'unknown',
    ];

    public function up(): void
    {
        $this->modifyEnum('transactions', 'payment_status', self::PAYMENT_STATUSES, "'pending'");
        $this->modifyEnum('transactions', 'status', self::STATUSES, "'pending'");
    }

    public function down(): void
    {
        $this->modifyEnum('transactions', 'payment_status', [
            'pending', 'processing', 'success', 'failed', 'cancelled',
        ], "'pending'");

        $this->modifyEnum('transactions', 'status', [
            'pending', 'processing', 'success', 'failed', 'cancelled',
        ], "'pending'");
    }

    /**
     * @param  array<int,string>  $values
     */
    private function modifyEnum(string $table, string $column, array $values, string $default): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        // Only act on MySQL/MariaDB: ENUM is not part of the other grammars and
        // the module tests against MySQL (see phpunit.xml) precisely so that
        // engine-specific behaviour is exercised rather than hidden.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $list = implode(', ', array_map(fn (string $value) => "'" . $value . "'", $values));

        DB::statement(
            "ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$list}) NOT NULL DEFAULT {$default}"
        );
    }
};
