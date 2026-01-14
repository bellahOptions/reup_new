@extends('admin.layouts.app')

@section('title', 'Admin Management')
@section('page-title', 'Administrator Accounts')

@section('content')
<div class="space-y-6">
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Total Admins</span>
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $admins->total() }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Online Now</span>
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $admins->where('is_online', true)->count() }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Super Admins</span>
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $admins->where('is_super_admin', true)->count() }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Active Sessions</span>
                <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $admins->where('last_activity_at', '>=', now()->subMinutes(15))->count() }}</h3>
        </div>
    </div>

    <!-- Header and Actions -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Administrator Accounts</h3>
                <p class="text-sm text-gray-600 mt-1">Manage system administrators and their permissions</p>
            </div>
            @if(auth()->user()->is_super_admin)
            <div class="flex items-center space-x-3">
                <a href="{{ route('admin.admins.create') }}" 
                   class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-semibold py-2.5 px-6 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    <span>Add New Admin</span>
                </a>
            </div>
            @endif
        </div>

        <!-- Filters -->
        <div class="mb-6">
            <div class="flex items-center space-x-4">
                <div class="relative flex-1">
                    <input type="text" 
                           id="searchInput" 
                           placeholder="Search by name, email, or role..." 
                           class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                           onkeyup="searchAdmins()">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <select id="roleFilter" 
                        class="border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                        onchange="filterAdmins()">
                    <option value="">All Roles</option>
                    <option value="Super Admin">Super Admin</option>
                    <option value="Admin Manager">Admin Manager</option>
                    <option value="Support Agent">Support Agent</option>
                    <option value="Finance Manager">Finance Manager</option>
                </select>
                <select id="statusFilter" 
                        class="border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                        onchange="filterAdmins()">
                    <option value="">All Status</option>
                    <option value="online">Online</option>
                    <option value="offline">Offline</option>
                </select>
            </div>
        </div>

        <!-- Admins Table -->
