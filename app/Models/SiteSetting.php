<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $table = 'site_settings';
    
    protected $fillable = [
        'key', 'value', 'type', 'category', 'description', 'order', 'is_public'
    ];
    
    protected $casts = [
        'is_public' => 'boolean',
        'order' => 'integer'
    ];
    
    /**
     * Boot method - clear cache when settings change
     */
    protected static function boot()
    {
        parent::boot();
        
        static::saved(function () {
            Cache::forget('site_settings_all');
            Cache::forget('site_settings');
        });
        
        static::deleted(function () {
            Cache::forget('site_settings_all');
            Cache::forget('site_settings');
        });
    }
    
    /**
     * Get setting value by key
     */
    public static function getValue($key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }
    
    /**
     * Set setting value
     */
    public static function setValue($key, $value, $type = 'text', $category = 'general', $description = null)
    {
        return self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $type,
                'category' => $category,
                'description' => $description
            ]
        );
    }
    
    /**
     * Get all settings by category
     */
    public static function getByCategory($category)
    {
        return self::where('category', $category)
                   ->orderBy('order')
                   ->get()
                   ->keyBy('key');
    }
    
    /**
     * Get public settings
     */
    public static function getPublicSettings()
    {
        return self::where('is_public', true)
                   ->pluck('value', 'key')
                   ->toArray();
    }
    
    /**
     * Clear settings cache
     */
    public static function clearCache()
    {
        Cache::forget('site_settings_all');
        Cache::forget('site_settings');
    }
}