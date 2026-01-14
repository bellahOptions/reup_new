<?php

namespace App\Mail;

use App\Models\Transactions;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TransactionReceiptMail extends Mailable
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
        return $this->subject('Transaction Receipt - ' . $this->transaction->reference)
                    ->view('emails.transaction-receipt');
    }
}