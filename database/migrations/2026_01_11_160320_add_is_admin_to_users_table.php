<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsAdminToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
            $table->boolean('is_super_admin')->default(false)->after('is_admin');
            $table->string('admin_role')->nullable()->after('is_super_admin'); // admin, moderator, support
            $table->json('admin_permissions')->nullable()->after('admin_role');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->ipAddress('last_login_ip')->nullable()->after('last_login_at');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_admin', 
                'is_super_admin', 
                'admin_role', 
                'admin_permissions',
                'last_login_at',
                'last_login_ip'
            ]);
        });
    }
}