<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        
        $notificationSettings = [
            [
                'key' => 'failed_login_alert',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'notifications',
                'description' => 'Send alert on failed login attempts',
                'order' => 5,
                'is_public' => false
            ],
            [
                'key' => 'new_user_registration_alert',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'notifications',
                'description' => 'Send alert when new user registers',
                'order' => 6,
                'is_public' => false
            ],
            [
                'key' => 'high_value_transaction_alert',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'notifications',
                'description' => 'Send alert for high-value transactions',
                'order' => 7,
                'is_public' => false
            ],
            [
                'key' => 'system_error_alert',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'notifications',
                'description' => 'Send alert for system errors',
                'order' => 8,
                'is_public' => false
            ]
        ];

        // Add missing security settings
        $securitySettings = [
            [
                'key' => 'security_updates',
                'value' => '',
                'type' => 'richtext',
                'category' => 'security',
                'description' => 'Security updates and announcements',
                'order' => 5,
                'is_public' => false
            ],
            [
                'key' => 'ip_whitelist',
                'value' => '',
                'type' => 'textarea',
                'category' => 'security',
                'description' => 'IP addresses allowed for admin access (one per line)',
                'order' => 6,
                'is_public' => false
            ],
            [
                'key' => 'password_complexity',
                'value' => 'medium',
                'type' => 'select',
                'category' => 'security',
                'description' => 'Password complexity requirement',
                'order' => 7,
                'is_public' => false
            ],
            [
                'key' => 'auto_logout',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'security',
                'description' => 'Enable automatic logout after inactivity',
                'order' => 8,
                'is_public' => false
            ]
        ];

        // Insert settings
        foreach ($notificationSettings as $setting) {
            \App\Models\SiteSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }

        foreach ($securitySettings as $setting) {
            \App\Models\SiteSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }

    public function down()
    {
        // Remove the added settings if needed
        \App\Models\SiteSetting::whereIn('key', [
            'failed_login_alert',
            'new_user_registration_alert',
            'high_value_transaction_alert',
            'system_error_alert',
            'security_updates',
            'ip_whitelist',
            'password_complexity',
            'auto_logout'
        ])->delete();
    }
};