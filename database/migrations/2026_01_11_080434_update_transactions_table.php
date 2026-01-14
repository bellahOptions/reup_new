<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add missing columns if they don't exist
            if (!Schema::hasColumn('transactions', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->after('completed_at');
            }
            
            // Ensure all required columns exist
            if (!Schema::hasColumn('transactions', 'service_type')) {
                $table->string('service_type')->nullable()->after('type');
            }
            
            if (!Schema::hasColumn('transactions', 'service_fee')) {
                $table->decimal('service_fee', 10, 2)->default(0)->after('amount');
            }
            
            if (!Schema::hasColumn('transactions', 'total_amount')) {
                $table->decimal('total_amount', 10, 2)->default(0)->after('service_fee');
            }
            
            if (!Schema::hasColumn('transactions', 'balance_before')) {
                $table->decimal('balance_before', 15, 2)->nullable()->after('total_amount');
            }
            
            if (!Schema::hasColumn('transactions', 'balance_after')) {
                $table->decimal('balance_after', 15, 2)->nullable()->after('balance_before');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'verified_by',
                'service_type',
                'service_fee',
                'total_amount',
                'balance_before',
                'balance_after'
            ]);
        });
    }
};
