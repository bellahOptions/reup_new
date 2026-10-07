<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the code that authorises setting or changing a transaction PIN.
 *
 * Deliberately a different notification (and a different subject line) from
 * CustomLoginOtp. A PIN authorisation that arrived looking identical to a
 * sign-in code would be indistinguishable from phishing — and customers are
 * routinely told to ignore unexpected codes. The subject has to say what the
 * code is *for*, so a user who did not request it knows something is wrong.
 *
 * Like the sign-in code, the plaintext exists only inside this object for the
 * life of the send and is never persisted.
 */
class CustomPinOtp extends Notification implements ShouldQueue
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
            ->subject('Your ReUp PIN authorisation code: ' . $this->code)
            // `view`, not `markdown` — full HTML document with inline styles.
            ->view('emails.auth.pin-otp', [
                'user' => $notifiable,
                'code' => $this->code,
                'expireTime' => $this->expiresInMinutes,
                'requestedIp' => $this->requestedIp,
            ]);
    }
}
