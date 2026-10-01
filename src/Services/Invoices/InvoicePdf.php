<?php

namespace Pine\Commerce\Services\Invoices;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Pine\Commerce\Models\Order;
use RuntimeException;

/**
 * Invoice and packing-slip PDFs, generated on the fly from Blade templates.
 *
 * Templates: the first of "pdf.{document}" (client resources/views/pdf/…, then the active theme and its parents, e.g.
 * themes/acme/views/pdf/invoice.blade.php) and the core "commerce::pdf.{document}" (package resources/views/pdf, also
 * overridable at resources/views/vendor/commerce/pdf/…). They receive $orders (one page per order), $store
 * (DocumentData::store()), $document and $logo (data: URI or null) and must stay dompdf-friendly (tables, no flex/grid).
 *
 * Optional cache (config/setting invoices.cache): single-order PDFs under storage/app/private/invoices – never public.
 */
class InvoicePdf
{
    public const CACHE_DIR = 'invoices';

    public function __construct(protected PdfRenderer $renderer)
    {
    }

    /** One PDF for one or many orders (merged: one order per page). */
    public function render(Order|Collection $orders, string $document = 'invoice'): string
    {
        if (! in_array($document, Invoices::DOCUMENTS, true)) {
            throw new RuntimeException("Unknown document [{$document}].");
        }
        $orders = $orders instanceof Order ? collect([$orders]) : $orders->values();
        if ($orders->isEmpty()) {
            throw new RuntimeException('No orders to render.');
        }

        $this->issueNumbers($orders, $document);
        $single = $orders->count() === 1 ? $orders->first() : null;
        $cacheFile = $single && Invoices::bool('cache') ? $this->cachePath($single, $document) : null;
        if ($cacheFile && Storage::disk('local')->exists($cacheFile)) {
            return (string) Storage::disk('local')->get($cacheFile);
        }

        $pdf = $this->renderer->render($this->html($orders, $document), (string) Invoices::option('paper', 'a4'));

        if ($cacheFile) {
            Storage::disk('local')->put($cacheFile, $pdf);
        }

        return $pdf;
    }

    /** The HTML handed to dompdf (also useful for debugging a template). */
    public function html(Collection $orders, string $document = 'invoice'): string
    {
        $this->issueNumbers($orders, $document);
        $orders->each(fn (Order $order) => $order->loadMissing(['items' => fn ($q) => $q->orderBy('id')]));
        $store = DocumentData::store();

        return View::first(['pdf.'.$document, 'commerce::pdf.'.$document], [
            'orders' => $orders,
            'store' => $store,
            'document' => $document,
            'logo' => DocumentData::logoDataUri($store['logo_path']),
        ])->render();
    }

    /**
     * A ZIP of one PDF per order, written to a temporary file (the caller streams and deletes it).
     * Throws when the zip extension is missing – callers fall back to one merged PDF.
     */
    public function zip(Collection $orders, string $document = 'invoice'): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is not installed.');
        }
        $path = tempnam(sys_get_temp_dir(), 'commerce-pdf-');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }
        $names = [];
        foreach ($orders as $order) {
            if ($document === 'invoice') {
                Invoices::number($order); // issue first, so the file is named after the invoice number
            }
            $name = Invoices::filename($order, $document);
            $base = $name;
            for ($i = 2; isset($names[$name]); $i++) {
                $name = preg_replace('/\.pdf$/', '', $base)."-{$i}.pdf";
            }
            $names[$name] = true;
            $zip->addFromString($name, $this->render($order, $document));
        }
        $zip->close();

        return $path;
    }

    /** Issue due invoice numbers first (e.g. an order paid before numbering was switched on). */
    protected function issueNumbers(Collection $orders, string $document): void
    {
        if ($document === 'invoice') {
            $orders->each(fn (Order $order) => Invoices::number($order));
        }
    }

    /** Remove the cached PDFs of an order (Regenerate, status/address changes). */
    public function forget(Order $order): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(self::CACHE_DIR) as $file) {
            if (str_starts_with(basename($file), $order->id.'-')) {
                $disk->delete($file);
            }
        }
    }

    /**
     * The invoice PDF for an email attachment, or null (never throws – an email is always sent, with or without it).
     *
     * @return array{0:string, 1:string}|null [pdf bytes, file name]
     */
    public function forEmail(Order $order): ?array
    {
        try {
            if (Invoices::number($order) === null) {
                return null; // numbering on but not issued yet (e.g. "assign on completed" and this is the processing email)
            }

            return [$this->render($order, 'invoice'), Invoices::filename($order, 'invoice')];
        } catch (\Throwable $e) {
            Log::warning('Invoice PDF for order '.$order->number.' could not be attached: '.$e->getMessage());

            return null;
        }
    }

    /** Cache key: changes whenever the order, its number or the document settings change. */
    protected function cachePath(Order $order, string $document): string
    {
        $settings = collect(\Pine\Commerce\Models\Setting::allCached())
            ->filter(fn ($v, $k) => str_starts_with($k, 'store.') || str_starts_with($k, 'documents.') || str_starts_with($k, 'invoices.') || str_starts_with($k, 'tax.'))
            ->sortKeys()->all();
        $hash = sha1(json_encode([$document, $order->updated_at?->toIso8601String(), $order->invoice_number, $order->status, $settings, theme()->slug ?? null]));
        File::ensureDirectoryExists(Storage::disk('local')->path(self::CACHE_DIR));

        return self::CACHE_DIR.'/'.$order->id.'-'.$document.'-'.substr($hash, 0, 16).'.pdf';
    }
}
