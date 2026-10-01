<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Mail\Admin\CustomerInvoice;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\OrderItem;
use Pine\Commerce\Models\OrderNote;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\Refund;
use Pine\Commerce\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * Back-office order operations: status changes, fulfilment, notes, refunds, manual (phone) orders, editing orders,
 * addresses, deleting and (re-)sending emails.
 *
 * Emails, stock and payment-gateway refunds are delegated to the storefront's classes when they exist
 * (Pine\Commerce\Mail\*, Pine\Commerce\Services\Checkout\OrderStock, Pine\Commerce\Services\Payments\PaymentManager), so the admin never
 * duplicates checkout logic and keeps working before/after those classes change. Status changes always go through
 * Order::updateStatus() so the OrderStatusChanged listeners (customer emails, stock) run exactly as for the checkout.
 */
class OrderManager
{
    public const PAYMENT_MANAGER = 'Pine\\Commerce\\Services\\Payments\\PaymentManager';

    public const ORDER_STOCK = 'Pine\\Commerce\\Services\\Checkout\\OrderStock';

    /** Statuses whose items/prices can still be edited (nothing has been paid or sent yet). */
    public const EDITABLE_STATUSES = ['pending', 'on-hold', 'failed'];

    /** Statuses a (soft) delete is allowed for: unpaid orders only. */
    public const DELETABLE_STATUSES = ['pending', 'failed'];

    /** Statuses staff can create a manual order in. */
    public const MANUAL_STATUSES = [
        'pending' => 'Pending payment',
        'processing' => 'Processing (paid)',
        'on-hold' => 'On hold',
    ];

