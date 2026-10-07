{{--
    Button.

    Kept for Breeze compatibility, but now backed by the design system: the
    previous default was a grey/uppercase pill that matched nothing else in the
    product. Pass `variant` to pick a tone.
--}}
@props([
    'variant' => 'primary',
    'size' => null,
    'type' => 'submit',
])

@php
    $variants = [
        'primary' => 'btn-primary',
        'secondary' => 'btn-secondary',
        'outline' => 'btn-outline',
        'ghost' => 'btn-ghost',
        'destructive' => 'btn-destructive',
        'link' => 'btn-link',
    ];

    $sizes = ['sm' => 'btn-sm', 'lg' => 'btn-lg', 'icon' => 'btn-icon'];

    $classes = trim(
        'btn ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? '')
    );
@endphp

<button {{ $attributes->merge(['type' => $type, 'class' => $classes]) }}>
    {{ $slot }}
</button>
