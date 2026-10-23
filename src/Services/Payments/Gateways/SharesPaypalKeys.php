<?php

namespace Pine\Commerce\Services\Payments\Gateways;

/**
 * For methods that take payments through the PayPal app of PaypalGateway (card, Google Pay, Apple Pay): the client
 * id, secret, sandbox switch and webhook id are read from PayPal's own settings (payments.paypal.*), so there is one
 * set of credentials; enabled / title / description stay the method's own.
 */
trait SharesPaypalKeys
{
    protected function setting(string $key, $default = null)
    {
        return in_array($key, ['client_id', 'secret', 'sandbox', 'webhook_id'], true)
            ? setting('payments.paypal.'.$key, $default)
            : parent::setting($key, $default);
    }

    /** Notifications for every PayPal method arrive at /webhooks/paypal (PaypalGateway::PAYPAL_CODES). */
    public function handleWebhook(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return response('PayPal notifications for this payment method arrive at /webhooks/paypal.', 404);
    }
}
