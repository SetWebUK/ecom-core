<?php

namespace Pine\Commerce\Notifications;

use Pine\Commerce\Mail\CustomerResetPassword as ResetMail;
use Illuminate\Notifications\Notification;

/**
 * Storefront password reset (the /my-account/lost-password/ form). Sent through the password broker's
 * callback so staff keep their own AdminResetPassword notification for /admin.
 */
class CustomerResetPassword extends Notification
{
    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ResetMail
    {
        return (new ResetMail($notifiable, $this->token))->to($notifiable->getEmailForPasswordReset());
    }
}
