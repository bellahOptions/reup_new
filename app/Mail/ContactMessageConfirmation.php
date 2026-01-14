<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactMessageConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $contactMessage;

    public function __construct(ContactMessage $contactMessage)
    {
        $this->contactMessage = $contactMessage;
    }

    public function build()
    {
        return $this->subject('✅ Message Received - ' . config('app.name'))
                    ->view('emails.contact.confirmation')
                    ->with([
                        'contact' => $this->contactMessage
                    ]);
    }
}