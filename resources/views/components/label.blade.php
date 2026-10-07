@props(['value' => null])

<label {{ $attributes->merge(['class' => 'label']) }}>
    {{ $value ?? $slot }}
</label>
