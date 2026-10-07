<?php

namespace Database\Seeders;

use App\Models\PromotionNotification;
use Illuminate\Database\Seeder;

/**
 * Example announcements.
 *
 * Two corrections over the previous version:
 *
 *  1. `icon` holds an icon-component name (`sparkles`), not an emoji. The old
 *     emoji values ('🎉', '🔥', '⚠️', '📰') could only be rendered by the one view
 *     that carried a hand-written emoji→icon map; everywhere else they either
 *     printed as a literal emoji or were dropped.
 *
 *  2. `badge_color` / `text_color` hold hex (`#0b891a`), not Tailwind class
 *     strings. The old values ('bg-purple-100 text-purple-800') were written
 *     straight into a `style` attribute by the admin preview, producing
 *     `background-color: bg-purple-100 text-purple-800` — invalid CSS that the
 *     browser discards silently.
 *
 * `insert()` is used rather than `create()` because these are static rows, but
 * note it bypasses model casts and events: the values here must be exactly what
 * the columns should contain.
 */
class PromotionNotificationSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'type' => 'promotion',
                'title' => 'MTN Promo',
                'content' => 'MTN 1GB for ₦250 – Limited Time!',
                'badge' => 'NEW',
                'badge_color' => '#7e22ce',
                'text_color' => '#581c87',
                'icon' => 'sparkles',
                'is_active' => true,
            ],
            [
                'type' => 'promotion',
                'title' => 'Airtel Deal',
                'content' => 'Airtel 2GB for ₦500 – Today Only!',
                'badge' => 'HOT',
                'badge_color' => '#be185d',
                'text_color' => '#831843',
                'icon' => 'fire',
                'is_active' => true,
            ],
            [
                'type' => 'notification',
                'title' => 'System Maintenance',
                'content' => 'Wallet funding may be slow between 12am–1am.',
                'badge' => null,
                'badge_color' => '#b45309',
                'text_color' => '#78350f',
                'icon' => 'exclamation-triangle',
                'is_active' => true,
            ],
            [
                'type' => 'news',
                'title' => 'New Feature',
                'content' => 'We have added SME data plans for all networks.',
                'badge' => null,
                'badge_color' => null,
                'text_color' => null,
                'icon' => 'document-text',
                'is_active' => true,
            ],
        ];

        foreach ($rows as $row) {
            // Keyed on title so re-seeding updates rather than duplicating.
            PromotionNotification::updateOrCreate(
                ['title' => $row['title'], 'type' => $row['type']],
                $row
            );
        }
    }
}
