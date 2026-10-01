<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Pine\Commerce\Commerce;
use Pine\Commerce\Mail\CustomerCompletedOrder;
use Pine\Commerce\Mail\CustomerOnHoldOrder;
use Pine\Commerce\Mail\CustomerProcessingOrder;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Invoices\Invoices;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\Concerns\ReadsPdfs;
use Pine\Commerce\Tests\TestCase;

/**
 * Who may download which invoice (customer / guest / staff), the admin PDF actions and Settings › Invoices, and the
 * invoice attached to the order emails. Fresh neutral store on in-memory SQLite (package defaults: all switched on).
 */
class InvoiceAccessTest extends TestCase
{
    use InstallsNeutralStore;
    use ReadsPdfs;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Invoices::flush();
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'pending'])->save();
        $order->updateStatus('processing');
        $this->order = $order->fresh();
    }

    protected function tearDown(): void
    {
        Invoices::flush();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function customer(string $email): \Pine\Commerce\Models\User
    {
        return Commerce::userModel()::forceCreate(['name' => 'Other', 'first_name' => 'Other', 'last_name' => 'Person', 'email' => $email,
            'password' => Hash::make('Password12345'), 'role' => 'customer', 'is_active' => true]);
    }

    public function test_the_owner_downloads_their_invoice_from_my_account(): void
    {
        $this->actingAs($this->order->user);
        $response = $this->get(route('account.order.invoice', ['number' => $this->order->number]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="invoice-INV-00001.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertIsPdf($response->getContent());
        $this->assertStringContainsString('INV-00001', $this->pdfText($response->getContent()));

        // the link is on View order
        $this->get(route('account.order', ['number' => $this->order->number]))->assertOk()
            ->assertSee(route('account.order.invoice', ['number' => $this->order->number]), false)->assertSee('Download invoice');
    }

    public function test_another_customer_cannot_download_it(): void
    {
        $this->actingAs($this->customer('mallory@example.test'));
        $this->get(route('account.order.invoice', ['number' => $this->order->number]))->assertNotFound();
        // …not even with a guessed key
        $this->get(route('checkout.invoice', ['order' => $this->order->number, 'key' => 'wc_order_guess']))->assertNotFound();
    }

    public function test_guests_need_the_order_key(): void
    {
        $this->get(route('account.order.invoice', ['number' => $this->order->number]))->assertRedirect(); // to sign in
        $this->get(route('checkout.invoice', ['order' => $this->order->number]))->assertNotFound();
        $this->get(route('checkout.invoice', ['order' => $this->order->number, 'key' => 'wrong']))->assertNotFound();
        $this->get(route('checkout.invoice', ['order' => $this->order->number, 'key' => str_repeat('x', 200)]))->assertNotFound();
        $this->get(route('checkout.invoice', ['order' => '99999', 'key' => $this->order->order_key]))->assertNotFound();

        // the key alone (another browser): first the order-received billing-email check (1.1)
        $this->get(route('checkout.invoice', ['order' => $this->order->number, 'key' => $this->order->order_key]))
            ->assertRedirect($this->order->view_url);
        $this->post(route('checkout.thankyou.verify', ['order' => $this->order->number]), ['key' => $this->order->order_key, 'email' => $this->order->email]);

        $ok = $this->get(route('checkout.invoice', ['order' => $this->order->number, 'key' => $this->order->order_key]));
        $ok->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertIsPdf($ok->getContent());

        // the order-received page links to it
        $this->get(route('checkout.thankyou', ['order' => $this->order->number, 'key' => $this->order->order_key]))->assertOk()
            ->assertSee('Download invoice');
    }

    public function test_no_download_while_switched_off_or_before_the_invoice_exists(): void
    {
        $unpaid = Order::create(['email' => 'ada@example.test', 'user_id' => $this->order->user_id, 'status' => 'pending', 'subtotal' => 5, 'total' => 5]);
        $this->actingAs($this->order->user);
        $this->get(route('account.order.invoice', ['number' => $unpaid->number]))->assertNotFound();
        $this->get(route('account.order', ['number' => $unpaid->number]))->assertOk()->assertDontSee('Download invoice');

        Setting::set('invoices.customer_download', false);
        $this->get(route('account.order.invoice', ['number' => $this->order->number]))->assertNotFound();
        $this->get(route('checkout.invoice', ['order' => $this->order->number, 'key' => $this->order->order_key]))->assertNotFound();
        $this->get(route('account.order', ['number' => $this->order->number]))->assertOk()->assertDontSee('Download invoice');
        $this->assertNull(Invoices::customerUrl($this->order));
    }

    public function test_staff_download_single_bulk_merged_and_zip_pdfs_and_regenerate(): void
    {
        $second = Order::create(['email' => 'grace@example.test', 'status' => 'pending', 'subtotal' => 5, 'total' => 5, 'billing_first_name' => 'Grace']);
        $second->items()->create(['name' => 'Socks', 'quantity' => 1, 'unit_price' => 5, 'subtotal' => 5, 'total' => 5]);
        $this->actingAs($this->neutralAdmin());

        $single = $this->get(route('admin.orders.pdf', ['order' => $this->order->id, 'document' => 'invoice']));
        $single->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('filename="invoice-INV-00001.pdf"', $single->headers->get('Content-Disposition'));
        $inline = $this->get(route('admin.orders.pdf', ['order' => $this->order->id, 'document' => 'packing-slip', 'inline' => 1]));
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('£', $this->pdfText($inline->getContent()));

        $merged = $this->get(route('admin.pdf', ['document' => 'invoice', 'orders' => $this->order->id.','.$second->id]));
        $merged->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $merged->getContent()));
        $this->assertStringContainsStringIgnoringCase('Pro forma invoice', $this->pdfText($merged->getContent()), 'the unpaid order has no invoice number yet');

        if (class_exists(\ZipArchive::class)) {
            $zip = $this->get(route('admin.pdf', ['document' => 'invoice', 'orders' => $this->order->id.','.$second->id, 'format' => 'zip']));
            $zip->assertOk()->assertHeader('Content-Type', 'application/zip');
            $path = $zip->baseResponse->getFile()->getPathname();
            $archive = new \ZipArchive();
            $this->assertTrue($archive->open($path) === true);
            $names = [];
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $names[] = $archive->getNameIndex($i);
                $this->assertStringStartsWith('%PDF-', (string) $archive->getFromIndex($i));
            }
            $archive->close();
            $this->assertSame(['invoice-INV-00001.pdf', 'invoice-'.$second->number.'.pdf'], $names);
        }

        $this->get(route('admin.pdf', ['document' => 'invoice', 'orders' => 'x']))->assertNotFound();
        $this->get(route('admin.pdf', ['document' => 'receipt', 'orders' => $this->order->id]))->assertNotFound();

        // regenerate: a paid order without a number gets one (and a note); an issued number never changes
        $second->forceFill(['status' => 'completed'])->save(); // completed without the status event (e.g. imported)
        $this->from(route('admin.orders.show', $second))->post(route('admin.orders.invoice.regenerate', $second))
            ->assertRedirect(route('admin.orders.show', $second))->assertSessionHas('success');
        $this->assertSame('INV-00002', $second->fresh()->invoice_number);
        $this->post(route('admin.orders.invoice.regenerate', $this->order))->assertSessionHas('success');
        $this->assertSame('INV-00001', $this->order->fresh()->invoice_number);

        // order page shows the PDF actions and the number
        $this->get(route('admin.orders.show', $this->order))->assertOk()->assertSee('Invoice PDF')->assertSee('Regenerate invoice')->assertSee('INV-00001');
        $this->get(route('admin.orders.index', ['status' => 'all']))->assertOk()->assertSee('Invoices – ZIP of PDFs', false);
    }

    public function test_admin_pdfs_are_for_staff_only(): void
    {
        $url = route('admin.orders.pdf', ['order' => $this->order->id, 'document' => 'invoice']);
        $bulk = route('admin.pdf', ['document' => 'invoice', 'orders' => $this->order->id]);
        $this->get($url)->assertRedirect();
        $this->get($bulk)->assertRedirect();
        $this->post(route('admin.orders.invoice.regenerate', $this->order))->assertRedirect();

        $this->actingAs($this->order->user); // a customer
        $this->assertContains($this->get($url)->status(), [302, 403, 404]);
        $this->assertNotSame('application/pdf', $this->get($bulk)->headers->get('Content-Type'));
    }

    public function test_settings_invoices_screen_renders_and_saves(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.settings.index'))->assertOk()->assertSee('Invoices');
        $this->get(route('admin.settings.edit', 'invoices'))->assertOk()
            ->assertSee('Next invoice number')->assertSee('Attach the invoice PDF to the “order received” email', false)->assertSee('Packing slip footer');

        $this->put(route('admin.settings.update', 'invoices'), [
            'invoices__numbering' => '1', 'invoices__assign_on' => 'completed', 'invoices__prefix' => 'ACME-{y}-', 'invoices__suffix' => '',
            'invoices__padding' => 4, 'invoices__next_number' => 2, 'invoices__attach_customer_processing' => '0',
            'invoices__attach_customer_completed' => '1', 'invoices__customer_download' => '1', 'invoices__notes' => 'Bank: 12-34-56',
            'documents__invoice_footer' => 'Footer', 'documents__packing_slip_footer' => 'Slip footer', 'invoices__paper' => 'letter',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('completed', Invoices::assignOn());
        $this->assertSame('ACME-{y}-', Invoices::option('prefix'));
        $this->assertFalse(Invoices::attachTo('customer_processing'));
        $this->assertTrue(Invoices::attachTo('customer_completed'));
        $this->assertSame('letter', Invoices::option('paper'));
        $this->assertSame('Bank: 12-34-56', setting('invoices.notes'));

        $this->put(route('admin.settings.update', 'invoices'), ['invoices__prefix' => '<script>'])->assertSessionHasErrors('invoices__prefix');
        $this->put(route('admin.settings.update', 'invoices'), ['invoices__assign_on' => 'whenever'])->assertSessionHasErrors('invoices__assign_on');
    }

    public function test_invoice_pdf_is_attached_to_the_processing_and_completed_emails_when_switched_on(): void
    {
        $processing = new CustomerProcessingOrder($this->order);
        $attachments = $processing->attachments();
        $this->assertCount(1, $attachments);
        $this->assertInstanceOf(Attachment::class, $attachments[0]);
        $this->assertSame('invoice-INV-00001.pdf', $attachments[0]->as);
        $this->assertSame('application/pdf', $attachments[0]->mime);

        // the real email (array mailer) carries it
        Mail::swap(new \Illuminate\Mail\MailManager($this->app));
        config(['mail.default' => 'array']);
        Mail::to('ada@example.test')->send(new CustomerCompletedOrder($this->order->fresh()));
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $names = array_map(fn ($a) => $a->getFilename(), $sent->getAttachments());
        $this->assertSame(['invoice-INV-00001.pdf'], $names);
        $this->assertStringStartsWith('%PDF-', $sent->getAttachments()[0]->getBody());

        // switched off per email; never on other emails
        Setting::set('invoices.attach_customer_processing', false);
        $this->assertSame([], (new CustomerProcessingOrder($this->order))->attachments());
        $this->assertCount(1, (new CustomerCompletedOrder($this->order))->attachments());
        $this->assertSame([], (new CustomerOnHoldOrder($this->order))->attachments());
    }

    public function test_no_attachment_before_the_invoice_number_is_issued(): void
    {
        Setting::set('invoices.assign_on', 'completed');
        $order = Order::create(['email' => 'late@example.test', 'status' => 'pending', 'subtotal' => 5, 'total' => 5]);
        $order->updateStatus('processing');
        $this->assertSame([], (new CustomerProcessingOrder($order->fresh()))->attachments());
        $order->fresh()->updateStatus('completed');
        $this->assertCount(1, (new CustomerCompletedOrder($order->fresh()))->attachments());
    }

    public function test_status_change_sends_the_email_with_the_attachment(): void
    {
        Mail::fake();
        $order = Order::create(['email' => 'new@example.test', 'status' => 'pending', 'subtotal' => 5, 'total' => 5]);
        $order->updateStatus('processing');
        Mail::assertSent(CustomerProcessingOrder::class, fn (CustomerProcessingOrder $mail) => count($mail->attachments()) === 1
            && $mail->attachments()[0]->as === 'invoice-INV-00002.pdf');
    }
}