    public const ADDRESS_FIELDS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'county', 'postcode', 'country'];

    // Status & fulfilment ---------------------------------------------------------

    public static function isEditable(Order $order): bool
    {
        return in_array($order->status, self::EDITABLE_STATUSES, true);
    }

    public static function isDeletable(Order $order): bool
    {
        return in_array($order->status, self::DELETABLE_STATUSES, true);
    }

    /** Change status with a note naming the staff member (emails/stock follow via OrderStatusChanged). */
    public static function changeStatus(Order $order, string $status, ?string $note = null): bool
    {
        if ($order->status === $status || ! array_key_exists($status, Order::STATUSES)) {
            return false;
        }
        $by = 'by '.static::staffName().'.';
        $order->updateStatus($status, trim(($note ? rtrim($note, '. ').'. ' : '').'Changed '.$by));
        static::confirmPendingPayment($order);

        return true;
    }

    /**
     * Staff marked the order paid (processing / completed): its pending payment row for the order's payment method
     * (e.g. the bank transfer BacsGateway records as "pending") becomes "succeeded", with the time it was confirmed
     * (payload completed_at, like gateway payments) - otherwise reports and the payments list keep showing it unpaid.
     */
    public static function confirmPendingPayment(Order $order): int
    {
        if (! $order->isPaid() || ! $order->payment_method) {
            return 0;
        }
        $confirmed = 0;
        foreach ($order->payments()->where('gateway', $order->payment_method)->where('status', 'pending')->get() as $payment) {
            $payment->update([
                'status' => 'succeeded',
                'payload' => array_merge((array) ($payment->payload ?? []), [
                    'completed_at' => ($order->paid_at ?? now())->toIso8601String(),
                    'confirmed_by' => static::staffName(),
                ]),
            ]);
            $confirmed++;
        }

        return $confirmed;
    }

    /** Save tracking details and mark the order completed (the customer's "Completed order" email includes them). */
    public static function fulfil(Order $order, ?string $carrier, ?string $number): void
    {
        $carrier = trim((string) $carrier) ?: null;
        $number = trim((string) $number) ?: null;

        DB::transaction(function () use ($order, $carrier, $number) {
            if ($carrier !== $order->tracking_carrier || $number !== $order->tracking_number) {
                $order->tracking_carrier = $carrier;
                $order->tracking_number = $number;
                $order->save();
                if ($number) {
                    $order->addNote('Tracking added: '.trim(($carrier ? $carrier.' ' : '').$number).'.');
                }
            }
            if ($order->status !== 'completed') {
                $order->updateStatus('completed', 'Marked as fulfilled by '.static::staffName().'.');
                static::confirmPendingPayment($order);
            }
        });
    }

    // Notes -----------------------------------------------------------------------

    /** Add a staff note. Customer notes are emailed to the customer (Pine\Commerce\Mail\CustomerNote) when that mail exists. */
    public static function addNote(Order $order, string $text, bool $toCustomer = false): array
    {
        $note = $order->notes()->create([
            'note' => $text,
            'is_customer_note' => $toCustomer,
            'is_system' => false,
            'user_id' => auth()->id(),
        ]);

        $emailed = false;
        if ($toCustomer && $order->email) {
            $emailed = static::sendMail('Pine\\Commerce\\Mail\\CustomerNote', $order->email, ['order' => $order, 'note' => $note]);
        }

        return ['note' => $note, 'emailed' => $emailed];
    }

    // Emails ----------------------------------------------------------------------

    /**
     * Order emails that can be (re-)sent from the admin: the "Order details / invoice" email plus every Pine\Commerce\Mail class
     * whose constructor takes just an Order (processing, completed, refunded… from the storefront).
     *
     * @return array<class-string, string> class => friendly label
     */
    public static function resendableEmails(): array
    {
        $emails = [];
        $dir = dirname((new ReflectionClass(\Pine\Commerce\Mail\Support\OrderEmail::class))->getFileName(), 2); // package src/Mail
        foreach (is_dir($dir) ? (glob($dir.'/*.php') ?: []) : [] as $file) {
            $class = 'Pine\\Commerce\\Mail\\'.basename($file, '.php');
            try {
                if (! class_exists($class) || ! is_subclass_of($class, Mailable::class) || (new ReflectionClass($class))->isAbstract()) {
                    continue;
                }
                $constructor = (new ReflectionClass($class))->getConstructor();
            } catch (Throwable) {
                continue;
            }
            $takesOrder = false;
            $needsOther = false;
            foreach ($constructor?->getParameters() ?? [] as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && is_a($type->getName(), Order::class, true)) {
                    $takesOrder = true;
                } elseif (! $param->isOptional() && ! in_array($param->getName(), ['from', 'to', 'oldStatus', 'newStatus', 'status'], true)) {
                    $needsOther = true;
                }
            }
            if ($takesOrder && ! $needsOther) {
                $emails[$class] = static::emailLabel($class);
            }
        }
        asort($emails);

        return [CustomerInvoice::class => 'Order details / invoice'] + $emails;
    }

    public static function emailLabel(string $class): string
    {
        if ($class === CustomerInvoice::class) {
            return 'Order details / invoice';
        }
        $name = class_basename($class);
        $label = (string) str(preg_replace('/^(Customer|Admin)/', '', $name))->headline();

        return (static::isAdminEmail($class) ? 'Store owner: ' : 'Customer: ').$label;
    }

    /** Whether an email class is aimed at the store owner rather than the customer. */
    public static function isAdminEmail(string $class): bool
    {
        return (bool) preg_match('/^(Admin|Staff|Owner)|NewOrder/', class_basename($class));
    }

    /** @return list<string> */
    public static function adminRecipients(): array
    {
        $raw = setting('emails.admin_address') ?: setting('emails.admin_recipient') ?: setting('store.email') ?: config('mail.from.address');

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /** @return string|null the address it went to, null when it could not be sent */
    public static function resendEmail(Order $order, string $class): ?string
    {
        if (! array_key_exists($class, static::resendableEmails())) {
            return null;
        }
        if ($class === CustomerInvoice::class) {
            return static::sendInvoice($order) ? $order->email : null;
        }

        $to = static::isAdminEmail($class) ? implode(', ', static::adminRecipients()) : $order->email;
        $sent = static::sendMail($class, $to ?: null, ['order' => $order, 'from' => $order->status, 'to' => $order->status, 'status' => $order->status]);
        if ($sent) {
            $order->addNote('"'.static::emailLabel($class).'" email sent to '.$to.' by '.static::staffName().'.');
        }

        return $sent ? $to : null;
    }

    /** Email the customer their order details – with a "Pay for this order" link while it is unpaid. */
    public static function sendInvoice(Order $order): bool
    {
        if (! $order->email || ! filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $attach = CustomerInvoice::invoiceAttachment($order)['attach'];
        try {
            Mail::to($order->email)->send(new CustomerInvoice($order));
        } catch (Throwable $e) {
            Log::warning('Admin failed to send the invoice for order '.$order->number.': '.$e->getMessage());

            return false;
        }
        // the send issues the number of a paid order that has none yet
        $number = $attach ? (\Pine\Commerce\Services\Invoices\Invoices::number($order, false) ?? $order->number) : null;
        $order->addNote((CustomerInvoice::needsPayment($order) ? 'Payment link' : 'Order details').' emailed to '.$order->email.' by '.static::staffName()
            .($number ? ', with invoice '.$number.' (PDF)' : '').'.');

        return true;
    }

    /** Build a mailable by matching constructor parameters by name/type, then send it. */
    public static function sendMail(string $class, ?string $to, array $available): bool
    {
        if (! $to || ! class_exists($class)) {
            return false;
        }

        try {
            $args = [];
            foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
                $name = $param->getName();
                $type = $param->getType() instanceof ReflectionNamedType ? $param->getType()->getName() : null;
                $value = null;
                $found = false;
                if (array_key_exists($name, $available)) {
                    $value = $available[$name];
                    $found = true;
                } else {
                    foreach ($available as $candidate) {
                        if ($type && is_object($candidate) && is_a($candidate, $type)) {
                            $value = $candidate;
                            $found = true;
                            break;
                        }
                    }
                }
                // Adapt note model <-> text depending on what the mailable expects
                if ($found && $value instanceof OrderNote && $type === 'string') {
                    $value = $value->note;
                }
                if (! $found) {
                    if ($param->isOptional()) {
                        break;
                    }
                    throw new RuntimeException("Cannot build {$class}: no value for \${$name}");
                }
                $args[] = $value;
            }

            $recipients = array_values(array_filter(array_map('trim', explode(',', $to))));
            Mail::to($recipients)->send(new $class(...$args));

            return true;
        } catch (Throwable $e) {
            Log::warning('Admin failed to send '.$class.': '.$e->getMessage());

            return false;
        }
    }

    // Refunds ---------------------------------------------------------------------

    public static function refundableAmount(Order $order): float
    {
        return max(0, round((float) $order->total - (float) $order->refunded_total, 2));
    }

    /** Shipping already refunded (recorded on refunds as a {type: shipping} line). */
    public static function refundedShipping(Order $order): float
    {
        $refunds = $order->relationLoaded('refunds') ? $order->refunds : $order->refunds()->get(['id', 'items']);

        return round($refunds->sum(fn (Refund $r) => collect((array) $r->items)->where('type', 'shipping')->sum('amount')), 2);
    }

    public static function gatewayCanRefund(Order $order): bool
    {
        if (! class_exists(self::PAYMENT_MANAGER) || ! $order->payment_method || ! in_array($order->status, ['processing', 'completed', 'on-hold', 'refunded'], true)) {
            return false;
        }
        try {
            $manager = app(self::PAYMENT_MANAGER);
            if (! method_exists($manager, 'refund')) {
                return false;
            }
            if (method_exists($manager, 'supportsRefunds')) {
                return (bool) $manager->supportsRefunds($order->payment_method);
            }
            if (method_exists($manager, 'canRefund')) {
                return (bool) $manager->canRefund($order);
            }

            return in_array($order->payment_method, ['stripe', 'paypal'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Record a (partial or full) refund, optionally sending the money back through the payment gateway first.
     * The order row is locked for the whole operation so two clicks can never refund twice.
     *
     * @param  array<int, array{order_item_id:int, quantity:int, amount:float}>  $lines
     */
    public static function refund(Order $order, float $amount, array $lines = [], ?string $reason = null, bool $restock = false, bool $viaGateway = false, float $shipping = 0.0): Refund
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }

        $refund = DB::transaction(function () use ($order, $amount, $lines, $reason, $restock, $viaGateway, $shipping) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($amount > static::refundableAmount($locked) + 0.001) {
                throw new RuntimeException('You can refund at most '.money(static::refundableAmount($locked)).'.');
            }

            $gatewayRefundId = $viaGateway ? static::refundViaGateway($locked, $amount, $reason) : null;
            if ($viaGateway) {
                // Money has left the account – keep a trace even if saving the refund below were to fail
                Log::info('Gateway refund of '.money($amount).' for order '.$locked->number.' via '.$locked->payment_method.' (ref '.($gatewayRefundId ?? 'n/a').') by '.static::staffName());
            }

            $items = $locked->items()->lockForUpdate()->get()->keyBy('id');
            $recorded = [];
            $restocked = [];
            foreach ($lines as $line) {
                $item = $items->get((int) ($line['order_item_id'] ?? 0));
                $qty = max(0, (int) ($line['quantity'] ?? 0));
                $lineAmount = round((float) ($line['amount'] ?? 0), 2);
                if (! $item || ($qty === 0 && $lineAmount <= 0)) {
                    continue;
                }
                $qty = min($qty, max(0, $item->quantity - $item->refunded_quantity));
                if ($qty > 0) {
                    $item->increment('refunded_quantity', $qty);
                    if ($restock && static::adjustStock($item, $qty)) {
                        $restocked[] = $item->name.' ×'.$qty;
                    }
                }
                $recorded[] = ['order_item_id' => $item->id, 'name' => $item->name, 'quantity' => $qty, 'amount' => $lineAmount];
            }
            if ($shipping > 0) {
                $recorded[] = ['type' => 'shipping', 'name' => 'Shipping', 'quantity' => 0, 'amount' => round($shipping, 2)];
            }

            $refund = $locked->refunds()->create([
                'user_id' => auth()->id(),
                'amount' => $amount,
                'reason' => $reason,
                'items' => $recorded ?: null,
                'restock' => $restock && $restocked !== [],
                'gateway_refund_id' => $gatewayRefundId,
                'status' => 'completed',
            ]);

            $locked->refunded_total = round((float) $locked->refunded_total + $amount, 2);
            $locked->save();

            $how = $viaGateway
                ? 'via '.PaymentMethods::label($locked->payment_method, $locked->payment_method_title).($gatewayRefundId ? ' (refund ID '.$gatewayRefundId.')' : '')
                : 'manually – no money was sent through the payment provider';
            $locked->addNote('Refunded '.money($amount).' '.$how.' by '.static::staffName().'.'
                .($reason ? ' Reason: '.$reason : '')
                .($restocked ? ' Returned to stock: '.implode(', ', $restocked).'.' : ''));

            if ((float) $locked->refunded_total >= (float) $locked->total - 0.001 && $locked->status !== 'refunded') {
                $locked->updateStatus('refunded', 'Order fully refunded.');
            }

            return $refund;
        });

        $order->refresh();

        return $refund;
    }

    /** Ask the storefront payment service to send money back. Returns the gateway's refund reference. */
    protected static function refundViaGateway(Order $order, float $amount, ?string $reason): ?string
    {
        if (! static::gatewayCanRefund($order)) {
            throw new RuntimeException('Automatic refunds are not available for '.PaymentMethods::label($order->payment_method, $order->payment_method_title).'. Refund the customer in the payment provider’s dashboard, then record a manual refund.');
        }

        $manager = app(self::PAYMENT_MANAGER);
        $available = ['order' => $order, 'amount' => $amount, 'reason' => $reason];
        $args = [];
        foreach ((new \ReflectionMethod($manager, 'refund'))->getParameters() as $param) {
            if (array_key_exists($param->getName(), $available)) {
                $args[] = $available[$param->getName()];
            } elseif ($param->isOptional()) {
                break;
            } else {
                $args[] = match ($param->getPosition()) {
                    0 => $order, 1 => $amount, default => $reason
                };
            }
        }

        $result = $manager->refund(...$args);
        if ($result === false || (is_array($result) && (($result['success'] ?? $result['ok'] ?? true) === false))) {
            $message = is_array($result) ? ($result['message'] ?? $result['error'] ?? null) : null;
            if (! $message && method_exists($manager, 'lastError')) {
                $message = $manager->lastError();
            }
            throw new RuntimeException('The payment provider didn’t refund the money'.($message ? ': '.rtrim($message, '.').'.' : '.').' Nothing was recorded.');
        }

        $reference = match (true) {
            is_string($result) => $result,
            is_array($result) => (string) ($result['id'] ?? $result['reference'] ?? $result['gateway_refund_id'] ?? ''),
            is_object($result) => (string) ($result->id ?? $result->reference ?? ''),
            default => '',
        };
        if ($reference === '' && method_exists($manager, 'lastRefundId')) {
            $reference = (string) $manager->lastRefundId();
        }

        return $reference !== '' ? $reference : null;
    }

    /** Put stock back (positive qty) or take it out (negative qty) for an order line. Returns whether stock is tracked. */
    public static function adjustStock(OrderItem $item, int $qty): bool
    {
        $variation = $item->product_variation_id ? ProductVariation::find($item->product_variation_id) : null;
        if ($variation && $variation->manage_stock) {
            $variation->stock_quantity = (int) $variation->stock_quantity + $qty;
            $variation->save();

            return true;
        }
        $product = $item->product_id ? Product::withTrashed()->find($item->product_id) : null;
        if ($product && $product->manage_stock) {
            $product->stock_quantity = (int) $product->stock_quantity + $qty;
            $product->save();

            return true;
        }

        return false;
    }

    // Manual orders ---------------------------------------------------------------

    /**
     * Create an order from the admin "Create order" form (phone / manual orders).
     * Stock and coupon usage are taken exactly like the checkout does (Pine\Commerce\Services\Checkout\OrderStock), so
     * cancelling the order later puts the stock back.
     *
     * @param  array<string, mixed>  $data  ManualOrderRequest::orderData()
     */
    public static function createManualOrder(array $data): Order
    {
        $quote = OrderPricing::quote(static::pricingInput($data));
        if (! $quote['lines']) {
            throw new RuntimeException('Add at least one product to the order.');
        }
        if ($quote['coupon_errors']) {
            throw new RuntimeException($quote['coupon_errors'][0]);
        }
        $status = array_key_exists($data['status'] ?? '', self::MANUAL_STATUSES) ? $data['status'] : 'pending';
        $reduceStock = (bool) ($data['reduce_stock'] ?? true);
        $tracksStock = $reduceStock && class_exists(self::ORDER_STOCK);

        $order = DB::transaction(function () use ($data, $quote, $reduceStock, $tracksStock) {
            $order = new Order;
            $order->forceFill(array_merge(static::contactAttributes($data), static::totalsAttributes($quote), [
                'user_id' => $data['user_id'] ?? null,
                'status' => 'pending',
                'currency' => 'GBP',
                'payment_method' => $data['payment_method'] ?? null,
                'payment_method_title' => ! empty($data['payment_method']) ? (PaymentMethods::MANUAL_OPTIONS[$data['payment_method']] ?? PaymentMethods::label($data['payment_method'])) : null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'created_via' => 'admin',
                'source_type' => 'admin',
                'source' => 'Created by '.static::staffName(),
                'ip_address' => request()->ip(),
                'meta' => array_filter([
                    'created_by' => auth()->id(),
                    'coupon_discounts' => $quote['coupons'] ?: null,
                    'manual_discount' => $quote['manual_discount'] ?: null,
                    'tax_rate' => $quote['tax_rate'],
                    'shipping_tax' => $quote['shipping_tax'],
                    'prices_include_tax' => ($quote['prices_include_tax'] ?? false) ? 'yes' : 'no',
                ], fn ($v) => $v !== null) + ($tracksStock ? ['stock_reduced' => false, 'usage_recorded' => false] : []),
            ]));
            $order->save();

            static::writeItems($order, $quote['lines']);
            static::writeTaxLines($order, $quote);

            $order->addNote('Order created by '.static::staffName().'.');
            if (! empty($data['private_note'])) {
                $order->addNote($data['private_note'], false, false);
            }

            if ($tracksStock) {
                $stock = self::ORDER_STOCK;
                $stock::reduce($order);
                $stock::recordUsage($order);
            } else {
                static::recordUsageManually($order, $reduceStock);
            }

            return $order;
        });

        if (class_exists(\Pine\Commerce\Events\OrderPlaced::class)) {
            event(new \Pine\Commerce\Events\OrderPlaced($order));
        }
        if ($status !== 'pending') {
            $order->updateStatus($status, 'Order created by staff.');
        }
        if (! empty($data['user_id']) && ! empty($data['save_addresses'])) {
            static::saveAddressesToCustomer($order, User::find($data['user_id']));
        }
        if (! empty($data['send_invoice'])) {
            static::sendInvoice($order->refresh());
        }

        return $order->refresh();
    }

    /**
     * Edit an order: contact details and addresses always; items, discounts and shipping while it is still
     * editable (pending / on hold / failed). Stock that the order holds is released and taken again for the new items.
     *
     * @param  array<string, mixed>  $data  OrderUpdateRequest::orderData()
     * @return list<string> what changed (also written to the order's timeline)
     */
    public static function updateOrder(Order $order, array $data): array
    {
        $changes = [];

        return DB::transaction(function () use ($order, $data, &$changes) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $before = ['total' => (float) $order->total, 'email' => $order->email];

            $contact = static::contactAttributes($data);
            foreach ($contact as $key => $value) {
                if ((string) $order->{$key} !== (string) $value) {
                    $label = str_starts_with($key, 'shipping_') ? 'shipping address' : (str_starts_with($key, 'billing_') ? 'billing address' : 'contact details');
                    $changes[$label] = $label;
                }
            }
            $order->forceFill($contact);
            if (array_key_exists('customer_note', $data) && (string) $order->customer_note !== (string) $data['customer_note']) {
                $order->customer_note = $data['customer_note'];
                $changes['note'] = 'customer note';
            }
            if (array_key_exists('user_id', $data) && (int) $order->user_id !== (int) $data['user_id']) {
                $order->user_id = $data['user_id'] ?: null;
                $changes['customer'] = 'customer';
            }

            if (static::isEditable($order) && isset($data['lines'])) {
                $quote = OrderPricing::quote(static::pricingInput($data) + [
                    'exclude_order_id' => $order->id,
                    'trusted_coupons' => array_filter(array_map('trim', explode(',', (string) $order->coupon_code))),
                ]);
                if (! $quote['lines']) {
                    throw new RuntimeException('An order needs at least one item.');
                }
                if ($quote['coupon_errors']) {
                    throw new RuntimeException($quote['coupon_errors'][0]);
                }
                $stock = class_exists(self::ORDER_STOCK) ? self::ORDER_STOCK : null;
                $tracked = $stock && $stock::tracks($order);
                $held = $tracked && ($order->meta['stock_reduced'] ?? false);
                $used = $tracked && ($order->meta['usage_recorded'] ?? false);
                if ($held) {
                    $stock::restore($order, false);
                    $order->refresh();
                }
                if ($used) {
                    $stock::reverseUsage($order);
                    $order->refresh();
                }

                $order->forceFill($contact);
                $order->forceFill(static::totalsAttributes($quote));
                $order->meta = array_merge((array) $order->meta, [
                    'coupon_discounts' => $quote['coupons'] ?: null,
                    'manual_discount' => $quote['manual_discount'] ?: null,
                ]);
                $order->save();
                $order->items()->delete();
                static::writeItems($order, $quote['lines']);
                static::writeTaxLines($order, $quote);

                if ($held) {
                    $stock::reduce($order);
                }
                if ($used) {
                    $stock::recordUsage($order);
                }
                if (abs($before['total'] - (float) $order->total) > 0.004) {
                    $changes['items'] = 'items and totals ('.money($before['total']).' → '.money($order->total).')';
                } else {
                    $changes['items'] = 'items';
                }
            }

            $order->save();
            if ($changes) {
                $order->addNote('Order edited by '.static::staffName().': '.implode(', ', array_values(array_unique($changes))).'.');
            }

            return array_values(array_unique($changes));
        });
    }

    /** Update one address (billing includes email + phone) from the order page. */
    public static function saveAddress(Order $order, string $type, array $values): bool
    {
        $changed = false;
        foreach ($values as $key => $value) {
            if ((string) $order->{$key} !== (string) $value) {
                $order->{$key} = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $order->save();
            $order->addNote(ucfirst($type).' address updated by '.static::staffName().'.');
        }

        return $changed;
    }

    /** Soft-delete an unpaid order, releasing any stock it holds. */
    public static function delete(Order $order): void
    {
        if (! static::isDeletable($order)) {
            throw new RuntimeException('Only unpaid (pending or failed) orders can be deleted. Cancel or refund it instead.');
        }
        DB::transaction(function () use ($order) {
            if (class_exists(self::ORDER_STOCK)) {
                $stock = self::ORDER_STOCK;
                $stock::restore($order);
                $stock::reverseUsage($order);
            }
            $order->addNote('Order deleted by '.static::staffName().'.');
            $order->delete();
        });
    }

    /** Copy an order's billing/shipping address onto the customer's default address book entries. */
    public static function saveAddressesToCustomer(Order $order, ?User $user): void
    {
        if (! $user) {
            return;
        }
        foreach (['billing', 'shipping'] as $type) {
            $values = [];
            foreach (self::ADDRESS_FIELDS as $field) {
                $values[$field] = $order->{$type.'_'.$field};
            }
            if ($type === 'billing') {
                $values['email'] = $order->email;
                $values['phone'] = $order->phone;
            } else {
                $values['phone'] = $order->shipping_phone;
            }
            $user->addresses()->updateOrCreate(['type' => $type, 'is_default' => true], $values);
        }
    }

    // Helpers ---------------------------------------------------------------------

    public static function staffName(): string
    {
        return auth()->user()?->full_name ?: 'staff';
    }

    /** Input for OrderPricing::quote() from validated form data. */
    public static function pricingInput(array $data): array
    {
        return [
            'lines' => $data['lines'] ?? [],
            'coupon_code' => $data['coupon_code'] ?? null,
            'manual_discount' => $data['manual_discount'] ?? null,
            'shipping_method' => $data['shipping_method'] ?? null,
            'shipping_cost' => $data['shipping_cost'] ?? null,
            'email' => $data['email'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'address' => static::taxAddress($data),
        ];
    }

    /** Country/county/postcode/city of the order's addresses (tax rates for the order form and saved orders). */
    public static function taxAddress(array $data): array
    {
        $same = (bool) ($data['shipping_same_as_billing'] ?? false);
        $pick = fn (string $prefix) => ['country' => $data[$prefix.'_country'] ?? null, 'state' => $data[$prefix.'_county'] ?? ($data[$prefix.'_state'] ?? null),
            'postcode' => $data[$prefix.'_postcode'] ?? null, 'city' => $data[$prefix.'_city'] ?? null];
        $billing = $pick('billing');
        $billing['country'] = $billing['country'] ?: (isset($data['billing_postcode']) ? 'GB' : null);
        $shipping = $same ? $billing : $pick('shipping');
        $shipping['country'] = $shipping['country'] ?: $billing['country'];

        return ['shipping' => $shipping['country'] ? $shipping : null, 'billing' => $billing['country'] ? $billing : null];
    }

    /** Email, phone and both addresses from form data (shipping = billing when "same as billing"). */
    protected static function contactAttributes(array $data): array
    {
        $same = (bool) ($data['shipping_same_as_billing'] ?? false);
        $attributes = ['email' => $data['email'], 'phone' => $data['phone'] ?? null];
        foreach (self::ADDRESS_FIELDS as $field) {
            $attributes['billing_'.$field] = $data['billing_'.$field] ?? null;
            $attributes['shipping_'.$field] = $same ? ($data['billing_'.$field] ?? null) : ($data['shipping_'.$field] ?? null);
        }
        $attributes['shipping_phone'] = $same ? ($data['phone'] ?? null) : ($data['shipping_phone'] ?? null);
        $attributes['billing_country'] = $attributes['billing_country'] ?: 'GB';
        $attributes['shipping_country'] = $attributes['shipping_country'] ?: $attributes['billing_country'];

        return $attributes;
    }

    protected static function totalsAttributes(array $quote): array
    {
        return [
            'subtotal' => $quote['subtotal'],
            'discount_total' => $quote['discount'],
            'shipping_total' => $quote['shipping'],
            'tax_total' => $quote['tax'],
            'shipping_tax' => $quote['shipping_tax'] ?? 0,
            'prices_include_tax' => (bool) ($quote['prices_include_tax'] ?? false),
            'total' => $quote['total'],
            'coupon_code' => $quote['coupon_codes'] ? implode(',', $quote['coupon_codes']) : null,
            'shipping_method' => $quote['shipping_method']?->code,
            'shipping_method_title' => $quote['shipping_title'],
        ];
    }

    /** Tax per rate of a quote (replaces the order's previous lines). */
    protected static function writeTaxLines(Order $order, array $quote): void
    {
        $order->taxLines()->delete();
        foreach ($quote['tax_lines'] ?? [] as $line) {
            $order->taxLines()->create([
                'tax_rate_id' => $line['id'], 'label' => $line['label'], 'rate' => $line['rate'], 'compound' => $line['compound'],
                'tax_total' => $line['tax'], 'shipping_tax_total' => $line['shipping_tax'],
            ]);
        }
    }

    protected static function writeItems(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            $order->items()->create([
                'product_id' => $line['product_id'],
                'product_variation_id' => $line['variation_id'],
                'name' => $line['name'],
                'sku' => $line['sku'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'subtotal' => $line['net_subtotal'] ?? $line['subtotal'],
                'total' => $line['net_total'] ?? $line['total'],
                'tax' => $line['tax'],
                'subtotal_tax' => $line['subtotal_tax'] ?? 0,
                'tax_class' => $line['tax_class'] ?? null,
                'taxes' => ($line['taxes'] ?? []) ?: null,
                'options' => $line['options'] ?: null,
            ]);
        }
    }

    /** Without the storefront's OrderStock: take stock, count coupon use and product sales once. */
    protected static function recordUsageManually(Order $order, bool $reduceStock): void
    {
        foreach ($order->items()->get() as $item) {
            if ($reduceStock) {
                static::adjustStock($item, -$item->quantity);
            }
            if ($item->product_id) {
                Product::withTrashed()->whereKey($item->product_id)->increment('total_sales', $item->quantity);
            }
        }
        $codes = array_filter(array_map('trim', explode(',', (string) $order->coupon_code)));
        if ($codes) {
            Coupon::whereIn(DB::raw('LOWER(code)'), array_map('mb_strtolower', $codes))->increment('usage_count');
        }
        if ($reduceStock) {
            $order->addNote('Stock levels reduced.');
        }
    }
}
