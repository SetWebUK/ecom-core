<?php

namespace Pine\Commerce\Mail\Admin;

use Pine\Commerce\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Pine\Commerce\Services\Invoices\InvoicePdf;
use Pine\Commerce\Services\Invoices\Invoices;

/**
 * "Order details / invoice" email sent from the back office (WooCommerce's "Customer invoice / Order details").
 * While the order is unpaid it carries a "Pay for this order" link to /checkout/order-pay/{number}/?key=…
 * Uses the storefront's email layout when it exists so it looks like every other order email.
 *
 * Since 1.2 it carries the invoice PDF (Settings › Invoices › "Attach the invoice PDF to the Order details / invoice
 * email", key "customer_invoice") once the order has an invoice under the numbering rules: an issued number, or a
 * paid order (numbering on: issued on send when it qualifies; numbering off: the order number). An unpaid order gets
 * no PDF – the admin order page says so before sending (attachmentNote()).
 */
class CustomerInvoice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    /** The Settings › Invoices key of this email (Invoices::ATTACHABLE_EMAILS). */
    public function invoiceEmailKey(): ?string
    {
        return 'customer_invoice';
    }

    /** @return array{attach:bool, reason:string, number:?string} see Invoices::emailAttachment() */
    public static function invoiceAttachment(Order $order): array
    {
        return Invoices::emailAttachment($order, 'customer_invoice');
    }

    /** One line for the back office: will the invoice PDF go with this email, and if not, why. */
    public static function attachmentNote(Order $order): string
    {
        $state = static::invoiceAttachment($order);

        return match ($state['reason']) {
            'attach' => 'Invoice PDF attached'.($state['number'] ? ' ('.$state['number'].')' : ' (the invoice number is issued when it is sent)'),
            'switched_off' => 'No invoice PDF (switched off in Settings › Invoices)',
            'not_issued' => 'No invoice PDF yet – the invoice number is issued when the order is completed',
            default => 'No invoice PDF – the order isn’t paid yet, so it has no invoice number',
        };
    }

    /** The invoice PDF when Settings › Invoices attaches it to this email and the order has an invoice. */
    public function attachments(): array
    {
        $key = $this->invoiceEmailKey();
        if (! $key || ! Invoices::emailAttachment($this->order, $key)['attach'] || ! ($pdf = app(InvoicePdf::class)->forEmail($this->order))) {
            return [];
        }
        [$bytes, $name] = $pdf;

        return [Attachment::fromData(fn () => $bytes, $name)->withMime('application/pdf')];
    }

    public static function needsPayment(Order $order): bool
    {
        return in_array($order->status, ['pending', 'failed'], true) && (float) $order->total > 0;
    }

    public static function payUrl(Order $order): ?string
    {
        return static::needsPayment($order) && Route::has('checkout.pay')
            ? route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key])
            : null;
    }

    public function envelope(): Envelope
    {
        $store = (string) setting('store.name', config('app.name'));

        return new Envelope(subject: static::needsPayment($this->order)
            ? 'Invoice for order #'.$this->order->number.' from '.$store
            : 'Your '.$store.' order #'.$this->order->number.' details');
    }

    public function content(): Content
    {
        $this->order->loadMissing(['items', 'refunds']);
        $useStoreLayout = View::exists('emails.layouts.base') && View::exists('emails.partials.order-details');

        return new Content(
            view: $useStoreLayout ? 'commerce::admin.emails.customer-invoice' : 'commerce::admin.emails.customer-invoice-plain',
            with: [
                'order' => $this->order,
                'payUrl' => static::payUrl($this->order),
                'heading' => static::needsPayment($this->order) ? 'Invoice for order #'.$this->order->number : 'Details for order #'.$this->order->number,
                'storeName' => (string) setting('store.name', config('app.name')),
            ],
        );
    }
}
