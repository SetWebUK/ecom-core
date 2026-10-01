<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;
use Pine\Commerce\Services\Payments\Gateways\BacsGateway;
use Pine\Commerce\Services\Payments\PaymentManager;

/** Customer: order received but awaiting payment, e.g. bank transfer details (WC "Order on-hold"). */
class CustomerOnHoldOrder extends OrderEmail
{
    public function label(): string
    {
        return 'Order on-hold';
    }

    protected function subjectText(): string
    {
        return sprintf('Your %s order has been received!', static::storeName());
    }

    protected function headingText(): string
    {
        return 'Thank you for your order';
    }

    protected function template(): string
    {
        return 'customer-on-hold-order';
    }

    protected function extra(): array
    {
        $bacs = $this->order->payment_method === 'bacs' ? app(PaymentManager::class)->get('bacs') : null;

        return [
            'bacsInstructions' => $bacs instanceof BacsGateway ? $bacs->instructions() : null,
            'bacsAccounts' => $bacs instanceof BacsGateway ? $bacs->accounts() : [],
        ];
    }
}
