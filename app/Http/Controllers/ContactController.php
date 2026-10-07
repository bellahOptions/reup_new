<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessageConfirmation;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactController extends Controller
{
    public function index()
    {
        return view('contact');
    }

    /**
     * Accept a contact message.
     *
     * Responds in the shape the caller asked for: JSON for the AJAX client,
     * a redirect for a normal form POST. The view used to intercept the submit
     * with fetch() and expect JSON, while the rewritten view posts normally —
     * with only the JSON branch, a plain POST would have rendered raw JSON in
     * the browser.
     */
    public function submit(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $contactMessage = ContactMessage::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'is_read' => false,
            'is_responded' => false,
        ]);

        // The message is already persisted, so a mail failure must not lose it
        // or tell the customer their enquiry was not received.
        $this->notify($contactMessage);

        $success = 'Thanks — your message is with our support team. We reply within one business day.';

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $success]);
        }

        return redirect()->route('contact')->with('success', $success);
    }

    /**
     * Send the operations copy and the customer acknowledgement.
     *
     * Recipients come from config rather than a hardcoded address, and each
     * send is isolated so one failing delivery does not suppress the other.
     */
    private function notify(ContactMessage $contactMessage): void
    {
        $ops = config('services.support.ops_emails', []);

        if (! empty($ops)) {
            try {
                Mail::to($ops)->send(new ContactMessageReceived($contactMessage));
            } catch (Throwable $e) {
                Log::error('Contact notification to operations failed', [
                    'contact_message_id' => $contactMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            Mail::to($contactMessage->email)->send(new ContactMessageConfirmation($contactMessage));
        } catch (Throwable $e) {
            Log::error('Contact acknowledgement to customer failed', [
                'contact_message_id' => $contactMessage->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
