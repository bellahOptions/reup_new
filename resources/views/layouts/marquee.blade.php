@php
    // Get announcements or use defaults
    $announcements = $announcements ?? [
        [
            'badge' => 'NEW',
            'badgeColor' => 'bg-purple-100 text-purple-800',
            'text' => 'MTN 1GB for ₦250 - Limited Time!',
            'textColor' => 'text-purple-800',
        ],
        [
            'badge' => 'HOT',
            'badgeColor' => 'bg-pink-100 text-pink-800',
            'text' => 'Airtel 2GB for ₦500 - Today Only!',
            'textColor' => 'text-pink-800',
        ],
        [
            'badge' => 'DEAL',
            'badgeColor' => 'bg-indigo-100 text-indigo-800',
            'text' => 'Glo 4.5GB for ₦1000 - Special Offer!',
            'textColor' => 'text-indigo-800',
        ],
    ];
    
    // Get other options or use defaults
    $speed = $speed ?? 30;
    $bgGradient = $bgGradient ?? 'from-purple-50 to-pink-50';
    $borderColor = $borderColor ?? 'border-purple-200';
    $pauseOnHover = $pauseOnHover ?? true;
@endphp

<div class="marquee-container {{ $pauseOnHover ? 'pause-on-hover' : '' }}">
    <div class="rounded-lg overflow-hidden">
        <div class="flex overflow-hidden">
            <div class="flex items-center space-x-8 animate-marquee whitespace-nowrap py-3 marquee-content"
                 style="--marquee-speed: {{ $speed }}s;">
                
                @foreach(array_merge($announcements, $announcements) as $announcement)
                    <div class="flex items-center space-x-3 flex-shrink-0">
                        <span class="{{ $announcement['badgeColor'] }} text-xs font-semibold px-2.5 py-0.5 rounded">
                            {{ $announcement['badge'] }}
                        </span>
                        <span class="{{ $announcement['textColor'] ?? 'text-gray-800' }}">
                            {{ $announcement['text'] }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes marquee {
        0% { transform: translateX(0%); }
        100% { transform: translateX(-50%); }
    }
    
    .animate-marquee {
        animation: marquee var(--marquee-speed, 30s) linear infinite;
        will-change: transform;
    }
    
    .pause-on-hover:hover .marquee-content {
        animation-play-state: paused;
    }
</style>