<?php

namespace App\Listeners;

use App\Notifications\WelcomeEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendWelcomeEmail implements ShouldQueue
{
    public function handle(Verified $event)
    {
        // Send welcome email when user verifies their email
        $event->user->notify(new WelcomeEmailNotification());
    }
}