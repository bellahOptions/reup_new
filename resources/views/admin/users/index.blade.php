@extends('admin.layouts.app')

@section('title', 'Customers')
@section('page-title', 'Customers')
@section('page-description', 'Search, review and manage every customer account.')

@section('content')
@php
    $statusOptions = [
        'verified' => 'Verified',
        'unverified' => 'Unverified',
        'active' => 'Active in last 30 days',
        'inactive' => 'Inactive',
        'suspended' => 'Suspended',
    ];

    $quickFilters = [
        ['label' => 'Verified', 'icon' => 'check-circle', 'status' => 'verified'],
        ['label' => 'Unverified', 'icon' => 'exclamation-circle', 'status' => 'unverified'],
        ['label' => 'Active', 'icon' => 'bolt', 'status' => 'active'],
        ['label' => 'Inactive', 'icon' => 'clock', 'status' => 'inactive'],
        ['label' => 'Suspended', 'icon' => 'no-symbol', 'status' => 'suspended'],
    ];
@endphp

<div class="space-y-6">

    {{-- ============================ Metrics ============================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Total customers</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="users" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['total'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Active (30 days)</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                        <x-icon name="bolt" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['active'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Verified</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                        <x-icon name="check-circle" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['verified'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">New this month</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700">
                        <x-icon name="user-plus" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['new_this_month'] ?? 0) }}</p>
                <p class="mt-1 text-xs tabular-nums text-muted-foreground">{{ number_format($stats['total_today'] ?? 0) }} today</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Online now</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="signal" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['online_now'] ?? 0) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Active in last 5 minutes</p>
            </div>
        </div>
    </div>

    {{-- ============================ Filters =========================== --}}
    <div class="card" x-data="{ status: @json(request('status')) }">
        <div class="card-header">
            <h2 class="card-title">Search and filter</h2>
            <p class="card-description">Filters are applied server-side.</p>
        </div>

        <div class="card-content space-y-4">
            <form id="searchForm" method="GET" action="{{ route('admin.users.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div class="md:col-span-2">
                    <label class="label mb-2" for="searchInput">Search</label>
                    <input type="text" id="searchInput" name="search" value="{{ request('search') }}"
                           placeholder="Name, email or phone number" class="input" autocomplete="off">
                </div>

                <div>
                    <label class="label mb-2" for="statusSelect">Status</label>
                    <select id="statusSelect" name="status" class="select" x-model="status">
                        <option value="">All statuses</option>
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-1">
                        <x-icon name="magnifying-glass" class="h-4 w-4" />
                        Search
                    </button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-outline" aria-label="Reset filters">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                    </a>
                </div>
            </form>

            <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                <span class="text-sm text-muted-foreground">Quick filters:</span>
                @foreach($quickFilters as $filter)
                    <button type="button"
                            class="btn btn-outline btn-sm"
                            @click="status = '{{ $filter['status'] }}'; $nextTick(() => document.getElementById('searchForm').submit())">
                        <x-icon :name="$filter['icon']" class="h-3.5 w-3.5" />
                        {{ $filter['label'] }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- =========================== Customers ========================== --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="card-title">All customers</h2>
                <p class="card-description tabular-nums">
                    Showing {{ $users->firstItem() ?? 0 }}&ndash;{{ $users->lastItem() ?? 0 }} of {{ number_format($users->total()) }}
                </p>
            </div>

            @if(auth()->user()->is_super_admin)
                <a href="{{ route('admin.admins.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="user-plus" class="h-4 w-4" />
                    Add administrator
                </a>
            @endif
        </div>

        @if($users->count())
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Wallet balance</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($users as $user)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">{{ $user->name }}</p>
                                            <p class="text-xs tabular-nums text-muted-foreground">ID: #{{ str_pad($user->id, 6, '0', STR_PAD_LEFT) }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <p class="text-sm">{{ $user->email }}</p>
                                    <p class="text-xs text-muted-foreground">{{ $user->phone ?? 'No phone on file' }}</p>
                                </td>
                                <td class="font-semibold tabular-nums">&#8358;{{ number_format($user->wallet_balance ?? 0, 2) }}</td>
                                <td>
                                    <div class="flex flex-wrap items-center gap-1">
                                        @if($user->email_verified_at)
                                            <span class="badge badge-success">
                                                <x-icon name="check" class="h-3 w-3" />
                                                Verified
                                            </span>
                                        @else
                                            <span class="badge badge-warning">
                                                <x-icon name="clock" class="h-3 w-3" />
                                                Unverified
                                            </span>
                                        @endif

                                        @if($user->last_login_at && $user->last_login_at->diffInMinutes(now()) <= 5)
                                            <span class="badge badge-info">
                                                <x-icon name="bolt" class="h-3 w-3" />
                                                Online
                                            </span>
                                        @endif

                                        @if(($user->status ?? null) === 'suspended')
                                            <span class="badge badge-destructive">
                                                <x-icon name="no-symbol" class="h-3 w-3" />
                                                Suspended
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-sm text-muted-foreground">
                                    <span class="tabular-nums">{{ $user->created_at->format('M d, Y') }}</span><br>
                                    <span class="text-xs">{{ $user->created_at->diffForHumans() }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('admin.users.show', $user->id) }}" class="btn btn-outline btn-sm">
                                            <x-icon name="eye" class="h-4 w-4" />
                                            View
                                        </a>
                                        @if(auth()->user()->is_super_admin)
                                            <a href="{{ route('admin.admins.edit', $user->id) }}" class="btn btn-ghost btn-sm">
                                                <x-icon name="shield-check" class="h-4 w-4" />
                                                Make admin
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($users->hasPages())
                <div class="border-t border-border p-4">
                    {{ $users->withQueryString()->links() }}
                </div>
            @endif
        @else
            <div class="empty-state">
                <x-icon name="users" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No customers found</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    @if(request()->hasAny(['search', 'status', 'date_from', 'date_to']))
                        Try a different search or clear the filters.
                    @else
                        Customer accounts will appear here once people sign up.
                    @endif
                </p>
                @if(request()->hasAny(['search', 'status', 'date_from', 'date_to']))
                    <a href="{{ route('admin.users.index') }}" class="btn btn-outline btn-sm mt-4">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Clear filters
                    </a>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
