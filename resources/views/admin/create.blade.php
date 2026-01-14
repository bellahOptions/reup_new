@extends('admin.layouts.app')

@section('title', 'Add New Admin')

@section('content')
<div class="py-6">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-blue-100">
                <div class="flex items-center">
                    <a href="{{ route('admin.admins.index') }}" class="text-blue-600 hover:text-blue-800 mr-4">
                        <span class="text-xl">←</span>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Add New Admin</h1>
                        <p class="text-gray-600 mt-1">Create a new admin account with specific permissions</p>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <form action="{{ route('admin.admins.store') }}" method="POST" class="p-6 space-y-6">
                @csrf
                
                <!-- Basic Information -->
                <div class="space-y-4">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <span class="mr-2">👤</span>
                        Basic Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Name -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                                Full Name *
                            </label>
                            <input type="text" id="name" name="name" 
                                   value="{{ old('name') }}"
                                   class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
                                   required>
                            @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                                Email Address *
                            </label>
                            <input type="email" id="email" name="email" 
                                   value="{{ old('email') }}"
                                   class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
                                   required>
                            @error('email')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                                Password *
                            </label>
                            <input type="password" id="password" name="password" 
                                   class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
                                   required>
                            @error('password')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">
                                Confirm Password *
                            </label>
                            <input type="password" id="password_confirmation" name="password_confirmation" 
                                   class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
                                   required>
                        </div>
                    </div>
                </div>

                <!-- Admin Role -->
                <div class="space-y-4">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <span class="mr-2">👑</span>
                        Admin Role
                    </h2>
                    
                    <div>
                        <label for="admin_role" class="block text-sm font-medium text-gray-700 mb-2">
                            Select Role *
                        </label>
                        <select id="admin_role" name="admin_role" 
                                class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
                                required>
                            <option value="">Select a role</option>
                            <option value="Super Admin" {{ old('admin_role') == 'Super Admin' ? 'selected' : '' }}>
                                👑 Super Admin (Full Access)
                            </option>
                            <option value="Admin Manager" {{ old('admin_role') == 'Admin Manager' ? 'selected' : '' }}>
                                👨‍💼 Admin Manager (Users & Admins)
                            </option>
                            <option value="Support Agent" {{ old('admin_role') == 'Support Agent' ? 'selected' : '' }}>
                                💬 Support Agent (Chat & Users)
                            </option>
                            <option value="Finance Manager" {{ old('admin_role') == 'Finance Manager' ? 'selected' : '' }}>
                                💰 Finance Manager (Transactions & Funds)
                            </option>
                        </select>
                        @error('admin_role')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Permissions -->
                <div class="space-y-4">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <span class="mr-2">🔐</span>
                        Permissions
                    </h2>
                    <p class="text-sm text-gray-600">Select specific permissions for this admin (optional)</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach($defaultPermissions as $key => $label)
                        <div class="flex items-center space-x-2">
                            <input type="checkbox" id="permission_{{ $key }}" 
                                   name="permissions[]" value="{{ $key }}"
                                   {{ in_array($key, old('permissions', [])) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <label for="permission_{{ $key }}" class="text-sm text-gray-700">
                                {{ $label }}
                            </label>
                        </div>
                        @endforeach
                    </div>
                </div>

                <!-- Notes -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <span class="text-yellow-500">💡</span>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">Important Notes</h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <ul class="list-disc pl-5 space-y-1">
                                    <li>Super Admin has access to all features</li>
                                    <li>Role determines default permissions</li>
                                    <li>Custom permissions override role defaults</li>
                                    <li>Admin will receive email with login credentials</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end space-x-3 pt-6 border-t border-gray-200">
                    <a href="{{ route('admin.admins.index') }}" 
                       class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancel
                    </a>
                    <button type="submit" 
                            class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2">
                        <span>➕</span>
                        <span>Create Admin</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection