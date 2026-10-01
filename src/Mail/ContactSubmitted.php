<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Website enquiry (contact form) sent to the store inbox - same format as the legacy WordPress contact form mail. */
class ContactSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FormSubmission $submission)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Website enquiry: '.($this->submission->subject ?: 'Website enquiry'),
            replyTo: [new Address($this->submission->email, $this->submission->name)],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.contact-submitted');
    }
}
