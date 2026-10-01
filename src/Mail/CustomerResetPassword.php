<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;
use Pine\Commerce\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Customer: password reset link for /my-account/reset-password/{token}/ (WC "Reset password"). */
class CustomerResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $token)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('Password Reset Request for %s', OrderEmail::storeName()));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account.reset-password', with: [
            'user' => $this->user,
            'url' => route('password.reset', ['token' => $this->token, 'email' => $this->user->email]),
            'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            'heading' => 'Password Reset Request',
            'storeName' => OrderEmail::storeName(),
        ]);
    }
}
