@extends('admin.layouts.app')

@section('title', 'Edit Admin - ' . $admin->name)
@section('page-title', 'Edit Administrator Account')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Edit Administrator</h3>
                    <p class="text-sm text-gray-600 mt-1">Update {{ $admin->name }}'s account details</p>
                </div>
                <div class="flex items-center space-x-2">
                    @if($admin->is_online)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                        <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                        Online
                    </span>
                    @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                        <span class="w-2 h-2 bg-gray-400 rounded-full mr-2"></span>
                        Offline
                    </span>
                    @endif
                </div>
            </div>
        </div>

        <form action="{{ route('admin.admins.update', $admin, false) }}" method="POST">
            @csrf
            @method('PUT')
            
            <div class="space-y-6">
                <!-- Personal Information -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Personal Information</h4>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                        <input type="text" 
                               name="name" 
                               value="{{ old('name', $admin->name) }}"
                               required
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 @error('name') border-red-500 @enderror">
                        @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" 
                               name="email" 
                               value="{{ old('email', $admin->email) }}"
                               required
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 @error('email') border-red-500 @enderror">
                        @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Password Change -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Change Password</h4>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">New Password (optional)</label>
                        <input type="password" 
                               name="password" 
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 @error('password') border-red-500 @enderror"
                               placeholder="Leave blank to keep current password">
                        @error('password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                        <input type="password" 
                               name="password_confirmation" 
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="Confirm new password">
                    </div>
                </div>

                <!-- Role & Permissions -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Role & Permissions</h4>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Admin Role</label>
                        <select name="admin_role" 
                                required
                                class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 @error('admin_role') border-red-500 @enderror"
                                onchange="updatePermissions()">
                            <option value="Super Admin" {{ old('admin_role', $admin->admin_role) == 'Super Admin' ? 'selected' : '' }}>Super Admin</option>
                            <option value="Admin Manager" {{ old('admin_role', $admin->admin_role) == 'Admin Manager' ? 'selected' : '' }}>Admin Manager</option>
                            <option value="Support Agent" {{ old('admin_role', $admin->admin_role) == 'Support Agent' ? 'selected' : '' }}>Support Agent</option>
                            <option value="Finance Manager" {{ old('admin_role', $admin->admin_role) == 'Finance Manager' ? 'selected' : '' }}>Finance Manager</option>
                        </select>
                        @error('admin_role')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Permissions</label>
                        <div class="grid grid-cols-2 gap-3" id="permissionsContainer">
                            @php
                                $currentPermissions = is_array($admin->admin_permissions) ? $admin->admin_permissions : json_decode($admin->admin_permissions, true);
                                $currentPermissions = $currentPermissions ?: [];
                            @endphp
                            
                            @foreach($defaultPermissions as $permission => $label)
                            <label class="flex items-center space-x-2 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors">
                                <input type="checkbox" 
                                       name="permissions[]" 
                                       value="{{ $permission }}"
                                       class="text-green-600 focus:ring-green-500 rounded border-gray-300"
                                       {{ in_array($permission, old('permissions', $currentPermissions)) || in_array('*', $currentPermissions) ? 'checked' : '' }}>
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="flex items-center space-x-2">
                            <input type="checkbox" 
                                   name="is_active" 
                                   value="1"
                                   class="text-green-600 focus:ring-green-500 rounded border-gray-300"
                                   {{ old('is_active', $admin->is_online) ? 'checked' : '' }}>
                            <span class="text-sm text-gray-700">Set as active (online status)</span>
                        </label>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-between pt-6 border-t border-gray-200">
                    <a href="{{ route('admin.admins.index') }}" 
                       class="text-gray-700 hover:text-gray-900 font-medium flex items-center space-x-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        <span>Back to Admins</span>
                    </a>
                    
                    <div class="flex items-center space-x-3">
                        <a href="{{ route('admin.admins.index') }}" 
                           class="px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors">
                            Cancel
                        </a>
                        <button type="submit" 
                                class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-semibold py-2.5 px-6 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
                            Update Admin
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function updatePermissions() {
    const role = document.querySelector('select[name="admin_role"]').value;
    const permissions = document.querySelectorAll('input[name="permissions[]"]');
    
    // Reset all checkboxes
    permissions.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Set default permissions based on role
    const rolePermissions = {
        'Super Admin': ['*'],
        'Admin Manager': ['dashboard', 'users', 'admins', 'chat'],
        'Support Agent': ['dashboard', 'users', 'chat'],
        'Finance Manager': ['dashboard', 'transactions', 'bank-transfers']
    };
    
    if (rolePermissions[role]) {
        rolePermissions[role].forEach(permission => {
            if (permission === '*') {
                // Check all permissions for Super Admin
                permissions.forEach(checkbox => {
                    checkbox.checked = true;
                });
            } else {
                const checkbox = document.querySelector(`input[value="${permission}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            }
        });
    }
}
</script>
@endpush