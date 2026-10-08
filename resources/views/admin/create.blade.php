@extends('admin.layouts.app')

@section('title', 'Add administrator')
@section('page-title', 'Add administrator')
@section('page-description', 'Create a new admin account with specific permissions.')

@section('page-actions')
    <a href="{{ route('admin.admins.index') }}" class="btn btn-outline btn-sm">
        <x-icon name="arrow-left" class="h-4 w-4" />
        Back to administrators
    </a>
@endsection

@section('content')
<div class="mx-auto max-w-3xl">
    <form action="{{ route('admin.admins.store', [], false) }}" method="POST" class="space-y-6">
        @csrf

        {{-- ===================== Basic information ====================== --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title flex items-center gap-2">
                    <x-icon name="user" class="h-4 w-4 text-ink-500" />
                    Basic information
                </h2>
                <p class="card-description">The administrator signs in with these credentials.</p>
            </div>

            <div class="card-content space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label for="name" class="label mb-2">Full name <span class="text-destructive">*</span></label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" class="input" required>
                        @error('name')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="label mb-2">Email address <span class="text-destructive">*</span></label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" class="input" required>
                        @error('email')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label for="password" class="label mb-2">Password <span class="text-destructive">*</span></label>
                        <input type="password" id="password" name="password" class="input" required>
                        @error('password')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="label mb-2">Confirm password <span class="text-destructive">*</span></label>
                        <input type="password" id="password_confirmation" name="password_confirmation" class="input" required>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ Role ============================ --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title flex items-center gap-2">
                    <x-icon name="shield-check" class="h-4 w-4 text-ink-500" />
                    Admin role
                </h2>
                <p class="card-description">The role determines the default permissions below.</p>
            </div>

            <div class="card-content">
                <label for="admin_role" class="label mb-2">Select role <span class="text-destructive">*</span></label>
                <select id="admin_role" name="admin_role" class="select" required>
                    <option value="">Select a role</option>
                    <option value="Super Admin" {{ old('admin_role') == 'Super Admin' ? 'selected' : '' }}>
                        Super Admin (full access)
                    </option>
                    <option value="Admin Manager" {{ old('admin_role') == 'Admin Manager' ? 'selected' : '' }}>
                        Admin Manager (users &amp; admins)
                    </option>
                    <option value="Support Agent" {{ old('admin_role') == 'Support Agent' ? 'selected' : '' }}>
                        Support Agent (chat &amp; users)
                    </option>
                    <option value="Finance Manager" {{ old('admin_role') == 'Finance Manager' ? 'selected' : '' }}>
                        Finance Manager (transactions &amp; funds)
                    </option>
                </select>
                @error('admin_role')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- ========================= Permissions ======================== --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title flex items-center gap-2">
                    <x-icon name="key" class="h-4 w-4 text-ink-500" />
                    Permissions
                </h2>
                <p class="card-description">Optional. Custom permissions override the role defaults.</p>
            </div>

            <div class="card-content">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($defaultPermissions as $key => $label)
                        <label for="permission_{{ $key }}" class="flex items-center gap-2 text-sm">
                            <input type="checkbox"
                                   id="permission_{{ $key }}"
                                   name="permissions[]"
                                   value="{{ $key }}"
                                   {{ in_array($key, old('permissions', [])) ? 'checked' : '' }}
                                   class="checkbox">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ============================ Notes =========================== --}}
        <div class="card">
            <div class="card-content">
                <div class="flex items-start gap-3">
                    <x-icon name="information-circle" class="mt-0.5 h-5 w-5 text-ink-400" />
                    <div>
                        <h3 class="text-sm font-medium">Before you save</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            <li>Super Admin has access to every feature.</li>
                            <li>The role determines the default permissions.</li>
                            <li>Custom permissions override the role defaults.</li>
                            <li>The administrator receives an email with their login credentials.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        {{-- =========================== Actions ========================== --}}
        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('admin.admins.index') }}" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="plus" class="h-4 w-4" />
                Create administrator
            </button>
        </div>
    </form>
</div>
@endsection
