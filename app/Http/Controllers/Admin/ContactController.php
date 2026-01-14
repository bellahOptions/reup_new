<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Mail\ContactReplyMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function index()
    {
        $messages = ContactMessage::with('user')
            ->latest()
            ->paginate(20);

        $stats = [
            'total' => ContactMessage::count(),
            'unread' => ContactMessage::where('is_read', false)->count(),
            'pending' => ContactMessage::where('is_responded', false)->count(),
            'today' => ContactMessage::whereDate('created_at', today())->count()
        ];

        return view('admin.contact.index', compact('messages', 'stats'));
    }

    public function show($id)
    {
        $message = ContactMessage::with('user')->findOrFail($id);
        
        // Mark as read
        if (!$message->is_read) {
            $message->markAsRead();
        }

        return view('admin.contact.show', compact('message'));
    }

            public function reply(Request $request, $id)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $message = ContactMessage::findOrFail($id);
        $userEmail = $message->email;

        try {
            // Send reply email
            Mail::to($userEmail)->send(new ContactReplyMail(
                $request->subject,
                $request->message,
                $message->name
            ));

            // Update message status
            $message->update([
                'is_responded' => true,
                'responded_at' => now(),
                'replied_by' => auth()->id(),
                'admin_reply' => $request->message,
                'is_read' => true
            ]);

            // Create reply history record (if you have a separate table)
            \App\Models\ContactReply::create([
                'contact_message_id' => $message->id,
                'admin_id' => auth()->id(),
                'subject' => $request->subject,
                'message' => $request->message,
                'sent_at' => now(),
            ]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Reply sent successfully!'
                ]);
            }

            return back()->with('success', 'Reply sent successfully!');

        } catch (\Exception $e) {
            \Log::error('Failed to send reply email: ' . $e->getMessage());
            
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send reply: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->with('error', 'Failed to send reply. Please try again.');
        }
    }

    public function markAsRead(Request $request, $id)
    {
        $message = ContactMessage::findOrFail($id);
        $message->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAsResponded(Request $request, $id)
    {
        $message = ContactMessage::findOrFail($id);
        $message->markAsResponded();

        return response()->json(['success' => true]);
    }

    public function delete($id)
    {
        $message = ContactMessage::findOrFail($id);
        $message->delete();

        return redirect()->route('admin.contact.index')
            ->with('success', 'Contact message deleted successfully');
    }
}