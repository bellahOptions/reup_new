<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated Virtual Account (DVA) details.
 *
 * Paystack's dedicated virtual accounts are *permanent* per customer — they are
 * created once and reused, and every inbound transfer to that account is
 * reported as a `charge.success` webhook with `channel = dedicated_nuban`. So
 * the account lives on the user record rather than on a transaction.
 *
 * `dva_account_number` is indexed because the webhook identifies the customer
 * by receiver account number, and there is no reference to match on: the payer
 * types it into their own bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'paystack_customer_code')) {
                $table->string('paystack_customer_code')->nullable()->after('email_verified_at');
            }

            if (! Schema::hasColumn('users', 'dva_account_number')) {
                $table->string('dva_account_number', 20)->nullable()->after('paystack_customer_code');
                // Unique, not merely indexed: the credit webhook resolves the user
                // by receiver account number alone, so a duplicate would credit the
                // wrong wallet.
                $table->unique('dva_account_number');
            }

            if (! Schema::hasColumn('users', 'dva_bank_name')) {
                $table->string('dva_bank_name')->nullable()->after('dva_account_number');
            }

            if (! Schema::hasColumn('users', 'dva_account_name')) {
                $table->string('dva_account_name')->nullable()->after('dva_bank_name');
            }

            if (! Schema::hasColumn('users', 'dva_created_at')) {
                $table->timestamp('dva_created_at')->nullable()->after('dva_account_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['paystack_customer_code', 'dva_account_number', 'dva_bank_name', 'dva_account_name', 'dva_created_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
