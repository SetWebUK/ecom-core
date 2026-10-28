<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Pine\Commerce\Models\FormSubmission;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\ProductReview;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\StockNotification;
use Pine\Commerce\Services\Admin\StoreTasks;
use Pine\Commerce\Support\Doctor;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * 1.8.0 back-office journeys: the home page's "To do" card (go-live problems for administrators, waiting work for all
 * staff), low stock counted per variant, "next order to send" after fulfilling, and PayPal sandbox seen as test mode.
 */
class BackOfficeHomeTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_an_administrator_is_told_what_stops_the_shop_taking_orders(): void
    {
        config(['mail.default' => 'log']);

        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-dashboard-tasks', $html);
        $this->assertStringContainsString('data-task="payments"', $html);
        $this->assertStringContainsString('Customers can’t pay yet', $html);
        $this->assertStringContainsString('href="'.route('admin.payments.edit').'"', $html);
        $this->assertStringContainsString('data-task="mailer"', $html);
        $this->assertStringContainsString('data-task="products"', $html, 'a fresh install has no products');
        $this->assertStringNotContainsString('data-task="delivery"', $html, 'the installer adds free delivery');
    }

    public function test_a_shop_manager_sees_waiting_work_but_not_the_administrators_settings_problems(): void
    {
        config(['mail.default' => 'log']);
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'on-hold'])->save();
        $manager = \Pine\Commerce\Commerce::userModel()::forceCreate(['name' => 'Pat Manager', 'email' => 'pat@shop.example.test',
            'password' => Hash::make('Password12345'), 'role' => 'manager', 'is_active' => true]);

        $html = $this->actingAs($manager)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-task="on-hold"', $html);
        $this->assertStringContainsString('1 order on hold', $html);
        $this->assertStringContainsString(e(route('admin.orders.index', ['status' => 'on-hold'])), $html);
        $this->assertStringNotContainsString('data-task="payments"', $html);
        $this->assertStringNotContainsString('data-task="mailer"', $html);
    }

    public function test_reviews_messages_and_restock_requests_are_counted_with_links_to_their_lists(): void
    {
        [$product] = $this->catalogue();
        ProductReview::create(['product_id' => $product->id, 'name' => 'Bob', 'rating' => 5, 'content' => 'Lovely', 'is_approved' => false]);
        ProductReview::create(['product_id' => $product->id, 'name' => 'Cy', 'rating' => 4, 'content' => 'Good', 'is_approved' => false]);
        ProductReview::create(['product_id' => $product->id, 'name' => 'Di', 'rating' => 4, 'content' => 'Shown', 'is_approved' => true]);
        FormSubmission::create(['name' => 'Eve', 'email' => 'eve@example.test', 'message' => 'Do you deliver to Jersey?']);
        FormSubmission::create(['name' => 'Old', 'email' => 'old@example.test', 'message' => 'Read already', 'read_at' => now()]);
        StockNotification::create(['product_id' => $product->id, 'email' => 'fan@example.test']);
        StockNotification::create(['product_id' => $product->id, 'email' => 'fan2@example.test']);
        StockNotification::create(['product_id' => $product->id, 'email' => 'done@example.test', 'notified_at' => now()]);

        $tasks = collect(StoreTasks::tasks())->keyBy('key');

        $this->assertSame('2 reviews waiting for approval', $tasks['reviews']['title']);
        $this->assertSame(route('admin.reviews.index', ['status' => 'pending']), $tasks['reviews']['url']);
        $this->assertSame('1 unread message', $tasks['messages']['title']);
        $this->assertSame(route('admin.form-submissions.index', ['status' => 'unread']), $tasks['messages']['url']);
        $this->assertSame('2 customers waiting for sold-out items', $tasks['stock-alerts']['title']);
        $this->assertStringContainsString('1 product is back', $tasks['stock-alerts']['text']);
        $this->assertArrayNotHasKey('on-hold', $tasks->all());

        config(['commerce.features.reviews' => false]);
        $this->assertArrayNotHasKey('reviews', collect(StoreTasks::tasks())->keyBy('key')->all(), 'switched-off features stay quiet');
    }

    public function test_a_ready_shop_has_no_to_do_card(): void
    {
        config(['mail.default' => 'smtp']);
        $this->catalogue();
        Setting::set('payments.bacs.enabled', true, 'payments');
        Setting::flushMemo();

        $this->assertSame([], StoreTasks::forUser($this->neutralAdmin()));
        $this->actingAs($this->neutralAdmin())->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-dashboard-tasks', false);
    }

    public function test_paypal_sandbox_is_one_test_mode_warning_and_a_doctor_warning(): void
    {
        config(['mail.default' => 'smtp']);
        $this->catalogue();
        Setting::set('payments.paypal.enabled', true, 'payments');
        Setting::set('payments.paypal.client_id', 'AbCdEfGhIjKlMnOpQrStUvWxYz012345', 'payments');
        Setting::set('payments.paypal.secret', Crypt::encryptString('SeCrEtSeCrEtSeCrEtSeCrEt0123'), 'payments');
        Setting::set('payments.paypal.sandbox', true, 'payments');
        Setting::set('payments.paypal_card.enabled', true, 'payments');
        Setting::flushMemo();

        $problems = collect(StoreTasks::problems());
        $this->assertSame(['payments-test'], $problems->pluck('key')->all());
        $this->assertSame('Payments are in test mode', $problems[0]['title']);
        $this->assertStringContainsString('PayPal and Debit or credit card take test payments', $problems[0]['text']);

        $doctor = collect((new Doctor)->run())->firstWhere('title', 'PayPal');
        $this->assertSame('warn', $doctor['status']);
        $this->assertStringContainsString('TEST mode', $doctor['message']);

        Setting::set('payments.paypal.sandbox', false, 'payments');
        Setting::flushMemo();
        $this->assertSame([], StoreTasks::problems(), 'live keys: nothing to warn about');
    }

    public function test_low_stock_on_the_home_page_lists_single_variants_and_counts_sold_out_ones(): void
    {
        [$product] = $this->catalogue();
        $product->forceFill(['stock_quantity' => 30])->save();
        $tee = \Pine\Commerce\Models\Product::forceCreate(['name' => 'Crew Neck Tee', 'slug' => 'crew-neck-tee', 'sku' => 'TEE', 'type' => 'variable',
            'status' => 'published', 'regular_price' => 20, 'price' => 20, 'stock_status' => 'instock']);
        foreach (['5xl' => [1, 'instock'], '6xl' => [0, 'outofstock'], '2xl' => [9, 'instock']] as $size => [$qty, $status]) {
            ProductVariation::create(['product_id' => $tee->id, 'sku' => 'TEE-'.strtoupper($size), 'options' => ['size' => $size], 'regular_price' => 20,
                'manage_stock' => true, 'stock_quantity' => $qty, 'stock_status' => $status, 'is_active' => true]);
        }

        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Crew Neck Tee', $html);
        $this->assertStringContainsString('SKU TEE-5XL', $html);
        $this->assertStringContainsString('1 left', $html);
        $this->assertStringNotContainsString('TEE-2XL', $html, 'plenty left');
        $this->assertStringContainsString('data-sold-out-variants', $html);
        $this->assertStringContainsString('1 sold-out variant', $html);
        $this->assertStringContainsString(e(route('admin.products.inventory', ['stock' => 'outofstock'])), $html);
    }

    public function test_after_sending_an_order_the_page_offers_the_oldest_order_still_waiting(): void
    {
        [, , $order] = $this->catalogue();
        $older = Order::create(['email' => 'bob@example.test', 'status' => 'processing', 'billing_first_name' => 'Bob', 'billing_last_name' => 'Smith', 'total' => 12]);
        $older->forceFill(['created_at' => now()->subDays(2)])->save();
        $newer = Order::create(['email' => 'cy@example.test', 'status' => 'processing', 'total' => 15]);
        Order::create(['email' => 'paid@example.test', 'status' => 'completed', 'total' => 9]);

        $this->actingAs($this->neutralAdmin())->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.fulfil', $order), ['tracking_number' => 'RM1'])
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('next_to_fulfil', fn ($next) => $next['id'] === $older->id && $next['count'] === 2 && $next['name'] === 'Bob Smith');

        $this->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('data-next-to-fulfil', false)->assertSee('2 more orders to send')->assertSee('Next order: #'.$older->number);

        // the last one: all caught up
        foreach ([$older, $newer] as $o) {
            $o->forceFill(['status' => 'completed'])->save();
        }
        $last = Order::create(['email' => 'last@example.test', 'status' => 'processing', 'total' => 5]);
        $this->from(route('admin.orders.show', $last))->post(route('admin.orders.fulfil', $last))
            ->assertSessionHas('next_to_fulfil', fn ($next) => $next['id'] === null && $next['count'] === 0);
        $this->get(route('admin.orders.show', $last))->assertSee('All caught up')->assertDontSee('more orders to send');
    }

    public function test_the_orders_list_has_a_compact_card_for_phones(): void
    {
        $this->catalogue();

        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.orders.index'))->assertOk()->getContent();

        $this->assertStringContainsString('class="order-line only-mobile"', $html);
        $this->assertMatchesRegularExpression('#<td class="num stack-hide" data-label="Total">#', $html);
    }
}
