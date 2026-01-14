<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PromotionNotification;
use Carbon\Carbon;

class PromotionNotificationSeeder extends Seeder
{
    public function run(): void
    {
        PromotionNotification::insert([
    [
        'type' => 'promotion',
        'title' => 'MTN Promo',
        'content' => 'MTN 1GB for ₦250 – Limited Time!',
        'badge' => 'NEW',
        'badge_color' => 'bg-purple-100 text-purple-800',
        'text_color' => 'text-purple-800',
        'icon' => '🎉',
        'is_active' => true,
    ],
    [
        'type' => 'promotion',
        'title' => 'Airtel Deal',
        'content' => 'Airtel 2GB for ₦500 – Today Only!',
        'badge' => 'HOT',
        'badge_color' => 'bg-pink-100 text-pink-800',
        'text_color' => 'text-pink-800',
        'icon' => '🔥',
        'is_active' => true,
    ],
    [
        'type' => 'notification',
        'title' => 'System Maintenance',
        'content' => 'Wallet funding may be slow between 12am–1am.',
        'badge' => null,
        'badge_color' => null,
        'text_color' => null,
        'icon' => '⚠️',
        'is_active' => true,
    ],
    [
        'type' => 'news',
        'title' => 'New Feature',
        'content' => 'We have added SME data plans for all networks.',
        'badge' => null,
        'badge_color' => null,
        'text_color' => null,
        'icon' => '📰',
        'is_active' => true,
    ],
]);

    }
}
