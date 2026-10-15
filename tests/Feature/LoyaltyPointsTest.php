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

    public function test_registering_on_the_storefront_gives_the_welcome_bonus_once(): void
    {
        $this->post(route('register'), ['first_name' => 'Amy', 'last_name' => 'Tall', 'email' => 'amy@example.test', 'password' => 'Password12345!'])->assertRedirect();

        $amy = \Pine\Commerce\Commerce::userModel()::where('email', 'amy@example.test')->firstOrFail();
        $this->assertSame(200, Loyalty::balance($amy->id));
        $this->assertSame(1, LoyaltyPoint::where('user_id', $amy->id)->where('type', 'signup')->count());
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
        $this->assertSame('Points (400)', $order->discountNames());
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

    public function test_balances_come_straight_from_points_and_rewards_in_the_wordpress_database(): void
    {
        $bob = $this->customer();
        $file = sys_get_temp_dir().'/commerce-points-wp-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($file);
        config(['database.connections.points_wp' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => 'wp_', 'foreign_key_constraints' => false],
            'commerce-import.source' => ['connection' => 'points_wp', 'wp_path' => null, 'site_url' => null, 'site_host' => null, 'snapshots' => []]]);
        $wp = \Illuminate\Support\Facades\Schema::connection('points_wp');
        $wp->create('options', function ($t) { $t->bigIncrements('option_id'); $t->string('option_name'); $t->text('option_value'); $t->string('autoload')->default('yes'); });
        $wp->create('posts', function ($t) { $t->bigIncrements('ID'); $t->string('post_type')->default('post'); });
        $wp->create('users', function ($t) { $t->bigIncrements('ID'); $t->string('user_email'); });
        $wp->create('wc_points_rewards_user_points', function ($t) { $t->bigIncrements('id'); $t->unsignedBigInteger('user_id'); $t->bigInteger('points'); $t->bigInteger('points_balance'); });
        $db = \Illuminate\Support\Facades\DB::connection('points_wp');
        $db->table('users')->insert([['ID' => 7, 'user_email' => 'Bob@Example.test'], ['ID' => 8, 'user_email' => 'ghost@example.test']]);
        $db->table('wc_points_rewards_user_points')->insert([
            ['user_id' => 7, 'points' => 120, 'points_balance' => 80], ['user_id' => 7, 'points' => 300, 'points_balance' => 300], ['user_id' => 8, 'points' => 50, 'points_balance' => 50],
        ]);

        $this->artisan('commerce:loyalty:import', ['--from-wordpress' => true])->expectsOutputToContain('2 customers with Points & Rewards balances')
            ->expectsOutputToContain('1 customers updated')->assertSuccessful();
        $this->assertSame(200 + 380, Loyalty::balance($bob->id), 'the sum of the remaining balances');

        \Illuminate\Support\Facades\DB::purge('points_wp');
        \Illuminate\Support\Facades\DB::purge('wordpress_import');
        @unlink($file);
    }

    public function test_balances_export_to_csv_and_with_the_customers(): void
    {
        $bob = $this->customer();
        $this->customer('amy@example.test');
        Loyalty::add($bob->id, 200, 'adjust');
        $csv = tempnam(sys_get_temp_dir(), 'exp');

        $this->artisan('commerce:loyalty:export', ['file' => $csv])->expectsOutputToContain('2 customers, 600 points')->assertSuccessful();
        $lines = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES));
        $this->assertSame(['email', 'points', 'worth', 'first_name', 'last_name'], $lines[0]);
        $this->assertSame(['bob@example.test', '400', '10.00', 'Bob', 'Big'], $lines[2]);
        @unlink($csv);

        $customers = $this->actingAs($this->neutralAdmin())->get(route('admin.customers.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString(',Points', strtok($customers, "\n"));
    }
}
