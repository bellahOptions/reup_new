<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Affiliate / referral programme.
 *
 * Two pieces:
 *
 *   users.referral_code            — the code a user shares
 *   users.referred_by_user_id      — who invited them (set once, at sign-up)
 *   referrals                      — one row per successful payout, for audit
 *
 * ## Why a separate table as well as columns on users
 *
 * The columns answer "who invited whom" cheaply, which the dashboard needs on
 * every page load. The table records each *payout*: amount, when, which
 * transaction triggered it. That is what makes the reward idempotent — the
 * unique index on `referrals.transaction_id` is a database-level guarantee that
 * one funding event can never pay out twice, even if two webhook deliveries race
 * each other. Relying on application logic alone for money is how double credits
 * happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 16)->nullable()->after('avatar_icon');
            }

            if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->unsignedBigInteger('referred_by_user_id')->nullable()->after('referral_code');
                $table->index('referred_by_user_id');
            }
        });

        // Backfill a code for every existing user, then make it unique. Unique
        // after the backfill because the index cannot be added while the column
        // is entirely NULL-distinct-safe but the values would collide.
        DB::table('users')->whereNull('referral_code')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                do {
                    $code = strtoupper(Str::random(8));
                } while (DB::table('users')->where('referral_code', $code)->exists());

                DB::table('users')->where('id', $row->id)->update(['referral_code' => $code]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('referral_code');
        });

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();

                // The referrer: the person who is paid.
                $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
                // The new user whose funding triggered the reward.
                $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
                // The funding transaction that qualified.
                $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();

                $table->decimal('reward_amount', 12, 2);
                // What the referred user had to fund to qualify, captured at the
                // time so a later change to the threshold does not rewrite history.
                $table->decimal('qualifying_amount', 12, 2);

                // Kept for the audit trail; the actual credit is a normal
                // wallet transaction, which is the single source of money truth.
                $table->string('reward_reference', 64);
                $table->timestamp('paid_at')->nullable();

                $table->timestamps();

                /*
                 * The anti-double-payout guarantee. One funding event, one
                 * reward. Without this, two concurrent webhook deliveries both
                 * read "no referral yet" and both credit ₦200.
                 */
                $table->unique('transaction_id');

                // A user may only ever be referred once, so their rewards cannot
                // be farmed by re-signing-up against the same funder.
                $table->unique('referred_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropIndex(['referred_by_user_id']);
            $table->dropColumn(['referral_code', 'referred_by_user_id']);
        });
    }
};
