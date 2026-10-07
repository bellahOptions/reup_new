<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a one-time sign-in code.
 *
 * The plaintext code exists only inside this object for the lifetime of the
 * send; it is never persisted. The notification is intentionally NOT queued —
 * see below.
 *
 * `ShouldQueue` is implemented so the class stays queue-ready, but the service
 * sends it synchronously on purpose: with QUEUE_CONNECTION=sync a queued
 * notification would run inline anyway, and on a real queue the user would be
 * shown "we sent a code" while the job was still pending. A sign-in code the
 * user has to wait for is worse than a marginally longer request.
 */
class CustomLoginOtp extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $expiresInMinutes,
        public ?string $requestedIp = null
    ) {
    }

    /**
     * @return array<int,string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your ReUp sign-in code: ' . $this->code)
            // `view`, not `markdown` — this is a full HTML document with inline
            // styles and a table layout. See CustomVerifyEmail for the rationale.
            ->view('emails.auth.login-otp', [
                'user' => $notifiable,
                'code' => $this->code,
                'expireTime' => $this->expiresInMinutes,
                'requestedIp' => $this->requestedIp,
            ]);
    }
}
