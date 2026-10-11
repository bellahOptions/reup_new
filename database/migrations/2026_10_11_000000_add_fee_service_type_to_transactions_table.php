<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `fee` to `transactions.service_type`.
 *
 * ## Why this needs a migration at all
 *
 * `service_type` is an `ENUM`, not a free string — earlier migrations widened it
 * deliberately, and MySQL rejects an unlisted value by *truncating* it rather
 * than failing loudly. Writing `funding_fee` produced:
 *
 *     SQLSTATE[01000]: Warning: 1265 Data truncated for column 'service_type'
 *
 * which is the worst shape of error available: a warning rather than a failure,
 * so the row is written with a mangled type and only the ledger notices. Adding
 * the value properly is the only correct fix; a `VARCHAR` would trade the
 * constraint for a class of typo that silently becomes a new category.
 *
 * ## Why `fee` and not `funding_fee`
 *
 * The value is existing customer-facing vocabulary, and the history pages and
 * admin counters read it directly. `fee` is the shortest honest name and leaves
 * room for other fee kinds later without another widening.
 *
 * ## Safety
 *
 * Additive and non-destructive: no existing row changes value, nothing is
 * dropped, and `down()` maps `fee` rows to `other` before narrowing so a rollback
 * cannot truncate a row into an empty string. The statement is MySQL-only
 * because the ENUM is; other drivers store this column as a plain string.
 */
return new class extends Migration
{
    /** The full value list after this migration. */
    private const VALUES = [
        'airtime',
        'data',
        'funding',
        'cable-tv',
        'electricity',
        'exam',
        'betting',
        'refund',
        'transfer',
        'manual_adjustment',
        'other',
        'internet',
        'education',
        'epin',
        'esim',
        'international',
        'giftcard',
        'smm',
        'fee',
    ];

    /** The list as it stood before this migration. */
    private const PREVIOUS_VALUES = [
        'airtime',
        'data',
        'funding',
        'cable-tv',
        'electricity',
        'exam',
        'betting',
        'refund',
        'transfer',
        'manual_adjustment',
        'other',
        'internet',
        'education',
        'epin',
        'esim',
        'international',
        'giftcard',
        'smm',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transactions') || ! $this->isMySql()) {
            return;
        }

        // Normalise anything the current ENUM cannot hold before modifying it,
        // or the conversion fails on the very rows it is meant to rescue.
        DB::table('transactions')
            ->whereNotIn('service_type', self::VALUES)
            ->update(['service_type' => 'other']);

        DB::statement(
            'ALTER TABLE `transactions` MODIFY `service_type` '
            . $this->definition(self::VALUES) . ' NOT NULL'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions') || ! $this->isMySql()) {
            return;
        }

        // Map the value being removed before narrowing, so a rollback never
        // truncates a row into an empty string.
        DB::table('transactions')
            ->where('service_type', 'fee')
            ->update(['service_type' => 'other']);

        DB::statement(
            'ALTER TABLE `transactions` MODIFY `service_type` '
            . $this->definition(self::PREVIOUS_VALUES) . ' NOT NULL'
        );
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function definition(array $values): string
    {
        return 'ENUM(' . implode(', ', array_map(fn (string $value) => "'{$value}'", $values)) . ')';
    }
};
