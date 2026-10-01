<?php

namespace Pine\Commerce\Services\Payments;

use InvalidArgumentException;
use Pine\Commerce\Contracts\PaymentGateway;
use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\Gateways\BacsGateway;
use Pine\Commerce\Services\Payments\Gateways\PaypalGateway;
use Pine\Commerce\Services\Payments\Gateways\StripeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registry of checkout payment methods + the shared "payment succeeded / failed" bookkeeping.
 * Gateways: config commerce.payments.gateways (code => class, in checkout order; package default stripe, paypal, bacs)
 * followed by Commerce::gateway() registrations (same code = replaced in place). Every gateway implements
 * Pine\Commerce\Contracts\PaymentGateway.
 *
 *   app(PaymentManager::class)->available()                       enabled & configured gateways (checkout order)
 *   app(PaymentManager::class)->refund($order, 10.00, 'Reason')   refund through the order's gateway (bool)
 *   PaymentManager::complete($order, 'stripe', 'pi_123', 99.00)   mark paid: payments row, note, pending -> processing
 */
class PaymentManager
{
    /** Used when config commerce.payments.gateways is missing or empty. */
    public const DEFAULTS = [
        'stripe' => StripeGateway::class,
        'paypal' => PaypalGateway::class,
        'bacs' => BacsGateway::class,
    ];

    /** @var array<string, PaymentGateway> */
    protected array $gateways;

    protected ?string $lastError = null;

    protected ?string $lastRefundId = null;

    public function __construct()
    {
        $classes = array_replace((array) config('commerce.payments.gateways', []) ?: self::DEFAULTS,
            app(ExtensionRegistry::class)->gateways());
        $this->gateways = [];
        foreach ($classes as $code => $gateway) {
            if ($gateway === null || $gateway === false) {
                continue; // switched off by config / Commerce::gateway($code, null)
            }
            $gateway = is_string($gateway) ? app($gateway) : $gateway;
            if (! $gateway instanceof PaymentGateway) {
                throw new InvalidArgumentException('Payment gateway ['.$code.'] must implement '.PaymentGateway::class.'.');
            }
            if ($gateway->code() !== (string) $code) {
                throw new InvalidArgumentException('Payment gateway ['.$code.'] reports code ['.$gateway->code().'] – register it under its own code.');
            }
            $this->gateways[(string) $code] = $gateway;
        }
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }

    /** Gateways customers can use right now (enabled in settings and fully configured). */
    public function available(): array
    {
        return array_filter($this->gateways, fn (PaymentGateway $g) => $g->isAvailable());
    }

    public function get(?string $code): ?PaymentGateway
    {
        return $this->gateways[(string) $code] ?? null;
    }

    public function availableGateway(?string $code): ?PaymentGateway
    {
        $gateway = $this->get($code);

        return $gateway && $gateway->isAvailable() ? $gateway : null;
    }

    public function supportsRefunds(?string $code): bool
    {
        $gateway = $this->get($code);

        return $gateway !== null && $gateway->supportsRefunds() && $gateway->isConfigured();
    }

    /**
     * Refund part or all of an order through its payment gateway. Returns false (see lastError()) when the
     * gateway cannot refund or the provider declines; the caller records the Refund row itself.
     */
    public function refund(Order $order, float $amount, ?string $reason = null): bool
    {
        $this->lastError = null;
        $this->lastRefundId = null;
        $amount = round($amount, 2);

        if (! $this->supportsRefunds($order->payment_method)) {
            $this->lastError = 'Automatic refunds are not available for this payment method.';

            return false;
        }
        if ($amount <= 0 || $amount > round((float) $order->total - (float) $order->refunded_total, 2) + 0.001) {
            $this->lastError = 'The refund amount is not valid for this order.';

            return false;
        }

        try {
            $this->lastRefundId = $this->get($order->payment_method)->refund($order, $amount, $reason);

            return true;
        } catch (PaymentException $e) {
            $this->lastError = $e->getMessage();
            Log::warning('Refund of '.money($amount).' for order '.$order->number.' failed: '.$e->getMessage());

            return false;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastRefundId(): ?string
    {
        return $this->lastRefundId;
    }

    /**
     * Record a successful payment. Idempotent (return URL and webhook may both arrive).
     * Moves pending / failed / on-hold / cancelled orders to processing; an underpayment puts it on hold.
     */
    public static function complete(Order $order, string $gateway, ?string $transactionId, float $amount, array $payload = [], ?string $note = null, ?string $paymentReference = null): void
    {
        $paymentReference ??= $transactionId;

        DB::transaction(function () use ($order, $gateway, $transactionId, $amount, $payload, $note, $paymentReference) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            $payment = $locked->payments()->where('gateway', $gateway)->where('reference', $paymentReference)->lockForUpdate()->first();
            if ($payment && $payment->status === 'succeeded') {
                return;
            }

            $data = [
                'status' => 'succeeded',
                'amount' => round($amount, 2),
                'payload' => array_merge((array) ($payment?->payload ?? []), $payload, ['transaction_id' => $transactionId, 'completed_at' => now()->toIso8601String()]),
            ];
            $payment ? $payment->update($data) : $locked->payments()->create($data + ['gateway' => $gateway, 'reference' => $paymentReference]);

            $locked->transaction_id = $transactionId ?: $locked->transaction_id;
            $locked->paid_at ??= now();
            $locked->save();

            $title = app(self::class)->get($gateway)?->title() ?? $gateway;
            $message = $note ?? sprintf('%s payment completed (Transaction ID: %s).', $title, $transactionId ?: 'n/a');

            if ($amount + 0.01 < (float) $locked->total) {
                $locked->updateStatus('on-hold', $message.' The amount paid ('.money($amount).') is less than the order total ('.money($locked->total).') - please check before dispatching.');
            } elseif (in_array($locked->status, ['pending', 'failed', 'on-hold', 'cancelled'], true)) {
                $locked->updateStatus('processing', $message);
            } else {
                $locked->addNote($message);
            }
        });

        $order->refresh();
        // the basket this order came from is done (also when the webhook arrives before the customer returns)
        if (in_array($order->status, ['processing', 'completed', 'on-hold'], true)) {
            \Pine\Commerce\Services\Checkout\CheckoutService::closeBasket($order);
        }
    }

    /** Record a failed payment attempt: pending orders become failed (WooCommerce behaviour). */
    public static function fail(Order $order, string $gateway, ?string $reference, string $message): void
    {
        DB::transaction(function () use ($order, $gateway, $reference, $message) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($reference) {
                $locked->payments()->where('gateway', $gateway)->where('reference', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            }
            if ($locked->status === 'pending') {
                $locked->updateStatus('failed', $message);
            } elseif (! $locked->isPaid()) {
                $locked->addNote($message);
            }
        });

        $order->refresh();
    }
}
