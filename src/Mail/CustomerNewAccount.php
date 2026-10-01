<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;
use Pine\Commerce\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Customer: welcome email after registering (My Account or checkout) - WC "New account". */
class CustomerNewAccount extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('Your %s account has been created!', OrderEmail::storeName()));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account.new-account', with: [
            'user' => $this->user,
            'heading' => 'Welcome to '.OrderEmail::storeName(),
            'storeName' => OrderEmail::storeName(),
        ]);
    }
}
