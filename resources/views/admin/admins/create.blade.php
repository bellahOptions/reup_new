@extends('admin.layouts.app')

@section('title', 'Create New Admin')
@section('page-title', 'Create Administrator Account')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-surface rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="mb-6">
            <h3 class="text-lg font-bold text-gray-900">Create New Administrator</h3>
            <p class="text-sm text-gray-600 mt-1">Fill in the details below to create a new admin account</p>
        </div>

        <form action="{{ route('admin.admins.store', [], false) }}" method="POST">
            @csrf
            
            <div class="space-y-6">
                <!-- Personal Information -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Personal Information</h4>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                        <input type="text" 
                               name="name" 
                               value="{{ old('name') }}"
                               required
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 @error('name') border-red-500 @enderror"
                               placeholder="John Doe">
                        @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" 
                               name="email" 
                               value="{{ old('email') }}"
                               required
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 @error('email') border-red-500 @enderror"
                               placeholder="john@example.com">
                        @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Security -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Security</h4>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                        <input type="password" 
                               name="password" 
                               required
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 @error('password') border-red-500 @enderror"
                               placeholder="â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢">
                        <p class="mt-1 text-xs text-gray-500">Minimum 8 characters with letters and numbers</p>
                        @error('password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                        <input type="password" 
                               name="password_confirmation" 
                               required
                               class="w-full border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢">
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
                            <option value="">Select a role</option>
                            <option value="Super Admin" {{ old('admin_role') == 'Super Admin' ? 'selected' : '' }}>Super Admin</option>
                            <option value="Admin Manager" {{ old('admin_role') == 'Admin Manager' ? 'selected' : '' }}>Admin Manager</option>
                            <option value="Support Agent" {{ old('admin_role') == 'Support Agent' ? 'selected' : '' }}>Support Agent</option>
                            <option value="Finance Manager" {{ old('admin_role') == 'Finance Manager' ? 'selected' : '' }}>Finance Manager</option>
                        </select>
                        @error('admin_role')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Permissions</label>
                        <div class="grid grid-cols-2 gap-3" id="permissionsContainer">
                            @foreach($defaultPermissions as $permission => $label)
                            <label class="flex items-center space-x-2 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors">
                                <input type="checkbox" 
                                       name="permissions[]" 
                                       value="{{ $permission }}"
                                       class="text-success-soft-foreground focus:ring-green-500 rounded border-gray-300"
                                       {{ in_array($permission, old('permissions', [])) ? 'checked' : '' }}>
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Select permissions or use role defaults</p>
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
                        <button type="reset" 
                                class="px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors">
                            Reset
                        </button>
                        <button type="submit" 
                                class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-semibold py-2.5 px-6 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
                            Create Admin
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Role Permissions Info -->
    <div class="bg-surface rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
        <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Default Role Permissions</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-4 bg-purple-50 rounded-lg">
                <h5 class="font-medium text-purple-900 mb-2">Super Admin</h5>
                <p class="text-sm text-purple-700">Full system access including admin management and all permissions.</p>
            </div>
            <div class="p-4 bg-blue-50 rounded-lg">
                <h5 class="font-medium text-blue-900 mb-2">Admin Manager</h5>
                <p class="text-sm text-blue-700">Dashboard, user management, admin management, and chat access.</p>
            </div>
            <div class="p-4 bg-green-50 rounded-lg">
                <h5 class="font-medium text-green-900 mb-2">Support Agent</h5>
                <p class="text-sm text-green-700">Dashboard access, user management, and live chat support.</p>
            </div>
            <div class="p-4 bg-yellow-50 rounded-lg">
                <h5 class="font-medium text-yellow-900 mb-2">Finance Manager</h5>
                <p class="text-sm text-yellow-700">Dashboard, transaction management, and bank transfer processing.</p>
            </div>
        </div>
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