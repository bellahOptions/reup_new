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
    // Get unread notifications
public function getUnreadNotifications()
{
    $admin = Auth::user();

    $unreadContacts = ContactMessage::where('is_read', false)->count();

    // Chats awaiting an agent, or addressed to this admin.
    $unreadChats = ChatMessage::whereHas('session', function ($query) use ($admin) {
            $query->where('admin_id', $admin->id)->orWhereNull('admin_id');
        })
        ->where('sender_type', 'user')
        ->where('is_read', false)
        ->count();

    $notifications = [];

    // `id` is the raw primary key. It was previously prefixed with the type
    // ('contact_12'), which markAsRead() then passed to findOrFail() — so
    // marking a single notification read always 404'd.
    foreach (ContactMessage::where('is_read', false)->latest()->limit(10)->get() as $contact) {
        $notifications[] = [
            'id' => $contact->id,
            'type' => 'contact',
            'title' => $contact->subject ?: 'New contact message',
            'message' => 'From ' . ($contact->name ?: 'Anonymous'),
            'icon' => 'envelope',
            'url' => route('admin.contact.show', $contact->id),
            'is_read' => false,
            'time' => $contact->created_at->diffForHumans(),
            'timestamp' => $contact->created_at->timestamp,
        ];
    }

    foreach (ChatSession::whereIn('status', ['pending', 'active'])
        ->where('updated_at', '>=', now()->subDays(2))
        ->latest('last_message_at')
        ->limit(10)
        ->get() as $session) {
        $notifications[] = [
            'id' => $session->id,
            'type' => 'chat',
            'title' => 'Live chat — ' . ($session->subject ?: 'General enquiry'),
            'message' => $session->user?->name
                ? 'Waiting on ' . $session->user->name
                : 'A customer is waiting for a reply',
            'icon' => 'chat-bubble-left-right',
            'url' => route('admin.chat.index'),
            'is_read' => false,
            'time' => optional($session->last_message_at ?? $session->updated_at)->diffForHumans(),
            'timestamp' => ($session->last_message_at ?? $session->updated_at)->timestamp,
        ];
    }

    usort($notifications, fn ($a, $b) => $b['timestamp'] - $a['timestamp']);

    return response()->json([
        'notifications' => array_slice($notifications, 0, 10),
        'total_unread' => $unreadContacts + $unreadChats,
        'unread_contacts' => $unreadContacts,
        'unread_chats' => $unreadChats,
    ]);
}
    
    // Mark notifications as read
    public function markAsRead(Request $request)
    {
        $admin = Auth::user();

        $request->validate([
            'type' => 'nullable|in:chat,contact',
            'id' => 'nullable|integer',
        ]);

        if ($request->filled('type') && $request->filled('id')) {
            // Tolerate the legacy 'contact_12' id shape from cached clients.
            $id = (int) preg_replace('/\D/', '', (string) $request->input('id'));

            if ($request->input('type') === 'chat') {
                $message = ChatMessage::whereKey($id)->first();

                if ($message && $message->sender_type === 'user') {
                    $message->markAsRead();
                }
            } else {
                ContactMessage::whereKey($id)->update(['is_read' => true]);
            }
        } else {
            ChatMessage::whereHas('session', function ($query) use ($admin) {
                $query->where('admin_id', $admin->id)->orWhereNull('admin_id');
            })
                ->where('sender_type', 'user')
                ->where('is_read', false)
                ->update(['is_read' => true, 'read_at' => now()]);

            ContactMessage::where('is_read', false)->update(['is_read' => true]);
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
     * Validation rules shared by store and update.
     *
     * `badge_color` is validated as a hex colour because the form renders a
     * colour picker and the value is written straight into a `style` attribute.
     * It previously accepted any string, and seeded rows contain Tailwind class
     * names, which produce `background-color: bg-purple-100 text-purple-800` —
     * invalid CSS the browser silently discards.
     *
     * `icon` is constrained to names the icon component actually has, so a
     * typo cannot produce a blank glyph in the marquee.
     *
     * @return array<string,mixed>
     */
    private function rules(): array
    {
        return [
            'type' => 'required|in:promotion,notification,news',
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:2000',
            'badge' => 'nullable|string|max:50',
            'badge_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'in:' . implode(',', array_keys(config('announcements.icons', [])))],
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ];
    }

    /**
     * The columns an announcement form is allowed to write.
     *
     * Passed to the model explicitly rather than `$request->all()`, which
     * bypassed the model's `$fillable` entirely (the model defines no `$guarded`
     * boundary, so any request key became a mass-assignment candidate).
     *
     * @return array<string,mixed>
     */
    private function attributes(Request $request): array
    {
        $data = $request->only([
            'type', 'title', 'content', 'badge', 'badge_color', 'text_color',
            'icon', 'starts_at', 'ends_at',
        ]);

        /*
         * An unchecked checkbox submits nothing, so `is_active` would be absent
         * and the column's DEFAULT 1 would quietly make the announcement live —
         * the opposite of what the admin asked for. Normalise it to a real
         * boolean in both directions.
         */
        $data['is_active'] = $request->boolean('is_active');

        return $data;
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
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        PromotionNotification::create($this->attributes($request));

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
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $announcement = PromotionNotification::findOrFail($id);
        $announcement->update($this->attributes($request));

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