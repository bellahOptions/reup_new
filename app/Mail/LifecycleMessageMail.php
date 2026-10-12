<?php

namespace App\Mail;

use App\Models\LifecycleEmailTemplate;
use App\Models\LifecycleMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * A lifecycle email, rendered from the snapshot stored on its message row.
 *
 * ## Why the content comes from the row and not from the template
 *
 * The columns *are* the message: `subject` and `html_body` were rendered at schedule
 * time and are what the customer was promised. A retry must resend that, and an audit
 * next month must be able to say what was sent without depending on the template still
 * saying it.
 *
 * ## Sender identity
 *
 * Configured, never hardcoded, resolved in this order: the template's own
 * `from_address`/`from_name` when set, then `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` —
 * the address ReUp has actually verified with its mail provider. Nothing here invents
 * an address or reaches for SMTP credentials: a send with an unverified From is
 * rejected by most providers, and repeated attempts damage the sending domain for
 * every other customer's password reset.
 *
 * `from_name` falls back to the platform name, so a template that wants a named human
 * sender ("Olumuyiwa from ReUp") sets it itself. That keeps a personal sender a choice
 * an author makes rather than something applied to a receipt.
 *
 * Laravel 8: `build()`, not the `Envelope`/`Content` API, which arrived in Laravel 9.
 */
class LifecycleMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly LifecycleMessage $message)
    {
    }

    /** Set by the dispatcher; a header a provider can quote back to us. */
    private ?string $correlationId = null;

    public function withCorrelationId(string $id): self
    {
        $this->correlationId = $id;

        return $this;
    }

    public function build(): self
    {
        $template = LifecycleEmailTemplate::where('key', $this->message->template_key)->first();

        $address = trim((string) ($template?->from_address ?: config('mail.from.address', '')));
        $name = trim((string) ($template?->from_name ?: config('mail.from.name', '')));

        if ($address === '') {
            /*
             * `config('mail.from.address')` falls back to the verified no-reply
             * address, so reaching here means mail configuration is genuinely broken.
             * Fail loudly rather than sending with a blank From, which most providers
             * reject anyway and which makes the failure harder to find.
             */
            throw new RuntimeException(
                'No lifecycle sender address is configured. Set MAIL_FROM_ADDRESS to the verified ReUp sender.'
            );
        }

        $mail = $this->from($address, $name !== '' ? $name : (string) config('app.name', 'ReUp'))
            ->subject($this->message->subject)
            ->view('emails.lifecycle-raw', ['html' => $this->message->html_body]);

        /*
         * `List-Unsubscribe` makes opting out possible without the customer replying
         * "stop", and its presence is one of the signals filtering systems use to tell
         * a wanted bulk sender from a spammer.
         *
         * Marketing only. Adding it to a receipt would imply the customer can stop
         * receiving receipts, which they cannot and should not be encouraged to try.
         */
        if ($this->message->isMarketing()) {
            $unsubscribe = \URL::temporarySignedRoute(
                'lifecycle.unsubscribe',
                now()->addDays(180),
                ['user' => $this->message->user_id]
            );

            $mail->withSwiftMessage(function ($swift) use ($unsubscribe) {
                $swift->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribe . '>');
            });
        }

        /*
         * The correlation header. Laravel 8 sends through SwiftMailer and the
         * transport does not report a message id back, so this is what a delivery or
         * bounce webhook can quote to identify the row it refers to.
         */
        if ($this->correlationId !== null) {
            $mail->withSwiftMessage(function ($swift) {
                $swift->getHeaders()->addTextHeader('X-ReUp-Message-Id', $this->correlationId);
            });
        }

        return $mail;
    }
}
