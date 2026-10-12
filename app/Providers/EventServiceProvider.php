<?php

namespace App\Providers;

use App\Listeners\RecordUserRegistered;
use App\Listeners\SendWelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
     protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
            /*
             * Records `user.registered` in the lifecycle outbox, which is what a welcome
             * automation listens for.
             *
             * A separate listener from `SendWelcomeEmail` below, and both are wanted:
             * that one sends the framework-era welcome when the address is *verified*,
             * and this one records the domain event at *registration* so a
             * lifecycle-configured welcome (or a delayed reminder) has something to fire
             * from. They answer different questions and neither replaces the other.
             */
            RecordUserRegistered::class,
        ],
        Verified::class => [
            SendWelcomeEmail::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
