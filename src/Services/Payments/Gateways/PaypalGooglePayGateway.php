<?php

namespace Pine\Commerce\Services\Payments\Gateways;

/**
 * Google Pay through the shop's PayPal account (see PaypalWalletGateway). Shown in browsers where the customer has
 * Google Pay set up, once PayPal says the account can take it (Google Pay switched on for the PayPal app).
 *
 * Settings: payments.paypal_googlepay.enabled, .title, .description (credentials and sandbox: payments.paypal.*).
 */
class PaypalGooglePayGateway extends PaypalWalletGateway
{
    public function code(): string
    {
        return 'paypal_googlepay';
    }

    protected function defaultTitle(): string
    {
        return 'Google Pay';
    }

    public function wallet(): string
    {
        return 'googlepay';
    }

    public function walletScript(): string
    {
        return 'https://pay.google.com/gp/p/js/pay.js';
    }

    public function adminSettings(): array
    {
        return [
            'label' => 'Google Pay (via PayPal)',
            'icon' => 'wallet',
            'description' => 'Google Pay through your PayPal account – uses the PayPal keys above, no separate account. Customers see it only in browsers where they have Google Pay set up. Google Pay must be switched on for your PayPal app (developer.paypal.com › Apps & Credentials › your app › Features).',
            'fields' => static::baseSettingsFields('Google Pay'),
            'webhook' => null,
        ];
    }
}
