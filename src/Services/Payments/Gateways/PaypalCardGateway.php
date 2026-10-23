<?php

namespace Pine\Commerce\Services\Payments\Gateways;

use Pine\Commerce\Models\Order;

/**
 * "Debit or credit card" through PayPal: the same PayPal app and keys as PaypalGateway (Admin › Settings › Payments ›
 * PayPal), but PayPal's page opens on its card form (Orders v2 landing_page GUEST_CHECKOUT), so the customer pays by
 * Visa / Mastercard / Maestro / Amex without a PayPal account – what the WooCommerce PayPal Payments plugin offers as
 * "Debit & Credit Cards". Needs "PayPal account optional" on in the PayPal business account (on by default in the UK).
 *
 * Settings: payments.paypal_card.enabled, .title, .description (credentials and sandbox: payments.paypal.*).
 * Captures, refunds and the /webhooks/paypal notifications work exactly as for PayPal.
 */
class PaypalCardGateway extends PaypalGateway
{
    use SharesPaypalKeys;

    public function code(): string
    {
        return 'paypal_card';
    }

    protected function defaultTitle(): string
    {
        return 'Debit or credit card';
    }

    public function description(): ?string
    {
        return trim((string) $this->setting('description')) ?: 'Visa, Mastercard, Maestro or American Express – processed securely by PayPal. No PayPal account needed.';
    }

    public function adminSettings(): array
    {
        return [
            'label' => 'Debit or credit card (via PayPal)',
            'icon' => 'credit-card',
            'description' => 'Card payments through your PayPal account – uses the PayPal keys above, no separate card account. PayPal’s card form opens first; it needs “PayPal account optional” switched on in PayPal (Account settings › Website payments).',
            'fields' => static::baseSettingsFields('Debit or credit card', 'Visa, Mastercard, Maestro or American Express – processed securely by PayPal. No PayPal account needed.'),
            'webhook' => null,
        ];
    }

    /** Card marks only (no PayPal / Apple Pay / Google Pay, which this method doesn't offer). */
    public function icons(): string
    {
        return \Pine\Commerce\Theme\Storefront::paymentIcons(['visa', 'mastercard', 'maestro', 'amex']);
    }

    protected function experienceContext(Order $order): array
    {
        return ['landing_page' => 'GUEST_CHECKOUT'] + parent::experienceContext($order);
    }

}
