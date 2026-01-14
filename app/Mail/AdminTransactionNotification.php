<?php

namespace App\Mail;

use App\Models\Transactions;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminTransactionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $transaction;
    public $user;

    public function __construct(Transactions $transaction)
    {
        $this->transaction = $transaction;
        $this->user = $transaction->user;
    }

    public function build()
    {
        return $this->subject('New Transaction Alert - ' . $this->transaction->reference)
                    ->view('emails.admin-transaction-notification');
    }
}