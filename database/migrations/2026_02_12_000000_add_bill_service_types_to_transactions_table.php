<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `transactions.service_type`.
 *
 * The column was created as an ENUM of airtime/data/funding/cable-tv/
 * electricity/exam/transfer/other, but the application writes more than that:
 * the vending pipeline stored its product key (`cable_tv`, `waec`, `jamb`,
 * `betting`), a compensating refund is stored as `refund`, and an admin
 * adjustment as `manual_adjustment`.
 *
 * On a server running the default strict SQL mode an ENUM value outside the list
 * is not a warning, it is error 1265 — so those four products could not record
 * their transaction at all, and a refund could not be written, which is how a
 * customer ends up debited for a purchase that failed. On a lax server the value
 * was stored as an empty string instead, which is why every cable, exam and
 * betting history filter silently matched nothing.
 *
 * The pipeline now maps its product keys onto the vocabulary the rest of the
 * application reads (`cable_tv` => `cable-tv`, `waec`/`jamb` => `exam`), and this
 * migration makes the ENUM carry every value any code path writes.
 */
return new class extends Migration
{
    /**
     * Every value the application writes, plus the original list — which is kept
     * whole so nothing that already reads `other`, `transfer` or `funding`
     * changes meaning.
     */
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
    ];

    /** The definition this column had before this migration. */
    private const ORIGINAL_VALUES = [
        'airtime',
        'data',
        'funding',
        'cable-tv',
        'electricity',
        'exam',
        'transfer',
        'other',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        /*
         * Rows written under a lax server can hold a value that is not in the
         * ENUM at all (an empty string). Normalise those before MODIFY, or the
         * conversion itself fails on the rows it is meant to rescue.
         */
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
        if (! Schema::hasTable('transactions')) {
            return;
        }

        // The three values the original ENUM had no room for fall back to a
        // value it does hold, rather than being truncated by the conversion.
        DB::table('transactions')
            ->whereIn('service_type', ['betting', 'refund', 'manual_adjustment'])
            ->update(['service_type' => 'other']);

        DB::statement(
            'ALTER TABLE `transactions` MODIFY `service_type` '
            . $this->definition(self::ORIGINAL_VALUES) . ' NOT NULL'
        );
    }

    /**
     * @param  array<int, string>  $values
     */
    private function definition(array $values): string
    {
        return 'ENUM(' . implode(', ', array_map(fn (string $value) => "'{$value}'", $values)) . ')';
    }
};
