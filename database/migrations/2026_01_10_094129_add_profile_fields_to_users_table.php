<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('whatsapp')->nullable()->after('phone');
            $table->date('birthday')->nullable()->after('whatsapp');
            $table->string('gender')->nullable()->after('birthday');
            $table->string('profile_picture')->nullable()->after('gender');
            $table->text('address')->nullable()->after('profile_picture');
            $table->string('state')->nullable()->after('address');
            $table->string('city')->nullable()->after('state');
            $table->boolean('profile_completed')->default(false)->after('city');
            $table->boolean('phone_verified')->default(false)->after('profile_completed');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_verified');
            $table->json('notification_preferences')->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 
                'whatsapp', 
                'birthday', 
                'gender', 
                'profile_picture', 
                'address', 
                'state', 
                'city', 
                'profile_completed',
                'phone_verified',
                'phone_verified_at',
                'notification_preferences'
            ]);
        });
    }
};