<div class="overflow-x-auto">
    <table class="w-full">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Admin</th>
                <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Contact Info</th>
                <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Role & Permissions</th>
                <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Status</th>
                <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Last Active</th>
                <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Created</th>
                <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200" id="adminsTableBody">
            @forelse($admins as $admin)
            <tr class="hover:bg-gray-50 transition-colors admin-row" 
                data-name="{{ strtolower($admin->name) }}"
                data-email="{{ strtolower($admin->email) }}"
                data-phone="{{ strtolower($admin->phone ?? '') }}"
                data-role="{{ $admin->admin_role }}"
                data-status="{{ $admin->is_online ? 'online' : 'offline' }}">
                
                <td class="py-4 px-6">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-br 
                            @if($admin->is_super_admin) from-purple-500 to-purple-600
                            @elseif($admin->admin_role === 'Admin Manager') from-blue-500 to-blue-600
                            @elseif($admin->admin_role === 'Support Agent') from-green-500 to-green-600
                            @elseif($admin->admin_role === 'Finance Manager') from-yellow-500 to-yellow-600
                            @else from-gray-500 to-gray-600
                            @endif
                            rounded-full flex items-center justify-center text-white font-bold text-sm mr-3">
                            {{ substr($admin->name, 0, 1) }}
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">{{ $admin->name }}</p>
                            <p class="text-xs text-gray-600">{{ $admin->email }}</p>
                            @if($admin->is_super_admin)
                            <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                                Super Admin
                            </span>
                            @endif
                        </div>
                    </div>
                </td>
                
                <td class="py-4 px-6">
                    <div class="space-y-1">
                        @if($admin->phone)
                        <div class="flex items-center">
                            <svg class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            <span class="text-sm text-gray-900">{{ $admin->phone }}</span>
                        </div>
                        @else
                        <span class="text-xs text-gray-500 italic">No phone</span>
                        @endif
                        
                        @if($admin->whatsapp && $admin->whatsapp !== $admin->phone)
                        <div class="flex items-center">
                            <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.76.982.999-3.675-.236-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.9 6.994c-.004 5.45-4.438 9.88-9.888 9.88m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.333.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.333 11.893-11.893 0-3.18-1.24-6.162-3.495-8.411"/>
                            </svg>
                            <span class="text-sm text-gray-900">{{ $admin->whatsapp }}</span>
                        </div>
                        @endif
                    </div>
                </td>
                
                <td class="py-4 px-6">
                    <div>
                        <p class="font-medium text-gray-900">{{ $admin->admin_role }}</p>
                        <div class="flex flex-wrap gap-1 mt-2">
                            @php
                                $permissions = is_array($admin->admin_permissions) ? $admin->admin_permissions : json_decode($admin->admin_permissions, true);
                                $permissionLabels = [
                                    'dashboard' => 'Dashboard',
                                    'users' => 'Users',
                                    'admins' => 'Admins',
                                    'transactions' => 'Transactions',
                                    'bank-transfers' => 'Bank Transfers',
                                    'settings' => 'Settings',
                                    'chat' => 'Live Chat',
                                    '*' => 'All Permissions'
                                ];
                            @endphp
                            
                            @if($permissions)
                                @if(in_array('*', $permissions))
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                        All Permissions
                                    </span>
                                @else
                                    @foreach($permissions as $permission)
                                        @if(isset($permissionLabels[$permission]))
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ $permissionLabels[$permission] }}
                                        </span>
                                        @endif
                                    @endforeach
                                @endif
                            @else
                                <span class="text-xs text-gray-500">No specific permissions</span>
                            @endif
                        </div>
                    </div>
                </td>
                
                <td class="py-4 px-6">
                    @if($admin->is_online)
                    <div class="flex items-center">
                        <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                        <span class="text-sm font-medium text-green-700">Online</span>
                    </div>
                    @else
                    <div class="flex items-center">
                        <span class="w-2 h-2 bg-gray-400 rounded-full mr-2"></span>
                        <span class="text-sm font-medium text-gray-600">Offline</span>
                    </div>
                    @endif
                    @if($admin->last_activity_at)
                    <p class="text-xs text-gray-500 mt-1">
                        {{ $admin->last_activity_at->diffForHumans() }}
                    </p>
                    @endif
                </td>
                
                <td class="py-4 px-6">
                    @if($admin->last_activity_at)
                    <div class="text-sm text-gray-900">{{ $admin->last_activity_at->format('M d, Y') }}</div>
                    <div class="text-xs text-gray-500">{{ $admin->last_activity_at->format('h:i A') }}</div>
                    @else
                    <span class="text-sm text-gray-500">Never</span>
                    @endif
                </td>
                
                <td class="py-4 px-6">
                    <div class="text-sm text-gray-900">{{ $admin->created_at->format('M d, Y') }}</div>
                    <div class="text-xs text-gray-500">{{ $admin->created_at->diffForHumans() }}</div>
                </td>
                
                <td class="py-4 px-6">
                    <div class="flex items-center space-x-2">
                        @if(auth()->user()->is_super_admin && $admin->id !== auth()->id())
                        <button onclick="toggleAdminStatus({{ $admin->id }})" 
                                class="text-sm px-3 py-1.5 rounded-lg transition-colors
                                       @if($admin->is_online) 
                                       bg-red-50 text-red-700 hover:bg-red-100 
                                       @else 
                                       bg-green-50 text-green-700 hover:bg-green-100 
                                       @endif">
                            @if($admin->is_online)
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-12.728 12.728M5.636 5.636l12.728 12.728"/>
                                </svg>
                                Deactivate
                            @else
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z"/>
                                </svg>
                                Activate
                            @endif
                        </button>
                        @endif
                        
                        @if(auth()->user()->is_super_admin)
                        <a href="{{ route('admin.admins.edit', $admin) }}" 
                           class="text-sm px-3 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-lg transition-colors">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            Edit
                        </a>
                        @endif
                        
                        @if(auth()->user()->is_super_admin && $admin->id !== auth()->id())
                        <form action="{{ route('admin.admins.destroy', $admin) }}" 
                              method="POST" 
                              onsubmit="return confirmDelete()" 
                              class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" 
                                    class="text-sm px-3 py-1.5 bg-red-50 text-red-700 hover:bg-red-100 rounded-lg transition-colors">
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                Delete
                            </button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="py-12 text-center">
                    <div class="flex flex-col items-center">
                        <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <p class="text-gray-600 font-medium">No administrators found</p>
                        <p class="text-sm text-gray-500 mt-1">Click "Add New Admin" to create the first administrator</p>
                    </div>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

        <!-- Pagination -->
        @if($admins->hasPages())
        <div class="pt-6 border-t border-gray-200">
            {{ $admins->links() }}
        </div>
        @endif
    </div>

    <!-- Role Distribution Chart -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Role Distribution</h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            @php
                $roles = [
                    'Super Admin' => ['count' => $admins->where('is_super_admin', true)->count(), 'color' => 'bg-purple-500'],
                    'Admin Manager' => ['count' => $admins->where('admin_role', 'Admin Manager')->count(), 'color' => 'bg-blue-500'],
                    'Support Agent' => ['count' => $admins->where('admin_role', 'Support Agent')->count(), 'color' => 'bg-green-500'],
                    'Finance Manager' => ['count' => $admins->where('admin_role', 'Finance Manager')->count(), 'color' => 'bg-yellow-500'],
                ];
            @endphp
            
            @foreach($roles as $role => $data)
            <div class="text-center">
                <div class="mb-2">
                    <div class="w-16 h-16 {{ $data['color'] }} rounded-full flex items-center justify-center text-white font-bold text-lg mx-auto">
                        {{ $data['count'] }}
                    </div>
                </div>
                <p class="text-sm font-medium text-gray-700">{{ $role }}</p>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Activity Log -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="text-lg font-bold text-gray-900 mb-4">Recent Admin Activity</h3>
    <div class="space-y-4 max-h-96 overflow-y-auto">
        @php
            $recentLogs = \App\Models\AdminLog::with('user')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        @endphp
        
        @forelse($recentLogs as $log)
        <div class="flex items-start space-x-3 p-3 hover:bg-gray-50 rounded-lg transition-colors">
            <div class="w-8 h-8 {{ $log->user && $log->user->is_super_admin ? 'bg-purple-100 text-purple-600' : 'bg-blue-100 text-blue-600' }} rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0">
                {{ $log->user ? substr($log->user_id->name, 0, 1) : '?' }}
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-900">
                        {{ $log->user ? $log->user->name : 'Unknown Admin' }}
                    </p>
                    <span class="text-xs text-gray-500">{{ $log->created_at->diffForHumans() }}</span>
                </div>
                <p class="text-sm text-gray-600 mt-1">
                    {{ ucfirst(str_replace('_', ' ', $log->action)) }}
                </p>
                @if($log->details && is_array($log->details))
