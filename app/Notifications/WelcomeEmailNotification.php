<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmailNotification extends Notification
{
    use Queueable;

    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Welcome mail.
     *
     * The subject carried a party-popper emoji and each bullet was prefixed with
     * a check-mark glyph. Neither belongs in email: the subject emoji breaks
     * some inbox filters and renders as tofu in plain-text clients, and the tick
     * marks misalign in every proportional font. The Laravel mail theme already renders list
     * items, so the markers are simply dropped.
     */
    public function toMail($notifiable)
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject('Welcome to ' . $app)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Welcome to ' . $app . ' — we are glad to have you on board.')
            ->line('Your email address is verified and your wallet is ready.')
            ->line('Here is what you can do now:')
            ->line('Fund your wallet by card or bank transfer')
            ->line('Buy airtime, data, cable TV and electricity tokens')
            ->line('Generate WAEC and JAMB exam PINs')
            ->line('Complete your profile so receipts reach the right address')
            ->action('Go to your dashboard', route('dashboard'))
            ->line('If you have any questions, our support team replies within one business day.')
            ->line('Thank you for choosing ' . $app . '.')
            ->salutation('Best regards, The ' . $app . ' Team');
    }
}
