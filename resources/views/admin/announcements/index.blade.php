@extends('admin.layouts.app')

@section('title', 'Announcements')
@section('page-title', 'Announcements')
@section('page-description', 'Promotions, notifications and news shown to customers.')

@section('page-actions')
    <a href="{{ route('admin.announcement.create') }}" class="btn btn-primary btn-sm">
        <x-icon name="plus" class="h-4 w-4" />
        Create announcement
    </a>
@endsection

@section('content')
@php
    $types = ['promotion', 'notification', 'news'];

    $typeIcons = [
        'promotion' => 'funnel',
        'notification' => 'bell',
        'news' => 'document-text',
    ];
@endphp

<div class="space-y-6">
    {{-- ============================ Metrics ============================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Total</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="megaphone" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($announcements->total()) }}</p>
            </div>
        </div>

        @foreach($types as $type)
            @php
                $count = \App\Models\PromotionNotification::where('type', $type)->count();
                $activeCount = \App\Models\PromotionNotification::where('type', $type)->where('is_active', true)->count();
            @endphp
            <div class="card">
                <div class="card-content">
                    <div class="flex items-start justify-between gap-3">
                        <span class="stat-label capitalize">{{ $type }}</span>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface text-ink-600 ring-1 ring-border">
                            <x-icon :name="$typeIcons[$type]" class="h-4 w-4" />
                        </span>
                    </div>
                    <p class="stat-value mt-2">{{ number_format($count) }}</p>
                    <p class="mt-1 text-xs tabular-nums text-muted-foreground">{{ number_format($activeCount) }} active</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ========================= Announcements ======================== --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="card-title">All announcements</h2>
                <p class="card-description tabular-nums">
                    Showing {{ $announcements->firstItem() ?? 0 }}&ndash;{{ $announcements->lastItem() ?? 0 }} of {{ number_format($announcements->total()) }}
                </p>
            </div>
        </div>

        @if($announcements->count())
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Announcement</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Schedule</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($announcements as $announcement)
                            <tr>
                                <td>
                                    <div class="flex items-start gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface text-ink-600 ring-1 ring-border">
                                            <x-icon :name="$typeIcons[$announcement->type] ?? 'megaphone'" class="h-4 w-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-medium">{{ $announcement->title }}</p>
                                            <p class="mt-0.5 max-w-md text-xs text-muted-foreground">{{ Str::limit($announcement->content, 100) }}</p>
                                            @if($announcement->badge)
                                                <span class="badge mt-1.5"
                                                      style="background-color: {{ $announcement->badge_color ?? '#3B82F6' }}; color: {{ $announcement->text_color ?? '#ffffff' }}; border-color: transparent;">
                                                    {{ $announcement->badge }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-neutral">
                                        <x-icon :name="$typeIcons[$announcement->type] ?? 'megaphone'" class="h-3 w-3" />
                                        {{ ucfirst($announcement->type) }}
                                    </span>
                                </td>
                                <td>
                                    @if($announcement->is_active)
                                        <span class="badge badge-success">
                                            <x-icon name="check" class="h-3 w-3" />
                                            Active
                                        </span>
                                    @else
                                        <span class="badge badge-neutral">Inactive</span>
                                    @endif
                                </td>
                                <td class="text-sm text-muted-foreground">
                                    @if($announcement->starts_at && $announcement->ends_at)
                                        <span class="tabular-nums">{{ $announcement->starts_at->format('M d, Y') }}</span>
                                        &ndash;
                                        <span class="tabular-nums">{{ $announcement->ends_at->format('M d, Y') }}</span>
                                    @elseif($announcement->starts_at)
                                        <span class="tabular-nums">Starts {{ $announcement->starts_at->format('M d, Y') }}</span>
                                    @else
                                        No schedule
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('admin.announcement.edit', $announcement->id) }}" class="btn btn-outline btn-sm">
                                            <x-icon name="pencil-square" class="h-4 w-4" />
                                            Edit
                                        </a>

                                        <form action="{{ route('admin.announcement.toggle-status', $announcement->id, false) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost btn-sm"
                                                    title="{{ $announcement->is_active ? 'Deactivate' : 'Activate' }}">
                                                <x-icon :name="$announcement->is_active ? 'pause-circle' : 'play-circle'" class="h-4 w-4" />
                                                {{ $announcement->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>

                                        <form action="{{ route('admin.announcement.destroy', $announcement->id, false) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-ghost btn-sm text-destructive"
                                                    data-confirm="Are you sure you want to delete this announcement?">
                                                <x-icon name="trash" class="h-4 w-4" />
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($announcements->hasPages())
                <div class="border-t border-border p-4">
                    {{-- The default paginator view: `vendor.pagination.tailwind`
                         does not exist in this project and would throw. --}}
                    {{ $announcements->links() }}
                </div>
            @endif
        @else
            <div class="empty-state">
                <x-icon name="megaphone" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No announcements yet</p>
                <p class="mt-1 text-sm text-muted-foreground">Get started by creating your first announcement.</p>
                <a href="{{ route('admin.announcement.create') }}" class="btn btn-primary btn-sm mt-4">
                    <x-icon name="plus" class="h-4 w-4" />
                    Create announcement
                </a>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Confirm-before-submit for destructive buttons. Replaces the inline
    // `onclick="return confirm(...)"` handler on the delete button.
    document.addEventListener('submit', (event) => {
        const button = event.target.querySelector('button[data-confirm]');
        if (!button) return;

        if (!confirm(button.dataset.confirm)) {
            event.preventDefault();
        }
    }, true);
</script>
@endpush
