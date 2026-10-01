<?php

namespace Pine\Commerce\Services\Invoices;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Models\Order;

/**
 * Invoice rules in one place (docs/INVOICES.md): which options are on, when an order has an invoice, its number and
 * date, file names and the customer download link.
 *
 * Options: the Admin › Settings › Invoices value ("invoices.{key}" setting) wins; while it has never been saved the
 * default comes from config commerce.invoices.{key} (package: on for new installs; a client config can keep the
 * pre-v1.1 behaviour – numbering off, nothing attached, no customer link).
 */
class Invoices
{
    /** PDF documents the core renders (views pdf.{document}, theme-overridable). */
    public const DOCUMENTS = ['invoice', 'packing-slip'];

    /**
     * Customer emails that can carry the invoice PDF: StoreSettings::ORDER_EMAILS keys, plus "customer_invoice" – the
     * "Order details / invoice" email sent from the back office (Mail\Admin\CustomerInvoice, since 1.2).
     */
    public const ATTACHABLE_EMAILS = ['customer_processing', 'customer_completed', 'customer_invoice'];

    /** @var array<string, bool> memo per database */
    protected static array $columns = [];

    /** Setting "invoices.{key}", else config commerce.invoices.{key}, else $default. */
    public static function option(string $key, mixed $default = null): mixed
    {
        // prefix/suffix may be saved empty on purpose ("no prefix"), which setting() would read as "use the default"
        if (in_array($key, ['prefix', 'suffix'], true)) {
            try {
                $all = \Pine\Commerce\Models\Setting::allCached();
                if (array_key_exists('invoices.'.$key, $all) && $all['invoices.'.$key] !== null) {
                    return (string) $all['invoices.'.$key];
                }
            } catch (\Throwable) {
            }
        }
        $value = setting('invoices.'.$key);

        return $value === null ? config('commerce.invoices.'.$key, $default) : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(static::option($key, $default), FILTER_VALIDATE_BOOL);
    }

    /** Sequential invoice numbers are on (and the orders table has the v1.1 columns). */
    public static function numbering(): bool
    {
        return static::bool('numbering', true) && static::hasColumns();
    }

    /** "paid" (processing or completed) or "completed". */
    public static function assignOn(): string
    {
        return static::option('assign_on', 'paid') === 'completed' ? 'completed' : 'paid';
    }

    /** Whether the invoice PDF goes with the customer email $emailKey (customer_processing / customer_completed). */
    public static function attachTo(string $emailKey): bool
    {
        if (! in_array($emailKey, static::ATTACHABLE_EMAILS, true)) {
            return false;
        }
        $value = setting('invoices.attach_'.$emailKey);

        return filter_var($value ?? config('commerce.invoices.attach.'.$emailKey, false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Would the invoice PDF go with email $emailKey for this order, and why not? Never issues a number (the send does,
     * for a paid order that has none yet – the same rule as every invoice download).
     *
     * @return array{attach:bool, reason:string, number:?string}  reason: 'attach' | 'switched_off' | 'unpaid' | 'not_issued'
     */
    public static function emailAttachment(Order $order, string $emailKey): array
    {
        if (! static::attachTo($emailKey)) {
            return ['attach' => false, 'reason' => 'switched_off', 'number' => null];
        }
        if (static::numbering()) {
            $number = static::number($order, false);
            if ($number !== null || ($order->exists && static::qualifies($order))) {
                return ['attach' => true, 'reason' => 'attach', 'number' => $number];
            }
            // paid but numbers are issued on completion ("assign on completed")
            $paid = in_array((string) $order->status, ['processing', 'completed', 'refunded'], true) || $order->paid_at !== null;

            return ['attach' => false, 'reason' => $paid && static::assignOn() === 'completed' ? 'not_issued' : 'unpaid', 'number' => null];
        }

        return static::available($order)
            ? ['attach' => true, 'reason' => 'attach', 'number' => (string) $order->number]
            : ['attach' => false, 'reason' => 'unpaid', 'number' => null];
    }

    public static function customerDownload(): bool
    {
        return static::bool('customer_download', false);
    }

    /** The order has reached the status that issues its invoice number. */
    public static function qualifies(Order $order): bool
    {
        $status = (string) $order->status;
        if (static::assignOn() === 'completed') {
            return $status === 'completed' || ($status === 'refunded' && $order->completed_at !== null);
        }

        return in_array($status, ['processing', 'completed'], true) || ($status === 'refunded' && $order->paid_at !== null);
    }

    /**
     * The invoice number to print: with numbering on, the order's issued number (issued now if the order qualifies but
     * has none yet – e.g. paid before numbering was switched on), null while it has none; with numbering off, the
     * order number (the behaviour before v1.1).
     */
    public static function number(Order $order, bool $issue = true): ?string
    {
        if (! static::numbering()) {
            return (string) $order->number;
        }
        if ($order->invoice_number) {
            return (string) $order->invoice_number;
        }
        if ($issue && $order->exists && static::qualifies($order)) {
            return app(InvoiceNumbers::class)->assign($order);
        }

        return null;
    }

    /** Invoice date: when the number was issued, else when the order was paid, else when it was placed. */
    public static function date(Order $order): ?Carbon
    {
        $date = (static::hasColumns() ? $order->invoice_date : null) ?? $order->paid_at ?? $order->created_at;

        return $date ? Carbon::instance($date) : null;
    }

    /** A customer-facing invoice exists: an issued number (numbering on) or a paid order (numbering off). */
    public static function available(Order $order): bool
    {
        if (static::numbering()) {
            return static::number($order) !== null;
        }

        return in_array($order->status, ['processing', 'completed'], true)
            || (in_array($order->status, ['refunded', 'on-hold'], true) && $order->paid_at !== null);
    }

    /**
     * "Download invoice" link for the customer, or null (switched off / no invoice yet). The order's owner gets the
     * My account link; anyone else (guests, the order-received page) the order-key link.
     */
    public static function customerUrl(Order $order, ?Authenticatable $user = null): ?string
    {
        if (! static::customerDownload() || ! static::available($order)) {
            return null;
        }
        if ($user && $order->user_id && (int) $order->user_id === (int) $user->getAuthIdentifier()) {
            return route('account.order.invoice', ['number' => $order->number]);
        }

        return route('checkout.invoice', ['order' => $order->number, 'key' => $order->order_key]);
    }

    /** Download file name, e.g. "invoice-INV-00012.pdf" / "packing-slip-1042.pdf". */
    public static function filename(Order $order, string $document = 'invoice'): string
    {
        $ref = $document === 'invoice' ? (static::number($order, false) ?? $order->number) : $order->number;

        $ref = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $ref), '-.');

        return $document.'-'.($ref !== '' ? $ref : $order->id).'.pdf';
    }

    /** The orders table has the v1.1 invoice columns (false until `php artisan migrate` has run). */
    public static function hasColumns(): bool
    {
        try {
            $connection = DB::connection();
            $key = $connection->getName().'|'.$connection->getDatabaseName();

            return static::$columns[$key] ??= Schema::hasColumn('orders', 'invoice_number');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Forget memoised schema state (tests that migrate a fresh database). */
    public static function flush(): void
    {
        static::$columns = [];
    }
}
