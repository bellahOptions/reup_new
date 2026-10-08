<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `transactions.service_type` for the Wave 1 products.
 *
 * The same class of bug this column has already caused twice: an ENUM that does
 * not carry a value the application writes is error 1265 under strict SQL mode
 * (the transaction cannot be recorded at all) or a silent empty string under a
 * lax server (every history filter quietly matches nothing).
 *
 * Wave 1 adds six product families whose `service_type` is written by the
 * purchase pipeline, so the column has to carry them before any of them can be
 * sold. Following the existing convention, the values are the *stable,
 * customer-facing* spellings the history pages filter on:
 *
 *   internet       — broadband / fibre / Smile subscriptions
 *   education      — school fees, exam registrations as a category
 *   epin           — recharge card / e-PIN denominations
 *   esim           — eSIM plans
 *   international  — international airtime and data top-up
 *   giftcard       — gift card purchases
 *   smm            — social media marketing orders
 *
 * `exam` is deliberately left alone: WAEC and JAMB keep writing it, so every
 * existing exam history page and admin counter continues to work unchanged.
 *
 * `down()` maps the new values to `other` before narrowing, so a rollback never
 * truncates a row into an empty string. It is lossy for the new product types —
 * that is inherent in narrowing an ENUM, and it is why the rollback is a
 * deliberate, documented operation rather than something that happens by
 * accident.
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
        // Wave 1
        'internet',
        'education',
        'epin',
        'esim',
        'international',
        'giftcard',
        'smm',
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

        DB::table('transactions')
            ->whereIn('service_type', ['internet', 'education', 'epin', 'esim', 'international', 'giftcard', 'smm'])
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
