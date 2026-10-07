<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give "fraudulent" a real column.
 *
 * The admin bank-transfer screen previously derived fraud state by running
 * LIKE '%fraud%' against the free-text `status_message` column, so any admin
 * remark containing the word "fraud" reclassified an unrelated transaction,
 * and the filter could not be indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'is_fraudulent')) {
                $table->boolean('is_fraudulent')->default(false)->after('status_message');
                $table->index(['service_type', 'payment_method', 'is_fraudulent'], 'txn_funding_fraud_index');
            }
        });

        // Backfill from the historical string convention so existing rows keep
        // their classification.
        DB::table('transactions')
            ->where('status_message', 'like', '%fraud%')
            ->update(['is_fraudulent' => true]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'is_fraudulent')) {
                $table->dropIndex('txn_funding_fraud_index');
                $table->dropColumn('is_fraudulent');
            }
        });
    }
};
