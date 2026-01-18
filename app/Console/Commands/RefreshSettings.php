<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use Illuminate\Console\Command;

class RefreshSettings extends Command
{
    protected $signature = 'settings:refresh';
    protected $description = 'Clear and refresh site settings cache';

    public function handle()
    {
        SiteSetting::clearCache();
        $this->info('Settings cache cleared successfully!');
        
        // Warm up cache
        app('site.settings');
        $this->info('Settings cache warmed up!');
        
        return 0;
    }
}