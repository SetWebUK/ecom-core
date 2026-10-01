<?php

namespace Pine\Commerce\Http\Controllers;

use Illuminate\Http\Request;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Invoices\InvoicePdf;
use Pine\Commerce\Services\Invoices\Invoices;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer invoice downloads (Settings › Invoices › "Customers can download their invoice"):
 *   GET my-account/view-order/{number}/invoice           signed-in owner of the order (route account.order.invoice)
 *   GET checkout/order-received/{order}/invoice?key=…    the order key + OrderAccess (placing session / verified billing
 *                                                        email; anyone else is sent to the order-received email check)
 * 404 (never 403) for someone else's order, a wrong key, a switched-off download or an order without an invoice yet,
 * so the URLs reveal nothing about which order numbers exist.
 */
class InvoiceController extends Controller
{
    public function __construct(protected InvoicePdf $pdf)
    {
    }

    public function account(Request $request, string $number): Response
    {
        $order = Order::where('number', $number)->where('user_id', $request->user()->getAuthIdentifier())->first();

        return $this->download($order);
    }

    public function guest(Request $request, string $order): Response|\Illuminate\Http\RedirectResponse
    {
        $key = $request->query('key');
        $key = is_string($key) ? $key : '';
        $model = $key !== '' && strlen($key) <= 64 ? Order::where('number', $order)->first() : null;
        if ($model && ! hash_equals((string) $model->order_key, $key)) {
            $model = null;
        }
        // the key alone is not enough outside the session that placed the order: confirm the billing email first
        if ($model && ! \Pine\Commerce\Services\Checkout\OrderAccess::mayView($request, $model)) {
            return redirect()->to($model->view_url);
        }

        return $this->download($model);
    }

    protected function download(?Order $order): Response
    {
        abort_unless($order && Invoices::customerDownload() && Invoices::available($order), 404);
        $bytes = $this->pdf->render($order, 'invoice');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Invoices::filename($order, 'invoice').'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
