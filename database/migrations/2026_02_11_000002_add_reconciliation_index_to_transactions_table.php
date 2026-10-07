<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index for the reconciliation sweep.
 *
 * The reconciler runs every minute and filters on
 * (payment_method, status, created_at). Without an index that is a full table
 * scan of `transactions` once a minute, growing forever — on a busy
 * installation this query alone would dominate database load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['payment_method', 'status', 'created_at'], 'txn_reconcile_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('txn_reconcile_index');
        });
    }
};
