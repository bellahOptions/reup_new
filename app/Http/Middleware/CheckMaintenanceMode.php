<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Clear cache and check database directly
        Cache::forget('site_settings_all');
        
        try {
            // Check database directly - bypass all caching
            $maintenanceSetting = DB::table('site_settings')
                ->where('key', 'maintenance_mode')
                ->first();
            
            $maintenanceMode = $maintenanceSetting ? $maintenanceSetting->value : '0';
            
            \Log::info('Maintenance Check (Direct DB)', [
                'raw_value' => $maintenanceMode,
                'setting_exists' => $maintenanceSetting !== null,
                'user_id' => $request->user()?->id,
                'is_admin' => $request->user()?->is_admin ?? false,
                'is_super_admin' => $request->user()?->is_super_admin ?? false,
                'url' => $request->url(),
                'method' => $request->method()
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Maintenance check failed', ['error' => $e->getMessage()]);
            return $next($request);
        }
        
        // Convert to boolean - handle string "1" or "0"
        $isInMaintenance = ($maintenanceMode === '1' || $maintenanceMode === 1 || $maintenanceMode === true);
        
        // Get user and check admin status
        $user = $request->user();
        $isAdmin = $user && (
            ($user->is_admin ?? false) || 
            ($user->is_super_admin ?? false)
        );
        
        \Log::info('Maintenance Decision', [
            'is_maintenance' => $isInMaintenance,
            'is_admin' => $isAdmin,
            'will_show_maintenance' => $isInMaintenance && !$isAdmin
        ]);
        
        // Show maintenance page if enabled and user is not admin
        if ($isInMaintenance && !$isAdmin) {
            \Log::info('🔧 REDIRECTING TO MAINTENANCE PAGE');
            
            // Get maintenance message and site name
            $messageSetting = DB::table('site_settings')
                ->where('key', 'maintenance_message')
                ->first();
            
            $siteNameSetting = DB::table('site_settings')
                ->where('key', 'site_name')
                ->first();
            
            $contactEmailSetting = DB::table('site_settings')
                ->where('key', 'contact_email')
                ->first();
            
            $contactPhoneSetting = DB::table('site_settings')
                ->where('key', 'contact_phone')
                ->first();
            
            // Store in session to pass to error view
            session([
                'maintenance_message' => $messageSetting ? $messageSetting->value : null,
                'site_name' => $siteNameSetting ? $siteNameSetting->value : 'ReUp Digital Services',
                'contact_email' => $contactEmailSetting ? $contactEmailSetting->value : 'reup.bellahoptions@gmail.com',
                'contact_phone' => $contactPhoneSetting ? $contactPhoneSetting->value : '+234 903 141 2354'
            ]);
            
            // Return 503 error response
            abort(503);
        }
        
        // Continue to next middleware
        return $next($request);
    }
}