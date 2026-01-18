<?php

namespace App\Mail;

use App\Models\TermsPrivacy;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TermsUpdated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $document;
    public $user;
    public $updatedBy;
    public $documentType;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(TermsPrivacy $document, User $user)
    {
        $this->document = $document;
        $this->user = $user;
        $this->updatedBy = $document->updatedBy;
        $this->documentType = $document->type == 'terms' ? 'Terms of Service' : 'Privacy Policy';
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = "Important Update: {$this->documentType} Revision";

        return $this->subject($subject)
                    ->view('emails.terms_updated')
                    ->with([
                        'user' => $this->user,
                        'document' => $this->document,
                        'documentType' => $this->documentType,
                        'updatedBy' => $this->updatedBy,
                        'versionDate' => $this->document->formatted_version_date,
                        'effectiveDate' => $this->document->created_at->format('F j, Y'),
                        'viewUrl' => url('/terms/' . $this->document->type),
                        'loginUrl' => url('/login'),
                    ]);
    }
}