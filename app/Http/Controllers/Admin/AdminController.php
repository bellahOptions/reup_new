<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AdminLog;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules;
use App\Models\TermsPrivacy;
use Illuminate\Support\Facades\Auth;
use App\Mail\TermsUpdated;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    /**
     * Human labels for the permission checkboxes.
     * Sourced from the shared registry so the create/edit screens and the
     * route middleware can never disagree about what a permission means.
     */
    private function getDefaultPermissions(): array
    {
        return Permissions::LABELS;
    }

    private function getDefaultPermissionsForRole(string $role): array
    {
        return Permissions::defaultsForRole($role);
    }

    private function roles(): array
    {
        return Permissions::roles();
    }

    // List all admins
    public function index()
    {
        $admins = User::admins()
            ->orderByDesc('is_super_admin')
            ->orderByDesc('created_at')
            ->paginate(20);

        $stats = [
            'total_admins' => User::admins()->count(),
            'online_admins' => User::admins()->where('is_online', true)->count(),
            'super_admins' => User::where('is_super_admin', true)->count(),
            'active_admins' => User::admins()
                ->where('last_activity', '>=', now()->subMinutes(15))
                ->count(),
        ];

        // Role distribution, read from the same registry the routes enforce.
        $roleDistribution = collect(Permissions::roles())
            ->mapWithKeys(fn ($role) => [ucfirst($role) => User::where('admin_role', $role)->count()])
            ->put('Super admin', User::where('is_super_admin', true)->count())
            ->all();

        $recentLogs = AdminLog::with('user')
            ->latest()
            ->limit(10)
            ->get();

        return view('admin.admins.index', compact('admins', 'stats', 'roleDistribution', 'recentLogs'));
    }

    // Show create admin form
    public function create()
    {
        return view('admin.admins.create', [
            'defaultPermissions' => $this->getDefaultPermissions(),
            'roles' => $this->roles(),
            'roleDefaults' => collect($this->roles())
                ->mapWithKeys(fn ($role) => [$role => Permissions::defaultsForRole($role)])
                ->all(),
        ]);
    }

    // Store new admin
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::min(12)],
            'admin_role' => ['required', 'string', 'in:' . implode(',', Permissions::roles())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', Permissions::ALL)],
        ]);

        // A super admin may only be created by another super admin, and only
        // ever through this screen.
        $admin = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_admin' => true,
            'is_super_admin' => false,
            'admin_role' => $validated['admin_role'],
            'admin_permissions' => $validated['permissions']
                ?? Permissions::defaultsForRole($validated['admin_role']),
            'email_verified_at' => now(),
        ]);

        AdminLog::log(Auth::id(), 'admin_created', [
            'admin_id' => $admin->id,
            'role' => $admin->admin_role,
            'permissions' => $admin->admin_permissions,
        ]);

        return redirect()->route('admin.admins.index')
            ->with('success', 'Administrator created.');
    }

    // Show admin edit form
    public function edit(User $admin)
    {
        abort_unless($admin->isAdmin(), 404);

        return view('admin.admins.edit', [
            'admin' => $admin,
            'defaultPermissions' => $this->getDefaultPermissions(),
            'roles' => $this->roles(),
        ]);
    }

    // Update admin
    public function update(Request $request, User $admin)
    {
        abort_unless($admin->isAdmin(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $admin->id],
            'admin_role' => ['required', 'string', 'in:' . implode(',', Permissions::roles())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', Permissions::ALL)],
            'password' => ['nullable', 'confirmed', Rules\Password::min(12)],
        ]);

        // Guard the last remaining super admin: demoting or deactivating the
        // only one would lock everybody out of admin management permanently.
        if ($admin->is_super_admin && $admin->id === Auth::id() && User::where('is_super_admin', true)->count() === 1) {
            unset($validated['admin_role']);
        }

        $oldRole = $admin->admin_role;

        $admin->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'admin_role' => $validated['admin_role'] ?? $admin->admin_role,
            'admin_permissions' => $validated['permissions']
                ?? Permissions::defaultsForRole($validated['admin_role'] ?? $admin->admin_role),
        ]);

        if (! empty($validated['password'])) {
            $admin->password = Hash::make($validated['password']);
        }

        // Changing the sign-in address invalidates the verification it carried.
        if ($admin->isDirty('email')) {
            $admin->email_verified_at = null;
        }

        $admin->save();

        AdminLog::log(Auth::id(), 'admin_updated', [
            'admin_id' => $admin->id,
            'old_role' => $oldRole,
            'new_role' => $admin->admin_role,
        ]);

        return redirect()->route('admin.admins.index')
            ->with('success', 'Administrator updated.');
    }

    // Delete admin
    public function destroy(User $admin)
    {
        abort_unless($admin->isAdmin(), 404);

        if ($admin->id === Auth::id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($admin->is_super_admin && User::where('is_super_admin', true)->count() <= 1) {
            return back()->with('error', 'You cannot delete the last super admin.');
        }

        AdminLog::log(Auth::id(), 'admin_deleted', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'role' => $admin->admin_role,
        ]);

        $admin->delete();

        return redirect()->route('admin.admins.index')
            ->with('success', 'Administrator removed.');
    }

    // Toggle admin availability
    public function toggleStatus(User $admin)
    {
        abort_unless($admin->isAdmin(), 404);

        if ($admin->id === Auth::id()) {
            return response()->json(['error' => 'You cannot change your own availability here.'], 422);
        }

        $admin->forceFill(['is_online' => ! $admin->is_online])->save();

        AdminLog::log(Auth::id(), 'admin_status_toggled', [
            'admin_id' => $admin->id,
            'is_online' => $admin->is_online,
        ]);

        return response()->json(['success' => true, 'is_online' => $admin->is_online]);
    }

    // Admin activity tracking
    public function updateActivity()
    {
        if (Auth::check() && Auth::user()->isAdmin()) {
            Auth::user()->forceFill([
                'last_activity_at' => now(),
                'last_activity' => now(),
                'is_online' => true,
            ])->saveQuietly();
        }

        return response()->json(['success' => true]);
    }

   /**
 * Show Terms of Service editor
 */
