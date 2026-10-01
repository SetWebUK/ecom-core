<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pine\Commerce\Import\AdapterRegistry;
use Pine\Commerce\Import\Adapters\WooCommerce;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\RenderedSite\RenderedSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\ShippingStep;
use Pine\Commerce\Import\Steps\TaxStep;
use Pine\Commerce\Import\Support\WooCommerceContinents;
use Pine\Commerce\Models\ShippingClass;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Models\TaxClass;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Tests\TestCase;

/**
 * WooCommerce tax settings/classes/rates and shipping zones/methods/classes into the platform tables: WordPress in a
 * SQLite fixture, the target is phpunit's in-memory SQLite (never MySQL).
 */
class TaxAndShippingImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs phpunit\'s in-memory SQLite target.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
        WpFixture::boot();
        $this->woocommerceTables();
    }

    protected function tearDown(): void
    {
        DB::purge(WpFixture::CONNECTION);
        parent::tearDown();
    }

    private function woocommerceTables(): void
    {
        $s = Schema::connection(WpFixture::CONNECTION);
        $s->create('woocommerce_tax_rates', function (Blueprint $t) {
            $t->bigIncrements('tax_rate_id');
            $t->string('tax_rate_country')->default('');
            $t->string('tax_rate_state')->default('');
            $t->string('tax_rate')->default('');
            $t->string('tax_rate_name')->default('');
            $t->unsignedBigInteger('tax_rate_priority')->default(1);
            $t->integer('tax_rate_compound')->default(0);
            $t->integer('tax_rate_shipping')->default(1);
            $t->unsignedBigInteger('tax_rate_order')->default(0);
            $t->string('tax_rate_class')->default('');
        });
        $s->create('woocommerce_tax_rate_locations', function (Blueprint $t) {
            $t->bigIncrements('location_id');
            $t->string('location_code');
            $t->unsignedBigInteger('tax_rate_id');
            $t->string('location_type');
        });
        $s->create('wc_tax_rate_classes', function (Blueprint $t) {
            $t->bigIncrements('tax_rate_class_id');
            $t->string('name');
            $t->string('slug');
        });
        $s->create('woocommerce_shipping_zones', function (Blueprint $t) {
            $t->bigIncrements('zone_id');
            $t->string('zone_name');
            $t->unsignedBigInteger('zone_order')->default(0);
        });
        $s->create('woocommerce_shipping_zone_locations', function (Blueprint $t) {
            $t->bigIncrements('location_id');
            $t->unsignedBigInteger('zone_id');
            $t->string('location_code');
            $t->string('location_type');
        });
        $s->create('woocommerce_shipping_zone_methods', function (Blueprint $t) {
            $t->unsignedBigInteger('zone_id');
            $t->bigIncrements('instance_id');
            $t->string('method_id');
            $t->unsignedBigInteger('method_order')->default(0);
            $t->integer('is_enabled')->default(1);
        });

        $db = DB::connection(WpFixture::CONNECTION);
        $db->table('wc_tax_rate_classes')->insert([['name' => 'Reduced rate', 'slug' => 'reduced-rate'], ['name' => 'Books &amp; papers', 'slug' => 'books-papers']]);
        foreach ([
            ['tax_rate_id' => 1, 'tax_rate_country' => 'GB', 'tax_rate' => '20.0000', 'tax_rate_name' => 'VAT', 'tax_rate_order' => 0, 'tax_rate_class' => ''],
            ['tax_rate_id' => 2, 'tax_rate_country' => 'GB', 'tax_rate' => '5.0000', 'tax_rate_name' => 'VAT', 'tax_rate_order' => 1, 'tax_rate_class' => 'reduced-rate'],
            ['tax_rate_id' => 3, 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '7.2500', 'tax_rate_name' => 'CA tax', 'tax_rate_order' => 2, 'tax_rate_class' => '', 'tax_rate_shipping' => 0],
            ['tax_rate_id' => 4, 'tax_rate_country' => 'GB', 'tax_rate' => '0.0000', 'tax_rate_name' => 'Books', 'tax_rate_order' => 3, 'tax_rate_class' => 'books-papers'],
        ] as $rate) {
            $db->table('woocommerce_tax_rates')->insert($rate);
        }
        $db->table('woocommerce_tax_rate_locations')->insert([
            ['location_code' => '90001...90099', 'tax_rate_id' => 3, 'location_type' => 'postcode'],
            ['location_code' => 'LOS ANGELES', 'tax_rate_id' => 3, 'location_type' => 'city'],
        ]);

        $db->table('woocommerce_shipping_zones')->insert([
            ['zone_id' => 1, 'zone_name' => 'UK mainland', 'zone_order' => 1],
            ['zone_id' => 2, 'zone_name' => 'Highlands', 'zone_order' => 0],
            ['zone_id' => 3, 'zone_name' => 'Europe', 'zone_order' => 2],
        ]);
        $db->table('woocommerce_shipping_zone_locations')->insert([
            ['zone_id' => 1, 'location_code' => 'GB', 'location_type' => 'country'],
            ['zone_id' => 2, 'location_code' => 'GB', 'location_type' => 'country'],
            ['zone_id' => 2, 'location_code' => 'IV*', 'location_type' => 'postcode'],
            ['zone_id' => 2, 'location_code' => 'HS1...HS9', 'location_type' => 'postcode'],
            ['zone_id' => 3, 'location_code' => 'EU', 'location_type' => 'continent'],
            ['zone_id' => 3, 'location_code' => 'AS', 'location_type' => 'continent'],
        ]);
        $db->table('woocommerce_shipping_zone_methods')->insert([
            ['zone_id' => 1, 'instance_id' => 1, 'method_id' => 'flat_rate', 'method_order' => 1, 'is_enabled' => 1],
            ['zone_id' => 1, 'instance_id' => 2, 'method_id' => 'free_shipping', 'method_order' => 2, 'is_enabled' => 1],
            ['zone_id' => 2, 'instance_id' => 3, 'method_id' => 'flat_rate', 'method_order' => 1, 'is_enabled' => 1],
            ['zone_id' => 3, 'instance_id' => 4, 'method_id' => 'local_pickup', 'method_order' => 1, 'is_enabled' => 0],
            ['zone_id' => 0, 'instance_id' => 5, 'method_id' => 'table_rate', 'method_order' => 1, 'is_enabled' => 1],
        ]);
        $bulky = WpFixture::term(40, 'product_shipping_class', 'Bulky', 'bulky');
        WpFixture::option('woocommerce_flat_rate_1_settings', ['title' => 'Standard', 'tax_status' => 'taxable', 'cost' => '3.95 + 0.5 * [qty]', 'class_cost_40' => '20', 'no_class_cost' => '', 'type' => 'order']);
        WpFixture::option('woocommerce_free_shipping_2_settings', ['title' => 'Free over £50', 'requires' => 'either', 'min_amount' => '50', 'ignore_discounts' => 'yes']);
        WpFixture::option('woocommerce_flat_rate_3_settings', ['title' => 'Highlands', 'tax_status' => 'none', 'cost' => '14.50']);
        WpFixture::option('woocommerce_local_pickup_4_settings', ['title' => 'Collect', 'cost' => '0']);
        WpFixture::option('woocommerce_prices_include_tax', 'yes');
        WpFixture::option('woocommerce_calc_taxes', 'yes');
        WpFixture::option('woocommerce_tax_display_cart', 'incl');
        WpFixture::option('woocommerce_tax_based_on', 'billing');
        WpFixture::option('woocommerce_tax_round_at_subtotal', 'yes');
        WpFixture::option('woocommerce_shipping_tax_class', '');
        WpFixture::option('woocommerce_price_display_suffix', 'inc. VAT');
        WpFixture::option('woocommerce_default_country', 'GB:ENG');
        WpFixture::option('woocommerce_allowed_countries', 'specific');
        WpFixture::option('woocommerce_specific_allowed_countries', ['GB', 'ie']);

        // a product and a variation with the Bulky class
        $productId = WpFixture::post(['post_title' => 'Sofa', 'post_type' => 'product', 'post_name' => 'sofa']);
        WpFixture::relate($productId, $bulky);
        DB::table('products')->insert(['name' => 'Sofa', 'slug' => 'sofa', 'type' => 'simple', 'status' => 'published', 'wp_id' => $productId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function context(array $config = []): ImportContext
    {
        $wp = new WordPressSource(WpFixture::CONNECTION);

        return (new ImportContext(null, $wp, SiteProfile::detect($wp), new AdapterRegistry([]), new RenderedSource([]), $config))->boot();
    }

    public function test_woocommerce_tax_settings_are_mapped(): void
    {
        $settings = (new WooCommerce)->settings($this->context());
        $this->assertTrue($settings['tax.prices_include_tax']);
        $this->assertSame('incl', $settings['tax.display_cart']);
        $this->assertSame('billing', $settings['tax.based_on']);
        $this->assertSame('order', $settings['tax.rounding']);
        $this->assertSame('standard', $settings['tax.shipping_tax_class']);
        $this->assertSame('no', $settings['tax.shipping_prices_include_tax'], 'WooCommerce shipping costs are ex. VAT');
        $this->assertSame('inc. VAT', $settings['tax.price_suffix']);
        $this->assertSame(['GB', 'IE'], $settings['checkout.countries']);

        $limited = (new WooCommerce)->settings($this->context(['settings' => ['woocommerce' => ['store.name', 'store.country', 'store.currency']]]));
        $this->assertSame(['store.name', 'store.country', 'store.currency'], array_keys($limited), 'a client that leaves tax out keeps its own set-up');
    }

    public function test_tax_classes_and_rates_are_imported_idempotently(): void
    {
        $step = new TaxStep;
        $step->run($this->context());
        $step->run($this->context());

        $this->assertSame(4, TaxRate::count());
        $this->assertSame('Books & papers', TaxClass::where('slug', 'books-papers')->value('name'));
        $ca = TaxRate::where('wp_id', 3)->firstOrFail();
        $this->assertSame(['US', 'CA', 7.25, false], [$ca->country, $ca->state, $ca->rate, $ca->shipping]);
        $this->assertSame(['90001...90099'], $ca->postcodeList());
        $this->assertSame(['LOS ANGELES'], $ca->cityList());
        $this->assertSame('reduced-rate', TaxRate::where('wp_id', 2)->value('tax_class'));
        $this->assertSame('standard', TaxRate::where('wp_id', 1)->value('tax_class'));

        TaxRate::query()->delete();
        $optedOut = $this->context(['settings' => ['woocommerce' => ['store.name']]]);
        $this->assertFalse($step->shouldRun($optedOut));
        $step->run($optedOut);
        $this->assertSame(0, TaxRate::count());
    }

    public function test_every_woocommerce_continent_is_expanded_to_its_countries(): void
    {
        $this->assertSame(['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'], array_keys(WooCommerceContinents::CONTINENTS));
        $all = [];
        foreach (WooCommerceContinents::CONTINENTS as $code => $continent) {
            $this->assertNotEmpty($continent['countries'], $code);
            foreach ($continent['countries'] as $country) {
                $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $country);
                $this->assertArrayNotHasKey($country, $all, "{$country} is in one continent only");
                $all[$country] = $code;
            }
        }
        $this->assertSame(['NA', 'SA', 'OC', 'AF', 'AN', 'EU'], [$all['US'], $all['BR'], $all['AU'], $all['ZA'], $all['AQ'], $all['GB']]);
        $this->assertSame(WooCommerceContinents::countries('EU'), ShippingStep::EUROPE);
        $this->assertSame('North America', WooCommerceContinents::name('na'));
        $this->assertSame([], WooCommerceContinents::countries('XX'));

        $db = DB::connection(WpFixture::CONNECTION);
        $db->table('woocommerce_shipping_zones')->insert(['zone_id' => 4, 'zone_name' => 'Long haul', 'zone_order' => 3]);
        foreach (['NA', 'sa', 'OC', 'AF', 'AN', 'XX'] as $code) {
            $db->table('woocommerce_shipping_zone_locations')->insert(['zone_id' => 4, 'location_code' => $code, 'location_type' => 'continent']);
        }
        $db->table('woocommerce_shipping_zone_locations')->insert(['zone_id' => 4, 'location_code' => 'US', 'location_type' => 'country']);
        $db->table('woocommerce_shipping_zone_methods')->insert(['zone_id' => 4, 'instance_id' => 6, 'method_id' => 'flat_rate', 'method_order' => 1, 'is_enabled' => 1]);

        $ctx = $this->context();
        (new ShippingStep)->run($ctx);
        $zone = ShippingZone::where('name', 'Long haul')->firstOrFail();
        $expected = array_values(array_unique(array_merge(['US'], ...array_map(fn ($c) => WooCommerceContinents::countries($c), ['NA', 'SA', 'OC', 'AF', 'AN']))));
        $this->assertSame($expected, $zone->regions, 'countries first, then each continent, no duplicates');
        foreach (['CA', 'MX', 'AR', 'AU', 'NZ', 'NG', 'AQ'] as $country) {
            $this->assertContains($country, $zone->regions);
        }
        $this->assertNotContains('GB', $zone->regions);
        $warnings = implode("\n", $ctx->warnings);
        $this->assertStringContainsString("unknown continent 'XX'", $warnings);
        $this->assertStringNotContainsString("continent 'AS'", $warnings, 'Asia is imported, not reported');
        $this->assertStringNotContainsString("continent 'NA'", $warnings);
    }

    public function test_shipping_zones_methods_and_classes_are_imported(): void
    {
        $ctx = $this->context();
        (new ShippingStep)->run($ctx);
        (new ShippingStep)->run($this->context()); // idempotent

        $zones = ShippingZone::orderBy('sort_order')->get();
        $this->assertSame(['Highlands', 'UK mainland', 'Europe', 'Rest of the world'], $zones->pluck('name')->all());
        $this->assertSame(['IV*', 'HS1...HS9'], $zones[0]->postcodeList());
        $this->assertContains('FR', $zones[2]->regions, 'Europe expanded from the EU continent');
        $this->assertContains('JP', $zones[2]->regions, 'Asia (AS) expanded too');
        $this->assertContains('CY', $zones[2]->regions, 'WooCommerce lists Cyprus under Asia');
        $this->assertContains('TR', $zones[2]->regions, '… and Turkey under Europe');
        $this->assertSame(count(array_unique(array_merge(WooCommerceContinents::countries('EU'), WooCommerceContinents::countries('AS')))), count($zones[2]->regions));
        $this->assertNull($zones[3]->regions);

        $bulky = ShippingClass::where('wp_id', 40)->firstOrFail();
        $this->assertSame($bulky->id, (int) DB::table('products')->where('slug', 'sofa')->value('shipping_class_id'));

        $standard = ShippingMethod::where('code', 'flat_rate')->firstOrFail();
        $this->assertSame('flat_rate', $standard->type);
        $this->assertSame('3.95 + 0.5 * [qty]', $standard->setting('cost'));
        $this->assertSame('class', $standard->setting('calculation'));
        $this->assertSame([(string) $bulky->id => '20'], $standard->setting('class_costs'));
        $this->assertSame('max', $standard->setting('class_mode'), 'WooCommerce "per order" = the most expensive class');
        $this->assertSame($zones[1]->id, (int) $standard->shipping_zone_id);

        $free = ShippingMethod::where('code', 'free_shipping')->firstOrFail();
        $this->assertSame(['free_shipping', 'either', true, '50.00'], [$free->type, $free->setting('requires'), $free->setting('ignore_discounts'), (string) $free->min_order_amount]);

        $highlands = ShippingMethod::where('code', 'flat_rate-2')->firstOrFail();
        $this->assertSame(['14.50', 'none', $zones[0]->id], [(string) $highlands->cost, $highlands->tax_status, (int) $highlands->shipping_zone_id]);

        $pickup = ShippingMethod::where('code', 'local_pickup')->firstOrFail();
        $this->assertSame(['local_pickup', false], [$pickup->type, $pickup->is_active]);

        $plugin = ShippingMethod::where('code', 'table_rate')->firstOrFail();
        $this->assertFalse($plugin->is_active, 'unknown plugin methods are imported switched off');
        $this->assertSame($zones[3]->id, (int) $plugin->shipping_zone_id);
        $this->assertSame(5, ShippingMethod::count());
    }

    public function test_the_flat_import_is_kept_for_clients_that_switch_zones_off(): void
    {
        (new ShippingStep)->run($this->context(['shipping' => ['zones' => false]]));

        $this->assertSame(0, ShippingZone::count());
        $this->assertSame(0, ShippingClass::count());
        $methods = ShippingMethod::orderBy('id')->get()->keyBy('code');
        $this->assertSame(['GB'], $methods['flat_rate']->countries);
        $this->assertSame('0.00', (string) $methods['flat_rate']->cost, 'formula imported as 0 (with a warning)');
        $this->assertNull($methods['flat_rate']->shipping_zone_id);
        $this->assertSame('flat_rate', $methods['free_shipping']->typeKey(), 'old import: every method is a flat rate');
        $this->assertNull($methods['table_rate']->countries);
    }
}
