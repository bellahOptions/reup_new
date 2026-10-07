<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time sign-in codes.
 *
 * A dedicated table rather than a column on `users`, for three reasons:
 *
 *   1. Codes are stored as a bcrypt hash, so verification is a `Hash::check`
 *      rather than a string comparison — it cannot be a WHERE clause.
 *   2. Multiple rows per user are needed to keep an audit trail of request and
 *      consumption times, which is what makes replay detectable.
 *   3. `user_id` is indexed so "invalidate every outstanding code" is cheap.
 *
 * There is deliberately no unique constraint on `user_id`: several rows exist
 * per user over time, and only the newest unconsumed row is honoured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_one_time_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Hash of the 6-digit code. Never the code itself: a leaked database
            // must not hand out live sign-in codes.
            $table->string('code_hash');

            // What the code was issued for. Only 'login' today; the column keeps
            // the table reusable for step-up auth without a schema change.
            $table->string('purpose', 32)->default('login');

            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();

            // Context for the audit trail and for the "was this you?" line in the
            // email, mirroring what the admin console already records on login.
            $table->ipAddress('requested_ip')->nullable();
            $table->string('requested_user_agent', 255)->nullable();

            $table->timestamps();

            // The hot path: newest unconsumed, unexpired code for a user.
            $table->index(['user_id', 'purpose', 'consumed_at'], 'otc_user_purpose_consumed_index');
            // Housekeeping of expired rows.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_one_time_codes');
    }
};
