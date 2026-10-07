{{--
    Desktop nav link.

    Replaces a Breeze default that mixed a green underline with an indigo/green
    background — two accent systems in one control. Now a single flat pill.
--}}
@props(['active' => false, 'href' => '#'])

<a href="{{ $href }}"
   @if($active) aria-current="page" @endif
   {{ $attributes->merge([
       'class' => 'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors '
           . ($active
               ? 'bg-accent text-accent-foreground'
               : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900'),
   ]) }}>
    {{ $slot }}
</a>
