<?php

namespace Pine\Commerce\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset email for back-office staff. The link points at /admin/reset-password/{token}, so the
 * storefront's own reset flow (User::sendPasswordResetNotification -> /my-account/…) is untouched.
 */
class AdminResetPassword extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $store = setting('store.name', config('app.name'));
        $url = route('admin.password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject("Reset your {$store} admin password")
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line("Someone (hopefully you) asked to reset the password for your {$store} back-office account.")
            ->action('Choose a new password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you didn’t ask for this, you can ignore this email – your password won’t change.');
    }
}
