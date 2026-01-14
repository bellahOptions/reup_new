<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use App\Mail\ContactMessageReceived;
use App\Mail\ContactMessageConfirmation;
use App\Models\ContactMessage;

class ContactController extends Controller
{
    public function index()
    {
        return view('contact');
    }

    public function submit(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:2000'
        ]);

        // Get authenticated user if exists
        $userId = Auth::check() ? Auth::id() : null;

        try {
            // Save to database
            $contactMessage = ContactMessage::create([
                'user_id' => $userId,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'subject' => $validated['subject'],
                'message' => $validated['message'],
                'is_read' => false,
                'is_responded' => false
            ]);

            // Send email to admin
            Mail::to('reup.bellahoptions@gmail.com')
                ->send(new ContactMessageReceived($contactMessage));

            // Send confirmation email to sender
            Mail::to($validated['email'])
                ->send(new ContactMessageConfirmation($contactMessage));

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully! You will receive a confirmation email shortly.'
            ]);

        } catch (\Exception $e) {
            \Log::error('Contact form error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message. Please try again.'
            ], 500);
        }
    }
}