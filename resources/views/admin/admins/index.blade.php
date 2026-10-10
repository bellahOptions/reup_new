@extends('admin.layouts.app')

@section('title', 'Administrators')
@section('page-title', 'Administrator accounts')
@section('page-description', 'Who has access to this console, what they may do, and what they did recently.')

@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Administrator accounts
    |--------------------------------------------------------------------------
    | Rewritten to consume exactly what AdminController::index() already
    | computes:
    |
    |   $admins           LengthAwarePaginator of User (is_admin /
    |                     is_super_admin / admin_role / admin_permissions /
    |                     is_online / last_activity / last_login_at)
    |   $stats            total_admins, online_admins, super_admins,
    |                     active_admins
    |   $roleDistribution role label => count, platform-wide
    |   $recentLogs       AdminLog with `user` eager-loaded
    |
    | The previous version ran its own Eloquent queries inside the view
    | (AdminLog::with('user')->latest()->limit(10)->get()) and derived the
    | metrics with `$admins->where(...)`, which only ever counted the current
    | page — the header showed "Online now: 0" whenever the online admins all
    | sat on page two. It also threw
    | `Attempt to read property "name" on int` because the activity feed used
    | `$log->user_id->name` instead of `$log->user->name`.
    |
    | Searching and role/status filtering now happen in the browser over the
    | rows on screen (Alpine), which is what the previous DOM script did, minus
    | the global `onclick`/`onkeyup` handlers.
    */

    $maxRoleCount = max(1, (int) max($roleDistribution ?: [0]));
@endphp

