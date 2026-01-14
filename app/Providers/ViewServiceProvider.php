<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Models\PromotionNotification;
use Carbon\Carbon;

class ViewServiceProvider extends ServiceProvider
{
    public function boot()
    {
        View::composer('*', function ($view) {
            $now = Carbon::now();

            // Promotions for marquee
            $promotions = PromotionNotification::promotions()
                ->where(function ($q) use ($now) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                })
                ->get()
                ->map(function ($item) {
                    return [
                        'badge' => strtoupper($item->badge ?? 'HOT'),
                        'badgeColor' => 'bg-purple-100 text-purple-800',
                        'text' => $item->content,
                        'textColor' => 'text-purple-800',
                        'icon' => $item->icon ?? '🔥',
                    ];
                });

            // Announcements for modal (auth users only, excluding admins)
            $announcements = auth()->check() && !auth()->user()->is_admin
                ? PromotionNotification::announcements()->get()
                : collect();

            $view->with([
                'globalPromotions' => $promotions,
                'globalAnnouncements' => $announcements,
            ]);
        });
    }
}
