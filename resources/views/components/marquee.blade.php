{{--
    Announcement marquee — right-to-left, endlessly.

    Accepts Eloquent models or plain arrays. Renders nothing when there is
    nothing to show — the previous version hardcoded five fake offers as a
    fallback, so visitors saw expired promotions, and inlined a duplicate
    @keyframes block on every render.

    Icons are icon-component names (`megaphone`), annotated `aria-hidden`
    because the adjacent text already conveys the meaning. An unknown or absent
    name falls back to a megaphone rather than rendering nothing, so a title is
    never left visually unanchored — and never renders a raw emoji, which is
    what a previous version did.

    ## Why the content is rendered twice, in two groups

    A seamless loop needs the second copy to be *byte-for-byte* the first, and it
    needs the distance between the copies to equal the gap between items. So the
    spacing lives inside each group (`gap` plus a matching `padding-right`) and
    the track carrying both groups has none — see the `.marquee-*` rules in
    app.css for the arithmetic, and for why putting the gap on the track instead
    produces a marquee that hitches once per cycle.

    The second group is `aria-hidden`, because it exists only for the animation:
    a screen reader should hear each announcement once, not twice.
--}}
@props([
    'items' => [],
    'speed' => 30,
    'pauseOnHover' => true,
    'compact' => false,
])

@php
    $knownIcons = array_keys(config('announcements.icons', []));
    $legacyEmoji = config('announcements.legacy_emoji', []);

    $normalised = collect($items)
        ->map(function ($item) use ($legacyEmoji) {
            $get = fn ($key) => is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);

            $type = (string) ($get('type') ?? '');
            $icon = (string) ($get('icon') ?? '');

            return [
                // Rows created before icons were stored as names hold emoji.
                // Translated here, once, instead of in every consumer.
                'icon' => $legacyEmoji[$icon] ?? $icon,
                'title' => $get('title') ?: $get('content'),
                'badge' => $get('badge') ?: (config('announcements.type_badges')[$type] ?? null),
            ];
        })
        ->filter(fn ($item) => filled($item['title']) || filled($item['badge']))
        ->map(function ($item) use ($knownIcons) {
            $item['icon'] = in_array($item['icon'], $knownIcons, true) ? $item['icon'] : 'megaphone';

            return $item;
        })
        ->values();

    // Clamped rather than cast: a zero or negative duration would freeze the
    // row mid-scroll, which looks like a broken page rather than a setting.
    $duration = max(1, (int) $speed);
@endphp

@if($normalised->isNotEmpty())
    <div class="{{ $pauseOnHover ? 'pause-on-hover' : '' }}">
        <div class="marquee">
            <div class="marquee-track {{ $compact ? 'py-1' : 'py-2' }}"
                 style="--marquee-duration: {{ $duration }}s;">
                {{-- Two identical groups. The second is the loop's continuation and
                     is hidden from assistive technology, so nothing is announced
                     twice. --}}
                @foreach([1, 2] as $copy)
                    <div class="marquee-group whitespace-nowrap" @if($copy === 2) aria-hidden="true" @endif>
                        @foreach($normalised as $item)
                            <span class="flex shrink-0 items-center gap-2.5">
                                <x-icon :name="$item['icon']" class="h-4 w-4 text-brand-600" />

                                @if($item['badge'])
                                    <span class="badge badge-primary">{{ $item['badge'] }}</span>
                                @endif

                                <span class="text-sm font-medium text-ink-700">{{ $item['title'] }}</span>
                            </span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
