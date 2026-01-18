<?php

use App\Models\SiteSetting;

if (!function_exists('setting')) {
    /**
     * Get setting value
     * 
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    function setting(?string $key = null, $default = null)
    {
        if ($key === null) {
            return app('site.settings');
        }
        
        $settings = app('site.settings');
        return $settings[$key] ?? $default;
    }
}

if (!function_exists('settings')) {
    /**
     * Get all settings or by category
     * 
     * @param string|null $category
     * @return array
     */
    function settings(?string $category = null): array
    {
        $allSettings = app('site.settings');
        
        if ($category === null) {
            return $allSettings;
        }
        
        return collect($allSettings)
            ->filter(function ($value, $key) use ($category) {
                $setting = SiteSetting::where('key', $key)->first();
                return $setting && $setting->category === $category;
            })
            ->toArray();
    }
}