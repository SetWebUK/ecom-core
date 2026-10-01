<?php

namespace Pine\Commerce\Listeners;

use Pine\Commerce\Events\OrderStatusChanged;
use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Mail\AdminCancelledOrder;
use Pine\Commerce\Mail\AdminFailedOrder;
use Pine\Commerce\Mail\AdminNewOrder;
use Pine\Commerce\Mail\CustomerCompletedOrder;
use Pine\Commerce\Mail\CustomerOnHoldOrder;
use Pine\Commerce\Mail\CustomerProcessingOrder;
use Pine\Commerce\Mail\CustomerRefundedOrder;
use Pine\Commerce\Mail\Support\OrderEmail;
use Pine\Commerce\Models\Order;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Transactional order emails, triggered exactly like WooCommerce's WC_Email classes:
 *   pending/failed/cancelled -> processing|on-hold|completed   admin "New order"
 *   -> processing                                             customer "Processing order" (order received)
 *   pending/failed/cancelled -> on-hold                       customer "On-hold order" (bank transfer details)
 *   -> completed                                              customer "Completed order" (tracking number)
 *   -> refunded                                               customer "Refunded order"
 *   processing/on-hold -> cancelled                           admin "Cancelled order"
 *   pending/on-hold -> failed                                 admin "Failed order"
 * Each email can be switched off with the setting "emails.{key}.enabled" (key = new_order, customer_processing...)
 * and replaced by a client Mailable with Commerce::orderEmail($key, $class).
 * Runs after the surrounding DB transaction commits.
 */
class SendOrderStatusEmails implements ShouldHandleEventsAfterCommit
{
    public const UNPAID = ['pending', 'failed', 'cancelled'];

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        $from = (string) $event->from;
        $to = $event->to;

        if (in_array($from, self::UNPAID, true) && in_array($to, ['processing', 'on-hold', 'completed'], true)) {
            static::toAdmin('new_order', static::mail('new_order', AdminNewOrder::class, $order));
        }

        match ($to) {
            'processing' => static::toCustomer('customer_processing', $order, static::mail('customer_processing', CustomerProcessingOrder::class, $order)),
            'on-hold' => in_array($from, self::UNPAID, true) ? static::toCustomer('customer_on_hold', $order, static::mail('customer_on_hold', CustomerOnHoldOrder::class, $order)) : null,
            'completed' => static::toCustomer('customer_completed', $order, static::mail('customer_completed', CustomerCompletedOrder::class, $order)),
            'refunded' => static::toCustomer('customer_refunded', $order, static::mail('customer_refunded', CustomerRefundedOrder::class, $order)),
            'cancelled' => in_array($from, ['processing', 'on-hold'], true) ? static::toAdmin('cancelled_order', static::mail('cancelled_order', AdminCancelledOrder::class, $order)) : null,
            'failed' => in_array($from, ['pending', 'on-hold'], true) ? static::toAdmin('failed_order', static::mail('failed_order', AdminFailedOrder::class, $order)) : null,
            default => null,
        };
    }

    /** The Mailable for an order email: the client's replacement (Commerce::orderEmail($key, …)) or the core one. */
    public static function mail(string $key, string $default, Order $order): Mailable
    {
        $class = app(ExtensionRegistry::class)->orderEmail($key) ?? $default;

        return new $class($order);
    }

    public static function enabled(string $key): bool
    {
        return filter_var(setting('emails.'.$key.'.enabled', true), FILTER_VALIDATE_BOOL);
    }

    /** @return array<int, string> */
    public static function adminRecipients(): array
    {
        $raw = setting('emails.admin_address') ?: setting('emails.admin_recipient') ?: setting('store.email') ?: config('mail.from.address');

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    public static function toAdmin(string $key, Mailable $mail): bool
    {
        $to = static::adminRecipients();
        if (! $to || ! static::enabled($key)) {
            return false;
        }

        return static::send($to, $mail);
    }

    public static function toCustomer(string $key, Order $order, Mailable $mail): bool
    {
        if (! $order->email || ! filter_var($order->email, FILTER_VALIDATE_EMAIL) || ! static::enabled($key)) {
            return false;
        }
        $sent = static::send([$order->email], $mail);
        if ($sent && $mail instanceof OrderEmail) {
            $order->addNote(sprintf('%s email sent to %s.', $mail->label(), $order->email));
        }

        return $sent;
    }

    protected static function send(array $to, Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (\Throwable $e) {
            Log::error('Order email '.class_basename($mail).' failed: '.$e->getMessage());

            return false;
        }
    }
}
