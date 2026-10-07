{{--
    Shared error body.

    Each error page previously carried its own copy of the markup plus a wall of
    emoji. One component, one visual language.
--}}
@props([
    'code' => 'Error',
    'title' => 'Something went wrong',
    'message' => 'An unexpected error occurred. Please try again.',
    'icon' => 'exclamation-triangle',
])

<div class="flex flex-col items-center">
    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-accent text-brand-600">
        <x-icon :name="$icon" class="h-7 w-7" />
    </span>

    <p class="mt-6 font-mono text-xs uppercase tracking-[0.2em] text-ink-400">{{ $code }}</p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $title }}</h1>
    <p class="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">{{ $message }}</p>

    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
        <a href="{{ url('/') }}" class="btn btn-primary">
            <x-icon name="home" class="h-4 w-4" />
            Back to home
        </a>
        <a href="{{ route('contact') }}" class="btn btn-outline">
            <x-icon name="lifebuoy" class="h-4 w-4" />
            Contact support
        </a>
    </div>
</div>
