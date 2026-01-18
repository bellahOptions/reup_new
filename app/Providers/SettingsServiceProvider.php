<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;

class SettingsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Share public settings with all views
        View::composer('*', function ($view) {
            $settings = Cache::remember('site_settings.public', 3600, function () {
                return SiteSetting::getPublicSettings();
            });
            
            $view->with('siteSettings', $settings);
        });
        
        // Bind settings to config
        $this->app->singleton('site.settings', function () {
            return Cache::remember('site_settings.all', 3600, function () {
                return SiteSetting::all()->pluck('value', 'key')->toArray();
            });
        });
    }
    
    public function register()
    {
        // Register settings helper
        $this->app->bind('settings', function () {
            return new class {
                public function get($key, $default = null) {
                    return SiteSetting::getValue($key, $default);
                }
                
                public function set($key, $value, $type = 'text', $category = 'general') {
                    return SiteSetting::setValue($key, $value, $type, $category);
                }
            };
        });
    }
}