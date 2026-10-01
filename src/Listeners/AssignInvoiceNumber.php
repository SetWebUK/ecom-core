<?php

namespace Pine\Commerce\Listeners;

use Illuminate\Support\Facades\Log;
use Pine\Commerce\Events\OrderStatusChanged;
use Pine\Commerce\Services\Invoices\InvoiceNumbers;
use Pine\Commerce\Services\Invoices\InvoicePdf;
use Pine\Commerce\Services\Invoices\Invoices;

/**
 * Issues the order's sequential invoice number when it reaches the status set in Settings › Invoices ("paid":
 * processing or completed, or "completed"). Runs before SendOrderStatusEmails, so the emails can attach the invoice.
 * Never blocks the status change: a failure is logged and the number is issued the next time the invoice is generated.
 */
class AssignInvoiceNumber
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        try {
            if (Invoices::bool('cache')) {
                app(InvoicePdf::class)->forget($order); // the stamp (paid/refunded…) changed
            }
            if (Invoices::numbering() && ! $order->invoice_number && Invoices::qualifies($order)) {
                app(InvoiceNumbers::class)->assign($order);
            }
        } catch (\Throwable $e) {
            Log::warning('Invoice number for order '.$order->number.' not issued: '.$e->getMessage());
        }
    }
}
