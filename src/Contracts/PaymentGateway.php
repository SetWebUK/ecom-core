<?php

namespace Pine\Commerce\Contracts;

use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\PaymentException;
use Pine\Commerce\Services\Payments\PaymentResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * A checkout payment method. The built-in Stripe, PayPal and bank-transfer gateways implement it through the abstract
 * base class Pine\Commerce\Services\Payments\Gateway, which is also the easiest starting point for a client gateway:
 *
 *   Commerce::gateway('klarna', \App\Payments\KlarnaGateway::class);   // or config commerce.payments.gateways
 *
 * Settings live in the settings table under payments.{code}.* and are edited in Admin › Settings › Payments, which
 * renders the card and fields the gateway declares in adminSettings(). See docs/EXTENDING.md "Payment gateways".
 */
interface PaymentGateway
{
    /** Unique code, stored on orders (orders.payment_method) and used in URLs (webhooks/{code}, checkout/return/{code}). */
    public function code(): string;

    /** Name shown at checkout, in emails and in the admin. */
    public function title(): string;

    /** Short text under the method at checkout. */
    public function description(): ?string;

    /** Payment method logos (trusted HTML/SVG) for the checkout list. */
    public function icons(): string;

    /** Extra trusted HTML rendered inside the method's panel at checkout (card element, notes); '' for none. */
    public function checkoutHtml(): string;

    /** Switched on in Admin › Settings › Payments. */
    public function isEnabled(): bool;

    /** Has everything it needs (keys etc.) to take a payment. */
    public function isConfigured(): bool;

    /** Enabled and configured: offered at checkout. */
    public function isAvailable(): bool;

    /** Start taking payment for a freshly placed (pending) order. */
    public function process(Order $order, Request $request): PaymentResult;

    /** The customer came back from the provider (GET checkout/return/{code}). */
    public function handleReturn(Order $order, Request $request): PaymentResult;

    /** Provider webhook (POST webhooks/{code}, CSRF-exempt). */
    public function handleWebhook(Request $request): Response;

    public function supportsRefunds(): bool;

    /**
     * Send money back to the customer; returns the provider's refund reference.
     *
     * @throws PaymentException
     */
    public function refund(Order $order, float $amount, ?string $reason = null): ?string;

    /**
     * The gateway's card in Admin › Settings › Payments:
     *   ['label' => 'Klarna', 'icon' => 'credit-card' (admin icon), 'description' => '…',
     *    'fields' => [key => ['type' => bool|text|textarea|secret, 'label' => '…', 'help' => ?, 'default' => ?,
     *                        'placeholder' => ?, 'pattern' => ?regex, 'message' => ?regex error, 'mono' => ?bool,
     *                        'optional' => ?bool, 'wide' => ?bool]],
     *    'webhook' => null | ['provider' => 'Klarna', 'where' => 'Klarna › Settings › Webhooks']]
     * Values are stored as payments.{code}.{key}; 'secret' fields are encrypted and never sent back to the browser.
     *
     * @return array{label:string, icon:string, description:string, fields:array<string,array>, webhook?:?array}
     */
    public function adminSettings(): array;

    /** Extra checks when the payments form is saved ($values = this gateway's submitted fields). */
    public function validateSettings(array $values, Validator $validator): void;
}
