@extends('admin.layouts.app')

@section('title', 'Admin Management')

@section('content')
<div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Admin Management</h1>
                    <p class="text-gray-600 mt-2">Manage admin accounts and permissions</p>
                </div>
                @if(auth()->user()->is_super_admin)
                <a href="{{ route('admin.admins.create') }}" 
                   class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-lg font-semibold shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2">
                    <span>➕</span>
                    <span>Add New Admin</span>
                </a>
                @endif
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Total Admins</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $admins->total() }}</p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl text-blue-600">👥</span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Online Now</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">
                            {{ User::where('is_online', true)->where(function($q) {
                                $q->where('is_admin', true)->orWhere('is_super_admin', true);
                            })->count() }}
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl text-green-600">🟢</span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Super Admins</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">
                            {{ User::where('is_super_admin', true)->count() }}
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl text-purple-600">👑</span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Active Sessions</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">
                            {{ User::where('last_activity_at', '>=', now()->subMinutes(5))
                                ->where(function($q) {
                                    $q->where('is_admin', true)->orWhere('is_super_admin', true);
                                })->count() }}
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl text-yellow-600">⚡</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admins Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">All Admins</h2>
                    <div class="flex items-center space-x-4">
                        <div class="relative">
                            <input type="text" placeholder="Search admins..." 
                                   class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <span class="absolute left-3 top-2.5 text-gray-400">🔍</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Admin
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Role & Permissions
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Status
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Last Activity
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($admins as $admin)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <div class="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                                            {{ strtoupper(substr($admin->name, 0, 1)) }}
                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ $admin->name }}
                                            @if($admin->is_super_admin)
                                            <span class="ml-2 px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded-full">👑 Super</span>
                                            @endif
                                        </div>
                                        <div class="text-sm text-gray-500">{{ $admin->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900 font-medium">{{ $admin->admin_role }}</div>
                                @if($admin->admin_permissions)
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($admin->admin_permissions as $permission)
                                    <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">
                                        {{ $permission }}
                                    </span>
                                    @endforeach
                                </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <span class="w-2 h-2 rounded-full mr-2 {{ $admin->is_online ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                    <span class="text-sm {{ $admin->is_online ? 'text-green-600' : 'text-gray-600' }}">
                                        {{ $admin->is_online ? 'Online' : 'Offline' }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    @if($admin->last_activity_at)
                                    {{ $admin->last_activity_at->diffForHumans() }}
                                    @else
                                    <span class="text-gray-400">Never</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-3">
                                    <a href="{{ route('admin.admins.edit', $admin) }}" 
                                       class="text-blue-600 hover:text-blue-900">
                                        <span class="flex items-center">
                                            <span class="mr-1">✏️</span>
                                            Edit
                                        </span>
                                    </a>
                                    
                                    @if(auth()->user()->is_super_admin && $admin->id !== auth()->id())
                                    <button onclick="toggleStatus({{ $admin->id }}, this)" 
                                            class="text-gray-600 hover:text-gray-900">
                                        <span class="flex items-center">
                                            <span class="mr-1" id="statusIcon{{ $admin->id }}">
                                                {{ $admin->is_online ? '⏸️' : '▶️' }}
                                            </span>
                                            {{ $admin->is_online ? 'Pause' : 'Activate' }}
                                        </span>
                                    </button>
                                    
                                    <form action="{{ route('admin.admins.destroy', $admin) }}" 
                                          method="POST" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" 
                                                onclick="return confirm('Are you sure you want to delete this admin?')"
                                                class="text-red-600 hover:text-red-900">
                                            <span class="flex items-center">
                                                <span class="mr-1">🗑️</span>
                                                Delete
                                            </span>
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

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $admins->links() }}
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function toggleStatus(adminId, button) {
    fetch(`/admin/admins/${adminId}/toggle-status`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const statusIcon = document.getElementById(`statusIcon${adminId}`);
            const row = button.closest('tr');
            const statusSpan = row.querySelector('.w-2');
            
            if (data.is_online) {
                statusIcon.textContent = '⏸️';
                button.innerHTML = '<span class="flex items-center"><span class="mr-1">⏸️</span>Pause</span>';
                statusSpan.classList.remove('bg-gray-400');
                statusSpan.classList.add('bg-green-500');
                row.querySelector('.text-sm').textContent = 'Online';
                row.querySelector('.text-sm').classList.remove('text-gray-600');
                row.querySelector('.text-sm').classList.add('text-green-600');
            } else {
                statusIcon.textContent = '▶️';
                button.innerHTML = '<span class="flex items-center"><span class="mr-1">▶️</span>Activate</span>';
                statusSpan.classList.remove('bg-green-500');
                statusSpan.classList.add('bg-gray-400');
                row.querySelector('.text-sm').textContent = 'Offline';
                row.querySelector('.text-sm').classList.remove('text-green-600');
                row.querySelector('.text-sm').classList.add('text-gray-600');
            }
        }
    });
}

// Update activity every minute
setInterval(() => {
    fetch('/admin/update-activity', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    });
}, 60000);
</script>
@endsection