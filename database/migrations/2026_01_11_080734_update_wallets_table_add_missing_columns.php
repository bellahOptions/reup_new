<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Check and add missing columns
            if (!Schema::hasColumn('wallets', 'pending_balance')) {
                $table->decimal('pending_balance', 15, 2)->default(0)->after('balance');
            }
            
            if (!Schema::hasColumn('wallets', 'total_funded')) {
                $table->decimal('total_funded', 15, 2)->default(0)->after('pending_balance');
            }
            
            // Check if column is named 'total_withdrawn' and rename to 'total_spent'
            if (Schema::hasColumn('wallets', 'total_withdrawn') && !Schema::hasColumn('wallets', 'total_spent')) {
                $table->renameColumn('total_withdrawn', 'total_spent');
            } elseif (!Schema::hasColumn('wallets', 'total_spent')) {
                $table->decimal('total_spent', 15, 2)->default(0)->after('total_funded');
            }
            
            if (!Schema::hasColumn('wallets', 'transaction_count')) {
                $table->integer('transaction_count')->default(0)->after('total_spent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $columns = ['pending_balance', 'total_funded', 'total_spent', 'transaction_count'];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('wallets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};