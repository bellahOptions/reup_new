<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactMessageReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactMessage $contactMessage)
    {
    }

    public function build()
    {
        // No emoji in a subject line: many clients render them as tofu and they
        // break inbox filtering.
        return $this->subject('New contact message: ' . $this->contactMessage->subject)
            ->view('emails.contact.received')
            ->with(['contact' => $this->contactMessage]);
    }
}
