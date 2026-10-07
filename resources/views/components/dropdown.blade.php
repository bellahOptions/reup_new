@props(['align' => 'right', 'width' => '48'])

@php
    $alignment = match ($align) {
        'left' => 'origin-top-left left-0',
        'top' => 'origin-top',
        default => 'origin-top-right right-0',
    };

    $panelWidth = $width === '48' ? 'w-48' : 'w-' . $width;
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @close.stop="open = false">
    <div @click="open = !open">
        {{ $trigger }}
    </div>

    <div x-show="open"
         x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute z-50 mt-2 {{ $panelWidth }} overflow-hidden rounded-xl border border-border bg-white shadow-overlay {{ $alignment }}"
         @click="open = false">
        <div class="p-1.5">
            {{ $content }}
        </div>
    </div>
</div>
