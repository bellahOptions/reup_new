<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customisable avatar.
 *
 * Before this, the only way to personalise an account was uploading a photo
 * file through ProfileController — which meant the default experience for every
 * new user was a bare initial in a grey box, and a fair share of uploaded
 * "photos" were unusable crops, screenshots, or nothing at all.
 *
 * An avatar is now a choice of background colour plus an optional glyph, with
 * initials as the zero-configuration default. Nothing is uploaded and nothing
 * is stored on disk, so there is no file to moderate, no storage cost, and no
 * broken-image state. `profile_picture` is kept and still takes precedence when
 * set, so existing uploads keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'avatar_color')) {
                // Key into config('avatars.colors'), not a hex value: the palette
                // can then be re-themed without rewriting every row.
                $table->string('avatar_color', 32)->nullable()->after('profile_picture');
            }

            if (! Schema::hasColumn('users', 'avatar_icon')) {
                // Key into config('avatars.icons'). Null means "show initials".
                $table->string('avatar_icon', 32)->nullable()->after('avatar_color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['avatar_icon', 'avatar_color'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
