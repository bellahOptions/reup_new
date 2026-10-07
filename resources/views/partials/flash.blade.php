{{--
    Flash messages.

    Previously each layout re-implemented session('success'|'error') banners,
    and layouts/app also carried a large commented-out SweetAlert2 block that
    was never removed. This is the single implementation, rendered as toasts
    so it does not shift page content.
--}}
@php
    $flashMap = [
        'success' => ['icon' => 'check-circle', 'variant' => 'text-brand-600', 'border' => 'border-brand-200'],
        'error' => ['icon' => 'exclamation-circle', 'variant' => 'text-red-600', 'border' => 'border-red-200'],
        'warning' => ['icon' => 'exclamation-triangle', 'variant' => 'text-amber-600', 'border' => 'border-amber-200'],
        'status' => ['icon' => 'information-circle', 'variant' => 'text-sky-600', 'border' => 'border-sky-200'],
    ];

    $flashes = collect($flashMap)
        ->filter(fn ($meta, $key) => session()->has($key))
        ->map(fn ($meta, $key) => $meta + ['key' => $key, 'message' => session($key)]);
@endphp

@if($flashes->isNotEmpty())
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4"
         role="status" aria-live="polite">
        @foreach($flashes as $flash)
            <div x-data="{ show: true }"
                 x-init="setTimeout(() => show = false, 6000)"
                 x-show="show"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-end="opacity-0 -translate-y-2"
                 class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl border bg-white px-4 py-3 shadow-overlay {{ $flash['border'] }}">
                <x-icon :name="$flash['icon']" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 {{ $flash['variant'] }}" />
                <p class="flex-1 text-sm text-ink-800">{{ $flash['message'] }}</p>
                <button type="button" @click="show = false"
                        class="shrink-0 rounded-md p-0.5 text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
                        aria-label="Dismiss">
                    <x-icon name="x-mark" class="h-4 w-4" />
                </button>
            </div>
        @endforeach
    </div>
@endif
