<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Automatic discounts shown in the shop price (coupons.show_in_price): cards, product pages, the variant picker, the
 * feed and the schema show the price after the discount as a was/now price, and the basket charges exactly that.
 * Only for % off / amount off each item with nothing that depends on the basket or the customer.
 */
class DiscountInPriceTest extends TestCase
{
    use InstallsNeutralStore;

    private Category $knitwear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        $this->knitwear = Category::create(['name' => 'Knitwear', 'slug' => 'knitwear', 'is_visible' => true]);
        Cart::flush();
        Coupon::flushRunning();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?Category $category = null, array $attributes = []): Product
    {
        $product = Product::forceCreate($attributes + ['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'sku' => strtoupper(\Illuminate\Support\Str::slug($name)), 'type' => 'simple',
            'status' => 'published', 'regular_price' => $price, 'price' => $price, 'manage_stock' => false, 'stock_status' => 'instock',
            'primary_category_id' => $category?->id]);
        if ($category) {
            $product->categories()->attach($category->id);
        }

        return $product;
    }

    private function inPrice(array $attributes): Coupon
    {
        $coupon = Coupon::create($attributes + ['code' => 'AUTO-'.uniqid(), 'auto_apply' => true, 'show_in_price' => true, 'is_active' => true]);
        Coupon::flushRunning();

        return $coupon;
    }

    private function basketTotal(Product $product, int $qty = 1, ?ProductVariation $variation = null): float
    {
        $this->post(route('cart.add'), array_filter(['product_id' => $product->id, 'variation_id' => $variation?->id, 'quantity' => $qty]), ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $cart->reset();
        Cart::flush();

        return round($cart->totals()['total'], 2);
    }

    public function test_a_category_discount_shows_as_was_now_and_the_basket_charges_the_same(): void
    {
        $jumper = $this->product('Cable Jumper', 50, $this->knitwear);
        $jeans = $this->product('Stretch Jeans', 40);
        $this->inPrice(['label' => '20% off knitwear', 'type' => 'percent', 'amount' => 20, 'category_ids' => [$this->knitwear->id]]);
        $P = commerce_presenter();

        $this->assertSame(40.0, $P::price($jumper->fresh()));
        $this->assertSame(['regular' => 50.0, 'sale' => 40.0, 'saving' => 10.0, 'percent' => 20], $P::sale($jumper->fresh()));
        $this->assertSame(['percent' => 20, 'upto' => false], $P::bestSaving($jumper->fresh()));
        $this->assertStringContainsString('<del aria-hidden="true">', $P::priceHtml($jumper->fresh()));
        $this->assertSame('Save £10 (20%)', $P::saveBadge($jumper->fresh()));
        $this->assertNull($P::sale($jeans->fresh()), 'not in the category: full price');

        $offers = $P::offers($jumper->fresh());
        $this->assertTrue($offers[0]['in_price']);
        $this->assertSame(40.0, $offers[0]['price'], 'the price shown, not taken off twice');

        $this->assertSame(80.0, $this->basketTotal($jumper, 2), 'two at £40');
    }

    public function test_without_the_option_the_discount_stays_an_offer_only(): void
    {
        $jumper = $this->product('Cable Jumper', 50, $this->knitwear);
        $this->inPrice(['label' => '20% off knitwear', 'type' => 'percent', 'amount' => 20, 'category_ids' => [$this->knitwear->id], 'show_in_price' => false]);
        $P = commerce_presenter();

        $this->assertSame(50.0, $P::price($jumper->fresh()));
        $this->assertNull($P::sale($jumper->fresh()));
        $this->assertFalse($P::offers($jumper->fresh())[0]['in_price']);
        $this->assertSame(40.0, $P::offers($jumper->fresh())[0]['price']);
        $this->assertSame(40.0, $this->basketTotal($jumper), 'the basket still takes it off');
    }

    public function test_it_comes_off_a_sale_price_unless_sale_items_are_excluded(): void
    {
        $jumper = $this->product('Cable Jumper', 50, $this->knitwear, ['sale_price' => 40]);
        $coupon = $this->inPrice(['label' => 'Extra 10% off', 'type' => 'percent', 'amount' => 10, 'category_ids' => [$this->knitwear->id]]);
        $P = commerce_presenter();

        $this->assertSame(['regular' => 50.0, 'sale' => 36.0, 'saving' => 14.0, 'percent' => 28], $P::sale($jumper->fresh()));
        $this->assertSame(36.0, $this->basketTotal($jumper));

        $coupon->update(['exclude_sale_items' => true]);
        Coupon::flushRunning();
        $this->assertSame(40.0, $P::price($jumper->fresh()), 'only its own sale');
    }

    public function test_an_amount_off_each_item_and_a_product_with_variants(): void
    {
        $cardigan = Product::forceCreate(['name' => 'Cardigan', 'slug' => 'cardigan', 'type' => 'variable', 'status' => 'published', 'regular_price' => 60, 'price' => 60, 'primary_category_id' => $this->knitwear->id]);
        $cardigan->categories()->attach($this->knitwear->id);
        $make = fn (string $size, float $price, int $order) => ProductVariation::forceCreate(['product_id' => $cardigan->id, 'sku' => 'CARD-'.$size, 'options' => ['size' => $size],
            'regular_price' => $price, 'is_active' => true, 'stock_status' => 'instock', 'sort_order' => $order]);
        $small = $make('3xl', 60, 0);
        $make('6xl', 70, 1);
        $this->inPrice(['label' => '£15 off knitwear', 'type' => 'fixed_product', 'amount' => 15, 'category_ids' => [$this->knitwear->id]]);
        $P = commerce_presenter();

        $this->assertSame(45.0, $P::price($cardigan->fresh()), 'the lowest, after £15 off');
        $this->assertSame(['regular' => 60.0, 'sale' => 45.0, 'saving' => 15.0, 'percent' => 25], $P::variationSale($cardigan->fresh(), $small->fresh()));
        $this->assertSame(['percent' => 25, 'upto' => true], $P::bestSaving($cardigan->fresh()), '£15 is 25% of one size and 21% of the other');

        $page = $this->get($cardigan->fresh()->url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/"display_price":45(\.0)?,/', $page, 'the variant picker gets the reduced price');
        $this->assertStringContainsString('"display_regular_price":60', $page);

        $this->assertSame(45.0, $this->basketTotal($cardigan, 1, $small));
    }

    public function test_conditions_that_depend_on_the_basket_keep_it_out_of_the_price(): void
    {
        $jumper = $this->product('Cable Jumper', 50, $this->knitwear);
        $coupon = $this->inPrice(['label' => '20% off over £100', 'type' => 'percent', 'amount' => 20, 'minimum_spend' => 100]);
        $P = commerce_presenter();
        $this->assertSame(50.0, $P::price($jumper->fresh()), 'minimum spend');

        foreach ([['minimum_spend' => null, 'usage_limit_per_user' => 1], ['usage_limit_per_user' => null, 'allowed_emails' => ['vip@example.test']],
            ['allowed_emails' => null, 'type' => 'fixed_cart'], ['type' => 'percent', 'usage_limit' => 5, 'usage_count' => 5], ['usage_count' => 4, 'expires_at' => now()->subMinute()]] as $change) {
            $coupon->forceFill($change)->save();
            Coupon::flushRunning();
            $this->assertSame(50.0, $P::price($jumper->fresh()), json_encode($change));
        }

        $coupon->forceFill(['expires_at' => now()->addDay()])->save();
        Coupon::flushRunning();
        $this->assertSame(40.0, $P::price($jumper->fresh()), 'one use left, nothing else in the way');
    }

    public function test_the_feed_and_the_schema_carry_the_reduced_price(): void
    {
        $jumper = $this->product('Cable Jumper', 50, $this->knitwear);
        $ends = now()->addDays(5);
        $this->inPrice(['label' => '20% off knitwear', 'type' => 'percent', 'amount' => 20, 'category_ids' => [$this->knitwear->id], 'expires_at' => $ends]);

        $feed = $this->get(route('feed.google'))->assertOk()->getContent();
        $this->assertStringContainsString('<g:price>50.00 GBP</g:price>', $feed);
        $this->assertStringContainsString('<g:sale_price>40.00 GBP</g:sale_price>', $feed);

        $page = $this->get($jumper->fresh()->url)->assertOk()->getContent();
        $this->assertStringContainsString('"price":"40.00"', $page);
        $this->assertStringContainsString('"priceValidUntil":"'.$ends->toDateString().'"', $page);
    }

    public function test_admin_ticks_the_option_and_conditions_are_refused(): void
    {
        $base = ['auto_apply' => '1', 'label' => '20% off knitwear', 'code' => '', 'type' => 'percent', 'amount' => '20',
            'category_ids' => [$this->knitwear->id], 'is_active' => '1', 'show_in_price' => '1'];

        $this->actingAs($this->neutralAdmin())->post(route('admin.coupons.store'), $base + ['minimum_spend' => '50', 'usage_limit_per_user' => '1'])
            ->assertSessionHasErrors(['show_in_price' => 'To show the reduced price in the shop, remove the minimum basket total and the uses per customer – the price shown can’t depend on the basket or the customer.']);

        $this->actingAs($this->neutralAdmin())->post(route('admin.coupons.store'), $base)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Coupon::sole()->show_in_price);

        $this->actingAs($this->neutralAdmin())->put(route('admin.coupons.update', Coupon::sole()), array_merge($base, ['type' => 'fixed_cart', 'code' => Coupon::sole()->code]))
            ->assertSessionHasNoErrors();
        $this->assertFalse(Coupon::sole()->fresh()->show_in_price, 'an amount off the basket can’t be shown per product');

        $form = $this->actingAs($this->neutralAdmin())->get(route('admin.coupons.create'))->assertOk()->getContent();
        $this->assertStringContainsString('Show the reduced price in the shop', $form);
    }
}
