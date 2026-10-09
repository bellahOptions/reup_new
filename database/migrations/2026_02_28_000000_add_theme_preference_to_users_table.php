<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appearance preference.
 *
 * NULL means "no explicit choice", which resolves to config('theme.default_mode')
 * — `system`, i.e. follow the device. That is deliberate rather than a `default
 * ('system')` on the column: this application also creates accounts through
 * seeders, admin forms and imports, and a NULL column is the one representation
 * of "unset" that cannot be contradicted by a code path that forgets to set it.
 *
 * The column is intentionally *not* given a database default, because MySQL
 * refuses a default on older TEXT/BLOB types and this table has a long history
 * of being migrated on shared hosting. The application supplies the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'theme_preference')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Key into config('theme.modes'): 'system' | 'light' | 'dark'.
            $table->string('theme_preference', 16)->nullable()->after('avatar_icon');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'theme_preference')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme_preference');
        });
    }
};
