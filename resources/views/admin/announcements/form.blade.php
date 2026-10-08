@extends('admin.layouts.app')

@section('title', isset($announcement) ? 'Edit announcement' : 'Create announcement')
@section('page-title', isset($announcement) ? 'Edit announcement' : 'Create announcement')
@section('page-description', isset($announcement) ? 'Update the announcement details.' : 'Create a new promotion, notification or news item.')

@section('page-actions')
    <a href="{{ route('admin.announcement.index') }}" class="btn btn-outline btn-sm">
        <x-icon name="arrow-left" class="h-4 w-4" />
        All announcements
    </a>
@endsection

@section('content')
@php
    $types = [
        'promotion' => ['icon' => 'funnel', 'text' => 'Promotion'],
        'notification' => ['icon' => 'bell', 'text' => 'Notification'],
        'news' => ['icon' => 'document-text', 'text' => 'News'],
    ];

    $isActive = old('is_active', isset($announcement) ? $announcement->is_active : true);
@endphp

<div class="mx-auto max-w-4xl" x-data="{ active: {{ $isActive ? 'true' : 'false' }} }">
    <form action="{{ isset($announcement) ? route('admin.announcement.update', $announcement->id, false) : route('admin.announcement.store', [], false) }}"
          method="POST" class="space-y-6">
        @csrf
        @if(isset($announcement))
            @method('PUT')
        @endif

        @if($errors->any())
            <div class="card border-red-200 bg-red-50">
                <div class="card-content flex items-start gap-3">
                    <x-icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 text-red-600" />
                    <div>
                        <h2 class="text-sm font-medium text-red-800">Please fix the following errors</h2>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-red-700">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Announcement details</h2>
                <p class="card-description">Fields marked with <span class="text-destructive">*</span> are required.</p>
            </div>

            <div class="card-content space-y-6">
                {{-- ============================ Type ======================== --}}
                <div>
                    <span class="label mb-2">Announcement type <span class="text-destructive">*</span></span>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        @foreach($types as $value => $data)
                            <label class="cursor-pointer">
                                <input type="radio" name="type" value="{{ $value }}"
                                       class="peer sr-only"
                                       {{ old('type', isset($announcement) ? $announcement->type : '') == $value ? 'checked' : '' }}
                                       required>
                                <span class="flex h-full flex-col items-center gap-2 rounded-xl border border-border bg-white p-4 text-center transition-colors peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface text-ink-600 ring-1 ring-border">
                                        <x-icon :name="$data['icon']" class="h-5 w-5" />
                                    </span>
                                    <span class="text-sm font-medium">{{ $data['text'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- ============================ Title ======================= --}}
                <div>
                    <label for="title" class="label mb-2">Title <span class="text-destructive">*</span></label>
                    <input type="text" id="title" name="title"
                           value="{{ old('title', isset($announcement) ? $announcement->title : '') }}"
                           class="input" placeholder="e.g. Summer sale, 50% off" required>
                </div>

                {{-- =========================== Content ====================== --}}
                <div>
                    <label for="content" class="label mb-2">Content <span class="text-destructive">*</span></label>
                    <textarea id="content" name="content" rows="6" class="textarea"
                              placeholder="Enter the announcement details..." required>{{ old('content', isset($announcement) ? $announcement->content : '') }}</textarea>
                    <p class="mt-2 text-xs text-muted-foreground">Maximum 500 characters recommended.</p>
                </div>
            </div>
        </div>

        {{-- ========================= Badge settings ===================== --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Badge settings</h2>
                <p class="card-description">Optional badge shown alongside the announcement title.</p>
            </div>

            <div class="card-content grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="badge" class="label mb-2">Badge text</label>
                    <input type="text" id="badge" name="badge"
                           value="{{ old('badge', isset($announcement) ? $announcement->badge : '') }}"
                           class="input" placeholder="New, Sale, Limited">
                </div>

                <div>
                    <label for="badge_color" class="label mb-2">Badge colour</label>
                    <div class="flex items-center gap-3">
                        <input type="color" id="badge_color" name="badge_color"
                               value="{{ old('badge_color', isset($announcement) && $announcement->badge_color ? $announcement->badge_color : '#3B82F6') }}"
                               class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-border bg-white">
                        <input type="text"
                               value="{{ old('badge_color', isset($announcement) && $announcement->badge_color ? $announcement->badge_color : '#3B82F6') }}"
                               class="input font-mono text-xs" readonly aria-label="Badge colour hex value">
                    </div>
                </div>
            </div>
        </div>

        {{-- ========================== Icon ============================= --}}
        {{-- The `icon` column existed and the seeder populated it, but the form
             offered no way to set it, so every announcement an admin created had
             the fallback glyph. Stored as an icon-component name, not emoji. --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Icon</h2>
                <p class="card-description">Shown before the title in the announcement bar.</p>
            </div>

            <div class="card-content">
                @php
                    $icons = config('announcements.icons', []);
                    $selectedIcon = old('icon', isset($announcement) ? $announcement->icon : 'megaphone');
                @endphp

                <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
                    @foreach($icons as $value => $label)
                        <label class="cursor-pointer" title="{{ $label }}">
                            <input type="radio" name="icon" value="{{ $value }}" class="peer sr-only"
                                   {{ $selectedIcon === $value ? 'checked' : '' }}>
                            <span class="flex h-14 flex-col items-center justify-center gap-1 rounded-xl border border-border bg-white text-ink-600 transition-colors peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                <x-icon :name="$value" class="h-5 w-5" />
                                <span class="text-[10px] leading-none">{{ $label }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                @error('icon')
                    <p class="field-error mt-2">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- ========================== Schedule ========================== --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Schedule</h2>
                <p class="card-description">Optional. Leave empty for immediate display.</p>
            </div>

            <div class="card-content grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="starts_at" class="label mb-2">Start date &amp; time</label>
                    <input type="datetime-local" id="starts_at" name="starts_at"
                           value="{{ old('starts_at', isset($announcement) && $announcement->starts_at ? $announcement->starts_at->format('Y-m-d\TH:i') : '') }}"
                           class="input">
                </div>
                <div>
                    <label for="ends_at" class="label mb-2">End date &amp; time</label>
                    <input type="datetime-local" id="ends_at" name="ends_at"
                           value="{{ old('ends_at', isset($announcement) && $announcement->ends_at ? $announcement->ends_at->format('Y-m-d\TH:i') : '') }}"
                           class="input">
                </div>
            </div>
        </div>

        {{-- ======================== Active status ======================= --}}
        <div class="card">
            <div class="card-content flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium">Active status</p>
                    <p class="text-sm text-muted-foreground">Make this announcement visible to users.</p>
                </div>

                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" name="is_active" value="1" class="peer sr-only"
                           x-model="active"
                           {{ $isActive ? 'checked' : '' }}>
                    <span class="h-6 w-11 rounded-full bg-ink-300 transition-colors peer-checked:bg-brand-500"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-subtle transition-transform duration-150"
                          :class="active && 'translate-x-5'"></span>
                    <span class="sr-only">Active status</span>
                </label>
            </div>
        </div>

        {{-- =========================== Actions ========================== --}}
        <div class="flex flex-col-reverse items-stretch gap-2 sm:flex-row sm:items-center sm:justify-end">
            <a href="{{ route('admin.announcement.index') }}" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon :name="isset($announcement) ? 'check' : 'plus'" class="h-4 w-4" />
                {{ isset($announcement) ? 'Update announcement' : 'Create announcement' }}
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Badge colour picker mirror + content character counter
    // ---------------------------------------------------------------
    // The previous version bound these with a DOMContentLoaded block and
    // registered the textarea counter twice, which appended two counters to
    // the same field. Both behaviours are kept, once each.
    (function () {
        const picker = document.getElementById('badge_color');
        const hex = picker?.parentElement?.querySelector('input[readonly]');

        if (picker && hex) {
            picker.addEventListener('input', (event) => {
                hex.value = event.target.value;
            });
        }

        document.querySelectorAll('textarea[name="content"]').forEach((textarea) => {
            const counter = document.createElement('p');
            counter.className = 'mt-1 text-right text-xs tabular-nums text-muted-foreground';
            textarea.insertAdjacentElement('afterend', counter);

            const update = () => {
                const length = textarea.value.length;
                counter.textContent = `${length} / 500 characters`;
                counter.classList.toggle('text-destructive', length > 500);
                counter.classList.toggle('text-muted-foreground', length <= 500);
            };

            textarea.addEventListener('input', update);
            update();
        });
    })();
</script>
@endpush
