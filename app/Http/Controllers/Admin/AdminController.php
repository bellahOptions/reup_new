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
use App\Models\TermsPrivacy;
use Illuminate\Support\Facades\Auth;
use App\Mail\TermsUpdated;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

   /**
 * Show Terms of Service editor
 */
public function editTerms()
{
    // Check if user is super admin
    if (!Auth::user()->is_super_admin) {
        abort(403, 'Only Super Admins can manage Terms of Service');
    }

    // Get active terms and privacy
    $terms = TermsPrivacy::getTerms();
    $privacy = TermsPrivacy::getPrivacy();
    

    return view('admin.edit-terms', compact('terms', 'privacy'));
}

/**
 * Update Terms of Service
 */
public function updateTerms(Request $request)
{
    // Check if user is super admin
    if (!Auth::user()->is_super_admin) {
        return response()->json([
            'success' => false,
            'message' => 'Only Super Admins can manage Terms of Service'
        ], 403);
    }

    $validator = Validator::make($request->all(), [
        'content' => 'required|string|min:50',
        'type' => 'required|in:terms,privacy'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        // Use the new method from model
        $document = TermsPrivacy::updateOrCreateDocument(
            $request->type,
            $request->content,
            Auth::id()
        );

        DB::commit();

        Log::info('Terms/Privacy updated', [
            'type' => $request->type,
            'updated_by' => Auth::user()->name,
            'content_length' => strlen($request->content),
            'version_date' => $document->version_date
        ]);

        // Send emails to all users (in background)
        $this->sendNotificationToAllUsers($document);

        return response()->json([
            'success' => true,
            'message' => $document->type_name . ' updated successfully',
            'data' => [
                'updated_at' => $document->updated_at->format('M d, Y h:i A'),
                'updated_by' => $document->updatedBy ? $document->updatedBy->name : 'System',
                'version_date' => $document->formatted_version_date,
                'statistics' => $document->statistics,
                'is_active' => $document->is_active,
                'notification_sent' => true
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Failed to update Terms/Privacy: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to update document. Please try again.',
            'error' => $e->getMessage()
        ], 500);
    }
}
public function getPrivacyForTerms(Request $request)
{
    // Always return JSON, even for errors
    $headers = ['Content-Type' => 'application/json'];
    
    // Check if user is authenticated
    if (!Auth::check()) {
        return response()->json([
            'success' => false,
            'message' => 'Authentication required',
            'error' => 'Unauthenticated'
        ], 401, $headers);
    }
    
    // Check if user is super admin
    if (!Auth::user()->is_super_admin) {
        return response()->json([
            'success' => false,
            'message' => 'Only Super Admins can access this data',
            'error' => 'Forbidden',
            'user_id' => Auth::id(),
            'user_name' => Auth::user()->name,
            'is_super_admin' => Auth::user()->is_super_admin
        ], 403, $headers);
    }
    
    try {
        $privacy = TermsPrivacy::where('type', 'privacy')
                              ->where('is_active', true)
                              ->first();
        
        if (!$privacy) {
            return response()->json([
                'success' => false,
                'message' => 'Privacy Policy not found',
                'error' => 'Not Found',
                'suggestion' => 'Create a privacy policy in admin panel first'
            ], 404, $headers);
        }
        
        return response()->json([
            'success' => true,
            'content' => $privacy->content,
            'meta' => [
                'id' => $privacy->id,
                'type' => $privacy->type,
                'version_date' => $privacy->version_date?->format('Y-m-d H:i:s'),
                'updated_at' => $privacy->updated_at?->format('Y-m-d H:i:s')
            ]
        ], 200, $headers);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Server error',
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null
        ], 500, $headers);
    }
}
  
/**
 * Send notification to all users about updated terms
 */
private function sendNotificationToAllUsers(TermsPrivacy $document): void
{
    try {
        // Get all users (active users only, you can adjust as needed)
        $users = User::where('status', 'active')
                    ->whereNotNull('email_verified_at')
                    ->chunk(100, function ($usersChunk) use ($document) {
                        foreach ($usersChunk as $user) {
                            // Send email in background using job or queue
                            Mail::to($user->email)->queue(new TermsUpdated($document, $user));
                        }
                    });

        Log::info('Terms update notification sent to users', [
            'document_type' => $document->type,
            'updated_by' => Auth::user()->name,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to send terms update notification: ' . $e->getMessage(), [
            'document_id' => $document->id,
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * Get Terms/Privacy preview
 */
public function previewTerms($type)
{
    $document = TermsPrivacy::getByTypeAndVersion($type);

    if (!$document) {
        abort(404, ucfirst($type) . ' not found');
    }

    return view('admin.terms.preview', [
        'document' => $document,
        'content' => $document->preview_content
    ]);
}


/**
 * Restore previous version
 */
public function restoreVersion(Request $request, $id)
{
    if (!Auth::user()->is_super_admin) {
        return response()->json([
            'success' => false,
            'message' => 'Only Super Admins can restore versions'
        ], 403);
    }

    try {
        $document = TermsPrivacy::restoreVersion($id, Auth::id());

        Log::info('Document version restored', [
            'document_id' => $id,
            'restored_by' => Auth::user()->name,
            'new_version_id' => $document->id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Version restored successfully',
            'data' => [
                'new_version_id' => $document->id,
                'version_date' => $document->formatted_version_date
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to restore version: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Failed to restore version',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Get document content via AJAX
 */
public function getDocument($type)
{
    $document = TermsPrivacy::getByTypeAndVersion($type);

    if (!$document) {
        return response()->json([
            'success' => false,
            'message' => ucfirst($type) . ' not found'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'type' => $document->type,
        'type_name' => $document->type_name,
        'content' => $document->content,
        'preview_content' => $document->preview_content,
        'version_date' => $document->formatted_version_date,
        'updated_at' => $document->updated_at->diffForHumans(),
        'updated_by' => $document->updatedBy ? $document->updatedBy->name : 'System',
        'statistics' => $document->statistics,
        'is_active' => $document->is_active
    ]);
}

/**
 * Toggle document active status
 */
public function toggleTermsStatus($id)
{
    if (!Auth::user()->is_super_admin) {
        return response()->json([
            'success' => false,
            'message' => 'Only Super Admins can toggle document status'
        ], 403);
    }

    try {
        $document = TermsPrivacy::findOrFail($id);
        $document->update(['is_active' => !$document->is_active]);

        $status = $document->is_active ? 'activated' : 'deactivated';

        Log::info('Document status toggled', [
            'document_id' => $id,
            'type' => $document->type,
            'new_status' => $status,
            'by_user' => Auth::user()->name
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Document ' . $status . ' successfully',
            'is_active' => $document->is_active,
            'type_name' => $document->type_name
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to toggle document status: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Failed to toggle document status',
            'error' => $e->getMessage()
        ], 500);
    }
}
}