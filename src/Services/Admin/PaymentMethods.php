<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\Order;
use Illuminate\Support\Facades\Cache;

/** Human names for payment gateway codes (current gateways + legacy WooCommerce ones on imported orders). */
class PaymentMethods
{
    public const LABELS = [
        'stripe' => 'Card (Stripe)',
        'paypal' => 'PayPal',
        'bacs' => 'Bank transfer',
        'cheque' => 'Cheque',
        'cod' => 'Cash on delivery',
        'cash' => 'Cash',
        'card_phone' => 'Card over the phone',
        'manual' => 'Manual / other',
        'woocommerce_payments' => 'WooPayments (card, Apple Pay, Google Pay)',
        'ppcp-gateway' => 'PayPal (legacy)',
        'ppcp-credit-card-gateway' => 'PayPal card (legacy)',
        'superpayments' => 'Super Payments (legacy)',
        'takepaymentscardpayments' => 'TakePayments card (legacy)',
    ];

    /** Payment methods staff can choose when creating an order by hand. */
    public const MANUAL_OPTIONS = [
        'bacs' => 'Bank transfer',
        'card_phone' => 'Card over the phone',
        'cash' => 'Cash',
        'paypal' => 'PayPal',
        'stripe' => 'Card (Stripe)',
        'manual' => 'Other',
    ];

    public static function label(?string $code, ?string $fallback = null): string
    {
        if (! $code) {
            return $fallback ?: '—';
        }

        return self::LABELS[$code] ?? ($fallback ?: ucwords(str_replace(['_', '-'], ' ', $code)));
    }

    /**
     * Link to the payment in the provider's dashboard, when the gateway (and so the dashboard) is known:
     * Stripe payment intents/charges and PayPal transaction ids. WooPayments (legacy) payments live in the old
     * WooCommerce dashboard, so they get no link.
     *
     * @return array{url:string, label:string}|null
     */
    public static function transactionLink(?string $code, ?string $transactionId): ?array
    {
        $id = trim((string) $transactionId);
        if ($id === '' || ! preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $id)) {
            return null;
        }
        $isStripeId = (bool) preg_match('/^(pi|ch|py)_[A-Za-z0-9]+$/', $id);
        if ($code === 'stripe' && $isStripeId) {
            return ['url' => 'https://dashboard.stripe.com/payments/'.$id, 'label' => 'View in Stripe'];
        }
        if (in_array($code, ['paypal', 'ppcp-gateway', 'ppcp-credit-card-gateway'], true) && ! $isStripeId && preg_match('/^[A-Z0-9]{12,20}$/', $id)) {
            return ['url' => 'https://www.paypal.com/activity/payment/'.$id, 'label' => 'View in PayPal'];
        }

        return null;
    }

    /** Filter options for the orders table: every gateway code present on orders. */
    public static function filterOptions(): array
    {
        $codes = Cache::remember('admin.payment-method-codes', 600, fn () => Order::query()
            ->whereNotNull('payment_method')->where('payment_method', '!=', '')
            ->distinct()->pluck('payment_method')->all());

        $options = [];
        foreach ($codes as $code) {
            $options[$code] = self::label($code);
        }
        asort($options);

        return $options;
    }
}
