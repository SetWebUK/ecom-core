<?php

namespace Pine\Commerce\Providers;

use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use Pine\Commerce\Mail\Support\Smtp2GoTransport;
use Throwable;

/**
 * Applies the "Sender" settings (Settings › Emails: emails.from_name / emails.from_address) to every email the shop
 * sends, and adds the "smtp2go" mailer (MAIL_MAILER=smtp2go + SMTP2GO_API_KEY). Runs only when the mailer is first
 * used, so normal page views never read these settings.
 */
class StoreMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! config('mail.mailers.smtp2go')) {
            config(['mail.mailers.smtp2go' => ['transport' => 'smtp2go']]);
        }
    }

    public function boot(): void
    {
        $this->app->afterResolving('mail.manager', function (MailManager $manager) {
            $manager->extend('smtp2go', fn (array $config) => new Smtp2GoTransport(
                (string) ($config['key'] ?? config('commerce.mail.smtp2go.key')),
                (string) ($config['endpoint'] ?? config('commerce.mail.smtp2go.endpoint') ?: 'https://api.smtp2go.com/v3'),
            ));

            try {
                $address = trim((string) setting('emails.from_address'));
                $name = trim((string) setting('emails.from_name'));
            } catch (Throwable) {
                return;
            }
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                config(['mail.from.address' => $address]);
            }
            if ($name !== '') {
                config(['mail.from.name' => $name]);
            }
        });
    }
}
