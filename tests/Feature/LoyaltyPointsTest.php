<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Pine\Commerce\Models\LoyaltyPoint;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Loyalty\Loyalty;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Loyalty points (feature "loyalty"), set up like the old WooCommerce Points & Rewards shop: 1 point per £1,
 * 40 points = £1 off, 200 points welcome bonus.
 */
class LoyaltyPointsTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        config(['commerce.features.loyalty' => true]);
        Setting::set('loyalty.points_per_pound', 40, 'loyalty');
        Setting::set('loyalty.signup_points', 200, 'loyalty');
        Setting::set('payments.bacs.enabled', true, 'payments');
        Cart::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function customer(string $email = 'bob@example.test'): \Pine\Commerce\Models\User
    {
        return \Pine\Commerce\Commerce::userModel()::forceCreate(['name' => 'Bob Big', 'first_name' => 'Bob', 'last_name' => 'Big', 'email' => $email,
            'password' => Hash::make('Password12345'), 'role' => 'customer', 'is_active' => true]);
    }

    private function product(float $price): Product
    {
        return Product::forceCreate(['name' => 'Tee '.$price, 'slug' => 'tee-'.str_replace('.', '-', (string) $price), 'sku' => 'T'.$price, 'type' => 'simple', 'status' => 'published',
            'regular_price' => $price, 'price' => $price, 'manage_stock' => false, 'stock_status' => 'instock']);
    }

    private function checkout(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('checkout.place'), [
            'billing_email' => 'bob@example.test', 'shipping_first_name' => 'Bob', 'shipping_last_name' => 'Big', 'shipping_address_1' => '1 High Street',
            'shipping_city' => 'Chester', 'shipping_postcode' => 'CH1 4RN', 'shipping_country' => 'GB', 'shipping_phone' => '01244 000000',
            'payment_method' => 'bacs', 'terms' => '1', 'shipping_method' => 'free_shipping',
        ], ['Accept' => 'application/json']);
    }

    public function test_a_new_account_gets_the_welcome_bonus(): void
    {
        $bob = $this->customer();

        $this->assertSame(200, Loyalty::balance($bob->id));
        $this->assertSame('signup', LoyaltyPoint::sole()->type);
    }

    public function test_a_paid_order_earns_once_and_a_cancelled_one_gives_them_back(): void
    {
        $bob = $this->customer();
        $order = Order::create(['user_id' => $bob->id, 'email' => $bob->email, 'status' => 'pending', 'total' => 36]);
        $order->items()->create(['name' => 'Tee', 'quantity' => 2, 'unit_price' => 15, 'subtotal' => 30, 'total' => 30, 'tax' => 6]);

        $order->updateStatus('processing');
        $order->updateStatus('completed');
        $this->assertSame(200 + 36, Loyalty::balance($bob->id), '£36 with VAT = 36 points, earned once');

        $order->updateStatus('refunded');
        $this->assertSame(200, Loyalty::balance($bob->id));
    }

    public function test_points_pay_for_part_of_the_order_and_are_spent_once(): void
    {
        $bob = $this->customer();
        Loyalty::add($bob->id, 200, 'import', null, 'Old website balance'); // 400 points = £10
        $this->actingAs($bob);
        $this->post(route('cart.add'), ['product_id' => $this->product(30)->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();

        $this->postJson(route('cart.points'), ['context' => 'checkout'])->assertOk()->assertJson(['ok' => true]);
        Cart::flush();
        $cart = app(Cart::class);
        $cart->reset();
        $totals = $cart->totals();
        $this->assertSame(20.0, round($totals['total'], 2), '£30 – £10 of points (free delivery)');
        $this->assertSame('Points (400)', $totals['coupon_labels'][Loyalty::CODE]);

        $this->checkout()->assertOk();
        $order = Order::latest('id')->first();
        $this->assertSame('20.00', (string) $order->total);
        $this->assertSame(400, $order->meta['loyalty_points_spent']);
        $this->assertSame(0, Loyalty::balance($bob->id));

        $order->updateStatus('processing');
        $this->assertSame(20, Loyalty::balance($bob->id), 'earned on the £20 actually paid');
        $order->updateStatus('cancelled');
        $this->assertSame(400, Loyalty::balance($bob->id), 'the spent points come back, the earned ones go');
    }

    public function test_the_minimum_and_the_maximum_share_are_respected(): void
    {
        Setting::set('loyalty.min_redeem', 500, 'loyalty');
        Setting::set('loyalty.max_discount_percent', 50, 'loyalty');
        $bob = $this->customer();

        $this->assertSame(0, Loyalty::usable($bob->id, 100.0), '200 points is under the 500 minimum');
        Loyalty::add($bob->id, 3800, 'adjust');
        $this->assertSame(2000, Loyalty::usable($bob->id, 100.0), 'at most half of £100 = £50 = 2,000 points');
        $this->assertSame(25.0, Loyalty::value(1000));
    }

    public function test_balances_import_from_the_old_shop_and_running_it_again_only_adds_the_difference(): void
    {
        $bob = $this->customer();
        $csv = tempnam(sys_get_temp_dir(), 'pts');
        file_put_contents($csv, "user_email,points_balance\nBOB@example.test,640\nghost@example.test,90\n");

        $this->artisan('commerce:loyalty:import', ['file' => $csv])->expectsOutputToContain('1 customers updated')->assertSuccessful();
        $this->assertSame(200 + 640, Loyalty::balance($bob->id));

        file_put_contents($csv, "email,points\nbob@example.test,700\n");
        $this->artisan('commerce:loyalty:import', ['file' => $csv])->assertSuccessful();
        $this->assertSame(200 + 700, Loyalty::balance($bob->id));
        $this->artisan('commerce:loyalty:import', ['file' => $csv])->expectsOutputToContain('1 already up to date')->assertSuccessful();
        @unlink($csv);
    }

    public function test_staff_adjust_points_and_the_customer_sees_the_history(): void
    {
        $bob = $this->customer();

        $this->actingAs($this->neutralAdmin())->post(route('admin.customers.points', $bob), ['points' => -50, 'note' => 'Correction'])->assertSessionHas('success');
        $this->actingAs($this->neutralAdmin())->post(route('admin.customers.points', $bob), ['points' => -500])->assertSessionHasErrors('points');
        $this->assertStringContainsString('150', $this->actingAs($this->neutralAdmin())->get(route('admin.customers.show', $bob))->assertOk()->getContent());

        $page = $this->actingAs($bob)->get(route('account.points'))->assertOk()->getContent();
        $this->assertStringContainsString('150</strong> points', $page);
        $this->assertStringContainsString('Welcome bonus', $page);
        $this->assertStringContainsString('Correction', $page);
    }
}
