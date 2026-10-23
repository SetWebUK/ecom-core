<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Import\Mapping\ProductRows;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Scheduling\Tasks\RefreshSalePrices;
use Pine\Commerce\Services\Admin\Catalogue\PriceAdjuster;
use Pine\Commerce\Services\Admin\Catalogue\ProductFilter;
use Pine\Commerce\Services\Admin\Catalogue\ProductSaver;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Sale dates on variants: ProductVariation::isOnSale() honours sale_starts_at / sale_ends_at, the bulk "Put on sale"
 * and the editor's schedule reach the variants, the importers keep WooCommerce's per-variation dates, and the
 * scheduled price refresh covers products with variants.
 */
class ScheduledVariantSaleTest extends TestCase
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

    /** @return array{0: Product, 1: ProductVariation, 2: ProductVariation} */
    protected function tee(): array
    {
        $product = Product::forceCreate(['name' => 'Big Tee', 'slug' => 'big-tee', 'type' => 'variable', 'status' => 'published', 'regular_price' => 20, 'price' => 20]);
        $make = fn (string $size, int $order) => ProductVariation::forceCreate(['product_id' => $product->id, 'sku' => 'TEE-'.$size, 'options' => ['size' => $size],
            'regular_price' => 20, 'is_active' => true, 'stock_status' => 'instock', 'sort_order' => $order]);

        return [$product, $make('3xl', 0), $make('4xl', 1)];
    }

    public function test_a_variant_sale_price_only_applies_inside_its_dates(): void
    {
        [, $variant] = $this->tee();
        $variant->forceFill(['sale_price' => 15, 'sale_starts_at' => now()->addDay()])->save();
        $this->assertFalse($variant->fresh()->isOnSale(), 'not started yet');
        $this->assertSame(20.0, $variant->fresh()->currentPrice());

        $variant->forceFill(['sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay()])->save();
        $this->assertTrue($variant->fresh()->isOnSale());
        $this->assertSame(15.0, $variant->fresh()->currentPrice());

        $variant->forceFill(['sale_ends_at' => now()->subMinute()])->save();
        $this->assertFalse($variant->fresh()->isOnSale(), 'ended');

        $variant->forceFill(['sale_starts_at' => null, 'sale_ends_at' => null])->save();
        $this->assertTrue($variant->fresh()->isOnSale(), 'no dates = no limit, as before');
    }

    public function test_bulk_put_on_sale_schedules_every_variant_and_the_parent_price_follows(): void
    {
        [$product, $a, $b] = $this->tee();
        $starts = now()->addDays(2)->startOfMinute();
        $ends = now()->addDays(9)->startOfMinute();

        $this->assertSame(2, PriceAdjuster::setSale([$product->id], 25, $starts, $ends));

        foreach ([$a->fresh(), $b->fresh()] as $variant) {
            $this->assertSame(15.0, (float) $variant->sale_price);
            $this->assertSame($starts->toDateTimeString(), $variant->sale_starts_at->toDateTimeString());
            $this->assertSame($ends->toDateTimeString(), $variant->sale_ends_at->toDateTimeString());
            $this->assertFalse($variant->isOnSale());
        }
        $product->refresh();
        $this->assertSame(20.0, (float) $product->price, 'the sale has not started');
        $this->assertSame($starts->toDateTimeString(), $product->sale_starts_at->toDateTimeString(), 'the editor shows the schedule');
        $this->assertSame(0, Product::query()->whereRaw(ProductFilter::onSaleSql(), ProductFilter::onSaleBindings())->count());

        $this->travelTo($starts->copy()->addHour());
        (new RefreshSalePrices)->handle();
        $this->assertSame(15.0, (float) $product->fresh()->price, 'the scheduled refresh starts the sale');
        $this->assertSame(1, Product::query()->whereRaw(ProductFilter::onSaleSql(), ProductFilter::onSaleBindings())->count());

        $this->travelTo($ends->copy()->addHour());
        (new RefreshSalePrices)->handle();
        $this->assertSame(20.0, (float) $product->fresh()->price, 'and ends it');

        PriceAdjuster::setSale([$product->id], null);
        $this->assertNull($a->fresh()->sale_price);
        $this->assertNull($a->fresh()->sale_starts_at);
    }

    public function test_the_editor_schedule_is_copied_to_variants_with_a_sale_price(): void
    {
        [$product, $a, $b] = $this->tee();
        $product->forceFill(['sale_starts_at' => now()->addDay(), 'sale_ends_at' => now()->addDays(3)])->save();

        ProductSaver::syncVariations($product, [
            ['id' => $a->id, 'options' => ['size' => '3xl'], 'regular_price' => 20, 'sale_price' => 12],
            ['id' => $b->id, 'options' => ['size' => '4xl'], 'regular_price' => 20, 'sale_price' => null],
        ]);

        $this->assertNotNull($a->fresh()->sale_starts_at);
        $this->assertFalse($a->fresh()->isOnSale());
        $this->assertNull($b->fresh()->sale_starts_at, 'no sale price, no dates');
    }

    public function test_imports_keep_each_variations_sale_dates(): void
    {
        $from = strtotime('2026-11-27 00:00:00 UTC');
        $to = strtotime('2026-11-30 23:59:59 UTC');
        $row = ProductRows::variationRow([
            'wp_id' => 77, 'product_id' => 1, 'status' => 'publish', 'menu_order' => 0, 'date_gmt' => null, 'modified_gmt' => null, 'image' => null,
            'meta' => ['_regular_price' => '20', '_sale_price' => '15', '_sale_price_dates_from' => (string) $from, '_sale_price_dates_to' => (string) $to, 'attribute_pa_size' => '3xl'],
        ], '2026-10-15 00:00:00');

        $this->assertSame('2026-11-27 00:00:00', $row['sale_starts_at']);
        $this->assertSame('2026-11-30 23:59:59', $row['sale_ends_at']);

        $plain = ProductRows::variationRow([
            'wp_id' => 78, 'product_id' => 1, 'status' => 'publish', 'menu_order' => 0, 'date_gmt' => null, 'modified_gmt' => null, 'image' => null,
            'meta' => ['_regular_price' => '20', '_sale_price' => '', '_sale_price_dates_from' => (string) $from],
        ], '2026-10-15 00:00:00');
        $this->assertNull($plain['sale_starts_at'], 'dates without a sale price are dropped');
    }
}
