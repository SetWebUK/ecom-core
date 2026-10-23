<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Services\Payments\Gateways\PaypalApplePayGateway;
use Pine\Commerce\Services\Payments\PaymentManager;

/**
 * GET /.well-known/apple-developer-merchantid-domain-association – the file Apple fetches to check a domain registered
 * for Apple Pay. With Apple Pay through PayPal it is PayPal's own file (resources/payments/apple-pay: sandbox or live,
 * following the PayPal sandbox switch), served while PayPal has its keys so the domain can be registered in PayPal
 * before Apple Pay is switched on at checkout. Exact address, no redirect (TrailingSlash leaves /.well-known/ alone).
 */
class ApplePayDomainController
{
    public function __invoke(PaymentManager $payments)
    {
        $gateway = collect($payments->all())->first(fn ($gateway) => $gateway instanceof PaypalApplePayGateway);
        if (! $gateway || ! $gateway->isConfigured() || ! is_file($file = $gateway->domainAssociationFile())) {
            abort(404);
        }

        return response()->file($file, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'public, max-age=3600']);
    }
}
