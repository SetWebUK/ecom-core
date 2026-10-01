<?php

namespace Pine\Commerce\Tests\Tax;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\ShippingClass;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Pine\Commerce\Services\Tax\TaxRates;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Shipping zones and method types in the basket, tax at checkout and what an order stores – on a fresh install
 * (package defaults: prices include VAT, UK zone with free delivery, UK VAT rates) in in-memory SQLite.
 */
class ZonesAndCheckoutTest extends TestCase
{
    use InstallsNeutralStore;

    private Product $shirt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        [$this->shirt] = $this->catalogue(); // £30 (on sale), 8 in stock
        Setting::set('checkout.countries', ['GB', 'IE', 'FR', 'US'], 'checkout');
        Cart::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function basket(int $qty = 1, ?Product $product = null): Cart
    {
        $this->post(route('cart.add'), ['product_id' => ($product ?? $this->shirt)->id, 'quantity' => $qty], ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $cart->reset();

        return $cart;
    }

    private function to(Cart $cart, string $country, string $postcode = ''): array
    {
        $cart->setDestination(['country' => $country, 'postcode' => $postcode]);
        Cart::flush();

        return $cart->totals();
    }

    public function test_a_new_store_has_a_uk_zone_free_delivery_and_prices_including_vat(): void
    {
        $zone = ShippingZone::sole();
        $this->assertSame(['GB'], $zone->regions);
        $this->assertSame('free_shipping', ShippingMethod::sole()->type);
        $this->assertSame(6, TaxRate::count(), 'GB + IM: standard, reduced, zero');

        $totals = $this->basket()->totals();
        $this->assertSame(['free_shipping'], array_keys($totals['shipping_methods']));
        $this->assertSame(30.0, $totals['total'], 'VAT is inside the £30');
        $this->assertSame(5.0, $totals['tax']);
        $this->assertSame(25.0, $totals['subtotal']);
        $this->assertSame(30.0, $totals['subtotal_display']);
        $this->assertSame('VAT 20%', $totals['tax_lines'][0]['label']);
    }

    public function test_the_first_matching_zone_wins_and_postcodes_pick_zones(): void
    {
        $uk = ShippingZone::sole();
        $highlands = ShippingZone::create(['name' => 'Highlands & Islands', 'regions' => ['GB'], 'postcodes' => "IV*\nKW*\nHS1-HS9", 'sort_order' => 0]);
        ShippingMethod::create(['shipping_zone_id' => $highlands->id, 'type' => 'flat_rate', 'name' => 'Highlands courier', 'code' => 'highlands', 'cost' => 15, 'is_active' => true, 'sort_order' => 1]);
        $europe = ShippingZone::create(['name' => 'Europe', 'regions' => ['IE', 'FR'], 'sort_order' => 5]);
        ShippingMethod::create(['shipping_zone_id' => $europe->id, 'type' => 'flat_rate', 'name' => 'EU tracked', 'code' => 'eu_tracked', 'cost' => 12, 'is_active' => true, 'sort_order' => 1]);
        $cart = $this->basket();

        $this->assertSame(['highlands'], array_keys($this->to($cart, 'GB', 'IV51 9XX')['shipping_methods']));
        $this->assertSame(['highlands'], array_keys($this->to($cart, 'GB', 'HS2 9AB')['shipping_methods']));
        $this->assertSame(['free_shipping'], array_keys($this->to($cart, 'GB', 'EH1 1AA')['shipping_methods']));
        $this->assertSame(['free_shipping'], array_keys($this->to($cart, 'GB')['shipping_methods']), 'no postcode yet: the general zone');
        $this->assertSame($uk->id, $this->to($cart, 'GB', 'EH1 1AA')['shipping_zone']->id);

        $fr = $this->to($cart, 'FR', '75001');
        $this->assertSame(['eu_tracked'], array_keys($fr['shipping_methods']));
        $this->assertSame(0.0, $fr['tax'], 'no French rate: UK VAT comes off the price');
        $this->assertSame(37.0, $fr['total'], '£25 net + £12 delivery');

        $us = $this->to($cart, 'US', '90210');
        $this->assertSame([], $us['shipping_methods']);
        $this->assertNull($us['shipping_zone']);
        $html = view('theme::checkout.partials.shipping-methods', ['totals' => $us])->render();
        $this->assertStringContainsString('we don’t deliver to United States', $html);

        // reorder: the general zone first now swallows the Highlands
        $this->actingAs($this->neutralAdmin())->postJson(route('admin.shipping.zones.reorder'), ['ids' => [$uk->id, $highlands->id, $europe->id]])->assertOk();
        $this->assertSame(['free_shipping'], array_keys($this->to($cart, 'GB', 'IV51 9XX')['shipping_methods']));
    }

    public function test_flat_rate_per_order_per_item_and_per_shipping_class(): void
    {
        $zone = ShippingZone::sole();
        ShippingMethod::query()->delete();
        $bulky = ShippingClass::create(['name' => 'Bulky', 'slug' => 'bulky']);
        $method = ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'name' => 'Standard', 'code' => 'standard', 'cost' => 4.95, 'is_active' => true, 'sort_order' => 1,
            'settings' => ['calculation' => 'order']]);
        $cart = $this->basket(3);
        $this->assertSame(4.95, $cart->shippingMethods()['standard']['cost']);

