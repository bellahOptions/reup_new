@extends('admin.layouts.app')

@php
    use App\Models\User;
@endphp

@section('title', 'Administrators')
@section('page-title', 'Administrators')
@section('page-description', 'Admin accounts, roles and permissions.')

@section('page-actions')
    @if(auth()->user()->is_super_admin)
        <a href="{{ route('admin.admins.create') }}" class="btn btn-primary btn-sm">
            <x-icon name="plus" class="h-4 w-4" />
            Add administrator
        </a>
    @endif
@endsection

@section('content')
@php
    $onlineAdmins = User::where('is_online', true)
        ->where(function ($q) {
            $q->where('is_admin', true)->orWhere('is_super_admin', true);
        })
        ->count();

    $superAdmins = User::where('is_super_admin', true)->count();

    $activeSessions = User::where('last_activity_at', '>=', now()->subMinutes(5))
        ->where(function ($q) {
            $q->where('is_admin', true)->orWhere('is_super_admin', true);
        })
        ->count();
@endphp

<div class="space-y-6" x-data="adminStatus">

    {{-- ============================ Metrics ============================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Total administrators</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="users" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($admins->total()) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Online now</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                        <x-icon name="signal" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($onlineAdmins) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Super admins</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="shield-check" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($superAdmins) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Active sessions</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700">
                        <x-icon name="bolt" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($activeSessions) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Active in last 5 minutes</p>
            </div>
        </div>
    </div>

    {{-- ======================== Administrators ======================== --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="card-title">All administrators</h2>
                <p class="card-description tabular-nums">
                    Showing {{ $admins->firstItem() ?? 0 }}&ndash;{{ $admins->lastItem() ?? 0 }} of {{ number_format($admins->total()) }}
                </p>
            </div>

            <div class="relative">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400">
                    <x-icon name="magnifying-glass" class="h-4 w-4" />
                </span>
                <input type="search" x-model="search" placeholder="Search this page..." class="input pl-9" aria-label="Search administrators">
            </div>
        </div>

        @if($admins->count())
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Administrator</th>
                            <th>Role &amp; permissions</th>
                            <th>Status</th>
                            <th>Last activity</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($admins as $admin)
                            <tr x-show="search === '' || @js(strtolower($admin->name . ' ' . $admin->email)).includes(search.toLowerCase())">
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">
                                            {{ strtoupper(substr($admin->name, 0, 1)) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">
                                                {{ $admin->name }}
                                                @if($admin->is_super_admin)
                                                    <span class="badge badge-primary ml-1">
                                                        <x-icon name="shield-check" class="h-3 w-3" />
                                                        Super
                                                    </span>
                                                @endif
                                            </p>
                                            <p class="truncate text-xs text-muted-foreground">{{ $admin->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <p class="text-sm font-medium">{{ $admin->admin_role }}</p>
                                    @if($admin->admin_permissions)
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach($admin->admin_permissions as $permission)
                                                <span class="badge badge-neutral">{{ $permission }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if($admin->is_online)
                                        <span class="badge badge-success">
                                            <x-icon name="check" class="h-3 w-3" />
                                            Online
                                        </span>
                                    @else
                                        <span class="badge badge-neutral">Offline</span>
                                    @endif
                                </td>
                                <td class="text-sm text-muted-foreground">
                                    @if($admin->last_activity_at)
                                        <span class="tabular-nums">{{ $admin->last_activity_at->diffForHumans() }}</span>
                                    @else
                                        Never
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('admin.admins.edit', $admin) }}" class="btn btn-outline btn-sm">
                                            <x-icon name="pencil-square" class="h-4 w-4" />
                                            Edit
                                        </a>

                                        @if(auth()->user()->is_super_admin && $admin->id !== auth()->id())
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    data-admin-id="{{ $admin->id }}"
                                                    :disabled="busy === '{{ $admin->id }}'"
                                                    @click="toggleStatus($event.currentTarget.dataset.adminId)">
                                                <x-icon :name="$admin->is_online ? 'pause-circle' : 'play-circle'" class="h-4 w-4" />
                                                <span>{{ $admin->is_online ? 'Pause' : 'Activate' }}</span>
                                            </button>

                                            <form action="{{ route('admin.admins.destroy', $admin, false) }}" method="POST" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-ghost btn-sm text-destructive"
                                                        data-confirm="Are you sure you want to delete this administrator?">
                                                    <x-icon name="trash" class="h-4 w-4" />
                                                    Delete
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($admins->hasPages())
                <div class="border-t border-border p-4">
                    {{ $admins->links() }}
                </div>
            @endif
        @else
            <div class="empty-state">
                <x-icon name="users" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No administrators found</p>
                <p class="mt-1 text-sm text-muted-foreground">Administrator accounts will appear here once they are created.</p>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Administrator status toggle + activity heartbeat
    // ---------------------------------------------------------------
    // The previous implementation inlined `onclick="toggleStatus(...)"` and
    // hand-rewrote Tailwind classes on the row from a global function. The
    // action is unchanged (POST /admin/admins/{id}/toggle-status); only the
    // presentation moved into Alpine.
    document.addEventListener('alpine:init', () => {
        Alpine.data('adminStatus', () => ({
            search: '',
            busy: null,

            async toggleStatus(adminId) {
                if (!confirm('Change the status of this administrator account?')) return;

                this.busy = adminId;

                try {
                    const response = await fetch(`{{ route('admin.admins.toggle-status', '') }}/${adminId}`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.location.reload();
                    }
                } catch (error) {
                    // Transient failure: leave the row untouched.
                } finally {
                    this.busy = null;
                }
            },
        }));
    });

    // Confirm-before-submit for destructive buttons, replacing inline
    // `onclick="return confirm(...)"` handlers.
    document.addEventListener('submit', (event) => {
        const button = event.target.querySelector('button[data-confirm]');
        if (!button) return;

        if (!confirm(button.dataset.confirm)) {
            event.preventDefault();
        }
    }, true);
</script>
@endpush
