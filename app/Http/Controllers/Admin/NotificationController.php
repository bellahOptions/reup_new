<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\PromotionNotification;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class NotificationController extends Controller
{
    // Get unread notifications (Contact Messages only)
public function getUnreadNotifications()
{
    // Get unread contact messages count
    $unreadContacts = ContactMessage::where('is_read', false)->count();
    
    // Format notifications
    $notifications = [];
    
    // Recent contact notifications
    $recentContacts = ContactMessage::where('is_read', false)
        ->with('user')
        ->latest()
        ->limit(10)
        ->get();
    
    foreach ($recentContacts as $contact) {
        $notifications[] = [
            'id' => 'contact_' . $contact->id,
            'type' => 'contact',
            'title' => $contact->subject ?: 'New Contact Message',
            'message' => 'From ' . ($contact->user ? $contact->user->name : ($contact->name ?: 'Anonymous')),
            'email' => $contact->email,
            'icon' => '📧',
            'url' => route('admin.contact.show', $contact->id),
            'is_read' => false,
            'time' => $contact->created_at->diffForHumans(),
            'timestamp' => $contact->created_at->timestamp
        ];
    }
    
    // Sort by timestamp (newest first)
    usort($notifications, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return response()->json([
        'notifications' => array_slice($notifications, 0, 10),
        'total_unread' => $unreadContacts,
        'unread_contacts' => $unreadContacts,
        'new_notifications' => count($notifications)
    ]);
}
    
    // Mark notifications as read
    public function markAsRead(Request $request)
    {
        $admin = Auth::user();
        
        if ($request->has('type') && $request->has('id')) {
            // Mark specific notification as read
            if ($request->type === 'chat') {
                $message = ChatMessage::findOrFail($request->id);
                $message->markAsRead();
            } elseif ($request->type === 'contact') {
                $contact = ContactMessage::findOrFail($request->id);
                $contact->markAsRead();
            }
        } else {
            // Mark all as read
            ChatMessage::whereHas('session', function($query) use ($admin) {
                $query->where('admin_id', $admin->id)
                      ->orWhereNull('admin_id');
            })
            ->where('sender_type', 'user')
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
            
            ContactMessage::where('is_read', false)
                ->update(['is_read' => true]);
        }
        
        return response()->json(['success' => true]);
    }
    
    // Get notification stats for dashboard
    public function getStats()
    {
        $admin = Auth::user();
        
        return response()->json([
            'chats' => [
                'active' => ChatSession::where('status', 'active')->count(),
                'pending' => ChatSession::where('status', 'pending')->count(),
                'unread' => ChatMessage::whereHas('session', function($query) use ($admin) {
                    $query->where(function($q) use ($admin) {
                        $q->where('admin_id', $admin->id)->orWhereNull('admin_id');
                    })->whereIn('status', ['pending', 'active']);
                })->where('sender_type', 'user')->where('is_read', false)->count()
            ],
            'contacts' => [
                'total' => ContactMessage::count(),
                'unread' => ContactMessage::where('is_read', false)->count(),
                'pending' => ContactMessage::where('is_responded', false)->count(),
                'today' => ContactMessage::whereDate('created_at', today())->count()
            ]
        ]);
    }

   /**
     * Display all announcements
     */
    public function index()
    {
        $announcements = PromotionNotification::orderBy('created_at', 'desc')->paginate(10);
        return view('admin.announcements.index', compact('announcements'));
    }

    /**
     * Show the create form
     */
    public function create()
    {
        return view('admin.announcements.form');
    }

    /**
     * Store a new announcement
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:promotion,notification,news',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'badge' => 'nullable|string|max:50',
            'badge_color' => 'nullable|string|max:20',
            'text_color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        PromotionNotification::create($request->all());

        return redirect()->route('admin.announcement.index')
            ->with('success', 'Announcement created successfully!');
    }

    /**
     * Show the edit form
     */
    public function edit($id)
    {
        $announcement = PromotionNotification::findOrFail($id);
        return view('admin.announcements.form', compact('announcement'));
    }

    /**
     * Update an announcement
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:promotion,notification,news',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'badge' => 'nullable|string|max:50',
            'badge_color' => 'nullable|string|max:20',
            'text_color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $announcement = PromotionNotification::findOrFail($id);
        $announcement->update($request->all());

        return redirect()->route('admin.announcement.index')
            ->with('success', 'Announcement updated successfully!');
    }

    /**
     * Delete an announcement
     */
    public function destroy($id)
    {
        $announcement = PromotionNotification::findOrFail($id);
        $announcement->delete();

        return redirect()->route('admin.announcement.index')
            ->with('success', 'Announcement deleted successfully!');
    }

    /**
     * Toggle announcement status (active/inactive)
     */
    public function toggleStatus($id)
    {
        $announcement = PromotionNotification::findOrFail($id);
        $announcement->update([
            'is_active' => !$announcement->is_active
        ]);

        $status = $announcement->is_active ? 'activated' : 'deactivated';
        return redirect()->back()
            ->with('success', "Announcement {$status} successfully!");
    }

    /**
     * API: Get active announcements (for frontend display)
     */
    public function getActiveAnnouncements($type = null)
    {
        $now = now();
        
        $query = PromotionNotification::where('is_active', true)
            ->where(function($q) use ($now) {
                $q->whereNull('starts_at')
                  ->orWhere('starts_at', '<=', $now);
            })
            ->where(function($q) use ($now) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', $now);
            });

        if ($type) {
            $query->where('type', $type);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}