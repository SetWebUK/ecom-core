<?php

namespace Pine\Commerce\Services\Payments\Gateways;

/**
 * Apple Pay through the shop's PayPal account (see PaypalWalletGateway). Shown in Safari on Apple devices with Apple
 * Pay set up, once PayPal says the account can take it. Apple only allows it on domains registered with PayPal; the
 * domain association file Apple checks is served at /.well-known/apple-developer-merchantid-domain-association.
 *
 * Settings: payments.paypal_applepay.enabled, .title, .description (credentials and sandbox: payments.paypal.*).
 */
class PaypalApplePayGateway extends PaypalWalletGateway
{
    public function code(): string
    {
        return 'paypal_applepay';
    }

    protected function defaultTitle(): string
    {
        return 'Apple Pay';
    }

    public function wallet(): string
    {
        return 'applepay';
    }

    public function walletScript(): string
    {
        return 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js';
    }

    /** PayPal's domain association file for the current environment (served by ApplePayDomainController). */
    public function domainAssociationFile(): string
    {
        return __DIR__.'/../../../../resources/payments/apple-pay/paypal-'.($this->isSandbox() ? 'sandbox' : 'live');
    }

    public function adminSettings(): array
    {
        return [
            'label' => 'Apple Pay (via PayPal)',
            'icon' => 'wallet',
            'description' => 'Apple Pay through your PayPal account – uses the PayPal keys above, no separate account. Customers see it only in Safari on iPhone, iPad and Mac with Apple Pay set up. Apple Pay must be switched on for your PayPal app, and this site’s domain added under Apple Pay in PayPal; the file Apple checks is served at /.well-known/apple-developer-merchantid-domain-association.',
            'fields' => static::baseSettingsFields('Apple Pay'),
            'webhook' => null,
        ];
    }
}
