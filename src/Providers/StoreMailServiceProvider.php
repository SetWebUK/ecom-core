<?php

namespace Pine\Commerce\Providers;

use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Applies the "Sender" settings (Settings › Emails: emails.from_name / emails.from_address) to every email the shop
 * sends. Runs only when the mailer is first used, so normal page views never read these settings.
 */
class StoreMailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->afterResolving('mail.manager', function () {
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
