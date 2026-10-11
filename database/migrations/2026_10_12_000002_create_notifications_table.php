<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database-notification table.
 *
 * This is the exact table `php artisan notifications:table` generates, written
 * out rather than generated so the reason it exists is recorded. `User` already
 * uses the `Notifiable` trait, whose `notifications()` relation reads and writes
 * here — `User::markTermsNotificationSent()` has been calling
 * `$this->notifications()->create()` on a table that did not exist, so that
 * method threw `Base table or view not found` on every call and nothing
 * surfaced it because no caller was tested.
 *
 * The funding receipt is the first notification to use the `database` channel as
 * well as `mail`. Mail is the receipt; the row is the record — it is what makes
 * "was this customer told?" answerable from the database after the mail has left
 * the building, which is the question support actually gets asked.
 *
 * `notifiable` is a morph (id + type) with an index, and `read_at` is nullable so
 * "unread" is a usable state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
