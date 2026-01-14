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

        $user = Auth::User();
        $session = ChatSession::with('user')->findOrFail($request->session_id);

        // Verify user has access to this session
        if (!$user->isAdmin() && $session->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Mark messages as read
        $unreadMessages = $session->messages()
            ->where('sender_type', '!=', $user->isAdmin() ? 'admin' : 'user')
            ->where('is_read', false)
            ->get();

        foreach ($unreadMessages as $message) {
            $message->markAsRead();
        }

        $messages = $session->messages()
            ->with(['sender' => function($query) {
                $query->select('id', 'name', 'email');
            }])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'messages' => $messages,
            'session' => $session,
            'user' => $session->user,
            'admin_assigned' => $session->admin_id ? true : false
        ]);
    }

    public function sendMessage(Request $request)
{
    $request->validate([
        'session_id' => 'required|exists:chat_sessions,id',
        'message' => 'required|string|max:1000'
    ]);

    $user = Auth::User();
    $session = ChatSession::findOrFail($request->session_id);

    // Verify user has access
    if (!$user->isAdmin() && $session->user_id !== $user->id) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    // Create message with proper sender_type
    $message = ChatMessage::create([
        'chat_session_id' => $session->id,
        'sender_id' => $user->id,
        'sender_type' => 'App\Models\User', // Use full class path
        'message' => $request->message,
        'is_read' => $user->isAdmin() // Admin messages are read immediately
    ]);

    // Update session
    $session->update([
        'status' => 'active',
        'last_message_at' => now()
    ]);

    return response()->json([
        'success' => true,
        'message' => $message->load('sender')
    ]);
}

    public function userTyping(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id',
            'is_typing' => 'required|boolean'
        ]);

        $user = Auth::User();
        
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
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $session->update(['status' => 'closed']);

        return response()->json(['success' => true]);
    }

    public function getAvailableAdmins()
    {
        // Get online admins
        $admins = User::where('role', 'admin')
            ->where('is_online', true)
            ->orWhere('last_activity', '>=', now()->subMinutes(5))
            ->select('id', 'name', 'email', 'is_online', 'last_activity')
            ->get();

        return response()->json(['admins' => $admins]);
    }

    private function getAvailableAdmin()
    {
        // Simple round-robin admin assignment
        return User::where('role', 'admin')
            ->where(function($query) {
                $query->where('is_online', true)
                      ->orWhere('last_activity', '>=', now()->subMinutes(5));
            })
            ->orderBy('last_assigned_at', 'asc')
            ->first();
    }

    // Admin methods
    public function adminIndex()
    {
        return view('admin.chat.index');
    }

    public function getSessions(Request $request)
    {
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
        $admins = User::where('role', 'admin')
            ->where(function($query) {
                $query->where('is_online', true)
                      ->orWhere('last_activity', '>=', now()->subMinutes(5));
            })
            ->select('id', 'name', 'email', 'role as admin_role')
            ->get();

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
        return response()->json(['error' => 'Unauthorized'], 403);
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
        'sender_type' => 'App\Models\User', // Use full class path
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