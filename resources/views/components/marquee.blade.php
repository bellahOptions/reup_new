@props([
    'type' => 'badge',
    'items' => [],
    'speed' => 35,
    'direction' => 'left',
    'pauseOnHover' => true,
    'containerClass' => '',
    'innerClass' => '',
    'itemClass' => '',
    'textColor' => 'text-gray-700',
    'badgeColor' => 'bg-green-100 text-green-800',
    'compact' => false,
])

@php
    // Use provided items or empty array
    $items = $items ?? [];
    
    // Only show if we have items
    $hasItems = count($items) > 0;
    
    // For compact mode (fits in announcement bar)
    $paddingClass = $compact ? 'px-3' : 'px-6';
    $textSize = $compact ? 'text-xs' : 'text-sm';
@endphp

@if($hasItems)
<div class="marquee-wrapper {{ $containerClass }} {{ $pauseOnHover ? 'pause-on-hover' : '' }} w-full overflow-hidden">
    <div class="relative w-full overflow-hidden">
        <!-- Single marquee container with duplicated content -->
        <div class="flex animate-marquee {{ $innerClass }} whitespace-nowrap"
             style="animation-duration: {{ $speed }}s; animation-direction: {{ $direction === 'right' ? 'reverse' : 'normal' }};">
            
            <!-- First pass of items -->
            @foreach($items as $index => $item)
                <div class="flex items-center flex-shrink-0 {{ $paddingClass }} py-1 {{ $itemClass }}">
                    {{-- Badge --}}
                    @if($type === 'badge' && !empty($item['badge']))
                        <span class="{{ $item['badgeColor'] ?? $badgeColor }} {{ $textSize }} font-semibold px-2 py-0.5 rounded-full mr-2">
                            {{ $item['badge'] }}
                        </span>
                    @endif
                    
                    {{-- Icon --}}
                    @if(!empty($item['icon']))
                        <span class="{{ $textSize }} mr-2">{{ $item['icon'] }}</span>
                    @endif
                    
                    {{-- Title/Text --}}
                    @if(!empty($item['title']) || !empty($item['text']))
                        <span class="{{ $item['textColor'] ?? $textColor }} {{ $textSize }} font-medium">
                            {{ $item['title'] ?? $item['text'] }}
                        </span>
                    @endif
                    
                    {{-- Content --}}
                    @if(!empty($item['content']) && empty($item['title']) && empty($item['text']))
                        <span class="{{ $item['textColor'] ?? $textColor }} {{ $textSize }} font-medium">
                            {{ Str::limit($item['content'], 60) }}
                        </span>
                    @endif
                </div>
                
                {{-- Separator --}}
                @if(!$loop->last)
                    <div class="flex-shrink-0 px-3 py-1">
                        <span class="text-gray-300">•</span>
                    </div>
                @endif
            @endforeach
            
            <!-- Second pass (duplicate for seamless loop) -->
            @foreach($items as $index => $item)
                <div class="flex items-center flex-shrink-0 {{ $paddingClass }} py-1 {{ $itemClass }}">
                    {{-- Badge --}}
                    @if($type === 'badge' && !empty($item['badge']))
                        <span class="{{ $item['badgeColor'] ?? $badgeColor }} {{ $textSize }} font-semibold px-2 py-0.5 rounded-full mr-2">
                            {{ $item['badge'] }}
                        </span>
                    @endif
                    
                    {{-- Icon --}}
                    @if(!empty($item['icon']))
                        <span class="{{ $textSize }} mr-2">{{ $item['icon'] }}</span>
                    @endif
                    
                    {{-- Title/Text --}}
                    @if(!empty($item['title']) || !empty($item['text']))
                        <span class="{{ $item['textColor'] ?? $textColor }} {{ $textSize }} font-medium">
                            {{ $item['title'] ?? $item['text'] }}
                        </span>
                    @endif
                    
                    {{-- Content --}}
                    @if(!empty($item['content']) && empty($item['title']) && empty($item['text']))
                        <span class="{{ $item['textColor'] ?? $textColor }} {{ $textSize }} font-medium">
                            {{ Str::limit($item['content'], 60) }}
                        </span>
                    @endif
                </div>
                
                {{-- Separator --}}
                @if(!$loop->last)
                    <div class="flex-shrink-0 px-3 py-1">
                        <span class="text-gray-300">•</span>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>

<style>
    @keyframes marquee {
        0% {
            transform: translateX(0);
        }
        100% {
            transform: translateX(-50%);
        }
    }
    
    .animate-marquee {
        display: flex;
        animation: marquee linear infinite;
        will-change: transform;
    }
    
    .pause-on-hover:hover .animate-marquee {
        animation-play-state: paused;
    }
    
    /* For mobile responsiveness */
    @media (max-width: 640px) {
        .animate-marquee {
            animation-duration: {{ $speed * 0.7 }}s !important;
        }
    }
</style>
@else
<!-- No promotions to display -->
@endif