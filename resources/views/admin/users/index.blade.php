@extends('admin.layouts.app')

@section('title', 'Users')
@section('page-title', 'User Management')

@section('content')
<div class="space-y-6">
    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Total Users</span>
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['total'] ?? 0 }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Active Users</span>
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['active'] ?? 0 }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Verified</span>
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['verified'] ?? 0 }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">New (30 days)</span>
                <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['new_users'] ?? 0 }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Online Now</span>
                <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['online_now'] ?? 0 }}</h3>
            <p class="text-xs text-gray-500 mt-1">Active in last 5 mins</p>
        </div>
    </div>
    
    <!-- Header with Add Admin Button -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-900">All Users</h2>
            <p class="text-gray-600 mt-1">Manage user accounts and permissions</p>
        </div>
        
        @if(auth()->user()->is_super_admin)
        <a href="{{ route('admin.admins.create') }}" 
           class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-2.5 rounded-lg font-semibold shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2">
            <span>👑</span>
            <span>Add Admin</span>
        </a>
        @endif
    </div>

    <!-- Filters & Search -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form id="searchForm" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div class="md:col-span-2 relative">
                <div class="relative">
                    <input type="text" 
                           id="searchInput" 
                           name="search" 
                           value="{{ request('search') }}" 
                           placeholder="Search by name, email, phone..." 
                           class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 pr-10"
                           autocomplete="off">
                    <div class="absolute right-3 top-2.5">
                        <div id="searchSpinner" class="hidden">
                            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-green-600"></div>
                        </div>
                        <button type="button" id="clearSearch" class="hidden text-gray-400 hover:text-gray-600">
                            ✕
                        </button>
                    </div>
                </div>
                
                <!-- Suggestions Dropdown -->
                <div id="suggestionsBox" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg hidden">
                    <div id="suggestionsList" class="max-h-60 overflow-y-auto"></div>
                    <div id="noSuggestions" class="hidden p-4 text-center text-gray-500">
                        No users found
                    </div>
                </div>
            </div>
            <div>
                <select name="status" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    <option value="">All Status</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div>
                <select name="verified" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    <option value="">All Verification</option>
                    <option value="1" {{ request('verified') == '1' ? 'selected' : '' }}>Verified</option>
                    <option value="0" {{ request('verified') == '0' ? 'selected' : '' }}>Unverified</option>
                </select>
            </div>
            <div class="flex space-x-2">
                <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg">Search</button>
                <a href="{{ route('admin.users.index') }}" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-2 px-4 rounded-lg">Reset</a>
            </div>
        </form>
        
        <!-- Quick Filters -->
        <div class="mt-4 flex flex-wrap gap-2">
            <span class="text-sm text-gray-600 mr-2">Quick filters:</span>
            <button type="button" onclick="setQuickFilter('', 'Verified users', 'verified', '1')" class="text-xs bg-green-100 text-green-800 hover:bg-green-200 px-3 py-1 rounded-full transition-colors">
                ✅ Verified
            </button>
            <button type="button" onclick="setQuickFilter('active', 'Active users', 'status', 'active')" class="text-xs bg-blue-100 text-blue-800 hover:bg-blue-200 px-3 py-1 rounded-full transition-colors">
                🟢 Active
            </button>
            <button type="button" onclick="setQuickFilter('inactive', 'Inactive users', 'status', 'inactive')" class="text-xs bg-yellow-100 text-yellow-800 hover:bg-yellow-200 px-3 py-1 rounded-full transition-colors">
                ⏸️ Inactive
            </button>
            <button type="button" onclick="setQuickFilter('new', 'New users (30 days)', 'date_from', '{{ date('Y-m-d', strtotime('-30 days')) }}')" class="text-xs bg-purple-100 text-purple-800 hover:bg-purple-200 px-3 py-1 rounded-full transition-colors">
                🆕 New Users
            </button>
            <button type="button" onclick="setQuickFilter('online', 'Online users', 'online', '1')" class="text-xs bg-emerald-100 text-emerald-800 hover:bg-emerald-200 px-3 py-1 rounded-full transition-colors">
                ⚡ Online Now
            </button>
        </div>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">User</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Contact</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Wallet Balance</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Joined</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($users ?? [] as $user)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="py-4 px-6">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full flex items-center justify-center text-white font-bold text-lg mr-3">
                                    {{ substr($user->name, 0, 1) }}
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900">{{ $user->name }}</p>
                                    <p class="text-xs text-gray-600">ID: #{{ str_pad($user->id, 6, '0', STR_PAD_LEFT) }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="py-4 px-6">
                            <p class="text-sm text-gray-900">{{ $user->email }}</p>
                            <p class="text-xs text-gray-600">{{ $user->phone ?? 'No phone' }}</p>
                        </td>
                        <td class="py-4 px-6">
                            <span class="text-lg font-bold text-green-600">₦{{ number_format($user->wallet_balance ?? 0, 2) }}</span>
                        </td>
                        <td class="py-4 px-6">
                            <div class="space-y-1">
                                @if($user->email_verified_at)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                        ✅ Verified
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">⏳ Unverified</span>
                                @endif
                                
                                @if($user->last_login_at && $user->last_login_at->diffInMinutes(now()) <= 5)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                                        ⚡ Online
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="py-4 px-6 text-sm text-gray-600">
                            {{ $user->created_at->format('M d, Y') }}<br>
                            <span class="text-xs text-gray-500">{{ $user->created_at->diffForHumans() }}</span>
                        </td>
                        <td class="py-4 px-6">
                            <div class="flex items-center space-x-2">
                                <a href="{{ route('admin.users.show', $user->id) }}" 
                                   class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-sm font-semibold transition-colors flex items-center space-x-1">
                                    <span>👁️</span>
                                    <span>View</span>
                                </a>
                                @if(auth()->user()->is_super_admin)
                                <a href="{{ route('admin.admins.edit', $user->id) }}" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-sm font-semibold transition-colors flex items-center space-x-1">
                                    <span>👑</span>
                                    <span>Make Admin</span>
                                </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-12 text-center">
                            <div class="flex flex-col items-center">
                                <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                                <p class="text-gray-600 font-medium">No users found</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($users) && $users->hasPages())
        <div class="p-6 border-t border-gray-200">
            {{ $users->links() }}
        </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
// ... existing JavaScript code for search suggestions ...

function setQuickFilter(type, text, filterName, filterValue) {
    // Update search input placeholder
    document.getElementById('searchInput').placeholder = `Search ${text}...`;
    
    // Set the filter value
    if (filterName === 'verified') {
        document.querySelector('select[name="verified"]').value = filterValue;
    } else if (filterName === 'status') {
        document.querySelector('select[name="status"]').value = filterValue;
    } else if (filterName === 'date_from') {
        // Add a hidden input for date filter
        let form = document.getElementById('searchForm');
        let existingDateInput = document.querySelector('input[name="date_from"]');
        if (!existingDateInput) {
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'date_from';
            input.value = filterValue;
            form.appendChild(input);
        } else {
            existingDateInput.value = filterValue;
        }
    } else if (filterName === 'online') {
        // Add online filter
        let form = document.getElementById('searchForm');
        let onlineInput = document.querySelector('input[name="online"]');
        if (!onlineInput) {
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'online';
            input.value = filterValue;
            form.appendChild(input);
        } else {
            onlineInput.value = filterValue;
        }
    }
    
    // Submit the form
    form.submit();
}
</script>
@endpush