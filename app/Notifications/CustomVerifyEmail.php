<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class CustomVerifyEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The verification URL.
     *
     * @var string
     */
    public $verificationUrl;

    /**
     * Create a new notification instance.
     *
     * @param  string  $verificationUrl
     * @return void
     */
    public function __construct($verificationUrl)
    {
        $this->verificationUrl = $verificationUrl;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Verify Your Email Address - ReUp')
            // `view`, not `markdown`: this template is a full HTML document with
            // inline styles and a table layout. Rendering it through the Markdown
            // mailer wrapped it in Laravel's generic theme (doubling the payload
            // and re-styling the card), which is exactly what the template was
            // rewritten to avoid. Every other mailable in this app uses `view`.
            ->view('emails.auth.verify-email', [
                'user' => $notifiable,
                'verificationUrl' => $this->verificationUrl,
                'expireTime' => config('auth.verification.expire', 60)
            ]);
    }
}