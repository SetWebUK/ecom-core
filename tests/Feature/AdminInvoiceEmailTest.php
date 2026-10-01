<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Pine\Commerce\Mail\Admin\CustomerInvoice;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Invoices\Invoices;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * The back office "Order details / invoice" email (Mail\Admin\CustomerInvoice) carries the invoice PDF under the
 * invoice rules (setting invoices.attach_customer_invoice, numbering, assign on paid/completed); unpaid orders get no
 * PDF and the order page says so. Fresh neutral store on in-memory SQLite (package defaults: switched on).
 */
class AdminInvoiceEmailTest extends TestCase
{
    use InstallsNeutralStore;

    protected Order $paid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Invoices::flush();
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'pending'])->save();
        $order->updateStatus('processing');
        $this->paid = $order->fresh();
    }

    protected function tearDown(): void
    {
        Invoices::flush();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function sentAttachments(Order $order): array
    {
        Mail::swap(new \Illuminate\Mail\MailManager($this->app));
        config(['mail.default' => 'array']);
        Mail::to($order->email)->send(new CustomerInvoice($order));
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        return array_map(fn ($a) => [$a->getFilename(), substr($a->getBody(), 0, 5)], $sent->getAttachments());
    }

    public function test_a_paid_order_gets_its_invoice_pdf(): void
    {
        $this->assertTrue(config('commerce.invoices.attach.customer_invoice'), 'new-client default: on');
        $this->assertSame(['attach' => true, 'reason' => 'attach', 'number' => 'INV-00001'], CustomerInvoice::invoiceAttachment($this->paid));
        $attachments = (new CustomerInvoice($this->paid))->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('invoice-INV-00001.pdf', $attachments[0]->as);
        $this->assertSame('application/pdf', $attachments[0]->mime);
        $this->assertSame([['invoice-INV-00001.pdf', '%PDF-']], $this->sentAttachments($this->paid));
    }

    public function test_an_unpaid_order_gets_no_pdf_and_no_number(): void
    {
        $order = Order::create(['email' => 'unpaid@example.test', 'status' => 'pending', 'subtotal' => 5, 'total' => 5]);
        $this->assertSame('unpaid', CustomerInvoice::invoiceAttachment($order)['reason']);
        $this->assertStringContainsString('isn’t paid yet', CustomerInvoice::attachmentNote($order));
        $this->assertSame([], (new CustomerInvoice($order))->attachments());
        $this->assertSame([], $this->sentAttachments($order));
        $this->assertNull($order->fresh()->invoice_number, 'sending never issues a number to an unpaid order');

        // numbering off (the pre-1.1 behaviour): still no PDF while unpaid, the order number once paid
        Setting::set('invoices.numbering', false);
        $this->assertSame([], (new CustomerInvoice($order->fresh()))->attachments());
        $order->fresh()->updateStatus('processing');
        $this->assertSame(['attach' => true, 'reason' => 'attach', 'number' => (string) $order->number], CustomerInvoice::invoiceAttachment($order->fresh()));
        $this->assertSame('invoice-'.$order->number.'.pdf', (new CustomerInvoice($order->fresh()))->attachments()[0]->as);
    }

    public function test_assign_on_completed_and_a_number_issued_on_send(): void
    {
        Setting::set('invoices.assign_on', 'completed');
        $order = Order::create(['email' => 'late@example.test', 'status' => 'pending', 'subtotal' => 5, 'total' => 5]);
        $order->updateStatus('processing');
        $order = $order->fresh();
        $this->assertSame('not_issued', CustomerInvoice::invoiceAttachment($order)['reason']);
        $this->assertStringContainsString('when the order is completed', CustomerInvoice::attachmentNote($order));
        $this->assertSame([], (new CustomerInvoice($order))->attachments());

        // a completed order without a number yet (e.g. completed before numbering was on) gets it when the email is sent
        Order::query()->whereKey($order->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $order = $order->fresh();
        $this->assertNull($order->invoice_number);
        $this->assertSame(['attach' => true, 'reason' => 'attach', 'number' => null], CustomerInvoice::invoiceAttachment($order));
        $this->assertStringContainsString('issued when it is sent', CustomerInvoice::attachmentNote($order));
        $this->assertSame([['invoice-INV-00002.pdf', '%PDF-']], $this->sentAttachments($order));
        $this->assertSame('INV-00002', $order->fresh()->invoice_number);
    }

    public function test_switched_off_in_settings(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.settings.edit', 'invoices'))->assertOk()->assertSee('“Order details / invoice” email', false);
        $this->put(route('admin.settings.update', 'invoices'), ['invoices__attach_customer_invoice' => '0'])->assertRedirect();
        $this->assertFalse(Invoices::attachTo('customer_invoice'));
        $this->assertSame('switched_off', CustomerInvoice::invoiceAttachment($this->paid)['reason']);
        $this->assertSame([], (new CustomerInvoice($this->paid))->attachments());
        $this->assertTrue(Invoices::attachTo('customer_processing'), 'the other emails keep their own switch');
    }

    public function test_client_config_can_keep_it_off(): void
    {
        config(['commerce.invoices.attach' => ['customer_processing' => false, 'customer_completed' => false]]);
        $this->assertFalse(Invoices::attachTo('customer_invoice'), 'a client attach block without the key = off');
        $this->assertSame([], (new CustomerInvoice($this->paid))->attachments());
    }

    public function test_admin_order_page_says_what_is_attached_and_the_send_records_it(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.orders.show', $this->paid))->assertOk()
            ->assertSee('data-invoice-attachment="attach"', false)
            ->assertSee('Invoice PDF attached (INV-00001)');

        Mail::fake();
        $this->post(route('admin.orders.email', $this->paid), ['email' => CustomerInvoice::class])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Invoice PDF attached (INV-00001)'));
        Mail::assertSent(CustomerInvoice::class, fn (CustomerInvoice $mail) => count($mail->attachments()) === 1);
        $this->assertStringContainsString('with invoice INV-00001 (PDF)', (string) $this->paid->notes()->latest('id')->value('note'));

        $unpaid = Order::create(['email' => 'unpaid@example.test', 'status' => 'pending', 'subtotal' => 5, 'total' => 5, 'order_key' => 'wc_order_test']);
        $this->get(route('admin.orders.show', $unpaid))->assertOk()
            ->assertSee('data-invoice-attachment="unpaid"', false)
            ->assertSee('No invoice PDF – the order isn’t paid yet, so it has no invoice number', false);
        $this->post(route('admin.orders.email', $unpaid), ['email' => CustomerInvoice::class])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'No invoice PDF'));
        Mail::assertSent(CustomerInvoice::class, fn (CustomerInvoice $mail) => $mail->order->is($unpaid) && $mail->attachments() === []);
        $this->assertStringNotContainsString('(PDF)', (string) $unpaid->notes()->latest('id')->value('note'));
    }
}
