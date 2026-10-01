<?php

namespace Pine\Commerce\Tests\Tax;

use Illuminate\Http\UploadedFile;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ShippingClass;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Models\TaxClass;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Services\Admin\OrderManager;
use Pine\Commerce\Services\Admin\OrderPricing;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Pine\Commerce\Services\Tax\TaxSettings;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/** Back office: Settings › Tax, Settings › Shipping (zones, methods, classes, countries), product tax fields, orders. */
class TaxShippingAdminTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        $this->actingAs($this->neutralAdmin());
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_tax_settings_screen_saves_options(): void
    {
        $this->get(route('admin.settings.edit', 'tax'))->assertOk()
            ->assertSee('Prices are entered including tax')->assertSee('Tax rates')->assertSee('Tax classes')->assertSee('Reduced rate');
        $this->get(route('admin.settings.edit', ['tax', 'class' => 'reduced-rate']))->assertOk();
        $this->get(route('admin.settings.edit', 'checkout'))->assertOk()->assertDontSee('VAT rate (%)');

        $this->put(route('admin.settings.update', 'tax'), [
            'tax__enabled' => '1', 'tax__prices_include_tax' => '0', 'tax__based_on' => 'billing', 'tax__rounding' => 'order',
            'tax__display_shop' => 'excl', 'tax__display_cart' => 'incl', 'tax__shipping_taxable' => '0', 'tax__shipping_tax_class' => 'reduced-rate',
            'tax__shipping_prices_include_tax' => 'no', 'tax__price_suffix' => 'ex. VAT', 'tax__label' => 'VAT', 'tax__adjust_non_base_prices' => '0',
        ])->assertSessionHas('success');
        $this->assertFalse(TaxSettings::pricesIncludeTax());
        $this->assertSame('billing', TaxSettings::basedOn());
        $this->assertSame('order', TaxSettings::rounding());
        $this->assertFalse(TaxSettings::displayShopIncl());
        $this->assertTrue(TaxSettings::displayCartIncl());
        $this->assertFalse(TaxSettings::shippingTaxable());
        $this->assertSame('reduced-rate', TaxSettings::shippingTaxClass());
        $this->assertFalse(TaxSettings::shippingPricesIncludeTax());
        $this->assertFalse(TaxSettings::adjustNonBasePrices());
        $this->assertSame('ex. VAT', TaxSettings::priceSuffix());

        $this->put(route('admin.settings.update', 'tax'), ['tax__rounding' => 'sometimes'])->assertSessionHasErrors('tax__rounding');
    }

    public function test_tax_rates_are_edited_per_class_and_classes_added_and_removed(): void
    {
        $gb = TaxRate::where('country', 'GB')->where('tax_class', 'standard')->firstOrFail();
        $this->put(route('admin.tax.rates.save', 'standard'), ['rates' => [
            ['id' => $gb->id, 'country' => 'gb', 'state' => '', 'postcodes' => '', 'cities' => '', 'rate' => '20', 'name' => 'VAT', 'priority' => '1', 'shipping' => '1'],
            ['id' => '', 'country' => 'GB', 'postcodes' => 'BT*; IM1-IM9', 'rate' => '0', 'name' => 'Export', 'priority' => '1'],
            ['country' => '', 'rate' => '10', 'name' => 'Rest', 'priority' => '2', 'compound' => '1', 'shipping' => '1'],
        ]])->assertRedirect();
        $rates = TaxRate::where('tax_class', 'standard')->orderBy('sort_order')->get();
        $this->assertSame(['VAT', 'Export', 'Rest'], $rates->pluck('name')->all(), 'the IM standard rate left out = deleted');
        $this->assertSame($gb->id, $rates[0]->id, 'existing rows keep their id (orders refer to it)');
        $this->assertSame(['BT*', 'IM1-IM9'], $rates[1]->postcodeList());
        $this->assertFalse($rates[1]->shipping);
        $this->assertTrue($rates[2]->compound);
        $this->assertSame('', $rates[2]->country);

        $this->put(route('admin.tax.rates.save', 'standard'), ['rates' => [['country' => 'GBR', 'rate' => '120']]])
            ->assertSessionHasErrors(['rates.0.country', 'rates.0.rate']);
        $this->put(route('admin.tax.rates.save', 'no-such-class'), ['rates' => []])->assertNotFound();

        $this->post(route('admin.tax.classes.store'), ['name' => 'Children’s clothing'])->assertRedirect();
        $class = TaxClass::where('slug', 'childrens-clothing')->firstOrFail();
        $this->post(route('admin.tax.classes.store'), ['name' => 'Children’s clothing'])->assertSessionHasErrors('name', null, 'taxClass');
        $this->put(route('admin.tax.rates.save', $class->slug), ['rates' => [['country' => 'GB', 'rate' => '0', 'name' => 'VAT']]])->assertRedirect();
        $this->assertSame(1, TaxRate::where('tax_class', $class->slug)->count());
        $this->delete(route('admin.tax.classes.destroy', $class))->assertRedirect();
        $this->assertSame(0, TaxRate::where('tax_class', $class->slug)->count());
        $this->delete(route('admin.tax.classes.destroy', TaxClass::where('slug', 'standard')->first()))->assertStatus(422);
    }

    public function test_tax_rates_csv_export_and_import(): void
    {
        $csv = $this->get(route('admin.tax.export'))->assertOk()->streamedContent();
        $this->assertStringStartsWith('"Country code","State code","Postcode / ZIP",City,"Rate %","Tax name"', $csv);
        $this->assertStringContainsString('GB,,,,20,VAT,1,0,1,', $csv);
        $this->assertStringContainsString('GB,,,,5,VAT,1,0,1,reduced-rate', $csv);

        $file = UploadedFile::fake()->createWithContent('rates.csv', "Country code,State code,Postcode / ZIP,City,Rate %,Tax name,Priority,Compound,Shipping,Tax class\n"
            ."IE,,,,23,VAT,1,0,1,\nDE,,,,19,MwSt.,1,0,1,\nFR,,75001;75002,PARIS,5.5,TVA,1,0,0,books\n");
        $this->post(route('admin.tax.import'), ['file' => $file, 'mode' => 'append'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(23.0, TaxRate::where('country', 'IE')->value('rate'));
        $books = TaxRate::where('tax_class', 'books')->firstOrFail();
        $this->assertSame(['75001', '75002'], $books->postcodeList());
        $this->assertSame(['PARIS'], $books->cityList());
        $this->assertFalse($books->shipping);
        $this->assertTrue(TaxClass::where('slug', 'books')->exists(), 'unknown classes are created');

        $replace = UploadedFile::fake()->createWithContent('rates.csv', "GB,,,,17.5,VAT,1,0,1,\n");
        $this->post(route('admin.tax.import'), ['file' => $replace, 'mode' => 'replace'])->assertRedirect();
        $this->assertSame([17.5], TaxRate::where('tax_class', 'standard')->where('country', 'GB')->pluck('rate')->all());
        $this->assertSame(0, TaxRate::where('tax_class', 'standard')->where('country', 'IE')->count(), 'replace: the standard class was replaced');

        $bad = UploadedFile::fake()->createWithContent('rates.csv', "XX,,,,20,VAT,1,0,1,\nGB,,,,abc,VAT,1,0,1,\n");
        $this->post(route('admin.tax.import'), ['file' => $bad, 'mode' => 'append'])->assertSessionHasErrors('file');
    }

    public function test_shipping_zones_methods_classes_and_countries(): void
    {
        $this->get(route('admin.shipping.index'))->assertOk()->assertSee('United Kingdom (UK)')->assertSee('Free delivery')->assertSee('Countries you sell to');
        $this->get(route('admin.shipping.zones.create'))->assertOk();

        $this->post(route('admin.shipping.zones.store'), ['name' => 'Highlands', 'regions' => ['GB'], 'postcodes' => "IV*\r\nKW*\r\n", 'regions_extra' => ''])->assertRedirect();
        $zone = ShippingZone::where('name', 'Highlands')->firstOrFail();
        $this->assertSame(['GB'], $zone->regions);
        $this->assertSame(['IV*', 'KW*'], $zone->postcodeList());
        $this->get(route('admin.shipping.zones.edit', $zone))->assertOk()->assertSee('No delivery options yet');
        $this->put(route('admin.shipping.zones.update', $zone), ['name' => 'Highlands & Islands', 'regions' => ['GB'], 'regions_extra' => 'US:CA', 'postcodes' => 'IV*'])->assertRedirect();
        $this->assertSame(['GB', 'US:CA'], $zone->fresh()->regions);
        $this->put(route('admin.shipping.zones.update', $zone), ['name' => 'X', 'regions' => ['XX'], 'regions_extra' => 'bad value'])->assertSessionHasErrors(['regions.0', 'regions_extra']);

        $this->get(route('admin.shipping.create', ['zone' => $zone->id]))->assertOk()->assertSee('By weight');
        $this->post(route('admin.shipping.store'), ['name' => 'Highlands courier', 'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
            'cost' => '', 'cost_formula' => '10 + 2 * [qty]', 'calculation' => 'order', 'tax_status' => 'none', 'is_active' => '1'])->assertRedirect(route('admin.shipping.zones.edit', $zone));
        $method = ShippingMethod::where('code', 'highlands_courier')->firstOrFail();
        $this->assertSame('10 + 2 * [qty]', $method->setting('cost'));
        $this->assertFalse($method->isTaxable());
        $this->assertSame($zone->id, (int) $method->shipping_zone_id);

        $this->post(route('admin.shipping.store'), ['name' => 'Bad', 'type' => 'flat_rate', 'cost_formula' => 'exec("x")'])->assertSessionHasErrors('cost_formula');
        $this->post(route('admin.shipping.store'), ['name' => 'Bands', 'type' => 'weight_table'])->assertSessionHasErrors('rates');
        $this->post(route('admin.shipping.store'), ['name' => 'Bands', 'type' => 'weight_table', 'shipping_zone_id' => $zone->id,
            'rates' => [['min' => '5', 'max' => '', 'cost' => '12'], ['min' => '0', 'max' => '5', 'cost' => '6'], ['min' => '', 'max' => '', 'cost' => '']]])->assertRedirect();
        $bands = ShippingMethod::where('code', 'bands')->firstOrFail();
        $this->assertEquals([['min' => 0.0, 'max' => 5.0, 'cost' => 6.0], ['min' => 5.0, 'max' => null, 'cost' => 12.0]], $bands->setting('rates'), 'blank rows dropped, sorted by "from"');
        $this->post(route('admin.shipping.store'), ['name' => 'Free', 'type' => 'free_shipping', 'requires' => 'min_amount', 'shipping_zone_id' => $zone->id])->assertSessionHasErrors('min_order_amount');
        $this->post(route('admin.shipping.store'), ['name' => 'Free', 'type' => 'free_shipping', 'requires' => 'either', 'min_order_amount' => '50', 'shipping_zone_id' => $zone->id])->assertRedirect();
        $this->assertSame('Free over £50.00 or with a coupon', ShippingMethod::where('code', 'free')->first()->summary());
        $this->get(route('admin.shipping.edit', $bands))->assertOk();
        $this->postJson(route('admin.shipping.zones.methods.reorder', $zone), ['ids' => [$bands->id, $method->id]])->assertOk();
        $this->assertSame([$bands->id, $method->id], $zone->methods()->whereIn('id', [$bands->id, $method->id])->pluck('id')->all());

        $this->post(route('admin.shipping.classes.store'), ['name' => 'Bulky', 'description' => 'Sofas'])->assertRedirect();
        $class = ShippingClass::where('slug', 'bulky')->firstOrFail();
        $product = Product::forceCreate(['name' => 'Sofa', 'slug' => 'sofa', 'type' => 'simple', 'status' => 'published', 'regular_price' => 500, 'price' => 500, 'shipping_class_id' => $class->id]);
        $this->delete(route('admin.shipping.classes.destroy', $class))->assertRedirect();
        $this->assertNull($product->fresh()->shipping_class_id);

        $this->put(route('admin.shipping.countries.update'), ['countries' => ['GB', 'IE']])->assertRedirect();
        $this->assertSame(['GB', 'IE'], array_keys(CheckoutService::countries()));
        $this->put(route('admin.shipping.countries.update'), ['countries' => []])->assertSessionHasErrors('countries');

        $this->delete(route('admin.shipping.zones.destroy', $zone))->assertRedirect(route('admin.shipping.index'));
        $this->assertSame(0, ShippingMethod::where('shipping_zone_id', $zone->id)->count());
    }

    public function test_products_and_variants_get_tax_and_shipping_classes(): void
    {
        [$product] = $this->catalogue();
        $class = ShippingClass::create(['name' => 'Small', 'slug' => 'small']);
        $this->get(route('admin.products.edit', $product))->assertOk()->assertSee('Tax class')->assertSee('Shipping class');

        $request = new \Pine\Commerce\Http\Requests\Admin\Catalogue\ProductRequest(['tax_status' => 'taxable', 'tax_class' => 'reduced-rate', 'shipping_class_id' => (string) $class->id, 'type' => 'simple']);
        $data = (fn () => $this->taxAndShipping())->call($request);
        $this->assertSame(['tax_status' => 'taxable', 'tax_class' => 'reduced-rate', 'shipping_class_id' => $class->id], $data);
        $standard = (fn () => $this->taxAndShipping())->call(new \Pine\Commerce\Http\Requests\Admin\Catalogue\ProductRequest(['tax_class' => 'standard']));
        $this->assertSame(['tax_class' => null], $standard, 'standard is stored as null, like WooCommerce');
        $this->assertSame([], (fn () => $this->taxAndShipping())->call(new \Pine\Commerce\Http\Requests\Admin\Catalogue\ProductRequest([])), 'forms without the fields leave them alone');
    }

    public function test_back_office_orders_use_the_tax_rates_of_the_order_address(): void
    {
        [$product] = $this->catalogue();
        TaxRate::create(['tax_class' => 'standard', 'country' => 'IE', 'rate' => 23, 'name' => 'VAT', 'priority' => 1, 'shipping' => true]);
        Cart::flush();

        $quote = OrderPricing::quote(['lines' => [['product_id' => $product->id, 'quantity' => 2]], 'shipping_method' => 'free_shipping',
            'address' => OrderManager::taxAddress(['billing_country' => 'GB', 'billing_postcode' => 'BS1 1AA', 'shipping_same_as_billing' => true])]);
        $this->assertSame(60.0, $quote['total']);
        $this->assertSame(10.0, $quote['tax']);
        $this->assertSame(50.0, $quote['lines'][0]['net_total']);
        $this->assertSame(60.0, $quote['lines'][0]['total'], 'the form shows prices as entered');
        $this->assertSame('VAT 20%', $quote['tax_lines'][0]['label']);

        $ie = OrderPricing::quote(['lines' => [['product_id' => $product->id, 'quantity' => 2]],
            'address' => OrderManager::taxAddress(['billing_country' => 'IE', 'shipping_same_as_billing' => true])]);
        $this->assertSame(61.5, $ie['total'], '£50 net + 23%');

        $json = $this->postJson(route('admin.orders.quote'), ['lines' => [['product_id' => $product->id, 'quantity' => 1]], 'billing_country' => 'GB', 'shipping_same_as_billing' => 1])->assertOk()->json();
        $this->assertSame('£5.00', $json['tax_lines'][0]['formatted']);

        $order = OrderManager::createManualOrder([
            'lines' => [['product_id' => $product->id, 'quantity' => 1]], 'email' => 'jo@example.test', 'status' => 'processing',
            'billing_first_name' => 'Jo', 'billing_last_name' => 'Bloggs', 'billing_address_1' => '1 Street', 'billing_city' => 'Bath',
            'billing_postcode' => 'BA1 1AA', 'billing_country' => 'GB', 'shipping_same_as_billing' => true, 'shipping_method' => 'free_shipping', 'reduce_stock' => false,
        ]);
        $order = Order::with('items', 'taxLines')->find($order->id);
        $this->assertSame('5.00', (string) $order->tax_total);
        $this->assertSame('25.00', (string) $order->items[0]->total);
        $this->assertSame('5.00', (string) $order->taxLines[0]->tax_total);
        $this->get(route('admin.orders.show', $order))->assertOk()->assertSee('VAT 20%')->assertSee('incl. £5.00 tax');
        $this->get(route('admin.orders.edit', $order))->assertOk();
        $this->get(route('admin.print', ['document' => 'invoice', 'orders' => $order->id]))->assertOk()->assertSee('VAT 20%');

        $report = $this->get(route('admin.reports.index', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))->assertOk();
        $report->assertSee('Tax by rate');
        $csv = $this->get(route('admin.reports.export', ['table' => 'taxes', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))->assertOk()->streamedContent();
        $this->assertStringContainsString('VAT 20%', $csv);
    }
}
