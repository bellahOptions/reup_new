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

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!Auth::check()) {
                abort(403, 'You must be logged in to access this page.');
            }
            
            if (!Auth::user()->is_super_admin) {
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only Super Admins can access settings.',
                        'redirect' => url('/admin/dashboard')
                    ], 403);
                }
                
                return redirect('/admin/dashboard')
                    ->with('error', 'Only Super Admins can access settings.');
            }
            
            return $next($request);
        });
    }
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

public function check()
{
    $exists = SiteSetting::exists();
    
    return response()->json([
        'exists' => $exists,
        'count' => SiteSetting::count()
    ]);
}

/**
 * Check if settings exist (detailed)
 */
public function checkExists()
{
    $exists = SiteSetting::exists();
    
    return response()->json([
        'success' => true,
        'exists' => $exists,
        'count' => SiteSetting::count(),
        'categories' => SiteSetting::select('category')->distinct()->pluck('category')
    ]);
}

/**
 * Seed default settings
 */
public function seedDefaults()
{
    try {
        // Your seeding logic here
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
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to seed defaults: ' . $e->getMessage()
        ], 500);
    }
}

// In your SettingsController
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
 * Get maintenance status
 */
public function getMaintenanceStatus()
{
    try {
        $status = app()->isDownForMaintenance();
        $secret = null;
        
        if ($status) {
            $path = storage_path('framework/down');
            if (file_exists($path)) {
                $data = json_decode(file_get_contents($path), true);
                $secret = $data['secret'] ?? null;
            }
        }
        
        return response()->json([
            'enabled' => $status,
            'secret' => $secret
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'enabled' => false,
            'error' => $e->getMessage()
        ]);
    }
}
    
    /**
     * Update settings
     */
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'settings' => 'required|array',
                'settings.*.key' => 'required|string',
                'settings.*.value' => 'nullable',
                'settings.*.type' => 'required|in:text,boolean,number,json,textarea',
                'settings.*.category' => 'required|string'
            ]);
            
            $updatedCount = 0;
            
            foreach ($validated['settings'] as $setting) {
                // Convert boolean values
                if ($setting['type'] === 'boolean') {
                    $setting['value'] = filter_var($setting['value'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                }
                
                // Handle maintenance mode
                if ($setting['key'] === 'maintenance_mode') {
                    $this->handleMaintenanceMode($setting['value']);
                }
                
                SiteSetting::setValue(
                    $setting['key'],
                    $setting['value'],
                    $setting['type'],
                    $setting['category']
                );
                
                $updatedCount++;
            }
            
            // Clear settings cache
            Cache::forget('site_settings');
            
            Log::info('Settings updated', [
                'user_id' => Auth::id(),
                'updated_count' => $updatedCount,
                'settings' => array_keys($validated['settings'])
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
                'updated_count' => $updatedCount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Settings update failed', [
                'error' => $e->getMessage(),
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
        if ($enabled) {
            Artisan::call('down', [
                '--secret' => 'reup-' . time(),
                '--render' => 'errors::maintenance',
                '--retry' => '60'
            ]);
        } else {
            Artisan::call('up');
        }
        
        Log::info('Maintenance mode ' . ($enabled ? 'enabled' : 'disabled'), [
            'user_id' => Auth::id()
        ]);
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
}