<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('promotion_notifications', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['promotion', 'notification', 'news']);
            $table->string('title');
            $table->text('content');
            $table->string('badge')->nullable();
            $table->string('badge_color')->nullable();
            $table->string('text_color')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_notifications');
    }
};
