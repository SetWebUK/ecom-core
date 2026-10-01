<?php

namespace Pine\Commerce\Tests\Tax;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Tax\TaxRates;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * The v1.1 migration on a store set up before it (in-memory SQLite): the old single VAT rate becomes an equivalent
 * rate row and existing delivery options move into one zone – totals and options exactly as before.
 */
class UpgradeMigrationTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        // back to a pre-v1.1 store: no rates, no zones, methods without type/zone
        TaxRate::query()->delete();
        ShippingZone::query()->delete();
        ShippingMethod::query()->delete();
        DB::table('settings')->where('key', 'like', 'tax.%')->delete();
        Cache::forget('settings.all');
        Setting::flushMemo();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function migrate(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_30_120000_add_tax_rates_and_shipping_zones.php';
        $migration->up(); // tables exist already: only the data steps run
        Setting::flushMemo();
        Cart::flush();
    }

    private function legacyMethod(array $values): void
    {
        DB::table('shipping_methods')->insert($values + ['description' => null, 'min_order_amount' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_store_with_the_old_vat_rate_keeps_its_totals_and_options(): void
    {
        DB::table('settings')->insert([
            ['group' => 'tax', 'key' => 'tax.rate', 'value' => '20', 'created_at' => now(), 'updated_at' => now()],
            ['group' => 'tax', 'key' => 'tax.label', 'value' => 'VAT', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->legacyMethod(['name' => 'Free over £50', 'code' => 'free_shipping', 'cost' => 0, 'min_order_amount' => 50, 'countries' => '["GB"]', 'sort_order' => 1]);
        $this->legacyMethod(['name' => 'Saturday', 'code' => 'saturday', 'cost' => 25, 'countries' => '["GB"]', 'sort_order' => 10]);
        $this->legacyMethod(['name' => 'Express', 'code' => 'express', 'cost' => 10, 'min_order_amount' => 100, 'countries' => null, 'sort_order' => 20]);

        $this->migrate();

        $rate = TaxRate::sole();
        $this->assertSame([20.0, '', 'standard', true, 'VAT'], [$rate->rate, $rate->country, $rate->tax_class, $rate->shipping, $rate->name]);
        $this->assertFalse(setting('tax.prices_include_tax'), 'the old rate was always added on top');
        $zone = ShippingZone::sole();
        $this->assertSame('Everywhere', $zone->name, 'one method had no country limit');
        $this->assertSame(3, ShippingMethod::where('shipping_zone_id', $zone->id)->count());
        $free = ShippingMethod::where('code', 'free_shipping')->first();
        $this->assertSame(['free_shipping', 'either'], [$free->type, $free->setting('requires')]);
        $this->assertSame(['GB'], $free->countries, 'country lists are kept');

        // basket: 1 × £30 (ex VAT) – same maths as before v1.1
        [$product] = $this->catalogue();
        $this->post(route('cart.add'), ['product_id' => $product->id], ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $cart->reset();
        $this->assertSame(['saturday'], array_keys($cart->shippingMethods()), 'free only from £50, express only from £100');
        $totals = $cart->totals();
        $this->assertSame(30.0, $totals['subtotal']);
        $this->assertSame(25.0, $totals['shipping']);
        $this->assertSame(11.0, $totals['tax'], '20% of 30 + 20% of 25');
        $this->assertSame(66.0, $totals['total']);
        $this->assertSame(20.0, $totals['tax_rate']);

        Coupon::forceCreate(['code' => 'SHIPFREE', 'type' => 'percent', 'amount' => 0, 'free_shipping' => true, 'is_active' => true]);
        $this->assertTrue($cart->applyCoupon('SHIPFREE')['ok']);
        $cart->reset();
        $this->assertSame(['free_shipping', 'saturday'], array_keys($cart->shippingMethods()), 'a free-shipping coupon still unlocks the free option');

        // idempotent: running again adds nothing
        $this->migrate();
        $this->assertSame(1, TaxRate::count());
        $this->assertSame(1, ShippingZone::count());
    }

    public function test_a_store_without_vat_gets_no_rates_and_a_uk_zone(): void
    {
        config(['commerce.tax.prices_include_tax' => false, 'commerce.tax.display_shop' => 'excl', 'commerce.tax.display_cart' => 'excl']);
        $this->legacyMethod(['name' => 'Free shipping', 'code' => 'free_shipping', 'cost' => 0, 'countries' => '["GB"]', 'sort_order' => 1]);
        $this->legacyMethod(['name' => 'Saturday Delivery', 'code' => 'saturday', 'cost' => 25, 'countries' => '["GB"]', 'sort_order' => 10]);

        $this->migrate();

        $this->assertSame(0, TaxRate::count());
        $this->assertNull(DB::table('settings')->where('key', 'tax.prices_include_tax')->value('value'), 'no settings written');
        $zone = ShippingZone::sole();
        $this->assertSame(['United Kingdom (UK)', ['GB']], [$zone->name, $zone->regions]);
        $this->assertSame(['free_shipping' => 'free_shipping', 'saturday' => 'flat_rate'], ShippingMethod::orderBy('sort_order')->pluck('type', 'code')->all());
        $this->assertSame('', ShippingMethod::where('code', 'free_shipping')->first()->setting('requires'));

        [$product] = $this->catalogue();
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2], ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $cart->reset();
        $methods = $cart->shippingMethods();
        $this->assertSame(['free_shipping' => 0.0, 'saturday' => 25.0], array_map(fn ($o) => $o['cost'], $methods));
        $cart->setShippingMethod('saturday');
        $totals = $cart->totals();
        $this->assertSame([60.0, 0.0, 25.0, 0.0, 85.0], [$totals['subtotal'], $totals['discount'], $totals['shipping'], $totals['tax'], $totals['total']]);
        $this->assertSame([], $totals['tax_lines']);
        $this->assertSame(60.0, $totals['subtotal_display']);
        $this->assertSame(0.0, TaxRates::totalPercent(TaxRates::find(null, $totals['tax_location'])));
    }
}
