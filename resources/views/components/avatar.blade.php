@props([
    'user' => null,
    'size' => 'md',
    'ring' => false,
])

@php
    /*
    |--------------------------------------------------------------------------
    | Avatar
    |--------------------------------------------------------------------------
    | Resolves in priority order:
    |   1. an uploaded photo (`profile_picture`), so existing uploads keep working
    |   2. the user's chosen background colour + optional glyph
    |   3. initials — the zero-configuration default
    |
    | Colours come from config('avatars.colors') as a two-stop gradient. An
    | unknown or absent key falls back to the configured default rather than
    | rendering unstyled, so a stale value in the database is never visible.
    */
    $user = $user ?? auth()->user();

    $sizes = [
        'xs' => 'h-6 w-6 text-[10px]',
        'sm' => 'h-8 w-8 text-xs',
        'md' => 'h-10 w-10 text-sm',
        'lg' => 'h-14 w-14 text-lg',
        'xl' => 'h-24 w-24 text-3xl md:h-28 md:w-28 md:text-4xl',
    ];
    $box = $sizes[$size] ?? $sizes['md'];

    $colorKey = $user->avatar_color ?: config('avatars.default_color', 'brand');
    $colors = config('avatars.colors', []);
    $color = $colors[$colorKey] ?? ($colors[config('avatars.default_color', 'brand')] ?? null);

    $iconKey = $user->avatar_icon;
    $hasIcon = $iconKey && array_key_exists($iconKey, config('avatars.icons', []));

    $initials = \Illuminate\Support\Str::of($user->name ?? 'U')
        ->trim()
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');

    // Dark backgrounds need white text; the amber preset needs dark text.
    $textColor = ($color['ink'] ?? 'dark') === 'light' ? '#78350f' : '#ffffff';

    $style = $color
        ? "background-image:linear-gradient(135deg,{$color['from']},{$color['to']});color:{$textColor};"
        : 'background-color:#e5e7eb;color:#374151;';

    $ringClass = $ring ? 'ring-2 ring-white' : '';
@endphp

@if($user && $user->profile_picture)
    <img src="{{ $user->profile_picture }}"
         alt="{{ $user->name }}"
         {{ $attributes->merge(['class' => "shrink-0 rounded-full object-cover {$box} {$ringClass}"]) }}>
@else
    <span {{ $attributes->merge([
        'class' => "inline-flex shrink-0 select-none items-center justify-center overflow-hidden rounded-full font-semibold {$box} {$ringClass}",
        'style' => $style,
    ]) }}
          role="img"
          aria-label="{{ $user->name ?? 'User' }} avatar">
        @if($hasIcon)
            <x-icon :name="$iconKey" class="h-[55%] w-[55%]" :stroke-width="1.8" />
        @else
            {{ $initials ?: 'U' }}
        @endif
    </span>
@endif
