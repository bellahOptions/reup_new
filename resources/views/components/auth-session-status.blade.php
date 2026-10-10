@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-success-soft-foreground']) }}>
        {{ $status }}
    </div>
@endif
