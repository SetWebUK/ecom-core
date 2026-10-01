<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Invoices\InvoiceNumbers;
use Pine\Commerce\Services\Invoices\InvoicePdf;
use Pine\Commerce\Services\Invoices\Invoices;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back office PDFs (routes/admin/sales.php, staff only):
 *   GET  orders/{order}/pdf/{document}          one order's invoice / packing slip (?inline=1 opens it in the browser)
 *   GET  pdf/{document}?orders=1,2,3&format=    many orders: one merged PDF (format=pdf, default) or a ZIP of PDFs (format=zip)
 *   POST orders/{order}/invoice/regenerate      issue the invoice number if due, drop cached copies
 */
class InvoicePdfController extends Controller
{
    public const BULK_LIMIT = 200;

    public function __construct(protected InvoicePdf $pdf)
    {
    }

    public function show(Request $request, int $order, string $document): Response
    {
        $model = Order::withTrashed()->findOrFail($order);

        return $this->pdfResponse($this->pdf->render($model, $document), Invoices::filename($model, $document), $request->boolean('inline'));
    }

    public function bulk(Request $request, string $document): Response
    {
        $ids = collect(explode(',', is_string($request->query('orders')) ? $request->query('orders') : ''))
            ->map(fn ($id) => (int) trim($id))->filter(fn ($id) => $id > 0)->unique()->take(self::BULK_LIMIT)->values();
        abort_if($ids->isEmpty(), 404);

        /** @var Collection<int, Order> $orders */
        $orders = Order::withTrashed()->whereIn('id', $ids)->get()
            ->sortBy(fn (Order $order) => $ids->search($order->id))->values();
        abort_if($orders->isEmpty(), 404);
        @set_time_limit(300); // 200 pages take ~10 s and ~220 MB with dompdf
        static::raiseMemoryLimit('512M');

        $stamp = now()->format('Y-m-d-His');
        if ($request->query('format') === 'zip' && $orders->count() > 1) {
            try {
                $path = $this->pdf->zip($orders, $document);

                return response()->download($path, "{$document}s-{$stamp}.zip", [
                    'Content-Type' => 'application/zip',
                    'Cache-Control' => 'no-store, private',
                    'X-Robots-Tag' => 'noindex',
                ])->deleteFileAfterSend();
            } catch (\RuntimeException $e) {
                Log::info('PDF ZIP unavailable, sending one merged PDF: '.$e->getMessage());
            }
        }

        $name = $orders->count() === 1 ? Invoices::filename($orders->first(), $document) : "{$document}s-{$stamp}.pdf";

        return $this->pdfResponse($this->pdf->render($orders, $document), $name, $request->boolean('inline'));
    }

    public function regenerate(int $order): RedirectResponse
    {
        $model = Order::withTrashed()->findOrFail($order);
        $this->pdf->forget($model);
        $had = $model->invoice_number;
        $number = Invoices::numbering() && Invoices::qualifies($model) ? app(InvoiceNumbers::class)->assign($model) : null;

        $message = match (true) {
            ! Invoices::numbering() => 'Invoice regenerated. It uses the order number (sequential invoice numbers are off in Settings › Invoices).',
            $number && ! $had => 'Invoice number '.$number.' issued and the invoice regenerated.',
            (bool) $number => 'Invoice '.$number.' regenerated with the current order details and settings.',
            default => 'Invoice regenerated. It gets its invoice number once the order is '.(Invoices::assignOn() === 'completed' ? 'completed' : 'paid').'.',
        };
        if ($number && ! $had) {
            $model->addNote('Invoice number '.$number.' issued.');
        }

        return back()->with('success', $message);
    }

    /** Raise (never lower) PHP's memory limit for a large merged PDF. */
    protected static function raiseMemoryLimit(string $wanted): void
    {
        $bytes = static function (string $value): int {
            $value = trim($value);
            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };
        $current = (string) ini_get('memory_limit');
        if ($current !== '-1' && $bytes($current) < $bytes($wanted)) {
            @ini_set('memory_limit', $wanted);
        }
    }

    protected function pdfResponse(string $bytes, string $filename, bool $inline = false): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
