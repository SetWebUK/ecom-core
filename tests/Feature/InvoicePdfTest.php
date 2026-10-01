<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Invoices\DocumentData;
use Pine\Commerce\Services\Invoices\InvoicePdf;
use Pine\Commerce\Services\Invoices\Invoices;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\Concerns\ReadsPdfs;
use Pine\Commerce\Tests\TestCase;

/**
 * PDF invoices / packing slips (dompdf): fonts and £, template contents, tax breakdown, theme override, logo, cache.
 * Fresh neutral store on in-memory SQLite (never the shop database).
 */
class InvoicePdfTest extends TestCase
{
    use InstallsNeutralStore;
    use ReadsPdfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Invoices::flush();
    }

    protected function tearDown(): void
    {
        Invoices::flush();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function order(): Order
    {
        [, , $order] = $this->catalogue();
        $order->items()->create(['name' => 'Crème brûlée café set', 'sku' => 'CAFE-É1', 'quantity' => 2, 'unit_price' => 1234.5,
            'subtotal' => 2469, 'total' => 2469, 'options' => ['Colour' => 'Bleu foncé', 'Size' => 'Large']]);
        $order->forceFill(['subtotal' => 2499, 'total' => 2499])->save();

        return $order->fresh();
    }

    public function test_invoice_pdf_embeds_a_unicode_font_and_renders_pound_and_accented_characters(): void
    {
        $order = $this->order();
        $pdf = app(InvoicePdf::class)->render($order, 'invoice');

        $this->assertIsPdf($pdf);
        $this->assertStringContainsString('/FontFile2', $pdf, 'The TrueType font is embedded');
        $this->assertMatchesRegularExpression('#/BaseFont\s*/[A-Z]{6}\+DejaVuSans#', $pdf, 'A DejaVu Sans subset is embedded');
        $this->assertStringContainsString('/ToUnicode', $pdf, 'Text can be copied / searched');

        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('£2,499.00', $text);
        $this->assertStringContainsString('£1,234.50', $text);
        $this->assertStringContainsString('Crème brûlée café set', $text);
        $this->assertStringContainsString('SKU: CAFE-É1', $text);
        $this->assertStringContainsString('Colour: Bleu foncé', $text);
    }

    public function test_invoice_template_shows_store_details_numbers_lines_totals_and_notes(): void
    {
        Setting::set('store.company_name', 'Acme Trading Ltd');
        Setting::set('store.address', "1 Market Street\nBristol\nBS1 1AA");
        Setting::set('store.vat_number', 'GB 123 4567 89');
        Setting::set('store.company_number', '01234567');
        Setting::set('invoices.notes', 'Payment due within 14 days.');
        Setting::set('documents.invoice_footer', 'Thanks for your custom!');
        $order = $this->order();
        $order->forceFill(['status' => 'pending', 'customer_note' => 'Leave with neighbour', 'discount_total' => 10, 'coupon_code' => 'SAVE10', 'shipping_total' => 4.99])->save();
        $order->updateStatus('processing');

        $html = app(InvoicePdf::class)->html(collect([$order->fresh()]), 'invoice');

        foreach (['Acme Trading Ltd', '1 Market Street', 'VAT no. GB 123 4567 89', 'Company no. 01234567', 'INV-00001', 'Invoice date',
            'Order no.', $order->number, 'Ada Lovelace', 'ada@example.test', 'Classic Linen Shirt', 'SKU: SHIRT-1', 'Colour: Bleu foncé',
            'Discount (SAVE10)', '£4.99', 'Bank transfer', 'Leave with neighbour', 'Payment due within 14 days.', 'Thanks for your custom!', 'Paid'] as $needle) {
            $this->assertStringContainsString(e($needle), $html, "invoice shows {$needle}");
        }
    }

    public function test_tax_breakdown_per_rate_when_the_order_carries_tax_lines_and_a_single_line_otherwise(): void
    {
        $order = $this->order();
        $order->forceFill(['tax_total' => 25, 'total' => 2524, 'meta' => ['tax_lines' => [
            ['label' => 'VAT', 'rate' => 20, 'net' => 100, 'tax' => 20],
            ['label' => 'VAT', 'rate' => 5, 'net' => 100, 'tax' => 5],
        ]]])->save();

        $lines = DocumentData::taxLines($order);
        $this->assertCount(2, $lines);
        $html = app(InvoicePdf::class)->html(collect([$order]), 'invoice');
        $this->assertStringContainsString('VAT 20%', $html);
        $this->assertStringContainsString('VAT 5%', $html);
        $this->assertStringContainsString('£20.00', $html);
        $this->assertStringContainsString('£5.00', $html);

        // rows shaped like the tax module's order_tax_lines (items + shipping tax per rate; label may carry the %)
        $order->forceFill(['meta' => ['tax_lines' => [
            ['label' => 'VAT 20%', 'rate' => 20, 'tax_total' => 18, 'shipping_tax_total' => 2],
            ['label' => 'Reduced', 'rate' => 5, 'tax_total' => 5, 'shipping_tax_total' => 0],
        ]]])->save();
        $lines = DocumentData::taxLines($order->fresh());
        $this->assertSame([20.0, 5.0], array_column($lines, 'tax'));
        $this->assertSame(['VAT 20%', 'Reduced 5%'], array_map([DocumentData::class, 'taxLabel'], $lines));
        $this->assertStringNotContainsString('20% 20%', app(InvoicePdf::class)->html(collect([$order->fresh()]), 'invoice'));

        // no per-rate data: one line from orders.tax_total (+ the store rate)
        Setting::set('tax.rate', 20);
        $order->forceFill(['meta' => null])->save();
        $this->assertSame([['label' => 'VAT', 'rate' => 20.0, 'net' => null, 'tax' => 25.0]], DocumentData::taxLines($order->fresh()));

        // prices including VAT: total = subtotal + shipping − discount, the tax is shown as "Includes VAT"
        $order->forceFill(['tax_total' => 416.5, 'total' => 2499])->save();
        $this->assertStringContainsString('Includes VAT', app(InvoicePdf::class)->html(collect([$order->fresh()]), 'invoice'));
    }

    public function test_packing_slip_has_no_prices(): void
    {
        $order = $this->order();
        $pdf = app(InvoicePdf::class)->render($order, 'packing-slip');
        $this->assertIsPdf($pdf);
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('Crème brûlée café set', $text);
        $this->assertStringContainsString('CAFE-É1', $text);
        $this->assertStringNotContainsString('£', $text);
        $this->assertStringContainsString('3 items in this parcel', $text);
    }

    public function test_many_orders_render_into_one_pdf_page_per_order(): void
    {
        $order = $this->order();
        $second = Order::create(['email' => 'grace@example.test', 'status' => 'completed', 'subtotal' => 5, 'total' => 5, 'billing_first_name' => 'Grace']);
        $second->items()->create(['name' => 'Socks', 'quantity' => 1, 'unit_price' => 5, 'subtotal' => 5, 'total' => 5]);

        $pdf = app(InvoicePdf::class)->render(collect([$order, $second->fresh()]), 'invoice');
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf));
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('Grace', $text);
        $this->assertStringContainsString('Ada Lovelace', $text);
    }

    public function test_a_theme_or_the_app_can_override_the_pdf_template(): void
    {
        $dir = storage_path('framework/testing/pdf-override-'.uniqid());
        File::ensureDirectoryExists($dir.'/pdf');
        File::put($dir.'/pdf/invoice.blade.php', '<html><body><h1>Custom invoice {{ $orders->first()->number }} £</h1></body></html>');
        app('view')->getFinder()->prependLocation($dir);
        try {
            $order = $this->order();
            $this->assertStringContainsString('Custom invoice '.$order->number, app(InvoicePdf::class)->html(collect([$order]), 'invoice'));
            $this->assertStringContainsString('Custom invoice '.$order->number.' £', $this->pdfText(app(InvoicePdf::class)->render($order, 'invoice')));
        } finally {
            File::deleteDirectory($dir);
            app('view')->getFinder()->flush();
        }
    }

    public function test_logo_is_embedded_from_local_files_only(): void
    {
        Storage::fake('public');
        // 1×1 transparent PNG
        Storage::disk('public')->put('uploads/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $this->assertStringStartsWith('data:image/png;base64,', (string) DocumentData::logoDataUri('uploads/logo.png'));
        $this->assertNull(DocumentData::logoDataUri('https://evil.example.test/logo.png'), 'never fetches remote files');
        $this->assertNull(DocumentData::logoDataUri('../../.env'), 'never reads outside public/');
        $this->assertNull(DocumentData::logoDataUri('uploads/missing.png'));

        Setting::set('store.logo', 'uploads/logo.png');
        $order = $this->order();
        $this->assertStringContainsString('src="data:image/png;base64,', app(InvoicePdf::class)->html(collect([$order]), 'invoice'));
        $this->assertStringContainsString('/Subtype /Image', app(InvoicePdf::class)->render($order, 'invoice'));
    }

    public function test_optional_cache_lives_in_private_storage_and_is_cleared_on_status_change(): void
    {
        Storage::fake('local');
        Setting::set('invoices.cache', true);
        $order = $this->order();
        $order->forceFill(['status' => 'pending'])->save();
        $order->updateStatus('processing');

        $first = app(InvoicePdf::class)->render($order->fresh(), 'invoice');
        $files = Storage::disk('local')->files('invoices');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('invoices/'.$order->id.'-invoice-', $files[0]);
        $this->assertSame($first, app(InvoicePdf::class)->render($order->fresh(), 'invoice'));

        $order->fresh()->updateStatus('completed');
        $this->assertSame([], Storage::disk('local')->files('invoices'));
    }
}
