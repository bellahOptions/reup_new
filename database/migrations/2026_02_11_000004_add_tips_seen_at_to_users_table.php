<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First sign-in tips.
 *
 * A timestamp rather than a boolean: it records *when* the introduction was
 * seen, which is what makes it possible to show it again if the tips content
 * changes, and to tell "finished it" apart from "dismissed it immediately".
 *
 * Nullable with no default means existing accounts are treated as never having
 * seen the tips. That is deliberate but worth knowing: every current user will
 * see the introduction once on their next sign-in. Set the column for existing
 * rows during a deploy if that is not wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'tips_seen_at')) {
                $table->timestamp('tips_seen_at')->nullable()->after('profile_completed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'tips_seen_at')) {
                $table->dropColumn('tips_seen_at');
            }
        });
    }
};
