<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Transaction Info
            $table->string('reference')->unique();
            $table->enum('type', ['credit', 'debit'])->default('debit');
            $table->enum('service_type', ['airtime', 'data', 'funding', 'cable-tv', 'electricity', 'exam', 'transfer', 'other']);
            $table->string('description');
            
            // Financial Info
            $table->decimal('amount', 15, 2);
            $table->decimal('service_fee', 10, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('balance_before', 15, 2)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            
            // Service Details
            $table->string('recipient')->nullable();
            $table->string('provider')->nullable();
            $table->string('plan_name')->nullable();
            $table->string('plan_type')->nullable();
            
            // Payment Info
            $table->enum('payment_method', ['wallet', 'paystack', 'bank_transfer', 'card', 'ussd'])->default('wallet');
            $table->string('payment_reference')->nullable();
            $table->enum('payment_status', ['pending', 'processing', 'success', 'failed', 'cancelled'])->default('pending');
            
            // Transaction Status
            $table->enum('status', ['pending', 'processing', 'success', 'failed', 'cancelled'])->default('pending');
            $table->text('status_message')->nullable();
            
            // API Integration
            $table->string('api_reference')->nullable();
            $table->json('api_response')->nullable();
            
            // Additional Fields
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['reference']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};