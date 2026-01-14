<?php

namespace App\View\Components;

use Illuminate\View\Component;
use App\Models\PromotionNotification;

class Marquee extends Component
{
    public $type;
    public $items;
    public $speed;
    public $direction;
    public $pauseOnHover;
    public $containerClass;
    public $innerClass;
    public $itemClass;
    public $bgGradient;
    public $borderColor;

    public function __construct(
        $type = 'badge',
        $items = null,
        $speed = 30,
        $direction = 'left',
        $pauseOnHover = true,
        $containerClass = '',
        $innerClass = '',
        $itemClass = '',
        $bgGradient = 'from-purple-50 to-pink-50',
        $borderColor = 'border-purple-200'
    ) {
        $this->type = $type;
        $this->speed = $speed;
        $this->direction = $direction;
        $this->pauseOnHover = $pauseOnHover;
        $this->containerClass = $containerClass;
        $this->innerClass = $innerClass;
        $this->itemClass = $itemClass;
        $this->bgGradient = $bgGradient;
        $this->borderColor = $borderColor;

        $this->items = $items ?? $this->getDatabaseItems();
        if (empty($this->items)) {
            $this->items = $this->defaultItems();
        }
    }

    protected function getDatabaseItems(): array
    {
        $items = PromotionNotification::where('is_active', true)
                    ->where('type', 'promotion')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'badge' => $item->badge ?? null,
                            'badgeColor' => $item->badge_color ?? 'bg-gray-100 text-gray-800',
                            'text' => $item->content ?? '',
                            'textColor' => $item->text_color ?? 'text-gray-800',
                            'icon' => $item->icon ?? null,
                            'title' => $item->title ?? null,
                        ];
                    });

        return $items->toArray();
    }

    protected function defaultItems(): array
    {
        return [
            [
                'badge' => 'NEW',
                'badgeColor' => 'bg-purple-100 text-purple-800',
                'text' => 'MTN 1GB for ₦250 - Limited Time!',
                'textColor' => 'text-purple-800',
                'icon' => '🎉',
            ],
            [
                'badge' => 'HOT',
                'badgeColor' => 'bg-pink-100 text-pink-800',
                'text' => 'Airtel 2GB for ₦500 - Today Only!',
                'textColor' => 'text-pink-800',
                'icon' => '🔥',
            ],
            [
                'badge' => 'DEAL',
                'badgeColor' => 'bg-indigo-100 text-indigo-800',
                'text' => 'Glo 4.5GB for ₦1000 - Special Offer!',
                'textColor' => 'text-indigo-800',
                'icon' => '💎',
            ],
        ];
    }

    public function render()
    {
        return view('components.marquee');
    }
}
