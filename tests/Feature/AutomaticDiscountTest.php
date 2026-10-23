<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Automatic discounts (coupons with auto_apply): applied by the basket with no code when their rules match, shown by
 * their label, recorded on the order like a code; the multi-buy type ("any 2 for £25"); offers on product pages.
 */
class AutomaticDiscountTest extends TestCase
{
    use InstallsNeutralStore;

    private Category $tees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        $this->tees = Category::create(['name' => 'T-Shirts', 'slug' => 't-shirts', 'is_visible' => true]);
        Cart::flush();
        Coupon::flushRunning();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?Category $category = null): Product
    {
        $product = Product::forceCreate(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'sku' => strtoupper(\Illuminate\Support\Str::slug($name)), 'type' => 'simple',
            'status' => 'published', 'regular_price' => $price, 'price' => $price, 'manage_stock' => false, 'stock_status' => 'instock',
            'primary_category_id' => $category?->id]);
        if ($category) {
            $product->categories()->attach($category->id);
        }

        return $product;
    }

    private function add(Product $product, int $qty = 1): Cart
    {
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => $qty], ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $cart->reset();
        Cart::flush();

        return $cart;
    }

    private function automatic(array $attributes): Coupon
    {
        return Coupon::create($attributes + ['code' => 'AUTO-'.uniqid(), 'auto_apply' => true, 'is_active' => true]);
    }

    public function test_a_category_discount_applies_by_itself_and_only_to_that_category(): void
    {
        $tee = $this->product('Plain Tee', 20, $this->tees);
        $jeans = $this->product('Stretch Jeans', 40);
        $this->automatic(['label' => '20% off tees', 'type' => 'percent', 'amount' => 20, 'category_ids' => [$this->tees->id]]);

        $this->add($tee);
        $totals = $this->add($jeans)->totals();

        $this->assertSame(4.0, round($totals['discount_display'], 2), '20% of the £20 tee, nothing off the jeans');
        $code = $totals['automatic_coupons'][0];
        $this->assertSame('20% off tees', $totals['coupon_labels'][$code]);
        $this->assertNull(app(Cart::class)->model()->coupon_code, 'nothing stored on the basket: worked out every time');

        $summary = view('theme::checkout.partials.summary', ['cart' => app(Cart::class), 'totals' => $totals])->render();
        $this->assertStringContainsString('20% off tees', $summary);
        $this->assertStringNotContainsString('data-coupon-remove="'.$code.'"', $summary, 'an automatic discount has no remove button');
    }

    public function test_multi_buy_prices_each_full_set_and_leaves_the_rest(): void
    {
        $tee = $this->product('Plain Tee', 14.99, $this->tees);
        $this->automatic(['label' => 'Any 2 tees for £25', 'type' => 'multibuy', 'amount' => 25, 'buy_quantity' => 2, 'category_ids' => [$this->tees->id]]);

        $this->assertSame(0.0, $this->add($tee, 1)->totals()['discount'], 'one tee: no set yet');

        $totals = $this->add($tee, 2)->totals(); // 3 tees
        $this->assertSame(4.98, round($totals['discount_display'], 2), '2 × 14.99 = 29.98 → 25.00; the third pays 14.99');
        $this->assertSame(39.99, round($totals['total'], 2));

        $totals = $this->add($tee, 1)->totals(); // 4 tees = 2 sets
        $this->assertSame(50.0, round($totals['total'], 2));
    }

    public function test_multi_buy_groups_the_most_expensive_items_together(): void
    {
        $a = $this->product('Tee A', 20, $this->tees);
        $b = $this->product('Tee B', 15, $this->tees);
        $c = $this->product('Tee C', 12, $this->tees);
        $this->automatic(['label' => 'Any 2 for £25', 'type' => 'multibuy', 'amount' => 25, 'buy_quantity' => 2, 'category_ids' => [$this->tees->id]]);

        $this->add($a);
        $this->add($b);
        $totals = $this->add($c)->totals();

        $this->assertSame(37.0, round($totals['total'], 2), '20 + 15 → 25, then 12');
    }

    public function test_dates_minimum_spend_and_codes_that_cant_be_combined(): void
    {
        $tee = $this->product('Plain Tee', 20, $this->tees);
        $later = $this->automatic(['label' => 'Black Friday', 'type' => 'percent', 'amount' => 50, 'starts_at' => now()->addDay()]);
        $this->automatic(['label' => '£5 off over £50', 'type' => 'fixed_cart', 'amount' => 5, 'minimum_spend' => 50]);

        $this->assertSame(0.0, $this->add($tee)->totals()['discount'], 'not started; under the minimum spend');

        $totals = $this->add($tee, 2)->totals(); // £60
        $this->assertSame(['£5 off over £50'], array_values($totals['coupon_labels']));

        $later->forceFill(['starts_at' => now()->subMinute()])->save();
        $cart = app(Cart::class);
        $cart->reset();
        Cart::flush();
        $this->assertCount(2, $cart->totals()['automatic_coupons']);

        Coupon::create(['code' => 'VIP', 'type' => 'percent', 'amount' => 10, 'individual_use' => true, 'is_active' => true]);
        $this->assertTrue($cart->applyCoupon('VIP')['ok']);
        $cart->reset();
        Cart::flush();
        $totals = $cart->totals();
        $this->assertSame([], $totals['automatic_coupons'], 'a code that can’t be combined replaces the automatic discounts');
        $this->assertSame(['VIP'], array_keys($totals['coupons']));
    }

    public function test_an_automatic_discounts_code_cant_be_typed_in(): void
    {
        $tee = $this->product('Plain Tee', 20, $this->tees);
        $auto = $this->automatic(['label' => '10% off', 'type' => 'percent', 'amount' => 10]);
        $this->add($tee);

        $result = app(Cart::class)->applyCoupon($auto->code);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('applied automatically', $result['message']);
    }

    public function test_the_order_records_the_automatic_discount_like_a_code(): void
    {
        Setting::set('payments.bacs.enabled', true);
        $tee = $this->product('Plain Tee', 14.99, $this->tees);
        $auto = $this->automatic(['label' => 'Any 2 tees for £25', 'type' => 'multibuy', 'amount' => 25, 'buy_quantity' => 2, 'category_ids' => [$this->tees->id]]);
        $cart = $this->add($tee, 2);

        $this->withCookie(Cart::cookieName(), $cart->model()->token)->post(route('checkout.place'), [
            'billing_email' => 'ada@example.test', 'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Lovelace',
            'shipping_address_1' => '1 High Street', 'shipping_city' => 'Bristol', 'shipping_postcode' => 'BS1 1AA', 'shipping_country' => 'GB',
            'shipping_phone' => '01179 000000', 'payment_method' => 'bacs', 'terms' => '1', 'shipping_method' => 'free_shipping',
        ], ['Accept' => 'application/json'])->assertOk();

        $order = Order::latest('id')->first();
        $this->assertSame($auto->code, $order->coupon_code);
        $this->assertSame('25.00', (string) $order->total);
        $this->assertSame(1, (int) $auto->fresh()->usage_count);
        $this->assertSame('Any 2 tees for £25', $order->discountNames(), 'customers see the offer, not its internal code');
        $email = view('theme::emails.partials.order-details', ['order' => $order])->render();
        $this->assertStringContainsString('Discount (Any 2 tees for £25)', html_entity_decode($email));
    }

    public function test_admin_creates_an_automatic_multi_buy_without_typing_a_code(): void
    {
        $this->actingAs($this->neutralAdmin())->post(route('admin.coupons.store'), [
            'auto_apply' => '1', 'label' => 'Any 2 tees for £25', 'code' => '', 'type' => 'multibuy', 'buy_quantity' => '2', 'amount' => '25',
            'category_ids' => [$this->tees->id], 'is_active' => '1', 'show_countdown' => '1', 'expires_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $coupon = Coupon::sole();
        $this->assertSame('AUTO-ANY-2-TEES-FOR-25', $coupon->code);
        $this->assertTrue($coupon->auto_apply);
        $this->assertTrue($coupon->show_countdown);
        $this->assertSame(2, $coupon->buy_quantity);

        $this->actingAs($this->neutralAdmin())->post(route('admin.coupons.store'), [
            'auto_apply' => '1', 'label' => '', 'code' => '', 'type' => 'multibuy', 'amount' => '25', 'is_active' => '1',
        ])->assertSessionHasErrors(['label', 'buy_quantity']);

        $list = $this->actingAs($this->neutralAdmin())->get(route('admin.coupons.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Any 2 tees for £25', $list);
        $this->assertStringContainsString('Automatic', $list);
    }

    public function test_product_pages_learn_about_the_offers_that_cover_them(): void
    {
        $tee = $this->product('Plain Tee', 20, $this->tees);
        $jeans = $this->product('Stretch Jeans', 40);
        $this->automatic(['label' => '25% off tees', 'type' => 'percent', 'amount' => 25, 'category_ids' => [$this->tees->id], 'expires_at' => now()->addDays(2), 'show_countdown' => true]);
        $this->automatic(['label' => null, 'type' => 'percent', 'amount' => 5]); // no name: an internal rule, never advertised

        $offers = commerce_presenter()::offers($tee->fresh());
        $this->assertCount(1, $offers);
        $this->assertSame('25% off tees', $offers[0]['label']);
        $this->assertSame(15.0, $offers[0]['price']);
        $this->assertTrue($offers[0]['countdown']);
        $this->assertSame([], commerce_presenter()::offers($jeans->fresh()));
    }
}
