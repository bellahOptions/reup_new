<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the user columns the application already assumes exist.
 *
 * These were referenced throughout the codebase but never migrated, which is
 * why so much of the product silently misbehaved:
 *
 *   - `status`                — read and written by suspend/activate, filter
 *                               by "suspended", and shown on the profile;
 *   - `last_activity`         — written by TrackUserActivity middleware and
 *                               read by presence scopes and "online now"
 *                               counts on the admin dashboard;
 *   - `last_activity_at`      — written by AdminMiddleware;
 *   - `requires_phone_update` — read by the layout to show the phone nudge;
 *   - `is_blocked`            — written by the fraud flow.
 *
 * `wallet_balance` already exists on `users` but the model never wrote to it
 * (it is not fillable), so it drifted. It is retained as a deprecated mirror
 * and backfilled once here; `User::wallet_balance` now resolves from the
 * wallets table, which is the single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('active')->after('profile_completed');
                $table->index('status');
            }

            if (! Schema::hasColumn('users', 'last_activity')) {
                $table->timestamp('last_activity')->nullable()->after('last_login_ip');
                $table->index('last_activity');
            }

            if (! Schema::hasColumn('users', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable()->after('last_activity');
            }

            if (! Schema::hasColumn('users', 'requires_phone_update')) {
                $table->boolean('requires_phone_update')->default(false)->after('status');
            }

            if (! Schema::hasColumn('users', 'is_blocked')) {
                $table->boolean('is_blocked')->default(false)->after('requires_phone_update');
            }
        });

        // Align the legacy mirror with the authoritative wallets table.
        if (Schema::hasColumn('users', 'wallet_balance') && Schema::hasTable('wallets')) {
            DB::statement('
                UPDATE users u
                LEFT JOIN wallets w ON w.user_id = u.id
                SET u.wallet_balance = COALESCE(w.balance, 0)
            ');
        }

        // Everyone who has never been explicitly suspended is active.
        DB::table('users')->whereNull('status')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['is_blocked', 'requires_phone_update', 'last_activity_at', 'last_activity', 'status'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
