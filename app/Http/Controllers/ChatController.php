<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index()
    {
        $user = Auth::User();
        
        // Get or create chat session for user
        $session = ChatSession::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'active'])
            ->first();

        if (!$session) {
            $session = ChatSession::create([
                'user_id' => $user->id,
                'status' => 'pending',
                'subject' => 'General Inquiry',
                'last_message_at' => now()
            ]);
        }

        return view('live-chat', compact('session'));
    }

    public function getMessages(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id'
        ]);

        $user = Auth::user();
        // `admin` is eager-loaded so the chat view can name the assigned agent
        // without an extra round trip per poll.
        $session = ChatSession::with(['user', 'admin'])->findOrFail($request->session_id);

        // Someone else's session is a 404, not a 403: a 403 would confirm that
        // the id exists, letting a customer enumerate which session ids are
        // live. Ids are sequential, so that confirmation is the whole oracle.
        if (! $user->isAdmin() && $session->user_id !== $user->id) {
            abort(404);
        }

        // Mark the counterparty's messages as read. The previous filter read
        // `sender_type != 'admin'` for a customer, which is true for customer
        // messages — i.e. it marked the customer's *own* messages read and left
        // the agent's unread.
        $counterparty = $user->isAdmin()
            ? ChatMessage::SENDER_USER
            : ChatMessage::SENDER_ADMIN;

        $session->messages()
            ->where('sender_type', $counterparty)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        $messages = $session->messages()
            ->with(['sender:id,name,email'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'messages' => $messages,
            'session' => $session,
            'user' => $session->user,
            'admin_assigned' => $session->admin_id !== null,
        ]);
    }

    public function sendMessage(Request $request)
{
    $validated = $request->validate([
        'session_id' => 'required|exists:chat_sessions,id',
        'message' => 'required|string|max:1000'
    ]);

    $user = Auth::user();
    $session = ChatSession::findOrFail($validated['session_id']);

    // Same rule as getMessages(): another customer's session does not exist as
    // far as this user is concerned, and must not be writable either.
    if (! $user->isAdmin() && $session->user_id !== $user->id) {
        abort(404);
    }

    // `sender_type` is the role label the schema documents and the value every
    // unread query filters on. This previously wrote the class name
    // 'App\Models\User' for both customers and agents, so no unread count,
    // notification or read receipt ever matched.
    $isAdmin = $user->isAdmin();

    $message = ChatMessage::create([
        'chat_session_id' => $session->id,
        'sender_id' => $user->id,
        'sender_type' => $isAdmin ? ChatMessage::SENDER_ADMIN : ChatMessage::SENDER_USER,
        'message' => $validated['message'],
        // An agent's own message needs no acknowledgement from the agent.
        'is_read' => $isAdmin,
    ]);

    $session->update([
        'status' => 'active',
        'last_message_at' => now(),
        // First agent to reply takes ownership of the conversation.
        'admin_id' => $session->admin_id ?? ($isAdmin ? $user->id : null),
    ]);

    return response()->json([
        'success' => true,
        'message' => $message->load('sender'),
    ]);
}

    public function userTyping(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id',
            'is_typing' => 'required|boolean'
        ]);

        $user = Auth::User();

        // Previously unchecked: this accepted any session id, so a customer
        // could flag typing against a stranger's conversation. It returns no
        // data, but it is still an unauthenticated-by-object write.
        $session = ChatSession::findOrFail($request->session_id);

        if (! $user->isAdmin() && $session->user_id !== $user->id) {
            abort(404);
        }

        // In production, broadcast this event via Laravel Echo/Pusher
        // event(new UserTyping($request->session_id, $user->id, $request->is_typing));

        return response()->json(['success' => true]);
    }

    public function closeChat(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id'
        ]);

        $user = Auth::User();
        $session = ChatSession::findOrFail($request->session_id);

        if (!$user->isAdmin() && $session->user_id !== $user->id) {
            abort(404);
        }

        $session->update(['status' => 'closed']);

        return response()->json(['success' => true]);
    }

    public function getAvailableAdmins()
    {
        // `role` is not a column on users — admins are identified by the
        // is_admin / is_super_admin flags. The old query also leaked the
        // admin's email address to any authenticated customer.
        $admins = User::admins()
            ->where('is_online', true)
            ->where('last_activity', '>=', now()->subMinutes(5))
            ->get(['id', 'name']);

        return response()->json(['admins' => $admins]);
    }

    private function getAvailableAdmin()
    {
        // Simple round-robin admin assignment.
        return User::admins()
            ->where('is_online', true)
            ->where('last_activity', '>=', now()->subMinutes(5))
            ->orderBy('last_assigned_at')
            ->first();
    }

    /*
     * The methods below are the console side of chat. Nothing registers them on
     * the customer routes today — the admin console routes to
     * Admin\AdminChatController — but they live on a controller reachable by any
     * authenticated customer, and they return every session and every user in
     * the database. They therefore assert the admin flag themselves rather than
     * relying on a route definition not to change.
     */
    private function assertAdmin(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    // Admin methods
    public function adminIndex()
    {
        $this->assertAdmin();

        return view('admin.chat.index');
    }

    public function getSessions(Request $request)
    {
        $this->assertAdmin();

        $query = ChatSession::with(['user', 'messages' => function($q) {
            $q->latest()->limit(1);
        }]);

        // Apply filters
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        $sessions = $query->latest('last_message_at')->get();

        // Add unread count for each session
        $sessions->each(function($session) {
            $session->unread_count = $session->messages()
                ->where('sender_type', 'user')
                ->where('is_read', false)
                ->count();
            
            $latestMessage = $session->messages->first();
            $session->last_message = $latestMessage ? $latestMessage->message : null;
        });

        return response()->json(['sessions' => $sessions]);
    }

    public function getStats()
    {
        $this->assertAdmin();

        $active = ChatSession::where('status', 'active')->count();
        $pending = ChatSession::where('status', 'pending')->count();
        $closed = ChatSession::where('status', 'closed')
            ->whereDate('updated_at', today())
            ->count();

        return response()->json([
            'active' => $active,
            'pending' => $pending,
            'closed' => $closed
        ]);
    }

    public function getOnlineAdmins()
    {
        $this->assertAdmin();

        $admins = User::admins()
            ->where('is_online', true)
            ->where('last_activity', '>=', now()->subMinutes(5))
            ->get(['id', 'name'])
            ->map(fn (User $admin) => [
                'id' => $admin->id,
                'name' => $admin->name,
            ]);

        return response()->json(['admins' => $admins]);
    }

    public function updateAvailability(Request $request)
    {
        $request->validate([
            'is_available' => 'required|boolean'
        ]);

        $user = Auth::User();
        $user->update([
            'is_online' => $request->is_available,
            'last_activity' => now()
        ]);

        return response()->json(['success' => true]);
    }

    // Add to ChatController
public function getAdminMessages($sessionId)
{
    $this->assertAdmin();

    $session = ChatSession::with('user')->findOrFail($sessionId);
    
    // Get messages
    $messages = $session->messages()
        ->with(['sender' => function($query) {
            $query->select('id', 'name', 'email');
        }])
        ->orderBy('created_at', 'asc')
        ->get();
    
    // Mark all user messages as read
    $session->messages()
        ->where('sender_type', 'user')
        ->where('is_read', false)
        ->update(['is_read' => true, 'read_at' => now()]);
    
    return response()->json([
        'messages' => $messages,
        'user' => $session->user,
        'session' => $session
    ]);
}

public function sendAdminMessage(Request $request)
{
    $request->validate([
        'session_id' => 'required|exists:chat_sessions,id',
        'message' => 'required|string|max:1000'
    ]);

    $user = Auth::User();
    
    if (!$user->isAdmin()) {
        abort(403);
    }

    $session = ChatSession::findOrFail($request->session_id);
    
    // Assign admin to session if not already assigned
    if (!$session->admin_id) {
        $session->update(['admin_id' => $user->id, 'status' => 'active']);
    }

    // Create message
    $message = ChatMessage::create([
        'chat_session_id' => $session->id,
        'sender_id' => $user->id,
        'sender_type' => ChatMessage::SENDER_ADMIN,
        'message' => $request->message,
        'is_read' => true // Admin messages are automatically read
    ]);

    // Update session
    $session->update(['last_message_at' => now()]);

    return response()->json([
        'success' => true,
        'message' => $message->load('sender')
    ]);
}


}