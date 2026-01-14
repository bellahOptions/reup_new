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
            ->markdown('emails.auth.verify-email', [
                'user' => $notifiable,
                'verificationUrl' => $this->verificationUrl,
                'expireTime' => config('auth.verification.expire', 60)
            ]);
    }
}