public function editTerms()
{
    // Authorisation is enforced by the route group (`admin:manage_settings`).
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
    // Gated by `admin:manage_settings` on the route.
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
 * Version history for a document.
 */
public function termsHistory(string $type)
{
    $type = TermsPrivacy::assertValidType($type);

    return response()->json([
        'success' => true,
        'type' => $type,
        'versions' => TermsPrivacy::history($type)->map(fn ($version) => [
            'id' => $version->id,
            'version_date' => \Illuminate\Support\Carbon::parse($version->version_date)->format('F j, Y g:i A'),
            'updated_by' => $version->updated_by,
            'checksum' => substr($version->checksum, 0, 12),
            'excerpt' => \Illuminate\Support\Str::limit(trim(strip_tags($version->content)), 160),
        ]),
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
public function getDocument(string $type)
{
    $type = TermsPrivacy::assertValidType($type);
    $document = TermsPrivacy::getByTypeAndVersion($type);

    if (!$document) {
        return response()->json([
            'success' => false,
            'message' => ucfirst($type) . ' has not been published yet.',
        ], 404);
    }

    return response()->json([
        'success' => true,
        'type' => $document->type,
        'type_name' => $document->type_name,
        'content' => $document->content,
        'preview_content' => $document->preview_content,
        'version_date' => $document->formatted_version_date,
        'updated_at' => $document->updated_at?->diffForHumans() ?? 'never',
        'updated_by' => $document->updatedBy?->name ?? 'System',
        'statistics' => $document->statistics,
        'is_active' => $document->is_active
    ]);
}

/**
 * Toggle document active status
 */
public function toggleTermsStatus($id)
{
    try {
        $document = TermsPrivacy::findOrFail($id);
        $document = TermsPrivacy::setActive($document->id, ! $document->is_active);

        $status = $document->is_active ? 'activated' : 'deactivated';

        Log::info('Document status toggled', [
            'document_id' => $document->id,
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

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json(['success' => false, 'message' => 'Document not found'], 404);
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