{{--
    A mobile network's brand mark, with a graceful fallback.

    ## Why this is a component

    The mark was assembled by hand in every template that showed a network, from
    a lookup each template built itself. That is why the same network rendered
    differently in two places, and why the brand images sat unused while
    placeholder monograms were shown. One component means one sizing, one
    `object-fit`, one alt-text rule and one fallback — and adding a network is a
    change to `config/networks.php` alone.

    ## Props

    * `network`  — any identifier that resolves: a canonical key (`9mobile`), a
      provider code (`03`), a display name (`T2mobile`) or an alias (`9 MOBILE`).
      A value that resolves to nothing renders the neutral label, never the
      provider's name.
    * `provider` — the provider whose code space `network` belongs to, when known
      (`clubkonnect`). Numeric codes are ambiguous between providers, so passing
      this makes the resolution authoritative rather than a guess.
    * `size`     — a tailwind size class pair for the box. Defaults to the inline
      size used beside a network name.
    * `withLabel`— render the network's name beside the mark.
    * `colour`   — use the network's brand colour behind the fallback mark.

    ## The fallback

    Two levels, because a 404 on an image is invisible until somebody looks at the
    page:
    1. the brand asset;
    2. the configured secondary asset (`fallback_logo`), tried once via `onerror`;
    3. a coloured monogram of the network's initial, which reads as the network
       rather than as a broken image.

    The monogram is `aria-hidden` because the adjacent label already names the
    network, and `alt` carries the label so a screen reader never announces an
    unnamed graphic.
--}}
@props([
    'network' => null,
    'provider' => null,
    'size' => 'h-6 w-6',
    'withLabel' => false,
    'colour' => true,
])

@php
    use App\Services\NetworkBrand;

    $brand = NetworkBrand::for(NetworkBrand::key(
        is_scalar($network) ? (string) $network : null,
        is_scalar($provider) ? (string) $provider : null,
    ));

    // Only a genuinely resolved network earns a mark. An unresolved value would
    // otherwise render a monogram for a network nobody identified.
    $resolved = NetworkBrand::key(is_scalar($network) ? (string) $network : null) !== null;

    $initial = mb_strtoupper(mb_substr($brand['label'], 0, 1));
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @if($resolved)
        <span class="relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full {{ $size }}">
            @if($brand['logo'])
                <img src="{{ $brand['logo'] }}"
                     alt="{{ $brand['alt'] }}"
                     loading="lazy"
                     decoding="async"
                     class="h-full w-full object-contain"
                     @if($brand['fallback_logo'])
                         onerror="this.onerror=null;this.src='{{ $brand['fallback_logo'] }}';"
                     @else
                         onerror="this.style.display='none';this.nextElementSibling && (this.nextElementSibling.style.display='flex');"
                     @endif>
            @endif

            {{-- The monogram. Visible from the start when no asset is configured,
                 and revealed by `onerror` when the asset 404s. --}}
            <span class="absolute inset-0 items-center justify-center rounded-full text-[0.6em] font-bold leading-none text-white"
                  style="{{ $colour && $brand['colour'] ? 'background-color:' . $brand['colour'] . ';' : 'background-color:var(--color-ink-300, #cbd5e1);' }}{{ $brand['logo'] ? 'display:none;' : 'display:flex;' }}"
                  aria-hidden="true">{{ $initial }}</span>
        </span>
    @endif

    @if($withLabel)
        <span class="text-sm font-medium">{{ $brand['label'] }}</span>
    @endif
</span>
