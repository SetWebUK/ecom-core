<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * The Google Shopping feed's clothing fields (colour, size + fitting, gender, age group), page-builder-free
 * descriptions and the "leave delivery to Merchant Center" switch.
 */
class GoogleFeedClothingTest extends TestCase
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

    protected function feed(): string
    {
        Cache::forget('feeds.google-shopping');
        Setting::flushMemo();

        return $this->get(route('feed.google'))->assertOk()->getContent();
    }

    /** @return array<string, string> slug => value id */
    protected function attribute(string $name, string $slug, array $values): array
    {
        $attribute = Attribute::create(['name' => $name, 'slug' => $slug]);
        $ids = [];
        foreach ($values as $valueSlug => $value) {
            $ids[$valueSlug] = $attribute->values()->create(['value' => $value, 'slug' => $valueSlug])->id;
        }

        return $ids;
    }

    protected function joggers(): Product
    {
        $product = Product::forceCreate(['name' => 'Espionage Jersey Jogging Pants Black', 'slug' => 'joggers-black', 'sku' => 'JOG001-BLACK', 'type' => 'variable',
            'status' => 'published', 'regular_price' => 28, 'price' => 28, 'manage_stock' => false, 'stock_status' => 'instock',
            'description' => '[vc_row][vc_column][vc_column_text css=&quot;&quot;]<p>Soft <strong>jersey</strong> joggers.</p>[/vc_column_text][/vc_column][/vc_row]']);
        $colour = $this->attribute('COLOUR', 'colour', ['black' => 'BLACK']);
        $size = $this->attribute('SIZE', 'size', ['2xl' => '+2XL', '3xl' => '+3XL']);
        $fitting = $this->attribute('FITTING', 'fitting', ['short' => 'SHORT', 'regular' => 'REGULAR']);
        $product->attributeValues()->attach([$colour['black'], $size['2xl'], $size['3xl'], $fitting['short'], $fitting['regular']]);
        foreach ([['2xl', 'short'], ['2xl', 'regular']] as $i => [$s, $f]) {
            ProductVariation::forceCreate(['product_id' => $product->id, 'sku' => null, 'regular_price' => 28, 'stock_status' => 'instock',
                'options' => ['size' => $s, 'fitting' => $f], 'sort_order' => $i]);
        }

        return $product;
    }

    public function test_clothing_fields_come_from_the_attributes_and_the_settings(): void
    {
        $this->joggers();
        Setting::set('feeds.google_gender', 'male', 'feeds');
        Setting::set('feeds.google_age_group', 'adult', 'feeds');

        $xml = $this->feed();

        $this->assertStringContainsString('<g:color>BLACK</g:color>', $xml);
        $this->assertStringContainsString('<g:size>+2XL Short</g:size>', $xml, 'the fitting keeps the sizes of one product apart');
        $this->assertStringContainsString('<g:size>+2XL Regular</g:size>', $xml);
        $this->assertSame(2, substr_count($xml, '<g:gender>male</g:gender>'));
        $this->assertSame(2, substr_count($xml, '<g:age_group>adult</g:age_group>'));
        $this->assertStringContainsString('<g:description>Soft jersey joggers.</g:description>', $xml, 'no page-builder shortcodes or HTML');
        $this->assertStringNotContainsString('vc_', $xml);
        $this->assertStringContainsString('<g:shipping>', $xml, 'the delivery cost is sent by default');
    }

    public function test_a_products_own_gender_wins_and_nothing_is_sent_without_one(): void
    {
        $product = $this->joggers();
        $this->assertStringNotContainsString('<g:gender>', $this->feed(), 'no attribute and no setting: not sent');

        $gender = $this->attribute('GENDER', 'gender', ['ladies' => 'LADIES']);
        $product->attributeValues()->attach($gender['ladies']);
        Setting::set('feeds.google_gender', 'male', 'feeds');

        $this->assertStringContainsString('<g:gender>female</g:gender>', $this->feed());
    }

    public function test_delivery_can_be_left_to_merchant_center(): void
    {
        $this->joggers();
        Setting::set('feeds.include_shipping', false, 'feeds');

        $this->assertStringNotContainsString('<g:shipping>', $this->feed());
    }
}
