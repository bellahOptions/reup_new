<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AdminLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminController extends Controller
{
    // List all admins
    public function index()
{
    // Get admins with selected fields
    $admins = User::where('is_admin', true)
        ->orWhere('is_super_admin', true)
        ->orderBy('is_super_admin', 'desc')
        ->orderBy('created_at', 'desc')
        ->select([
            'id',
            'name',
            'email',
            'phone',
            'whatsapp', // Optional: if you want to include WhatsApp number
            'is_admin',
            'is_super_admin',
            'admin_role',
            'admin_permissions',
            'is_online',
            'last_login_at',
            'last_login_at',
            'created_at',
            'updated_at'
        ])
        ->paginate(20);

    // Get stats for dashboard cards
    $stats = [
        'total_admins' => User::where('is_admin', true)
            ->orWhere('is_super_admin', true)
            ->count(),
        'online_admins' => User::where(function($query) {
                $query->where('is_admin', true)
                      ->orWhere('is_super_admin', true);
            })
            ->where(function($query) {
                $query->where('is_online', true)
                      ->orWhere('last_login_at', '>=', now()->subMinutes(15));
            })
            ->count(),
        'super_admins' => User::where('is_super_admin', true)->count(),
        'active_admins' => User::where(function($query) {
                $query->where('is_admin', true)
                      ->orWhere('is_super_admin', true);
            })
            ->where('last_login_at', '>=', now()->subMinutes(15))
            ->count(),
    ];

    // Get role distribution
    $roleDistribution = [
        'Super Admin' => User::where('is_super_admin', true)->count(),
        'Admin Manager' => User::where('admin_role', 'Admin Manager')->count(),
        'Support Agent' => User::where('admin_role', 'Support Agent')->count(),
        'Finance Manager' => User::where('admin_role', 'Finance Manager')->count(),
        'Other' => User::where('is_admin', true)
            ->whereNull('admin_role')
            ->orWhere('admin_role', '')
            ->count(),
    ];

    // Get recent activity logs
    $recentLogs = AdminLog::with('user')
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();

    return view('admin.admins.index', compact(
        'admins', 
        'stats', 
        'roleDistribution', 
        'recentLogs'
    ));
}

    // Show create admin form
    public function create()
    {
        $defaultPermissions = $this->getDefaultPermissions();
        return view('admin.admins.create', compact('defaultPermissions'));
    }

    // Store new admin
    public function store(Request $request)
    {
        // Only super admin can create admins
        if (!auth()->user()->is_super_admin) {
            return redirect()->route('admin.admins.index')
                ->with('error', 'Only Super Admin can create new admins.');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'admin_role' => ['required', 'string', 'in:Super Admin,Admin Manager,Support Agent,Finance Manager'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:dashboard,users,transactions,bank-transfers,settings,chat']
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Create admin user
        $admin = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_admin' => true,
            'is_super_admin' => $request->admin_role === 'Super Admin',
            'admin_role' => $request->admin_role,
            'admin_permissions' => $request->permissions ?? $this->getDefaultPermissionsForRole($request->admin_role),
        ]);

        // Log the action
        AdminLog::log(
            auth()->id(),
            'admin_created',
            [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'role' => $admin->admin_role
            ]
        );

        return redirect()->route('admin.admins.index')
            ->with('success', 'Admin created successfully!');
    }

    // Show admin edit form
    public function edit(User $admin)
    {
        // Only super admin can edit admins
        if (!auth()->user()->is_super_admin) {
            return redirect()->route('admin.admins.index')
                ->with('error', 'Only Super Admin can edit admins.');
        }

        $defaultPermissions = $this->getDefaultPermissions();
        return view('admin.admins.edit', compact('admin', 'defaultPermissions'));
    }

    // Update admin
    public function update(Request $request, User $admin)
    {
        // Only super admin can update admins
        if (!auth()->user()->is_super_admin) {
            return redirect()->route('admin.admins.index')
                ->with('error', 'Only Super Admin can update admins.');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $admin->id],
            'admin_role' => ['required', 'string', 'in:Super Admin,Admin Manager,Support Agent,Finance Manager'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:dashboard,users,transactions,bank-transfers,settings,chat'],
            'is_active' => ['boolean']
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $oldRole = $admin->admin_role;

        $admin->update([
            'name' => $request->name,
            'email' => $request->email,
            'is_admin' => true,
            'is_super_admin' => $request->admin_role === 'Super Admin',
            'admin_role' => $request->admin_role,
            'admin_permissions' => $request->permissions ?? $this->getDefaultPermissionsForRole($request->admin_role),
            'is_online' => $request->has('is_active') ? $request->is_active : $admin->is_online,
        ]);

        // Update password if provided
        if ($request->filled('password')) {
            $admin->update([
                'password' => Hash::make($request->password)
            ]);
        }

        // Log the action
        AdminLog::log(
            auth()->id(),
            'admin_updated',
            [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'old_role' => $oldRole,
                'new_role' => $admin->admin_role
            ]
        );

        return redirect()->route('admin.admins.index')
            ->with('success', 'Admin updated successfully!');
    }

    // Delete admin
    public function destroy(User $admin)
    {
        // Prevent deleting self
        if ($admin->id === auth()->id()) {
            return redirect()->back()
                ->with('error', 'You cannot delete your own account.');
        }

        // Only super admin can delete admins
        if (!auth()->user()->is_super_admin) {
            return redirect()->route('admin.admins.index')
                ->with('error', 'Only Super Admin can delete admins.');
        }

        // Prevent deleting the last super admin
        if ($admin->is_super_admin) {
            $superAdminCount = User::where('is_super_admin', true)->count();
            if ($superAdminCount <= 1) {
                return redirect()->back()
                    ->with('error', 'Cannot delete the last Super Admin.');
            }
        }

        // Log the action before deletion
        AdminLog::log(
            auth()->id(),
            'admin_deleted',
            [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'role' => $admin->admin_role
            ]
        );

        $admin->delete();

        return redirect()->route('admin.admins.index')
            ->with('success', 'Admin deleted successfully!');
    }

    // Toggle admin status
    public function toggleStatus(User $admin)
    {
        // Only super admin can toggle status
        if (!auth()->user()->is_super_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $admin->update([
            'is_online' => !$admin->is_online
        ]);

        AdminLog::log(
            auth()->id(),
            'admin_status_toggled',
            [
                'admin_id' => $admin->id,
                'status' => $admin->is_online ? 'online' : 'offline'
            ]
        );

        return response()->json([
            'success' => true,
            'is_online' => $admin->is_online
        ]);
    }

    // Get default permissions for roles
    private function getDefaultPermissionsForRole($role)
    {
        $permissions = [
            'Super Admin' => ['*'], // All permissions
            'Admin Manager' => ['dashboard', 'users', 'admins', 'chat'],
            'Support Agent' => ['dashboard', 'users', 'chat'],
            'Finance Manager' => ['dashboard', 'transactions', 'bank-transfers']
        ];

        return $permissions[$role] ?? ['dashboard'];
    }

    // Get all available permissions
    private function getDefaultPermissions()
    {
        return [
            'dashboard' => 'Access Dashboard',
            'users' => 'Manage Users',
            'admins' => 'Manage Admins',
            'transactions' => 'Manage Transactions',
            'bank-transfers' => 'Manage Bank Transfers',
            'settings' => 'Access Settings',
            'chat' => 'Access Live Chat'
        ];
    }

    // Admin activity tracking
    public function updateActivity()
    {
        if (auth()->check() && auth()->user()->isAdmin()) {
            auth()->user()->update([
                'last_activity_at' => now(),
                'is_online' => true
            ]);
        }

        return response()->json(['success' => true]);
    }
}