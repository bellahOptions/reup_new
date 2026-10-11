<?php

namespace App\Notifications;

use App\Models\Transactions;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The receipt a customer gets when their wallet is funded.
 *
 * ## Why a notification and not a Mailable
 *
 * The two things the request asked for are one event seen from two sides: "sends
 * a notification to the user" and "with receipts". A notification carries both,
 * because it can be delivered on more than one channel — mail is the receipt the
 * customer keeps, and the `database` row is the record that we sent one, which
 * is what answers "was this customer told?" weeks later without trawling a mail
 * log.
 *
 * ## Why the transaction, and not a copy of its numbers
 *
 * The constructor takes the funding row and reads every figure from it. Nothing
 * is recomputed, and nothing is passed in separately: `WalletService` writes
 * `balance_before` and `balance_after` under a row lock at the instant the money
 * moved, so they are an audit trail rather than a number assembled for an email.
 * A payload of loose values would also be the thing that drifts — a receipt that
 * says ₦5,000 while the wallet moved ₦5,000.50 is worse than no receipt.
 *
 * ## Queued
 *
 * `ShouldQueue` so a slow mail server cannot hold up the sweep that sends these
 * — the sweep's job is to look at rows, not to talk to SMTP. The notification is
 * `SerializesModels`, so the transaction is re-read from the database when the
 * worker picks it up, which also means the receipt is built from the row as it
 * stands rather than from a stale copy held in a queue payload.
 */
class FundingReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Transactions $transaction)
    {
    }

    /**
     * The record first, then the mail.
     *
     * The order is load-bearing. Channels are processed in the order given, and
     * a channel that throws stops the ones after it — so with `['mail',
     * 'database']` a mail server that is down means *no record either*, and
     * "was this customer sent a receipt?" becomes unanswerable at exactly the
     * moment somebody needs to know. `database` is a local insert that only fails
     * if the database is gone, in which case nothing else was going to work
     * either.
     *
     * No SMS: `notification_preferences` has an SMS group, but no SMS gateway is
     * wired up in this deployment (see ProfileController::requestPhoneVerification),
     * and a receipt that silently goes nowhere is worse than one that was never
     * promised.
     *
     * @return array<int,string>
     */
    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * The receipt.
     *
     * `view`, not `markdown`: the template is a full HTML document with a table
     * layout, for the same reason every other mail in this application uses one —
     * the Markdown mailer wraps the content in Laravel's generic theme, which
     * doubles the payload and restyles the card.
     *
     * The subject is a plain `sprintf` rather than a currency-symbol literal in
     * this file: the naira sign is two bytes of UTF-8, and a file written by an
     * editor that is not configured for UTF-8 turns it into `â‚¦` — in the one
     * line of a receipt that is read without opening it. `\u{20A6}` names the
     * character and cannot be mangled by an encoding setting.
     */
    public function toMail($notifiable): MailMessage
    {
        $amount = Money::fromDatabase($this->transaction->total_amount)
            ->format(withSymbol: false);

        return (new MailMessage)
            ->subject(sprintf(
                'Receipt: %s%s added to your %s wallet',
                "\u{20A6}",
                $amount,
                config('app.name')
            ))
            ->view('emails.funding-receipt', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
            ]);
    }

    /**
     * The stored record of the notification.
     *
     * Deliberately small and money-free except for the amount: this is an index
     * entry pointing at the transaction, not a second copy of it. Anything that
     * needs the full picture reads the transaction named by `transaction_id`,
     * which is the row the ledger agrees with.
     *
     * @return array<string,mixed>
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'funding_receipt',
            'transaction_id' => $this->transaction->getKey(),
            'reference' => $this->transaction->reference,
            'amount' => $this->transaction->total_amount,
            'currency' => Money::CURRENCY,
            'status' => $this->transaction->status,
            'message' => "\u{20A6}" . Money::fromDatabase($this->transaction->total_amount)->format(withSymbol: false)
                . ' was added to your wallet.',
            'url' => route('wallet.history'),
        ];
    }
}
