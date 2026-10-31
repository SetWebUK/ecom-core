<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Tests\TestCase;

/**
 * A shop imported through the REST API before 1.11.0: every size without its own SKU got the parent's, so the
 * admin refused to save the product. The migration clears those copies and leaves real variant SKUs alone.
 */
class CopiedVariantSkuMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs phpunit\'s in-memory SQLite target.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    public function test_variant_skus_copied_from_the_product_are_cleared(): void
    {
        $shirt = Product::forceCreate(['name' => 'Evening Shirt', 'slug' => 'evening-shirt', 'sku' => 'SLX5003-', 'type' => 'variable',
            'status' => 'published', 'regular_price' => 30, 'price' => 30, 'stock_status' => 'instock']);
        $polo = Product::forceCreate(['name' => 'Polo', 'slug' => 'polo', 'sku' => 'PO74', 'type' => 'variable',
            'status' => 'published', 'regular_price' => 20, 'price' => 20, 'stock_status' => 'instock']);
        $sizes = ['15' => 'SLX5003-', '16' => 'SLX5003-', '17' => 'SLX5003-17'];
        foreach ($sizes as $size => $sku) {
            ProductVariation::create(['product_id' => $shirt->id, 'sku' => $sku, 'options' => ['size' => (string) $size], 'regular_price' => 30, 'is_active' => true]);
        }
        // the same SKU as another product's is not a copy of its own product's: left for the shop to sort out
        ProductVariation::create(['product_id' => $polo->id, 'sku' => 'SLX5003-', 'options' => ['size' => 'xl'], 'regular_price' => 20, 'is_active' => true]);

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_30_000100_clear_copied_variant_skus.php';
        $migration->up();

        $this->assertSame([null, null, 'SLX5003-17'], ProductVariation::query()->where('product_id', $shirt->id)->orderBy('id')->pluck('sku')->all());
        $this->assertSame('SLX5003-', ProductVariation::query()->where('product_id', $polo->id)->value('sku'));
        $this->assertSame('SLX5003-', $shirt->fresh()->sku);
    }
}
