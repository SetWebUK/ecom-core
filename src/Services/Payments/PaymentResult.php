<?php

namespace Pine\Commerce\Services\Payments;

/**
 * What the checkout should do after asking a gateway to take payment.
 *  - success:  payment taken / order placed on hold - go to $redirect (thank-you page)
 *  - redirect: send the customer off-site to approve the payment (PayPal)
 *  - action:   the browser must finish the payment (Stripe Payment Element confirmPayment with $data)
 *  - failure:  show $message and let the customer try again
 */
class PaymentResult
{
    public function __construct(
        public string $status,
        public ?string $redirect = null,
        public array $data = [],
        public ?string $message = null,
    ) {
    }

    public static function success(string $redirect): self
    {
        return new self('success', $redirect);
    }

    public static function redirect(string $url): self
    {
        return new self('redirect', $url);
    }

    public static function action(array $data): self
    {
        return new self('action', null, $data);
    }

    public static function failure(string $message, ?string $redirect = null): self
    {
        return new self('failure', $redirect, [], $message);
    }

    public function successful(): bool
    {
        return in_array($this->status, ['success', 'redirect', 'action'], true);
    }
}
