<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Display settings page
     */
    public function index(Request $request)
{
    $categories = [
        'general' => 'General Settings',
        'maintenance' => 'Maintenance Mode',
        'contact' => 'Contact Information',
        'seo' => 'SEO & Meta',
        'social' => 'Social Media',
        'payment' => 'Payment Settings',
        'notifications' => 'Notifications',
        'security' => 'Security'
    ];
    
    // Check if it's an AJAX request for data
    if ($request->ajax() || $request->wantsJson() || $request->is('admin/settings/data')) {
        $settings = [];
        
        foreach ($categories as $key => $name) {
            $categorySettings = SiteSetting::getByCategory($key);
            
            // Convert collection to array with proper structure
            $settings[$key] = [];
            foreach ($categorySettings as $settingKey => $setting) {
                $settings[$key][$settingKey] = [
                    'value' => $setting->value,
                    'type' => $setting->type,
                    'description' => $setting->description
                ];
            }
        }
        
        return response()->json([
            'success' => true,
            'settings' => $settings
        ]);
    }
    
    // Regular request - return view
    return view('admin.settings.index', compact('categories'));
}
    
    /**
     * Update settings
     */
  public function update(Request $request)
{
    try {
        \Log::info('Settings update request received', [
            'user_id' => Auth::id(),
            'data' => $request->all()
        ]);
        
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable',
            'settings.*.type' => 'required|in:text,boolean,number,json,textarea,select',
            'settings.*.category' => 'required|string',
            'settings.*.description' => 'nullable|string'
        ]);
        
        $updatedCount = 0;
        
        foreach ($validated['settings'] as $setting) {
            // Convert boolean values properly
            if ($setting['type'] === 'boolean') {
                // Handle various boolean representations
                $value = $setting['value'];
                if (is_bool($value)) {
                    $setting['value'] = $value ? '1' : '0';
                } elseif (is_string($value)) {
                    $setting['value'] = in_array(strtolower($value), ['true', '1', 'yes', 'on']) ? '1' : '0';
                } else {
                    $setting['value'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                }
            }
            
            \Log::info('Updating setting', [
                'key' => $setting['key'],
                'value' => $setting['value'],
                'type' => $setting['type']
            ]);
            
            // Update or create setting
            SiteSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'category' => $setting['category'],
                    'description' => $setting['description'] ?? null
                ]
            );
            
            // Handle maintenance mode toggle
            if ($setting['key'] === 'maintenance_mode') {
                $this->handleMaintenanceMode($setting['value'] === '1');
            }
            
            $updatedCount++;
        }
        
        // Clear ALL caches
        Cache::forget('site_settings_all');
        Cache::forget('site_settings');
        \Artisan::call('cache:clear');
        
        \Log::info('Settings updated successfully', [
            'user_id' => Auth::id(),
            'updated_count' => $updatedCount
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'updated_count' => $updatedCount
        ]);
        
    } catch (\Illuminate\Validation\ValidationException $e) {
        \Log::error('Settings validation failed', [
            'errors' => $e->errors(),
            'user_id' => Auth::id()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
        
    } catch (\Exception $e) {
        \Log::error('Settings update failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'user_id' => Auth::id()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to update settings: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Handle maintenance mode toggle
     */
    private function handleMaintenanceMode($enabled)
    {
        Log::info('Maintenance mode ' . ($enabled ? 'enabled' : 'disabled'), [
        'user_id' => Auth::id(),
        'timestamp' => now()
    ]);
    
    Cache::forget('site_settings_all');
    }
    
    /**
     * Get maintenance mode status
     */
    public function getMaintenanceStatus()
    {
        $status = false;
        $secret = null;
        
        try {
            $status = app()->isDownForMaintenance();
            
            if ($status) {
                $path = storage_path('framework/down');
                if (file_exists($path)) {
                    $data = json_decode(file_get_contents($path), true);
                    $secret = $data['secret'] ?? null;
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to get maintenance status', ['error' => $e->getMessage()]);
        }
        
        return response()->json([
            'enabled' => $status,
            'secret' => $secret
        ]);
    }
    
    /**
     * Seed default settings
     */
    public function seedDefaults()
    {
        if (!Auth::user()->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        
        $defaults = $this->getDefaultSettings();
        $seeded = 0;
        
        foreach ($defaults as $setting) {
            if (!SiteSetting::where('key', $setting['key'])->exists()) {
                SiteSetting::create($setting);
                $seeded++;
            }
        }
        
        return response()->json([
            'success' => true,
            'message' => "Seeded $seeded default settings"
        ]);
    }
    
        public function data(Request $request)
    {
        $categories = ['general', 'maintenance', 'contact', 'seo', 'social', 'payment', 'notifications', 'security'];
        $settings = [];
        
        foreach ($categories as $category) {
            $categorySettings = SiteSetting::where('category', $category)
                ->orderBy('order')
                ->get();
            
            $settings[$category] = [];
            foreach ($categorySettings as $setting) {
                $settings[$category][$setting->key] = [
                    'value' => $setting->value,
                    'type' => $setting->type,
                    'description' => $setting->description
                ];
            }
        }
        
        return response()->json([
            'success' => true,
            'settings' => $settings
        ]);
    }

    /**
     * Default settings configuration
     */
    private function getDefaultSettings()
    {
        return [
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
    }

    // In SettingsController
public function generateLlmTxt()
{
    $settings = SiteSetting::getPublicSettings();
    
    $content = "This is a description of " . ($settings['site_name'] ?? 'our platform') . "\n\n";
    $content .= "Purpose: " . ($settings['site_tagline'] ?? 'Digital services platform') . "\n\n";
    $content .= "Contact Information:\n";
    $content .= "- Email: " . ($settings['contact_email'] ?? 'Not specified') . "\n";
    $content .= "- Phone: " . ($settings['contact_phone'] ?? 'Not specified') . "\n";
    $content .= "- Address: " . ($settings['contact_address'] ?? 'Not specified') . "\n\n";
    $content .= "Services: Airtime and data purchase platform for Nigerian networks\n";
    $content .= "Networks: MTN, Airtel, Glo, 9Mobile\n";
    $content .= "Last Updated: " . date('F j, Y');
    
    return response($content)
        ->header('Content-Type', 'text/plain')
        ->header('Content-Disposition', 'inline');
}
public function checkExists()
{
    $exists = SiteSetting::exists();
    
    return response()->json([
        'success' => true,
        'exists' => $exists,
        'count' => SiteSetting::count()
    ]);
}

/**
 * Check if settings exist (simple version)
 */
public function check()
{
    $exists = SiteSetting::exists();
    
    return response()->json([
        'exists' => $exists
    ]);
}
}