<?php

namespace Pine\Commerce\Services\Payments;

use Pine\Commerce\Contracts\PaymentGateway;
use Pine\Commerce\Models\Order;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for checkout payment methods (implements Pine\Commerce\Contracts\PaymentGateway). Configuration lives in
 * settings written by the back office: payments.{code}.enabled / .title / ... (secrets are stored with
 * Crypt::encryptString). A client gateway extends this class and is registered with Commerce::gateway().
 */
abstract class Gateway implements PaymentGateway
{
    abstract public function code(): string;

    abstract protected function defaultTitle(): string;

    /** Has everything it needs (keys etc.) to take a payment. */
    abstract public function isConfigured(): bool;

    /** Start taking payment for a freshly placed (pending) order. */
    abstract public function process(Order $order, Request $request): PaymentResult;

    public function title(): string
    {
        return trim((string) $this->setting('title')) ?: $this->defaultTitle();
    }

    /** Short text shown under the method on the checkout. */
    public function description(): ?string
    {
        return trim((string) $this->setting('description')) ?: null;
    }

    public function isEnabled(): bool
    {
        return filter_var($this->setting('enabled', false), FILTER_VALIDATE_BOOL);
    }

    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    /** Payment method logos (trusted HTML/SVG) for the checkout list. */
    public function icons(): string
    {
        return '';
    }

    /** Extra trusted HTML inside the method's checkout panel (the built-in themes render Stripe's card element themselves). */
    public function checkoutHtml(): string
    {
        return '';
    }

    /**
     * Card + fields in Admin › Settings › Payments (see PaymentGateway::adminSettings()). The default offers the three
     * fields every gateway has; override and add your own (keep enabled/title/description).
     */
    public function adminSettings(): array
    {
        return [
            'label' => $this->defaultTitle(),
            'icon' => 'credit-card',
            'description' => '',
            'fields' => static::baseSettingsFields($this->defaultTitle()),
            'webhook' => null,
        ];
    }

    /** The enabled / title / description fields, for adminSettings() overrides. */
    public static function baseSettingsFields(string $defaultTitle, ?string $defaultDescription = null, string $enabledLabel = 'Offer at checkout'): array
    {
        return [
            'enabled' => ['type' => 'bool', 'label' => $enabledLabel],
            'title' => ['type' => 'text', 'label' => 'Name at checkout', 'default' => $defaultTitle],
            'description' => ['type' => 'text', 'label' => 'Text under the name'] + ($defaultDescription !== null ? ['default' => $defaultDescription] : []),
        ];
    }

    /** Extra validation when Admin › Settings › Payments is saved. */
    public function validateSettings(array $values, Validator $validator): void {}

    /** Customer came back from the provider (return URL). */
    public function handleReturn(Order $order, Request $request): PaymentResult
    {
        return PaymentResult::success($order->view_url);
    }

    /** Provider webhook. */
    public function handleWebhook(Request $request): Response
    {
        return response('Webhooks are not used by this payment method.', 404);
    }

    /**
     * Send money back to the customer. Returns the provider's refund reference.
     *
     * @throws PaymentException
     */
    public function refund(Order $order, float $amount, ?string $reason = null): ?string
    {
        throw new PaymentException($this->title().' does not support automatic refunds.');
    }

    protected function setting(string $key, $default = null)
    {
        return setting('payments.'.$this->code().'.'.$key, $default);
    }

    /** Read an encrypted setting; values saved before encryption was introduced are used as-is. */
    protected function secret(string $key): ?string
    {
        $value = $this->setting($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $value = Crypt::decryptString($value);
        } catch (DecryptException) {
            // stored in plain text
        }

        return trim($value) !== '' ? trim($value) : null;
    }

    protected function flag(string $key, bool $default = false): bool
    {
        return filter_var($this->setting($key, $default), FILTER_VALIDATE_BOOL);
    }

    /** Amount in minor units (pence). */
    protected function pence(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
