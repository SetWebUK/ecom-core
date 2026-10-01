<?php

namespace Pine\Commerce\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\Gateway;
use Pine\Commerce\Services\Payments\PaymentResult;

/** A client payment gateway as a client would write it (ExtensionApiTest). */
class AcmePayGateway extends Gateway
{
    public function code(): string
    {
        return 'acmepay';
    }

    protected function defaultTitle(): string
    {
        return 'AcmePay instalments';
    }

    public function isConfigured(): bool
    {
        return $this->secret('api_key') !== null;
    }

    public function checkoutHtml(): string
    {
        return '<p class="acmepay-note">Pay in 4 with AcmePay</p>';
    }

    public function process(Order $order, Request $request): PaymentResult
    {
        return PaymentResult::redirect('https://pay.acme.test/session/'.$order->number);
    }

    public function adminSettings(): array
    {
        return [
            'label' => 'AcmePay',
            'icon' => 'credit-card',
            'description' => 'Split payments with AcmePay.',
            'fields' => static::baseSettingsFields($this->defaultTitle()) + [
                'api_key' => ['type' => 'secret', 'label' => 'API key', 'pattern' => '/^ak_[a-z0-9]+$/', 'message' => 'An AcmePay key starts with ak_.'],
                'merchant' => ['type' => 'text', 'label' => 'Merchant ID', 'mono' => true],
            ],
            'webhook' => ['provider' => 'AcmePay', 'where' => 'AcmePay › Developers'],
        ];
    }

    public function validateSettings(array $values, Validator $validator): void
    {
        if (($values['merchant'] ?? '') === 'forbidden') {
            $validator->errors()->add('payments.acmepay.merchant', 'That merchant is not allowed.');
        }
    }
}
