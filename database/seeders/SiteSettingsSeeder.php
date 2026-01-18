<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SiteSetting;

class SiteSettingsSeeder extends Seeder
{
    public function run()
    {
        $settings = [
    // General Settings
    [
        'key' => 'site_name',
        'value' => 'ReUp Digital Services',
        'type' => 'text',
        'category' => 'general',
        'description' => 'The name of your platform',
        'order' => 1,
        'is_public' => true
    ],
    [
        'key' => 'site_tagline',
        'value' => 'Instant Airtime & Data Services',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Brief description of your platform',
        'order' => 2,
        'is_public' => true
    ],
    [
        'key' => 'site_url',
        'value' => config('app.url'),
        'type' => 'text',
        'category' => 'general',
        'description' => 'Your website URL',
        'order' => 3,
        'is_public' => true
    ],
    [
        'key' => 'site_logo',
        'value' => '/images/logo.png',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Path to your logo image',
        'order' => 4,
        'is_public' => true
    ],
    [
        'key' => 'site_favicon',
        'value' => '/images/favicon.ico',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Path to your favicon',
        'order' => 5,
        'is_public' => true
    ],
    [
        'key' => 'default_currency',
        'value' => 'NGN',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Default currency for transactions',
        'order' => 6,
        'is_public' => true
    ],
    [
        'key' => 'timezone',
        'value' => 'Africa/Lagos',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Default timezone',
        'order' => 7,
        'is_public' => false
    ],

    // Maintenance Mode
    [
        'key' => 'maintenance_mode',
        'value' => '0',
        'type' => 'boolean',
        'category' => 'maintenance',
        'description' => 'Enable maintenance mode',
        'order' => 1,
        'is_public' => false
    ],
    [
        'key' => 'maintenance_message',
        'value' => 'We are currently performing maintenance. Please check back soon.',
        'type' => 'textarea',
        'category' => 'maintenance',
        'description' => 'Message to display during maintenance',
        'order' => 2,
        'is_public' => false
    ],

    // Contact Information
    [
        'key' => 'contact_email',
        'value' => 'reup.bellahoptions@gmail.com',
        'type' => 'text',
        'category' => 'contact',
        'description' => 'Primary contact email',
        'order' => 1,
        'is_public' => true
    ],
    [
        'key' => 'support_email',
        'value' => 'support@reup.com',
        'type' => 'text',
        'category' => 'contact',
        'description' => 'Support email address',
        'order' => 2,
        'is_public' => true
    ],
    [
        'key' => 'contact_phone',
        'value' => '+234 907 601 7916',
        'type' => 'text',
        'category' => 'contact',
        'description' => 'Contact phone number',
        'order' => 3,
        'is_public' => true
    ],
    [
        'key' => 'contact_address',
        'value' => 'Lagos, Nigeria',
        'type' => 'textarea',
        'category' => 'contact',
        'description' => 'Physical address',
        'order' => 4,
        'is_public' => true
    ],
    [
        'key' => 'business_hours',
        'value' => 'Mon - Fri: 9:00 AM - 6:00 PM',
        'type' => 'text',
        'category' => 'contact',
        'description' => 'Business operating hours',
        'order' => 5,
        'is_public' => true
    ],

    // SEO & Meta
    [
        'key' => 'meta_title',
        'value' => 'ReUp - Instant Airtime & Data Purchase Platform',
        'type' => 'text',
        'category' => 'seo',
        'description' => 'Default meta title',
        'order' => 1,
        'is_public' => true
    ],
    [
        'key' => 'meta_description',
        'value' => 'Instant airtime recharge and data bundle purchase platform for MTN, Airtel, Glo, and 9Mobile networks',
        'type' => 'textarea',
        'category' => 'seo',
        'description' => 'Default meta description',
        'order' => 2,
        'is_public' => true
    ],
    [
        'key' => 'meta_keywords',
        'value' => 'airtime, data, recharge, mobile, MTN, Airtel, Glo, 9Mobile, Nigeria',
        'type' => 'text',
        'category' => 'seo',
        'description' => 'Meta keywords',
        'order' => 3,
        'is_public' => true
    ],
    [
        'key' => 'google_analytics_id',
        'value' => '',
        'type' => 'text',
        'category' => 'seo',
        'description' => 'Google Analytics Tracking ID',
        'order' => 4,
        'is_public' => false
    ],
    [
        'key' => 'google_site_verification',
        'value' => '',
        'type' => 'text',
        'category' => 'seo',
        'description' => 'Google Search Console verification code',
        'order' => 5,
        'is_public' => false
    ],

    // Social Media
    [
        'key' => 'social_facebook',
        'value' => '',
        'type' => 'text',
        'category' => 'social',
        'description' => 'Facebook page URL',
        'order' => 1,
        'is_public' => true
    ],
    [
        'key' => 'social_twitter',
        'value' => '',
        'type' => 'text',
        'category' => 'social',
        'description' => 'Twitter profile URL',
        'order' => 2,
        'is_public' => true
    ],
    [
        'key' => 'social_instagram',
        'value' => '',
        'type' => 'text',
        'category' => 'social',
        'description' => 'Instagram profile URL',
        'order' => 3,
        'is_public' => true
    ],
    [
        'key' => 'social_linkedin',
        'value' => '',
        'type' => 'text',
        'category' => 'social',
        'description' => 'LinkedIn company page URL',
        'order' => 4,
        'is_public' => true
    ],
    [
        'key' => 'social_whatsapp',
        'value' => '',
        'type' => 'text',
        'category' => 'social',
        'description' => 'WhatsApp contact number',
        'order' => 5,
        'is_public' => true
    ],

    // Payment Settings
    [
        'key' => 'payment_currency',
        'value' => 'NGN',
        'type' => 'text',
        'category' => 'payment',
        'description' => 'Payment currency',
        'order' => 1,
        'is_public' => false
    ],
    [
        'key' => 'airtime_service_fee',
        'value' => '2',
        'type' => 'number',
        'category' => 'payment',
        'description' => 'Airtime service fee percentage',
        'order' => 2,
        'is_public' => false
    ],
    [
        'key' => 'data_service_fee',
        'value' => '1.5',
        'type' => 'number',
        'category' => 'payment',
        'description' => 'Data service fee percentage',
        'order' => 3,
        'is_public' => false
    ],
    [
        'key' => 'min_wallet_funding',
        'value' => '500',
        'type' => 'number',
        'category' => 'payment',
        'description' => 'Minimum wallet funding amount',
        'order' => 4,
        'is_public' => true
    ],
    [
        'key' => 'max_wallet_funding',
        'value' => '100000',
        'type' => 'number',
        'category' => 'payment',
        'description' => 'Maximum wallet funding amount',
        'order' => 5,
        'is_public' => false
    ],

    // Notifications
    [
        'key' => 'email_notifications',
        'value' => '1',
        'type' => 'boolean',
        'category' => 'notifications',
        'description' => 'Enable email notifications',
        'order' => 1,
        'is_public' => false
    ],
    [
        'key' => 'sms_notifications',
        'value' => '0',
        'type' => 'boolean',
        'category' => 'notifications',
        'description' => 'Enable SMS notifications',
        'order' => 2,
        'is_public' => false
    ],
    [
        'key' => 'transaction_alerts',
        'value' => '1',
        'type' => 'boolean',
        'category' => 'notifications',
        'description' => 'Send transaction alerts',
        'order' => 3,
        'is_public' => false
    ],
    [
        'key' => 'promotional_emails',
        'value' => '1',
        'type' => 'boolean',
        'category' => 'notifications',
        'description' => 'Send promotional emails',
        'order' => 4,
        'is_public' => false
    ],

    // Security
    [
        'key' => 'enable_2fa',
        'value' => '0',
        'type' => 'boolean',
        'category' => 'security',
        'description' => 'Enable Two-Factor Authentication',
        'order' => 1,
        'is_public' => false
    ],
    [
        'key' => 'session_timeout',
        'value' => '60',
        'type' => 'number',
        'category' => 'security',
        'description' => 'Session timeout in minutes',
        'order' => 2,
        'is_public' => false
    ],
    [
        'key' => 'max_login_attempts',
        'value' => '5',
        'type' => 'number',
        'category' => 'security',
        'description' => 'Maximum login attempts before lockout',
        'order' => 3,
        'is_public' => false
    ],
    [
        'key' => 'password_expiry_days',
        'value' => '90',
        'type' => 'number',
        'category' => 'security',
        'description' => 'Password expiry in days',
        'order' => 4,
        'is_public' => false
    ]
];

        foreach ($settings as $setting) {
            SiteSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }

        $this->command->info('Site settings seeded successfully.');
    }
}