<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public $subject;
    public $message;
    public $name;

    /**
     * Create a new message instance.
     */
    public function __construct($subject, $message, $name)
    {
        $this->subject = $subject;
        $this->message = $message;
        $this->name = $name;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('[Reply] ' . $this->subject)
                    ->view('emails.contact-reply')
                    ->with([
                        'replyMessage' => $this->message,
                        'name' => $this->name,
                    ]);
    }
}