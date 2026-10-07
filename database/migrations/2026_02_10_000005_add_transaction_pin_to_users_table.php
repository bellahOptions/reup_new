<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaction PIN and PIN lockout state.
 *
 * A 4-digit PIN is only meaningful if guessing is bounded, so the failure
 * counter and lockout timestamp live beside the hash. The hash itself is a
 * bcrypt digest — `$hidden` on the model keeps it out of JSON responses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'transaction_pin')) {
                $table->string('transaction_pin')->nullable()->after('password');
                $table->timestamp('transaction_pin_set_at')->nullable()->after('transaction_pin');
                $table->unsignedTinyInteger('failed_pin_attempts')->default(0)->after('transaction_pin_set_at');
                $table->timestamp('pin_locked_until')->nullable()->after('failed_pin_attempts');
            }
        });

        // Index for the daily/monthly spending aggregation, which runs on every
        // purchase attempt.
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'type', 'created_at'], 'txn_user_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['transaction_pin', 'transaction_pin_set_at', 'failed_pin_attempts', 'pin_locked_until'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('txn_user_type_created_index');
        });
    }
};