<p class="text-xs text-gray-500 mt-1 truncate">
    @php
        $detailText = '';
        $details = $log->details;
        if (is_array($details)) {
            $detailText = implode(', ', array_map(function($key, $value) {
                return "$key: $value";
            }, array_keys($details), $details));
        }
    @endphp
    {{ $detailText }}
</p>
@endif
            </div>
        </div>
        @empty
        <div class="text-center py-8">
            <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-gray-600 text-sm">No recent activity</p>
        </div>
        @endforelse
    </div>
</div>
</div>
@endsection

@push('scripts')
<script>
function searchAdmins() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.admin-row');
    
    rows.forEach(row => {
        const name = row.getAttribute('data-name');
        const email = row.getAttribute('data-email');
        const phone = row.getAttribute('data-phone');
        
        if (name.includes(searchTerm) || 
            email.includes(searchTerm) || 
            phone.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterAdmins() {
    const roleFilter = document.getElementById('roleFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('.admin-row');
    
    rows.forEach(row => {
        const role = row.getAttribute('data-role');
        const status = row.getAttribute('data-status');
        
        const roleMatch = !roleFilter || role === roleFilter;
        const statusMatch = !statusFilter || status === statusFilter;
        
        if (roleMatch && statusMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function toggleAdminStatus(adminId) {
    if (!confirm('Are you sure you want to change this admin\'s status?')) {
        return;
    }
    
    fetch(`/admin/admins/${adminId}/toggle-status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to update admin status');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update admin status');
    });
}

function confirmDelete() {
    return confirm('Are you sure you want to delete this admin? This action cannot be undone.');
}

// Auto-refresh status every 30 seconds
setInterval(() => {
    fetch('{{ route("admin.update.activity") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    });
}, 30000);

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Set initial filter values from URL params
    const urlParams = new URLSearchParams(window.location.search);
    const role = urlParams.get('role');
    const status = urlParams.get('status');
    
    if (role) {
        document.getElementById('roleFilter').value = role;
    }
    if (status) {
        document.getElementById('statusFilter').value = status;
    }
    
    if (role || status) {
        filterAdmins();
    }
});
</script>
@endpush