<?php

namespace Pine\Commerce\Mail\Support;

use Pine\Commerce\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Pine\Commerce\Services\Invoices\InvoicePdf;
use Pine\Commerce\Services\Invoices\Invoices;

/**
 * Base for the WooCommerce-style order emails (resources/views/emails/orders/*). Kept outside app/Mail's top
 * level on purpose: the back office lists every Pine\Commerce\Mail\* class that takes an Order as "re-sendable".
 */
abstract class OrderEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    /** Friendly name used in order notes ("Processing order email sent to ..."). */
    abstract public function label(): string;

    abstract protected function subjectText(): string;

    abstract protected function headingText(): string;

    abstract protected function template(): string;

    /** Extra data for the template. */
    protected function extra(): array
    {
        return [];
    }

    /** Admin emails let staff reply straight to the customer. */
    protected function replyToCustomer(): bool
    {
        return false;
    }

    public function envelope(): Envelope
    {
        $replyTo = [];
        if ($this->replyToCustomer() && filter_var($this->order->email, FILTER_VALIDATE_EMAIL)) {
            $replyTo[] = new Address($this->order->email, $this->order->billing_name ?: null);
        }

        return new Envelope(subject: $this->subjectText(), replyTo: $replyTo);
    }

    public function content(): Content
    {
        $this->order->loadMissing(['items', 'refunds']);

        return new Content(
            view: 'emails.orders.'.$this->template(),
            with: array_merge([
                'order' => $this->order,
                'heading' => $this->headingText(),
                'storeName' => static::storeName(),
            ], $this->extra()),
        );
    }

    /**
     * The email's key in Settings › Emails / Settings › Invoices (e.g. "customer_processing") when it can carry the
     * invoice PDF; null = never attaches one. Client subclasses of the core emails inherit it.
     */
    public function invoiceEmailKey(): ?string
    {
        return null;
    }

    /** Invoice PDF when Settings › Invoices attaches it to this email (and the order has an invoice). */
    public function attachments(): array
    {
        $key = $this->invoiceEmailKey();
        if (! $key || ! Invoices::attachTo($key) || ! ($pdf = app(InvoicePdf::class)->forEmail($this->order))) {
            return [];
        }
        [$bytes, $name] = $pdf;

        return [Attachment::fromData(fn () => $bytes, $name)->withMime('application/pdf')];
    }

    public static function storeName(): string
    {
        return (string) setting('store.name', config('app.name'));
    }
}
