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

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Welcome to ' . config('app.name') . '! 🎉')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Welcome to ' . config('app.name') . '! We\'re thrilled to have you on board.')
            ->line('Your email has been successfully verified and your account is now active.')
            ->line('Here\'s what you can do now:')
            ->line('✓ Fund your wallet and start transactions')
            ->line('✓ Pay bills instantly (Airtime, Data, TV, Electricity)')
            ->line('✓ Complete your profile for a personalized experience')
            ->action('Go to Dashboard', route('dashboard'))
            ->line('If you have any questions, our support team is here to help 24/7.')
            ->line('Thank you for choosing ' . config('app.name') . '!')
            ->salutation('Best regards, The ' . config('app.name') . ' Team');
    }
}