<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminChatController extends Controller
{
    // Admin chat dashboard
    public function index()
    {
        return view('admin.chat.index');
    }

    // Get all chat sessions
    public function getSessions(Request $request)
    {
        $status = $request->get('status', 'active');
        
        $query = ChatSession::with(['user', 'admin'])
            ->withCount(['messages', 'unreadMessages'])
            ->orderBy('last_message_at', 'desc');
        
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        
        $sessions = $query->limit(50)->get()->map(function($session) {
            $session->last_message = $session->messages()->latest()->first()?->message;
            return $session;
        });
        
        return response()->json(['sessions' => $sessions]);
    }

    // Get session messages
    public function getSessionMessages($sessionId)
    {
        $session = ChatSession::with('user')->findOrFail($sessionId);
        
        // Mark unread messages as read
        $session->unreadMessages()->update([
            'is_read' => true,
            'read_at' => now()
        ]);
        
        $messages = $session->messages()
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get();
        
        return response()->json([
            'messages' => $messages,
            'user' => $session->user
        ]);
    }

    // Admin sends message
    public function sendMessage(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id',
            'message' => 'required|string|max:1000'
        ]);
        
        $admin = Auth::user();
        $session = ChatSession::findOrFail($request->session_id);
        
        // Make sure admin has permission to chat
        if (!$admin->hasPermission('chat')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        // Assign admin to session if not already assigned
        if (!$session->admin_id) {
            $session->update([
                'admin_id' => $admin->id,
                'status' => 'active'
            ]);
        }
        
        // Create message
        $message = ChatMessage::create([
            'chat_session_id' => $session->id,
            'sender_id' => $admin->id,
            'sender_type' => 'admin',
            'message' => $request->message
        ]);
        
        // Update session
        $session->update(['last_message_at' => now()]);
        
        return response()->json([
            'success' => true,
            'message' => $message
        ]);
    }

    // Get online admins
    public function getOnlineAdmins()
    {
        $admins = User::where('is_admin', true)
            ->orWhere('is_super_admin', true)
            ->where('is_online', true)
            ->where('last_activity_at', '>=', now()->subMinutes(5))
            ->select('id', 'name', 'email', 'admin_role')
            ->orderBy('last_activity_at', 'desc')
            ->get();
        
        return response()->json(['admins' => $admins]);
    }

    // Get chat statistics
    public function getStats()
    {
        return response()->json([
            'active' => ChatSession::where('status', 'active')->count(),
            'pending' => ChatSession::where('status', 'pending')->count(),
            'closed' => ChatSession::where('status', 'closed')
                ->whereDate('updated_at', today())
                ->count(),
        ]);
    }

    // Update admin availability
    public function updateAvailability(Request $request)
    {
        $request->validate([
            'is_available' => 'required|boolean'
        ]);
        
        Auth::user()->update([
            'is_online' => $request->is_available
        ]);
        
        return response()->json(['success' => true]);
    }

    // Handle typing indicator
    public function typingStatus(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id',
            'is_typing' => 'required|boolean'
        ]);
        
        // You could broadcast this event to the user
        // For now, just log it
        \Log::info('Admin typing', [
            'session_id' => $request->session_id,
            'admin_id' => Auth::id(),
            'is_typing' => $request->is_typing
        ]);
        
        return response()->json(['success' => true]);
    }

    // Close chat session
    public function closeSession(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id'
        ]);
        
        $session = ChatSession::findOrFail($request->session_id);
        $session->update(['status' => 'closed']);
        
        return response()->json(['success' => true]);
    }

    // Transfer chat to another admin
    public function transferSession(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id',
            'admin_id' => 'required|exists:users,id'
        ]);
        
        $session = ChatSession::findOrFail($request->session_id);
        
        // Check if target user is admin
        $admin = User::findOrFail($request->admin_id);
        if (!$admin->isAdmin()) {
            return response()->json(['error' => 'Target user is not an admin'], 400);
        }
        
        $session->update(['admin_id' => $admin->id]);
        
        return response()->json(['success' => true]);
    }
}