<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use App\Models\TermsPrivacy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        view()->composer(['terms-of-service', 'privacy-policy'], function ($view) {
        $terms = TermsPrivacy::where('type', 'terms')
                           ->where('is_active', true)
                           ->first();
        
        $privacy = TermsPrivacy::where('type', 'privacy')
                              ->where('is_active', true)
                              ->first();
        
        $view->with([
            'terms' => $terms,
            'privacy' => $privacy
        ]);
    });
    }
}