<div class="space-y-6" x-data="adminDirectory()">

    {{-- ============================== Metrics ============================== --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Total administrators</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="users" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format((int) ($stats['total_admins'] ?? 0)) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Accounts with console access</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Online now</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                        <x-icon name="signal" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format((int) ($stats['online_admins'] ?? 0)) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Marked available right now</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Super admins</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="shield-check" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format((int) ($stats['super_admins'] ?? 0)) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Unrestricted access</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Active this session</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700">
                        <x-icon name="clock" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format((int) ($stats['active_admins'] ?? 0)) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Seen in the last 15 minutes</p>
            </div>
        </div>
    </div>

    {{-- ========================= Role distribution ========================= --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Role distribution</h2>
            <p class="card-description">Every administrator on the platform, grouped by role.</p>
        </div>
        <div class="card-content">
            @if(count($roleDistribution))
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($roleDistribution as $role => $count)
                        @php $count = (int) $count; @endphp
                        <div class="rounded-lg border border-border bg-surface p-4">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-medium">{{ $role }}</span>
                                <span class="text-lg font-semibold tabular-nums">{{ number_format($count) }}</span>
                            </div>
                            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-ink-200">
                                <div class="h-full rounded-full bg-brand-500"
                                     style="width: {{ (int) round(min(100, ($count / $maxRoleCount) * 100)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-state py-8">
                    <x-icon name="chart-pie" class="h-8 w-8 text-ink-300" />
                    <p class="mt-2 text-sm text-muted-foreground">No roles are configured yet.</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ============================ Admin table =========================== --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="card-title">Administrator directory</h2>
                <p class="card-description tabular-nums">
                    Showing {{ $admins->firstItem() ?? 0 }}&ndash;{{ $admins->lastItem() ?? 0 }}
                    of {{ number_format($admins->total()) }}
                </p>
            </div>

            @if(auth()->user()->hasPermission('manage_admins'))
                <a href="{{ route('admin.admins.create') }}" class="btn btn-primary btn-sm shrink-0">
                    <x-icon name="user-plus" class="h-4 w-4" />
                    Add administrator
                </a>
            @endif
        </div>

        @if($admins->count())
            {{-- Toolbar: filters the rows currently on screen. --}}
            <div class="flex flex-wrap items-end gap-3 border-b border-border p-4">
                <div class="min-w-[16rem] flex-1">
                    <label class="label mb-1.5" for="adminSearch">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                            <x-icon name="magnifying-glass" class="h-4 w-4" />
                        </span>
                        <input type="search" id="adminSearch" class="input pl-9"
                               placeholder="Name, email or phone" x-model="search">
                    </div>
                </div>

                <div>
                    <label class="label mb-1.5" for="adminRoleFilter">Role</label>
                    <select id="adminRoleFilter" class="select" x-model="role">
                        <option value="">All roles</option>
                        @foreach(array_keys($roleDistribution ?? []) as $roleLabel)
                            <option value="{{ strtolower($roleLabel) }}">{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label mb-1.5" for="adminStatusFilter">Status</label>
                    <select id="adminStatusFilter" class="select" x-model="status">
                        <option value="">All statuses</option>
                        <option value="online">Online</option>
                        <option value="offline">Offline</option>
                    </select>
                </div>

                <button type="button" class="btn btn-ghost btn-sm"
                        @click="clear()"
                        x-show="hasFilters" x-cloak>
                    <x-icon name="arrow-path" class="h-4 w-4" />
                    Clear
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Administrator</th>
                            <th scope="col">Role</th>
                            <th scope="col">Permissions</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody x-ref="rows" @click="onRowAction($event)">
                        @foreach($admins as $admin)
                            @php
                                $permissions = is_array($admin->admin_permissions)
                                    ? $admin->admin_permissions
                                    : (array) json_decode((string) $admin->admin_permissions, true);
                                $hasAll = in_array('*', $permissions, true);
                                $isSelf = $admin->id === auth()->id();
                                $isSuper = auth()->user()->is_super_admin;
                            @endphp
                            <tr x-show="matches({
                                    name: @js(strtolower($admin->name ?? '')),
                                    email: @js(strtolower($admin->email ?? '')),
                                    phone: @js(strtolower($admin->phone ?? '')),
                                    role: @js(strtolower((string) $admin->admin_role)),
                                    status: @js($admin->is_online ? 'online' : 'offline')
                                })">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-semibold uppercase text-ink-700">
                                            {{ strtoupper(substr((string) $admin->name, 0, 1)) ?: '?' }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">{{ $admin->name }}</p>
                                            <p class="truncate text-xs text-muted-foreground">{{ $admin->email }}</p>
                                            @if($admin->phone)
                                                <p class="truncate text-xs tabular-nums text-muted-foreground">{{ $admin->phone }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge {{ $admin->is_super_admin ? 'badge-primary' : 'badge-neutral' }}">
                                        <x-icon :name="$admin->is_super_admin ? 'shield-check' : 'user'" class="h-3.5 w-3.5" />
                                        {{ $admin->is_super_admin ? 'Super admin' : ucfirst((string) $admin->admin_role) }}
                                    </span>
                                    @if($isSelf)
                                        <p class="mt-1 text-xs text-muted-foreground">This is you</p>
                                    @endif
                                </td>

                                <td>
                                    @if($hasAll)
                                        <span class="badge badge-primary">All permissions</span>
                                    @elseif(count($permissions))
                                        <details class="group">
                                            <summary class="cursor-pointer list-none">
                                                <span class="badge badge-neutral">
                                                    {{ count($permissions) }}
                                                    {{ \Illuminate\Support\Str::plural('permission', count($permissions)) }}
                                                    <x-icon name="chevron-down" class="h-3.5 w-3.5 transition-transform group-open:rotate-180" />
                                                </span>
                                            </summary>
                                            <div class="mt-2 flex max-w-xs flex-wrap gap-1">
                                                @foreach($permissions as $permission)
                                                    <span class="badge badge-neutral">{{ $permission }}</span>
                                                @endforeach
                                            </div>
                                        </details>
                                    @else
                                        <span class="text-sm text-muted-foreground">None</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap">
                                    @if($admin->is_online)
                                        <span class="badge badge-success">
                                            <x-icon name="check-circle" variant="solid" class="h-3.5 w-3.5" />
                                            Online
                                        </span>
                                    @else
                                        <span class="badge badge-neutral">
                                            <x-icon name="minus" class="h-3.5 w-3.5" />
                                            Offline
                                        </span>
                                    @endif
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        @if($admin->last_activity)
                                            {{ $admin->last_activity->diffForHumans() }}
                                        @elseif($admin->last_login_at)
                                            Last login {{ $admin->last_login_at->diffForHumans() }}
                                        @else
                                            Never signed in
                                        @endif
                                    </p>
                                </td>

                                <td class="whitespace-nowrap text-sm text-muted-foreground">
                                    <span class="tabular-nums">{{ $admin->created_at?->format('M j, Y') ?? '—' }}</span>
                                </td>

                                <td class="text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        @if($isSuper && ! $isSelf)
                                            <button type="button"
                                                    class="btn btn-outline btn-sm"
                                                    data-toggle-id="{{ $admin->id }}"
                                                    data-toggle-name="{{ $admin->name }}"
                                                    data-toggle-online="{{ $admin->is_online ? '1' : '0' }}">
                                                <x-icon :name="$admin->is_online ? 'no-symbol' : 'play-circle'" class="h-4 w-4" />
                                                {{ $admin->is_online ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        @endif

                                        @if($isSuper)
                                            <a href="{{ route('admin.admins.edit', $admin) }}" class="btn btn-outline btn-sm">
                                                <x-icon name="pencil-square" class="h-4 w-4" />
                                                Edit
                                            </a>
                                        @endif

                                        @if($isSuper && ! $isSelf)
                                            <button type="button"
                                                    class="btn btn-destructive btn-sm"
                                                    data-delete-id="{{ $admin->id }}"
                                                    data-delete-name="{{ $admin->name }}">
                                                <x-icon name="trash" class="h-4 w-4" />
                                                Delete
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div x-show="hasFilters && !hasRows" x-cloak>
                <div class="empty-state py-10">
                    <x-icon name="magnifying-glass" class="h-8 w-8 text-ink-300" />
                    <p class="mt-3 font-medium">No administrators match these filters</p>
                    <p class="mt-1 text-sm text-muted-foreground">Filters apply to the administrators on this page.</p>
                    <button type="button" class="btn btn-outline btn-sm mt-4" @click="clear()">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Clear filters
                    </button>
                </div>
            </div>

            @if($admins->hasPages())
                <div class="card-footer justify-center">
                    {{ $admins->links() }}
                </div>
            @endif
        @else
            <div class="empty-state">
                <x-icon name="users" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No administrators yet</p>
                <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                    Administrator accounts will be listed here once they are created.
                </p>
                @if(auth()->user()->hasPermission('manage_admins'))
                    <a href="{{ route('admin.admins.create') }}" class="btn btn-primary btn-sm mt-4">
                        <x-icon name="user-plus" class="h-4 w-4" />
                        Add administrator
                    </a>
                @endif
            </div>
        @endif
    </div>

    {{-- ========================== Recent activity ========================= --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recent administrator activity</h2>
            <p class="card-description">The last {{ $recentLogs->count() }} recorded console actions.</p>
        </div>

        @if($recentLogs->count())
            <ul class="divide-y divide-border">
                @foreach($recentLogs as $log)
                    @php
                        $actor = $log->user;
                        $details = $log->details;
                        $detailParts = [];

                        if (is_array($details)) {
                            foreach ($details as $key => $value) {
                                if (is_array($value)) {
                                    $value = implode(', ', array_map(
                                        fn ($item) => is_scalar($item) ? (string) $item : json_encode($item),
                                        $value
                                    ));
                                } elseif (is_bool($value)) {
                                    $value = $value ? 'yes' : 'no';
                                } elseif (! is_scalar($value) && ! is_null($value)) {
                                    $value = json_encode($value);
                                }

                                $detailParts[] = ucfirst(str_replace('_', ' ', (string) $key)) . ': ' . ($value ?? '—');
                            }
                        } elseif (is_string($details) && $details !== '') {
                            $detailParts[] = $details;
                        }
                    @endphp
                    <li class="flex items-start gap-3 px-5 py-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold uppercase text-brand-700">
                            {{ $actor ? (strtoupper(substr((string) $actor->name, 0, 1)) ?: '?') : '?' }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <p class="text-sm font-medium">
                                    {{ $actor->name ?? 'Unknown administrator' }}
                                </p>
                                <p class="text-xs tabular-nums text-muted-foreground">
                                    {{ $log->created_at?->diffForHumans() ?? 'Unknown time' }}
                                </p>
                            </div>

                            <p class="mt-0.5 text-sm text-muted-foreground">
                                {{ $log->action_label }}
                            </p>

                            @if(count($detailParts))
                                <p class="mt-1 break-words text-xs text-muted-foreground">{{ implode(', ', $detailParts) }}</p>
                            @endif

                            @if($log->ip_address)
                                <p class="mt-1 flex items-center gap-1.5 text-xs tabular-nums text-muted-foreground">
                                    <x-icon name="globe-alt" class="h-3.5 w-3.5" />
                                    {{ $log->ip_address }}
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="empty-state">
                <x-icon name="clipboard-document-list" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No recorded activity</p>
                <p class="mt-1 text-sm text-muted-foreground">Administrator actions will appear here as they happen.</p>
            </div>
        @endif
    </div>

    {{-- ====================== Toggle availability dialog =================== --}}
    <div x-show="toggleAction !== null" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-scrim/50 p-4"
         @keydown.escape.window="toggleAction = null"
         @click.self="toggleAction = null">
        <div class="w-full max-w-md overflow-hidden rounded-xl border border-border bg-surface shadow-overlay"
             role="dialog" aria-modal="true" aria-labelledby="toggleAdminTitle">
            <div class="border-b border-border px-5 py-4">
                <h3 id="toggleAdminTitle" class="card-title">Change availability</h3>
                <p class="card-description">
                    <span x-text="toggleAction ? (toggleAction.online ? 'Take' : 'Put') + ' ' + toggleAction.name + (toggleAction.online ? ' offline' : ' online') : ''"></span>.
                </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 px-5 py-4">
                <button type="button" class="btn btn-outline btn-sm" @click="toggleAction = null">Cancel</button>
                <form method="POST" :action="toggleAction ? toggleAction.url : ''">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="check" class="h-4 w-4" />
                        Confirm
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- ========================= Delete admin dialog ======================= --}}
    <div x-show="deleteAction !== null" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-scrim/50 p-4"
         @keydown.escape.window="deleteAction = null"
         @click.self="deleteAction = null">
        <div class="w-full max-w-md overflow-hidden rounded-xl border border-border bg-surface shadow-overlay"
             role="dialog" aria-modal="true" aria-labelledby="deleteAdminTitle">
            <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
                    <x-icon name="exclamation-triangle" variant="solid" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h3 id="deleteAdminTitle" class="card-title">Delete administrator</h3>
                    <p class="card-description">
                        <span x-text="deleteAction ? deleteAction.name : ''"></span>
                        will lose access to this console immediately. This cannot be undone.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 px-5 py-4">
                <button type="button" class="btn btn-outline btn-sm" @click="deleteAction = null">Cancel</button>
                <form method="POST" :action="deleteAction ? deleteAction.url : ''">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-destructive btn-sm">
                        <x-icon name="trash" class="h-4 w-4" />
                        Delete administrator
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Administrator directory
    // ---------------------------------------------------------------
    // Filtering runs over the rows on the current page, plus the two confirm
    // steps for changing availability and deleting an account. The previous
    // version exposed searchAdmins(), filterAdmins(), toggleAdminStatus() and
    // confirmDelete() as globals and wired them up with inline
    // onclick/onkeyup/onsubmit attributes; the actions are now delegated from
    // the table body instead of interpolated per row.
    document.addEventListener('alpine:init', () => {
        Alpine.data('adminDirectory', () => ({
            search: '',
            role: '',
            status: '',
            hasRows: true,
            toggleAction: null,
            deleteAction: null,

            get hasFilters() {
                return this.search !== '' || this.role !== '' || this.status !== '';
            },

            // Called from `x-show` on every row, so it must stay pure.
            matches(row) {
                const needle = this.search.trim().toLowerCase();

                const textMatch = needle === ''
                    || row.name.includes(needle)
                    || row.email.includes(needle)
                    || row.phone.includes(needle);

                const roleMatch = this.role === '' || row.role === this.role;
                const statusMatch = this.status === '' || row.status === this.status;

                return textMatch && roleMatch && statusMatch;
            },

            clear() {
                this.search = '';
                this.role = '';
                this.status = '';
            },

            // An empty filtered list is worth explaining, so read the rows
            // back out of the DOM once Alpine has applied `x-show`.
            updateEmptyState() {
                if (!this.$refs.rows) {
                    return;
                }

                this.hasRows = Array.from(this.$refs.rows.querySelectorAll('tr'))
                    .some((row) => row.style.display !== 'none');
            },

            init() {
                this.$watch('search', () => this.$nextTick(() => this.updateEmptyState()));
                this.$watch('role', () => this.$nextTick(() => this.updateEmptyState()));
                this.$watch('status', () => this.$nextTick(() => this.updateEmptyState()));
                this.$nextTick(() => this.updateEmptyState());
            },

            onRowAction(event) {
                const toggle = event.target.closest('[data-toggle-id]');

                if (toggle) {
                    this.toggleAction = {
                        url: `{{ url('admin/admins') }}/${toggle.dataset.toggleId}/toggle-status`,
                        name: toggle.dataset.toggleName,
                        online: toggle.dataset.toggleOnline === '1',
                    };

                    return;
                }

                const remove = event.target.closest('[data-delete-id]');

                if (remove) {
                    this.deleteAction = {
                        url: `{{ url('admin/admins') }}/${remove.dataset.deleteId}`,
                        name: remove.dataset.deleteName,
                    };
                }
            },
        }));
    });
</script>
@endpush