        $method->update(['settings' => ['calculation' => 'item']]);
        Cart::flush();
        $cart->reset();
        $this->assertSame(14.85, $cart->shippingMethods()['standard']['cost']);

        $method->update(['cost' => 0, 'settings' => ['calculation' => 'order', 'cost' => '2 + 1.5 * [qty]']]);
        Cart::flush();
        $cart->reset();
        $this->assertSame(6.5, $cart->shippingMethods()['standard']['cost']);

        $sofa = Product::forceCreate(['name' => 'Sofa', 'slug' => 'sofa', 'type' => 'simple', 'status' => 'published', 'regular_price' => 500, 'price' => 500,
            'stock_status' => 'instock', 'shipping_class_id' => $bulky->id, 'weight' => 40]);
        $method->update(['cost' => 5, 'settings' => ['calculation' => 'class', 'class_costs' => [(string) $bulky->id => '25'], 'no_class_cost' => '1 * [qty]', 'class_mode' => 'sum']]);
        $cart = $this->basket(1, $sofa);
        Cart::flush();
        $cart->reset();
        $this->assertSame(33.0, $cart->shippingMethods()['standard']['cost'], '5 + bulky 25 + 3 shirts × 1');
        $method->update(['settings' => ['calculation' => 'class', 'class_costs' => [(string) $bulky->id => '25'], 'no_class_cost' => '1 * [qty]', 'class_mode' => 'max']]);
        Cart::flush();
        $cart->reset();
        $this->assertSame(30.0, $cart->shippingMethods()['standard']['cost'], '5 + the most expensive class only');
    }

    public function test_weight_and_price_bands_free_shipping_rules_and_minimums(): void
    {
        $zone = ShippingZone::sole();
        ShippingMethod::query()->delete();
        config(['commerce.features.multi_shipping' => true]);
        $this->shirt->forceFill(['weight' => 0.4])->save();
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'weight_table', 'name' => 'By weight', 'code' => 'weight', 'cost' => 0, 'is_active' => true, 'sort_order' => 1,
            'settings' => ['rates' => [['min' => 0, 'max' => 1, 'cost' => 3.5], ['min' => 1, 'max' => 5, 'cost' => 6], ['min' => 5, 'max' => null, 'cost' => 12]]]]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'price_table', 'name' => 'By value', 'code' => 'value', 'cost' => 0, 'is_active' => true, 'sort_order' => 2,
            'settings' => ['rates' => [['min' => 0, 'max' => 49.99, 'cost' => 4.99], ['min' => 50, 'max' => 150, 'cost' => 2.99]]]]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'name' => 'Free over £50', 'code' => 'free_over', 'cost' => 0, 'min_order_amount' => 50, 'is_active' => true, 'sort_order' => 3,
            'settings' => ['requires' => 'either']]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'name' => 'Coupon only', 'code' => 'free_coupon', 'cost' => 0, 'is_active' => true, 'sort_order' => 4,
            'settings' => ['requires' => 'coupon']]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'name' => 'Express', 'code' => 'express', 'cost' => 9, 'min_order_amount' => 100, 'is_active' => true, 'sort_order' => 5]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'local_pickup', 'name' => 'Collect', 'code' => 'collect', 'cost' => 0, 'is_active' => true, 'sort_order' => 6]);

        $cart = $this->basket(1); // £30, 0.4 kg
        $methods = $cart->shippingMethods();
        $this->assertSame(['weight', 'value', 'collect'], array_keys($methods));
        $this->assertSame(3.5, $methods['weight']['cost']);
        $this->assertSame(4.99, $methods['value']['cost']);

        $cart->update($cart->items()->first()->id, 5); // £150, 2 kg
        Cart::flush();
        $cart->reset();
        $methods = $cart->shippingMethods();
        $this->assertSame(['weight', 'value', 'free_over', 'express', 'collect'], array_keys($methods));
        $this->assertSame(6.0, $methods['weight']['cost']);
        $this->assertSame(2.99, $methods['value']['cost']);

        $cart->update($cart->items()->first()->id, 1);
        Coupon::forceCreate(['code' => 'FREESHIP', 'type' => 'percent', 'amount' => 0, 'free_shipping' => true, 'is_active' => true]);
        $this->assertTrue($cart->applyCoupon('FREESHIP')['ok']);
        Cart::flush();
        $cart->reset();
        $this->assertSame(['weight', 'value', 'free_over', 'free_coupon', 'collect'], array_keys($cart->shippingMethods()), 'a free-shipping coupon unlocks "either" and "coupon"');

        config(['commerce.features.multi_shipping' => false]);
        Cart::flush();
        $cart->reset();
        $this->assertSame(['weight'], array_keys($cart->shippingMethods()), 'single option mode: the first available one');
    }

    public function test_local_pickup_is_taxed_at_the_shop_and_untaxed_methods_carry_no_tax(): void
    {
        $zone = ShippingZone::create(['name' => 'Ireland', 'regions' => ['IE'], 'sort_order' => 3]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'name' => 'Courier', 'code' => 'ie_courier', 'cost' => 12.3, 'is_active' => true, 'sort_order' => 1, 'tax_status' => 'none']);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'local_pickup', 'name' => 'Collect in store', 'code' => 'collect', 'cost' => 0, 'is_active' => true, 'sort_order' => 2]);
        TaxRate::create(['tax_class' => 'standard', 'country' => 'IE', 'rate' => 23, 'name' => 'VAT', 'priority' => 1, 'shipping' => true]);
        TaxRates::flush();
        config(['commerce.features.multi_shipping' => true]);
        $cart = $this->basket();

        $ie = $this->to($cart, 'IE', 'D02 X285');
        $this->assertSame('ie_courier', $ie['shipping_method']->code);
        $this->assertSame(0.0, $ie['shipping_tax'], 'courier is not taxable');
        $this->assertSame(5.75, $ie['tax'], '£25 net × 23%');
        $this->assertSame(43.05, $ie['total']);

        $cart->setShippingMethod('collect');
        $pickup = $cart->totals();
        $this->assertSame('GB', $pickup['tax_location']->country, 'collection: the shop’s VAT');
        $this->assertSame(30.0, $pickup['total']);
    }

    public function test_exclusive_prices_display_and_the_checkout_order_stores_tax_per_line_and_per_rate(): void
    {
        Setting::set('tax.prices_include_tax', false);
        Setting::set('tax.display_cart', 'excl');
        Setting::set('payments.bacs.enabled', true);
        ShippingMethod::sole()->update(['type' => 'flat_rate', 'cost' => 5, 'settings' => ['calculation' => 'order']]);
        Cart::flush();
        $cart = $this->basket(2); // 2 × £30 + VAT
        Coupon::forceCreate(['code' => 'TENOFF', 'type' => 'fixed_cart', 'amount' => 10, 'is_active' => true]);
        $this->assertTrue($cart->applyCoupon('TENOFF')['ok']);
        $cart->reset();

        $totals = $cart->totals();
        $this->assertSame(60.0, $totals['subtotal']);
        $this->assertSame(10.0, $totals['discount']);
        $this->assertSame(11.0, $totals['tax'], '(50 + 5) × 20%');
        $this->assertSame(66.0, $totals['total']);
        $summary = view('theme::checkout.partials.summary', ['cart' => $cart, 'totals' => $totals])->render();
        $this->assertStringContainsString('VAT 20%', $summary);
        $this->assertStringContainsString('£11.00', $summary);

        $token = $cart->model()->token;
        $response = $this->withCookie(Cart::cookieName(), $token)->post(route('checkout.place'), [
            'billing_email' => 'ada@example.test', 'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Lovelace',
            'shipping_address_1' => '1 High Street', 'shipping_city' => 'Bristol', 'shipping_postcode' => 'bs11aa', 'shipping_country' => 'GB',
            'shipping_phone' => '01179 000000', 'payment_method' => 'bacs', 'terms' => '1', 'shipping_method' => 'free_shipping',
        ], ['Accept' => 'application/json']);
        $response->assertOk();

        $order = Order::latest('id')->with('items', 'taxLines')->first();
        $this->assertSame('66.00', (string) $order->total);
        $this->assertSame('11.00', (string) $order->tax_total);
        $this->assertSame('1.00', (string) $order->shipping_tax);
        $this->assertFalse($order->prices_include_tax);
        $this->assertSame('BS1 1AA', $order->shipping_postcode);
        $this->assertCount(1, $order->taxLines);
        $this->assertSame('10.00', (string) $order->taxLines[0]->tax_total);
        $this->assertSame('1.00', (string) $order->taxLines[0]->shipping_tax_total);
        $item = $order->items[0];
        $this->assertSame('60.00', (string) $item->subtotal);
        $this->assertSame('50.00', (string) $item->total);
        $this->assertSame('10.00', (string) $item->tax);
        $this->assertSame('12.00', (string) $item->subtotal_tax);
        $this->assertSame('standard', $item->tax_class);

        $receipt = view('theme::emails.partials.order-details', ['order' => $order])->render();
        $this->assertStringContainsString('VAT 20%', $receipt);
        $this->assertSame([['label' => 'VAT 20%', 'rate' => 20.0, 'amount' => 11.0]], $order->taxBreakdown());
    }

    public function test_an_inclusive_order_keeps_the_shown_price_and_the_receipt_says_includes_vat(): void
    {
        Setting::set('payments.bacs.enabled', true);
        $cart = $this->basket(1);
        $token = $cart->model()->token;
        $this->withCookie(Cart::cookieName(), $token)->post(route('checkout.place'), [
            'billing_email' => 'ada@example.test', 'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Lovelace',
            'shipping_address_1' => '1 High Street', 'shipping_city' => 'Bristol', 'shipping_postcode' => 'BS1 1AA', 'shipping_country' => 'GB',
            'shipping_phone' => '01179 000000', 'payment_method' => 'bacs', 'terms' => '1',
        ], ['Accept' => 'application/json'])->assertOk();

        $order = Order::latest('id')->with('items')->first();
        $this->assertSame('30.00', (string) $order->total);
        $this->assertSame('5.00', (string) $order->tax_total);
        $this->assertSame('25.00', (string) $order->subtotal);
        $this->assertTrue($order->pricesIncludeTax());
        $amounts = $order->displayAmounts();
        $this->assertTrue($amounts['incl']);
        $this->assertSame(30.0, $amounts['subtotal']);
        $html = view('theme::checkout.partials.order-summary', ['order' => $order])->render();
        $this->assertStringContainsString('Includes', $html);
        $this->assertStringContainsString('£5.00 VAT 20%', $html);
    }

    public function test_mixed_standard_and_zero_rated_basket_summary_and_invoice_show_net_per_rate(): void
    {
        Setting::set('payments.bacs.enabled', true);
        $book = Product::forceCreate([
            'name' => 'Printed Manual', 'slug' => 'printed-manual', 'sku' => 'BOOK-1', 'type' => 'simple', 'status' => 'published',
            'regular_price' => 20, 'price' => 20, 'tax_class' => 'zero-rate', 'stock_status' => 'instock',
        ]);
        $this->basket(1);
        $cart = $this->basket(1, $book);
        $totals = $this->to($cart, 'GB', 'BS1 1AA');
        $this->assertSame(5.0, $totals['tax'], '£30 incl. 20% + £20 at 0%');
        $summary = view('theme::checkout.partials.summary', ['cart' => $cart, 'totals' => $totals])->render();
        $this->assertStringContainsString('£5.00 VAT 20%', $summary);
        $this->assertStringNotContainsString('VAT 20%,', $summary, 'no dangling separator for the skipped 0% line');

        $this->withCookie(Cart::cookieName(), $cart->model()->token)->post(route('checkout.place'), [
            'billing_email' => 'ada@example.test', 'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Lovelace',
            'shipping_address_1' => '1 High Street', 'shipping_city' => 'Bristol', 'shipping_postcode' => 'BS1 1AA', 'shipping_country' => 'GB',
            'shipping_phone' => '01179 000000', 'payment_method' => 'bacs', 'terms' => '1',
        ], ['Accept' => 'application/json'])->assertOk();

        $order = Order::latest('id')->with('items')->first();
        $lines = collect(\Pine\Commerce\Services\Invoices\DocumentData::taxLines($order))->keyBy('rate')->all();
        $this->assertCount(2, $lines, 'the zero-rated goods get their own line on the VAT invoice');
        $this->assertSame(25.0, $lines['20']['net'] ?? $lines[20.0]['net']);
        $this->assertSame(5.0, ($lines['20'] ?? $lines[20.0])['tax']);
        $this->assertSame(20.0, ($lines['0'] ?? $lines[0.0])['net']);
        $this->assertSame(0.0, ($lines['0'] ?? $lines[0.0])['tax']);
    }

    public function test_an_inclusive_order_outside_the_shop_country_invoices_the_unit_price_actually_charged(): void
    {
        Setting::set('payments.bacs.enabled', true);
        $zone = ShippingZone::create(['name' => 'Everywhere else', 'regions' => [], 'sort_order' => 9]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'name' => 'Abroad', 'code' => 'abroad', 'cost' => 10, 'is_active' => true, 'sort_order' => 1]);
        Cart::flush();
        $cart = $this->basket(2);
        $this->to($cart, 'US', '10001');
        $this->withCookie(Cart::cookieName(), $cart->model()->token)->post(route('checkout.place'), [
            'billing_email' => 'ada@example.test', 'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Lovelace',
            'shipping_address_1' => '1 Main St', 'shipping_city' => 'New York', 'shipping_postcode' => '10001', 'shipping_country' => 'US',
            'shipping_phone' => '212 000 0000', 'payment_method' => 'bacs', 'terms' => '1', 'shipping_method' => 'abroad',
        ], ['Accept' => 'application/json'])->assertOk();

        $order = Order::latest('id')->with('items')->first();
        $this->assertSame('50.00', (string) $order->items[0]->subtotal, '2 × £30 without the UK VAT');
        $html = view('commerce::pdf.invoice', ['orders' => collect([$order]), 'store' => \Pine\Commerce\Services\Invoices\DocumentData::store(),
            'logo' => null, 'document' => 'invoice'])->render();
        $this->assertStringContainsString('£25.00', $html, 'unit price charged');
        $this->assertStringNotContainsString('£30.00', $html, 'not the UK catalogue price');
    }

    public function test_checkout_update_uses_the_typed_address_and_rejects_other_countries(): void
    {
        $zone = ShippingZone::create(['name' => 'Europe', 'regions' => ['IE'], 'sort_order' => 3]);
        ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'name' => 'EU post', 'code' => 'eu_post', 'cost' => 10, 'is_active' => true, 'sort_order' => 1]);
        Cart::flush();
        $cart = $this->basket();
        $token = $cart->model()->token;

        $json = $this->withCookie(Cart::cookieName(), $token)->postJson(route('checkout.update'), ['shipping_country' => 'IE', 'shipping_postcode' => 'D02 X285'])->assertOk()->json();
        $this->assertSame('eu_post', $json['shipping_method']);
        $this->assertSame(['country' => 'IE', 'state' => '', 'postcode' => 'D02 X285', 'city' => ''], DB::table('carts')->where('token', $token)->value('destination') ? json_decode(DB::table('carts')->where('token', $token)->value('destination'), true)['shipping'] : null);

        // a country the shop does not sell to is ignored
        $this->withCookie(Cart::cookieName(), $token)->postJson(route('checkout.update'), ['shipping_country' => 'JP'])->assertOk();
        $this->assertSame('IE', json_decode(DB::table('carts')->where('token', $token)->value('destination'), true)['shipping']['country']);

        $this->assertSame(['GB' => 'United Kingdom (UK)', 'IE' => 'Ireland', 'FR' => 'France', 'US' => 'United States'], CheckoutService::countries());
        $this->assertTrue(CheckoutService::validPostcode('D02 X285', 'IE'));
        $this->assertFalse(CheckoutService::validPostcode('D02 X285', 'GB'));
        $this->assertSame('SW1A 1AA', CheckoutService::formatPostcode('sw1a1aa', 'GB'));
    }

    public function test_shop_prices_follow_the_display_setting(): void
    {
        $presenter = commerce_presenter();
        $this->assertSame(30.0, $presenter::price($this->shirt), 'entered incl, shown incl');

        Setting::set('tax.display_shop', 'excl');
        $this->assertSame(25.0, $presenter::price($this->shirt->fresh()));
        $this->assertStringContainsString('25.00', $presenter::priceHtml($this->shirt->fresh()));

        Setting::set('tax.prices_include_tax', false);
        Setting::set('tax.display_shop', 'incl');
        Setting::set('tax.price_suffix', 'inc. VAT ({price_excl} ex. VAT)');
        $html = $presenter::priceHtml($this->shirt->fresh());
        $this->assertStringContainsString('36.00', $html);
        $this->assertStringContainsString('inc. VAT (£30.00 ex. VAT)', $html);

        $this->shirt->forceFill(['tax_status' => 'none'])->save();
        $this->assertSame(30.0, $presenter::price($this->shirt->fresh()), 'not taxable: never converted');
    }
}
