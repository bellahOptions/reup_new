{{--
    Mobile nav link. Previously carried an indigo active state alongside a
    green border — now aligned with the rest of the system.
--}}
@props(['active' => false, 'href' => '#'])

<a href="{{ $href }}"
   @if($active) aria-current="page" @endif
   {{ $attributes->merge([
       'class' => 'flex items-center gap-3 rounded-lg px-3 py-2.5 text-base font-medium transition-colors '
           . ($active
               ? 'bg-accent text-accent-foreground'
               : 'text-ink-700 hover:bg-ink-100 hover:text-ink-900'),
   ]) }}>
    {{ $slot }}
</a>
