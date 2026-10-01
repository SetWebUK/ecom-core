<?php

namespace Pine\Commerce\Tests\Tax;

use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Services\Tax\TaxEngine;
use Pine\Commerce\Services\Tax\TaxLocation;
use Pine\Commerce\Services\Tax\TaxRates;
use Pine\Commerce\Services\Tax\TaxSettings;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * The tax maths (in-memory SQLite, fresh install): inclusive/exclusive prices, per line / per order rounding,
 * compound rates, priorities, postcode rates, shipping tax classes and re-basing inclusive prices for other rates.
 */
class TaxEngineTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        TaxRate::query()->delete();
        TaxRates::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function rate(array $values): TaxRate
    {
        $rate = TaxRate::create($values + ['tax_class' => 'standard', 'country' => 'GB', 'state' => '', 'name' => 'VAT', 'priority' => 1,
            'compound' => false, 'shipping' => true, 'sort_order' => 0]);
        TaxRates::flush();

        return $rate;
    }

    private function gb(string $postcode = ''): TaxLocation
    {
        return new TaxLocation('GB', '', $postcode);
    }

    private function calc(array $lines, ?array $shipping = null, ?TaxLocation $at = null, array $options = []): array
    {
        $lines = array_map(fn ($l) => is_array($l) ? $l : ['subtotal' => $l, 'total' => $l], $lines);

        return app(TaxEngine::class)->calculate($lines, $shipping, $at ?? $this->gb(), $options + ['base' => $this->gb()]);
    }

    public function test_the_package_defaults_are_uk_inclusive_and_the_install_seeded_vat(): void
    {
        $this->assertTrue(TaxSettings::pricesIncludeTax());
        $this->assertTrue(TaxSettings::displayShopIncl());
        $this->assertTrue(TaxSettings::displayCartIncl());
        $this->assertSame('line', TaxSettings::rounding());
        $this->assertSame('GB', TaxSettings::baseLocation()->country);
    }

    public function test_exclusive_prices_add_tax_per_line_like_before_v1_1(): void
    {
        $this->rate(['rate' => 20]);
        $r = $this->calc([10.00, 4.99], ['cost' => 5.00, 'taxable' => true], null, ['inclusive' => false, 'shipping_inclusive' => false]);

        $this->assertSame(14.99, $r['subtotal']);
        $this->assertSame([2.0, 1.0], array_column(array_values($r['lines']), 'tax')); // 0.998 → 1.00
        $this->assertSame(1.0, $r['shipping_tax']);
        $this->assertSame(4.0, $r['tax']);
        $this->assertSame(23.99, $r['total']);
        $this->assertSame(round($r['subtotal'] - $r['discount'] + $r['shipping_net'] + $r['tax'], 2), $r['total']);
        $this->assertSame('VAT 20%', array_values($r['rates'])[0]['label']);
    }

    public function test_inclusive_prices_extract_tax_and_the_customer_pays_the_shown_price(): void
    {
        $this->rate(['rate' => 20]);
        $r = $this->calc([120.00, 9.99], ['cost' => 4.99, 'taxable' => true], null, ['inclusive' => true, 'shipping_inclusive' => true]);

        $lines = array_values($r['lines']);
        $this->assertSame(20.0, $lines[0]['tax']);
        $this->assertSame(100.0, $lines[0]['net_total']);
        $this->assertSame(1.67, $lines[1]['tax']);   // 9.99 / 1.2 = 8.325 → 1.665 → 1.67
        $this->assertSame(8.32, $lines[1]['net_total']);
        $this->assertSame(0.83, $r['shipping_tax']); // 4.99 / 6
        $this->assertSame(134.98, $r['total'], 'total = the prices the customer saw');
        $this->assertSame(22.5, $r['tax']);
        $this->assertSame(129.99, $r['gross_subtotal']);
        $this->assertSame(round($r['subtotal'] - $r['discount'] + $r['shipping_net'] + $r['tax'], 2), $r['total']);
    }

    public function test_rounding_per_line_or_per_order(): void
    {
        $this->rate(['rate' => 20]);
        $lines = [0.99, 0.99, 0.99];
        $perLine = $this->calc($lines, null, null, ['inclusive' => false, 'rounding' => 'line']);
        $perOrder = $this->calc($lines, null, null, ['inclusive' => false, 'rounding' => 'order']);

        $this->assertSame(0.6, $perLine['tax']);    // 0.198 → 0.20 × 3
        $this->assertSame(0.59, $perOrder['tax']);  // 0.594
        $this->assertSame(3.57, $perLine['total']);
        $this->assertSame(3.56, $perOrder['total']);

        $incl = $this->calc($lines, null, null, ['inclusive' => true, 'rounding' => 'order']);
        $this->assertSame(2.97, $incl['total'], 'inclusive totals never change with rounding');
        $this->assertSame(0.5, $incl['tax']); // 3 × 0.165 = 0.495
    }

    public function test_coupons_are_taken_off_the_entered_price_in_both_modes(): void
    {
        $this->rate(['rate' => 20]);
        // £120 incl. VAT, £10 coupon: the customer pays £110, of which £18.33 VAT
        $incl = $this->calc([['subtotal' => 120.00, 'total' => 110.00]], null, null, ['inclusive' => true]);
        $this->assertSame(110.0, $incl['total']);
        $this->assertSame(18.33, $incl['tax']);
        $this->assertSame(100.0, $incl['subtotal']);
        $this->assertSame(8.33, $incl['discount']);
        $this->assertSame(10.0, $incl['gross_discount']);

        // £100 ex. VAT, £10 coupon: £90 + £18 VAT
        $excl = $this->calc([['subtotal' => 100.00, 'total' => 90.00]], null, null, ['inclusive' => false]);
        $this->assertSame(108.0, $excl['total']);
        $this->assertSame(10.0, $excl['discount']);
        $this->assertSame(18.0, $excl['tax']);
    }

    public function test_priorities_add_up_and_compound_rates_go_on_top(): void
    {
        $this->rate(['rate' => 5, 'name' => 'GST', 'priority' => 1]);
        $this->rate(['rate' => 10, 'name' => 'PST', 'priority' => 2, 'compound' => true]);
        $excl = $this->calc([100.00], null, null, ['inclusive' => false]);
        $this->assertSame(15.5, $excl['tax']); // 5 + 10% of 105
        $this->assertSame(['GST 5%', 'PST 10%'], array_column(array_values($excl['rates']), 'label'));

        $incl = $this->calc([115.50], null, null, ['inclusive' => true]);
        $this->assertSame(15.5, $incl['tax']);
        $this->assertSame(100.0, $incl['subtotal']);
        $this->assertSame(15.5, TaxRates::totalPercent(TaxRates::find('standard', $this->gb())));
    }

    public function test_the_most_specific_rate_wins_within_a_priority(): void
    {
        $this->rate(['rate' => 20, 'country' => '', 'name' => 'Tax']);
        $this->rate(['rate' => 20, 'country' => 'GB']);
        $this->rate(['rate' => 0, 'country' => 'GB', 'postcodes' => "BT*\nIM*", 'name' => 'NI test']);
        $this->rate(['rate' => 8.875, 'country' => 'US', 'state' => 'NY', 'cities' => 'NEW YORK', 'name' => 'NYC']);

        $this->assertSame('VAT', TaxRates::find(null, $this->gb('SW1A 1AA'))[0]->name);
        $this->assertSame('NI test', TaxRates::find(null, $this->gb('BT1 1AA'))[0]->name);
        $this->assertSame('Tax', TaxRates::find(null, new TaxLocation('FR'))[0]->name);
        $this->assertSame('NYC', TaxRates::find(null, new TaxLocation('US', 'NY', '10001', 'New York'))[0]->name);
        $this->assertSame('Tax', TaxRates::find(null, new TaxLocation('US', 'CA', '90210', 'Los Angeles'))[0]->name, 'the every-country rate');
        $this->assertSame([], TaxRates::find('reduced-rate', $this->gb()), 'no reduced rates: nothing charged');
    }

    public function test_tax_classes_and_the_shipping_tax_class(): void
    {
        $this->rate(['rate' => 20]);
        $this->rate(['rate' => 5, 'tax_class' => 'reduced-rate']);

        $reduced = $this->calc([['subtotal' => 105.0, 'total' => 105.0, 'class' => 'reduced-rate']], ['cost' => 10.50, 'taxable' => true], null, ['inclusive' => true, 'shipping_inclusive' => true]);
        $this->assertSame(5.0, $reduced['lines'][0]['tax']);
        $this->assertSame(0.5, $reduced['shipping_tax'], 'inherit: only reduced-rate items, so shipping at 5%');

        $mixed = $this->calc([['subtotal' => 105.0, 'total' => 105.0, 'class' => 'reduced-rate'], ['subtotal' => 12.0, 'total' => 12.0]], ['cost' => 12.0, 'taxable' => true], null, ['inclusive' => true, 'shipping_inclusive' => true]);
        $this->assertSame(2.0, $mixed['shipping_tax'], 'any standard item: shipping at the standard rate');

        $fixed = $this->calc([['subtotal' => 105.0, 'total' => 105.0, 'class' => 'reduced-rate']], ['cost' => 12.0, 'taxable' => true], null, ['inclusive' => true, 'shipping_inclusive' => true, 'shipping_class' => 'standard']);
        $this->assertSame(2.0, $fixed['shipping_tax']);

        $untaxed = $this->calc([12.0], ['cost' => 12.0, 'taxable' => false], null, ['inclusive' => true, 'shipping_inclusive' => true]);
        $this->assertSame(0.0, $untaxed['shipping_tax']);
        $this->assertSame(12.0, $untaxed['shipping_net']);

        $notTaxable = $this->calc([['subtotal' => 50.0, 'total' => 50.0, 'taxable' => false]], null, null, ['inclusive' => true]);
        $this->assertSame(0.0, $notTaxable['tax']);
        $this->assertSame(50.0, $notTaxable['total']);

        $off = $this->calc([120.0], ['cost' => 6.0], null, ['inclusive' => true, 'shipping_inclusive' => true, 'enabled' => false]);
        $this->assertSame(0.0, $off['tax']);
        $this->assertSame(126.0, $off['total']);
    }

    public function test_inclusive_prices_are_rebased_for_customers_with_other_rates(): void
    {
        $this->rate(['rate' => 20]);
        $this->rate(['rate' => 21, 'country' => 'IE']);

        $us = $this->calc([120.0], null, new TaxLocation('US'), ['inclusive' => true]);
        $this->assertSame(100.0, $us['total'], 'no US rate: UK VAT removed');
        $this->assertSame(0.0, $us['tax']);

        $ie = $this->calc([120.0], null, new TaxLocation('IE'), ['inclusive' => true]);
        $this->assertSame(121.0, $ie['total'], 'net £100 + 21%');
        $this->assertSame(21.0, $ie['tax']);

        $same = $this->calc([120.0], null, new TaxLocation('US'), ['inclusive' => true, 'adjust' => false]);
        $this->assertSame(120.0, $same['total'], 'adjusting switched off: everyone pays the shown price');
        $this->assertSame(0.0, $same['tax']);
    }

    public function test_settings_are_read_with_config_fallbacks(): void
    {
        Setting::set('tax.prices_include_tax', false);
        Setting::set('tax.rounding', 'order');
        Setting::set('tax.display_shop', 'incl');
        $this->assertFalse(TaxSettings::pricesIncludeTax());
        $this->assertSame('order', TaxSettings::rounding());
        $this->assertTrue(TaxSettings::displayShopIncl());
        Setting::set('tax.display_cart', '');
        config(['commerce.tax.display_cart' => '']);
        $this->assertFalse(TaxSettings::displayCartIncl(), 'blank = as entered');
        Setting::set('tax.shipping_prices_include_tax', 'yes');
        $this->assertTrue(TaxSettings::shippingPricesIncludeTax());
    }
}
