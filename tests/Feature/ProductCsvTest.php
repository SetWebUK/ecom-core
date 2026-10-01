<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Media;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Cells;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Columns;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\CsvFile;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Exporter;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImportRunner;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImportSession;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\RowImporter;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Full product CSV (feature switch product_csv): export, column mapping (incl. WooCommerce product exports), dry run,
 * batched + resumable import, images from URLs, the admin pages and the commerce:products:* commands.
 * Fresh package install on phpunit's in-memory SQLite only (never the shop's database); fake disks and HTTP.
 */
class ProductCsvTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Storage::fake('local');
        Storage::fake('public');
        config(['commerce.product_csv.url_guard' => false]);
        Http::preventStrayRequests();
        Http::fake(['img.example.test/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function fixture(string $name): string
    {
        return dirname(__DIR__).'/Fixtures/csv/'.$name;
    }

    protected function png(): string
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Session for a file with auto-mapping, then the whole pass run through ImportRunner (small batches). */
    protected function import(string $path, array $options = [], bool $dryRun = false, int $chunk = 2): ImportSession
    {
        $session = ImportSession::create($path, basename($path), $this->neutralAdmin()->id, $options);
        $session->begin($dryRun ? 'preview' : 'importing');
        (new ImportRunner($session))->run(null, $chunk);

        return ImportSession::find($session->token);
    }

    protected function results(ImportSession $session): array
    {
        $rows = [];
        foreach ($session->results() as $row) {
            $rows[$row['sku'] !== '' ? $row['sku'] : 'line'.$row['line']] = $row;
        }

        return $rows;
    }

    protected function csvFrom(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pcsv');
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    // ---------------------------------------------------------------------------------------------- cells / columns

    public function test_list_cells_round_trip_with_escapes(): void
    {
        $this->assertSame(['A', 'B|C', 'D\\E'], Cells::split('A | B\\|C | D\\\\E'));
        $this->assertSame(['Kitchen > Mugs', 'Bags, Cases'], Cells::split('Kitchen > Mugs, Bags\\, Cases', true), 'WooCommerce commas');
        $this->assertSame(['Kitchen > Mugs, Gifts'], Cells::split('Kitchen > Mugs, Gifts'), 'own format: commas stay');
        $pairs = [['Memory', ['8GB', '16GB']], ['Colour', ['Silver, Grey', 'a|b']]];
        $this->assertSame($pairs, Cells::pairs(Cells::joinPairs($pairs)));
        $this->assertSame([['Ports', ['USB-C, HDMI | x']]], Cells::pairs(Cells::joinPairs([['Ports', ['USB-C, HDMI | x']]], false), false));
        $this->assertSame('published', Cells::status('1'));
        $this->assertSame('private', Cells::status('0'));
        $this->assertSame('draft', Cells::status('-1'));
        $this->assertSame('onbackorder', Cells::stockStatus('backorder'));
        $this->assertSame('notify', Cells::backorders('notify'));
        $this->assertSame('simple', Cells::type('simple, virtual'));
        $this->assertSame(1234.5, Cells::money('£1,234.50'));
        $this->assertSame('2026-06-30 23:00:00', Cells::date('01/07/2026', 'x')->format('Y-m-d H:i:s'), 'UK day/month, stored UTC (BST)');
        $this->expectException(\InvalidArgumentException::class);
        Cells::type('grouped');
    }

    public function test_headers_are_auto_mapped_including_woocommerce(): void
    {
        $woo = CsvFile::inspect($this->fixture('woocommerce-export.csv'), 100);
        $this->assertTrue(Columns::looksLikeWooCommerce($woo['headers']));
        $map = array_combine($woo['headers'], Columns::autoMap($woo['headers']));
        $this->assertSame('status', $map['Published']);
        $this->assertSame('featured', $map['Is featured?']);
        $this->assertSame('stock_status', $map['In stock?']);
        $this->assertSame('stock_quantity', $map['Stock']);
        $this->assertSame('weight', $map['Weight (kg)']);
        $this->assertSame('parent_sku', $map['Parent']);
        $this->assertSame('gtin', $map['GTIN, UPC, EAN, or ISBN']);
        $this->assertSame('brand', $map['Brands']);
        $this->assertSame('meta_title', $map['Meta: _yoast_wpseo_title']);
        $this->assertSame('woo_attr_name:1', $map['Attribute 1 name']);
        $this->assertSame('woo_attr_values:2', $map['Attribute 2 value(s)']);
        $this->assertNull($map['Attribute 1 global']);
        $this->assertNull($map['Tags']);
        $this->assertSame(7, $woo['rows']);

        $own = CsvFile::inspect($this->fixture('products.csv'), 100);
        $this->assertFalse(Columns::looksLikeWooCommerce($own['headers']));
        $this->assertSame(['id', 'type', 'sku', 'name', 'slug', 'status', 'parent_sku', 'categories'], array_slice(Columns::autoMap($own['headers']), 0, 8));
        $this->assertSame(5, $own['rows'], 'a quoted cell over two lines is one row');
    }

    public function test_file_limits_and_bad_files(): void
    {
        $this->expectExceptionMessage('more than 3 rows');
        config(['commerce.product_csv.max_rows' => 3]);
        ImportSession::create($this->fixture('woocommerce-export.csv'), 'x.csv', null);
    }

    // ---------------------------------------------------------------------------------------------- export

    public function test_export_writes_every_product_and_variant_with_formula_guard(): void
    {
        [$shirt] = $this->catalogue();
        $this->import($this->fixture('woocommerce-export.csv'), ['update_existing' => true]);
        Product::query()->where('sku', 'SHIRT-1')->update(['name' => '=cmd|calc']);

        $handle = fopen('php://memory', 'w+');
        $count = (new Exporter)->write($handle, Product::query());
        rewind($handle);
        $csv = stream_get_contents($handle);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(4 + 2, $count, 'shirt, mug, tee, lamp + two variants');

        $info = CsvFile::inspect($this->writeTemp($csv), 100);
        $this->assertSame(Columns::exportKeys(), $info['headers']);
        $rows = [];
        foreach (CsvFile::rows($this->writeTemp($csv), ',', $info['offset']) as [, $cells]) {
            $rows[$cells[2]] = array_combine($info['headers'], array_pad($cells, count($info['headers']), ''));
        }
        $this->assertSame("'=cmd|calc", $rows['SHIRT-1']['name'], 'formula injection guarded');
        $this->assertSame('Shirts', $rows['SHIRT-1']['categories']);
        $this->assertSame('40.00', $rows['SHIRT-1']['regular_price']);
        $this->assertSame('Material: 100% linen', $rows['SHIRT-1']['specs']);
        $this->assertSame('Kitchen > Mugs | Gifts', $rows['WOO-MUG']['categories']);
        $this->assertSame('Kitchen > Mugs', $rows['WOO-MUG']['primary_category']);
        $this->assertSame('Colour: Blue, Green | Material: Enamel', $rows['WOO-MUG']['attributes']);
        $this->assertStringContainsString('/storage/uploads/', $rows['WOO-MUG']['images']);
        $this->assertSame('variable', $rows['WOO-TEE']['type']);
        $this->assertSame('', $rows['WOO-TEE']['regular_price'], 'a product with variants takes its price from them');
        $this->assertSame('variation', $rows['WOO-TEE-S']['type']);
        $this->assertSame('WOO-TEE', $rows['WOO-TEE-S']['parent_sku']);
        $this->assertSame('Size: S | Colour: White', $rows['WOO-TEE-S']['variation_options']);
        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", $rows['WOO-LAMP']['description']);
        $this->assertArrayNotHasKey('condition', $rows['WOO-MUG'], 'product_condition is off in the package default');
        $this->assertSame('Acme', $rows['WOO-MUG']['brand']);
        $this->assertSame(Columns::hasShippingClass(), in_array('shipping_class', $info['headers'], true));
        $this->assertSame($shirt->id, (int) $rows['SHIRT-1']['id']);
    }

    protected function writeTemp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pcsv');
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_export_then_import_round_trip_changes_nothing_and_updates_what_changed(): void
    {
        $this->catalogue();
        $this->import($this->fixture('woocommerce-export.csv'));
        $before = Product::query()->orderBy('id')->get()->map->only(['id', 'name', 'slug', 'regular_price', 'sale_price', 'stock_quantity', 'status', 'primary_category_id'])->all();
        $media = Media::query()->count();

        $handle = fopen($path = tempnam(sys_get_temp_dir(), 'pcsv'), 'w');
        (new Exporter)->write($handle, Product::query());
        fclose($handle);

        $dry = $this->import($path, [], true);
        $this->assertSame(['create' => 0, 'update' => 6, 'skip' => 0, 'error' => 0], $dry->state['preview']['counts']);
        $again = $this->import($path);
        $this->assertSame(0, $again->state['result']['counts']['error'], json_encode(iterator_to_array($again->results(null, ['error']), false)));
        $after = Product::query()->orderBy('id')->get()->map->only(['id', 'name', 'slug', 'regular_price', 'sale_price', 'stock_quantity', 'status', 'primary_category_id'])->all();
        $this->assertEquals($before, $after, 'importing the export again changes nothing');
        $this->assertSame($media, Media::query()->count(), 'own image URLs are reused, not downloaded');
        $this->assertSame(2, ProductVariation::query()->count());

        // edit the file: new price, new name, sale removed
        $csv = str_replace(['Enamel Camping Mug', ',12.00,8.50,'], ['Enamel Mug XL', ',14.00,-,'], (string) file_get_contents($path));
        file_put_contents($path, $csv);
        $this->import($path);
        $mug = Product::query()->where('sku', 'WOO-MUG')->first();
        $this->assertSame('Enamel Mug XL', $mug->name);
        $this->assertSame('14.00', $mug->regular_price);
        $this->assertNull($mug->sale_price);
        $this->assertNull($mug->sale_ends_at, 'sale dates go with the sale price');
    }

    // ---------------------------------------------------------------------------------------------- WooCommerce import

    public function test_woocommerce_export_dry_run_saves_nothing(): void
    {
        $counts = fn () => [Product::query()->count(), Category::query()->count(), Attribute::query()->count(), Media::query()->count(), ProductVariation::query()->count()];
        $before = $counts();
        $session = $this->import($this->fixture('woocommerce-export.csv'), [], true);

        $this->assertSame($before, $counts(), 'dry run writes nothing');
        Http::assertNothingSent();
        $this->assertSame('previewed', $session->phase());
        $this->assertSame(['create' => 5, 'update' => 0, 'skip' => 0, 'error' => 2], $session->state['preview']['counts']);
        $rows = $this->results($session);
        $this->assertSame('create', $rows['WOO-TEE-S']['action'], 'variant of a parent created earlier in the file');
        $this->assertContains('New category: Kitchen', $rows['WOO-MUG']['messages']);
        $this->assertContains('2 image(s) will be downloaded.', $rows['WOO-MUG']['messages']);
        $this->assertStringContainsString('isn’t supported', $rows['WOO-SET']['messages'][0]);
        $this->assertStringContainsString('isn’t a valid amount', $rows['WOO-BAD']['messages'][0]);
    }

    public function test_woocommerce_export_imports_products_variants_categories_attributes_and_images(): void
    {
        $session = $this->import($this->fixture('woocommerce-export.csv'));
        $this->assertSame('done', $session->phase());
        $this->assertSame(['create' => 5, 'update' => 0, 'skip' => 0, 'error' => 2], $session->state['result']['counts']);

        $mug = Product::query()->where('sku', 'WOO-MUG')->with(['categories', 'primaryCategory', 'images', 'attributeValues.attribute', 'productAttributes'])->firstOrFail();
        $this->assertSame('Enamel Camping Mug', $mug->name);
        $this->assertSame('enamel-camping-mug', $mug->slug);
        $this->assertSame('published', $mug->status);
        $this->assertTrue($mug->is_featured);
        $this->assertSame('12.00', $mug->regular_price);
        $this->assertSame('8.50', $mug->sale_price);
        $this->assertSame('8.50', $mug->price, 'sale running');
        $this->assertTrue($mug->manage_stock);
        $this->assertSame(12, $mug->stock_quantity);
        $this->assertSame(2, $mug->low_stock_threshold);
        $this->assertEquals(0.35, (float) $mug->weight);
        $this->assertSame('5012345678900', $mug->gtin);
        $this->assertSame('Acme', $mug->brand);
        $this->assertSame('Mug SEO title', $mug->meta_title);
        $this->assertSame('Mug SEO description', $mug->meta_description);
        $this->assertSame(['Gifts', 'Mugs'], $mug->categories->pluck('name')->sort()->values()->all());
        $this->assertSame('kitchen/mugs', $mug->primaryCategory->path);
        $this->assertSame(['Blue', 'Enamel', 'Green'], $mug->attributeValues->pluck('value')->sort()->values()->all());
        $this->assertCount(2, $mug->images);
        Storage::disk('public')->assertExists($mug->images[0]->path);
        $this->assertStringEndsWith('mug.png', $mug->images[0]->path, 'extension from the real image type');
        $this->assertSame(sha1('https://img.example.test/mug.jpg'), Media::query()->where('path', $mug->images[0]->path)->value('source_hash'));
        if (! class_exists('Pine\\Commerce\\Services\\Media\\ImageGenerator')) {
            Storage::disk('public')->assertExists(preg_replace('/\.png$/', '-150x150.png', $mug->images[0]->path)); // media-browser thumbnail
        }

        $tee = Product::query()->where('sku', 'WOO-TEE')->with('variations', 'productAttributes.attribute')->firstOrFail();
        $this->assertSame('variable', $tee->type);
        $this->assertCount(2, $tee->variations);
        $small = $tee->variations->firstWhere('sku', 'WOO-TEE-S');
        $this->assertSame(['colour' => 'white', 'size' => 's'], collect($small->options)->sortKeys()->all());
        $this->assertSame(5, $small->stock_quantity);
        $this->assertStringStartsWith('uploads/', $small->image);
        $medium = $tee->variations->firstWhere('sku', 'WOO-TEE-M');
        $this->assertSame('outofstock', $medium->stock_status);
        $this->assertSame('12.00', $medium->sale_price);
        $this->assertSame('12.00', $tee->price, 'parent price from the variants');
        $this->assertSame('instock', $tee->stock_status);
        $this->assertTrue($tee->productAttributes->every(fn ($pa) => $pa->is_variation), 'Size + Colour drive the variants');

        $lamp = Product::query()->where('sku', 'WOO-LAMP')->firstOrFail();
        $this->assertSame('draft', $lamp->status);
        $this->assertSame('reduced-rate', $lamp->tax_class);
        $this->assertSame('notify', $lamp->backorders);
        $this->assertSame('outofstock', $lamp->stock_status);
        $this->assertSame('=HYPERLINK("http://evil.test")', $lamp->description);
        $this->assertSame('kitchen/lighting', Category::query()->where('name', 'Lighting')->value('path'));
        $this->assertSame(1, Category::query()->where('name', 'Kitchen')->count(), 'the parent category is shared');
        $this->assertNull(Product::query()->where('sku', 'WOO-SET')->first());
        $this->assertNull(Product::query()->where('sku', 'WOO-BAD')->first());

        // same file again: everything updates, nothing duplicated, no image downloaded twice
        $media = Media::query()->count();
        $requests = count(Http::recorded());
        $again = $this->import($this->fixture('woocommerce-export.csv'));
        $this->assertSame(['create' => 0, 'update' => 5, 'skip' => 0, 'error' => 2], $again->state['result']['counts']);
        $this->assertSame(3, Product::query()->count());
        $this->assertSame(2, ProductVariation::query()->count());
        $this->assertSame($media, Media::query()->count());
        $this->assertSame($requests, count(Http::recorded()));

        // updates switched off → skipped
        $skip = $this->import($this->fixture('woocommerce-export.csv'), ['update_existing' => false]);
        $this->assertSame(['create' => 0, 'update' => 0, 'skip' => 5, 'error' => 2], $skip->state['result']['counts']);
    }

    public function test_own_format_with_local_images_pipes_specs_and_variant_errors(): void
    {
        Storage::disk('public')->put('uploads/2026/01/lamp.jpg', $this->png());
        $session = $this->import($this->fixture('products.csv'));
        $rows = $this->results($session);
        $this->assertSame('error', $rows['OWN-ORPHAN']['action']);
        $this->assertStringContainsString('not found', $rows['OWN-ORPHAN']['messages'][0]);

        $lamp = Product::query()->where('sku', 'OWN-LAMP')->with('categories', 'specs', 'images', 'primaryCategory')->firstOrFail();
        $this->assertSame('24.99', $lamp->regular_price);
        $this->assertSame("<p>Line one\nline two, with a comma</p>", $lamp->description);
        $this->assertSame(['Lighting', 'Offers'], $lamp->categories->pluck('name')->sort()->values()->all());
        $this->assertSame('home/lighting', $lamp->primaryCategory->path);
        $this->assertSame(['E27', '40W, dimmable'], $lamp->specs->pluck('value')->all());
        $this->assertSame('uploads/2026/01/lamp.jpg', $lamp->images->first()->path, 'this shop’s own image used as it is');
        $this->assertSame('2030-12-31', $lamp->sale_ends_at->setTimezone('Europe/London')->format('Y-m-d'));
        $this->assertSame('23:59', $lamp->sale_ends_at->setTimezone('Europe/London')->format('H:i'), 'a date-only end runs to the end of that day');
        Http::assertNothingSent();

        $scarf = Product::query()->where('sku', 'OWN-SCARF')->with('variations')->firstOrFail();
        $this->assertSame('draft', $scarf->status);
        $this->assertTrue($scarf->is_featured);
        $red = $scarf->variations->firstWhere('sku', 'OWN-SCARF-R');
        $blue = $scarf->variations->firstWhere('sku', 'OWN-SCARF-B');
        $this->assertTrue($red->is_active);
        $this->assertFalse($blue->is_active, 'status draft = inactive variant');
        $this->assertSame('outofstock', $blue->stock_status);
        $this->assertSame('15.00', $scarf->price);
    }

    public function test_empty_cells_keep_or_clear_and_missing_categories_can_be_refused(): void
    {
        [$shirt] = $this->catalogue();
        $file = $this->csvFrom([['sku', 'sale_price', 'short_description', 'categories'], ['SHIRT-1', '', '', 'Brand New > Sub']]);

        $keep = $this->import($file, ['create_missing' => false]);
        $shirt->refresh();
        $this->assertSame('30.00', $shirt->sale_price, 'empty cell = unchanged');
        $this->assertSame('<p>A soft linen shirt.</p>', $shirt->short_description);
        $this->assertSame(0, Category::query()->where('name', 'Brand New')->count());
        $this->assertStringContainsString('doesn’t exist – skipped', $this->results($keep)['SHIRT-1']['messages'][0]);
        $this->assertSame(['Shirts'], $shirt->categories()->pluck('name')->all(), 'no category column value resolved = categories untouched');

        $this->import($file, ['empty_cells' => 'overwrite']);
        $shirt->refresh();
        $this->assertNull($shirt->sale_price);
        $this->assertNull($shirt->short_description);
        $this->assertSame(['Sub'], $shirt->categories()->pluck('name')->all());
        $this->assertSame('brand-new/sub', $shirt->primaryCategory()->value('path'));
    }

    public function test_match_by_id_type_change_and_sku_of_a_variant(): void
    {
        [$shirt] = $this->catalogue();
        $this->import($this->fixture('woocommerce-export.csv'));
        $file = $this->csvFrom([
            ['id', 'name', 'type', 'sku'],
            [(string) $shirt->id, 'Renamed Shirt', '', ''],
            [(string) $shirt->id, 'Renamed Shirt', 'variable', ''],
            ['', 'Clash', 'simple', 'WOO-TEE-S'],
        ]);
        $session = $this->import($file, ['match_by' => 'id']);
        $rows = iterator_to_array($session->results(), false);
        $this->assertSame(['update', 'error', 'error'], array_column($rows, 'action'), json_encode($rows));
        $this->assertStringContainsString('already used by a variant', $rows[2]['messages'][0]);
        $this->assertSame('Renamed Shirt', $shirt->fresh()->name);
        $this->assertStringContainsString('changing the type', $rows[1]['messages'][0]);

        $bySku = $this->import($this->csvFrom([['sku', 'name'], ['WOO-TEE-S', 'Clash']]));
        $this->assertStringContainsString('belongs to a variant', iterator_to_array($bySku->results(), false)[0]['messages'][0]);
    }

    public function test_variant_tax_and_shipping_classes_import_export_and_round_trip(): void
    {
        $this->assertTrue(Columns::hasShippingClass(), 'core schema: products/product_variations.shipping_class_id');
        DB::table('shipping_classes')->insert(['name' => 'Small parcel', 'slug' => 'small-parcel', 'created_at' => now(), 'updated_at' => now()]);

        // a WooCommerce product export: its "Tax class" / "Shipping class" columns on the variation rows
        $headers = ['ID', 'Type', 'SKU', 'Name', 'Published', 'Tax class', 'Shipping class', 'Regular price', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Attribute 1 visible', 'Attribute 1 global'];
        $map = array_combine($headers, Columns::autoMap($headers));
        $this->assertSame('tax_class', $map['Tax class']);
        $this->assertSame('shipping_class', $map['Shipping class']);
        $this->assertSame('tax_class', Columns::autoMap(['Variant tax class'])[0]);
        $this->assertSame('shipping_class', Columns::autoMap(['variation_shipping_class'])[0]);

        $file = $this->csvFrom([$headers,
            ['301', 'variable', 'VC-BOX', 'Gift box', '1', 'reduced-rate', 'Small parcel', '', '', 'Size', 'S, L, XL', '1', '1'],
            ['302', 'variation', 'VC-BOX-S', 'Gift box - S', '1', 'parent', '', '10.00', 'VC-BOX', 'Size', 'S', '', '1'],
            ['303', 'variation', 'VC-BOX-L', 'Gift box - L', '1', 'Zero rate', 'Pallet', '20.00', 'VC-BOX', 'Size', 'L', '', '1'],
            ['304', 'variation', 'VC-BOX-XL', 'Gift box - XL', '1', 'standard', 'small-parcel', '30.00', 'VC-BOX', 'Size', 'XL', '', '1'],
        ]);
        $dry = $this->import($file, [], true);
        $this->assertSame(['create' => 4, 'update' => 0, 'skip' => 0, 'error' => 0], $dry->state['preview']['counts']);
        $this->assertSame(1, DB::table('shipping_classes')->count(), 'dry run creates no class');

        $session = $this->import($file);
        $this->assertSame(0, $session->state['result']['counts']['error'], json_encode(iterator_to_array($session->results(null, ['error']), false)));
        $small = (int) DB::table('shipping_classes')->where('slug', 'small-parcel')->value('id');
        $pallet = (int) DB::table('shipping_classes')->where('slug', 'pallet')->value('id');
        $this->assertGreaterThan(0, $pallet, 'missing class created (create missing on)');

        $box = Product::query()->where('sku', 'VC-BOX')->with('variations')->firstOrFail();
        $this->assertSame('reduced-rate', $box->tax_class);
        $this->assertSame($small, (int) $box->shipping_class_id);
        $variant = fn (string $sku) => $box->variations->firstWhere('sku', $sku);
        $this->assertNull($variant('VC-BOX-S')->tax_class, '"parent" = same as the product');
        $this->assertNull($variant('VC-BOX-S')->shipping_class_id);
        $this->assertSame('zero-rate', $variant('VC-BOX-L')->tax_class);
        $this->assertSame($pallet, (int) $variant('VC-BOX-L')->shipping_class_id);
        $this->assertSame('standard', $variant('VC-BOX-XL')->tax_class, 'standard kept: overrides a reduced-rate product');
        $this->assertSame($small, (int) $variant('VC-BOX-XL')->shipping_class_id);
        $this->assertSame('standard', \Pine\Commerce\Services\Tax\PriceDisplay::taxClass($box, $variant('VC-BOX-XL')));
        $this->assertSame('reduced-rate', \Pine\Commerce\Services\Tax\PriceDisplay::taxClass($box, $variant('VC-BOX-S')));

        // export: the variant rows carry their own classes ("parent" / empty = same as the product)
        $exported = [];
        foreach ((new Exporter)->rows(Product::query()) as $row) {
            $row = array_combine(Columns::exportKeys(), $row);
            $exported[$row['sku']] = [$row['tax_class'], $row['shipping_class']];
        }
        $this->assertSame(['reduced-rate', 'small-parcel'], $exported['VC-BOX']);
        $this->assertSame(['parent', ''], $exported['VC-BOX-S']);
        $this->assertSame(['zero-rate', 'pallet'], $exported['VC-BOX-L']);
        $this->assertSame(['standard', 'small-parcel'], $exported['VC-BOX-XL']);

        // round trip (empty cells cleared): nothing changes
        $handle = fopen($path = tempnam(sys_get_temp_dir(), 'pcsv'), 'w');
        (new Exporter)->write($handle, Product::query());
        fclose($handle);
        $classes = fn () => ProductVariation::query()->orderBy('sku')->get()->map(fn ($v) => [$v->sku, $v->tax_class, $v->shipping_class_id ? (int) $v->shipping_class_id : null])->all();
        $before = $classes();
        $again = $this->import($path, ['empty_cells' => 'overwrite']);
        $this->assertSame(0, $again->state['result']['counts']['error']);
        $this->assertSame($before, $classes());

        // own format: change and reset a variant's classes
        $this->import($this->csvFrom([['sku', 'type', 'parent_sku', 'tax_class', 'shipping_class'],
            ['VC-BOX-L', 'variation', 'VC-BOX', 'Same as product', 'parent'],
            ['VC-BOX-S', 'variation', 'VC-BOX', 'reduced-rate', 'Pallet'],
        ]));
        $this->assertNull(ProductVariation::query()->where('sku', 'VC-BOX-L')->value('tax_class'));
        $this->assertNull(ProductVariation::query()->where('sku', 'VC-BOX-L')->value('shipping_class_id'));
        $this->assertSame('reduced-rate', ProductVariation::query()->where('sku', 'VC-BOX-S')->value('tax_class'));
        $this->assertSame($pallet, (int) ProductVariation::query()->where('sku', 'VC-BOX-S')->value('shipping_class_id'));

        // an unknown class without "create missing" rejects the variant row
        $refused = $this->results($this->import($this->csvFrom([['sku', 'type', 'parent_sku', 'shipping_class'], ['VC-BOX-S', 'variation', 'VC-BOX', 'Crate']]), ['create_missing' => false]));
        $this->assertSame('error', $refused['VC-BOX-S']['action']);
        $this->assertSame($pallet, (int) ProductVariation::query()->where('sku', 'VC-BOX-S')->value('shipping_class_id'));
    }

    public function test_shipping_class_column_follows_the_schema(): void
    {
        $had = \Illuminate\Support\Facades\Schema::hasColumn('products', 'shipping_class_id');
        $this->assertSame($had, in_array('shipping_class', Columns::exportKeys(), true));
        // the core shipping-classes schema (products.shipping_class_id → shipping_classes); created here only when absent
        if (! \Illuminate\Support\Facades\Schema::hasTable('shipping_classes')) {
            \Illuminate\Support\Facades\Schema::create('shipping_classes', function ($table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 100)->unique();
                $table->string('description', 500)->nullable();
                $table->timestamps();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('products', 'shipping_class_id')) {
            \Illuminate\Support\Facades\Schema::table('products', fn ($table) => $table->unsignedBigInteger('shipping_class_id')->nullable());
        }
        Columns::flush();
        $this->assertContains('shipping_class', Columns::exportKeys());

        $this->import($this->fixture('woocommerce-export.csv'), [], true);
        $this->assertSame(0, DB::table('shipping_classes')->count(), 'dry run');
        $file = $this->csvFrom([['sku', 'name', 'shipping_class'], ['SC-1', 'Heavy thing', 'Large Parcel'], ['SC-2', 'Other', 'large-parcel']]);
        $this->import($file);
        $this->assertSame(1, DB::table('shipping_classes')->count());
        $classId = DB::table('shipping_classes')->where('slug', 'large-parcel')->value('id');
        $this->assertSame([(int) $classId, (int) $classId], Product::query()->whereIn('sku', ['SC-1', 'SC-2'])->pluck('shipping_class_id')->map(fn ($id) => (int) $id)->all());

        $handle = fopen('php://memory', 'w+');
        (new Exporter)->write($handle, Product::query()->where('sku', 'SC-1'));
        rewind($handle);
        $this->assertStringContainsString('large-parcel', stream_get_contents($handle));

        $refused = $this->import($this->csvFrom([['sku', 'name', 'shipping_class'], ['SC-3', 'Third', 'Pallet']]), ['create_missing' => false]);
        $this->assertStringContainsString('Shipping class “Pallet” doesn’t exist', iterator_to_array($refused->results(), false)[0]['messages'][0]);
    }

    // ---------------------------------------------------------------------------------------------- batches

    public function test_batches_resume_and_recover_after_a_crash(): void
    {
        $session = ImportSession::create($this->fixture('woocommerce-export.csv'), 'woo.csv', null);
        $session->begin('importing');
        $status = (new ImportRunner($session))->step(1);
        $this->assertTrue($status['running']);
        $this->assertSame(1, $status['processed']);

        // a step that wrote row 2 and died before saving its progress
        $session = ImportSession::find($session->token);
        $row = null;
        foreach (CsvFile::rows($session->absolutePath('source.csv'), ',', $session->state['progress']['offset'], $session->state['progress']['line']) as $row) {
            break;
        }
        [$line, $cells, $offset] = $row;
        $result = (new RowImporter($session, false, new \Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImageImporter(true)))->handle($line, $cells);
        $session->appendResults([$result + ['offset' => $offset]]);
        $this->assertSame('create', $result['action']);

        $status = (new ImportRunner(ImportSession::find($session->token)))->run(null, 2);
        $this->assertFalse($status['running']);
        $this->assertSame(7, $status['processed']);
        $this->assertSame(5, $status['counts']['create']);
        $this->assertSame(1, Product::query()->where('sku', 'WOO-TEE')->count(), 'row 2 not imported twice');
        $this->assertSame(7, iterator_count(ImportSession::find($session->token)->results()));
    }

    public function test_batch_size_is_capped(): void
    {
        $rows = [['sku', 'name', 'regular_price']];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = ['CAP-'.$i, 'Cap '.$i, '1.00'];
        }
        $session = ImportSession::create($this->csvFrom($rows), 'caps.csv', null);
        $session->begin('preview');
        $status = (new ImportRunner($session))->step(10000, 600);
        $this->assertSame(ImportRunner::MAX_CHUNK, $status['processed']);
        $this->assertTrue($status['running']);
    }

    public function test_image_downloads_refuse_private_hosts(): void
    {
        config(['commerce.product_csv.url_guard' => true]);
        $session = $this->import($this->csvFrom([['sku', 'name', 'images'], ['IMG-1', 'Guarded', 'http://127.0.0.1/secret.png, http://10.0.0.5/x.jpg']]));
        $row = iterator_to_array($session->results(), false)[0];
        $this->assertSame('create', $row['action']);
        $this->assertStringContainsString('private address not allowed', implode(' ', $row['messages']));
        $this->assertSame(0, Product::query()->where('sku', 'IMG-1')->first()->images()->count());
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------------------------------- admin pages

    public function test_admin_upload_map_dry_run_import_and_report(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.products.csv'))->assertOk()->assertSee('Import / Export')->assertSee('Download CSV');
        $this->get(route('admin.products.index'))->assertOk()->assertSee(route('admin.products.csv'), false);

        $upload = new UploadedFile($this->fixture('woocommerce-export.csv'), 'woo-export.csv', 'text/csv', null, true);
        $response = $this->post(route('admin.products.csv.upload'), ['file' => $upload]);
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $response->assertRedirect(route('admin.products.csv.mapping', $token));
        $this->get(route('admin.products.csv.mapping', $token))->assertOk()->assertSee('WooCommerce export recognised')->assertSee('Attribute 1 name');

        $session = ImportSession::find($token);
        $form = ['mapping' => array_map(fn ($t) => $t ?? '', $session->state['mapping']), 'update_existing' => '1', 'match_by' => 'sku',
            'create_missing' => '1', 'download_images' => '1', 'empty_cells' => 'skip'];
        // the same field twice is refused
        $this->post(route('admin.products.csv.map', $token), array_replace_recursive($form, ['mapping' => [0 => 'sku']]))->assertSessionHasErrors('mapping.2');
        $this->post(route('admin.products.csv.map', $token), $form)->assertRedirect(route('admin.products.csv.run', $token));
        $this->get(route('admin.products.csv.run', $token))->assertOk()->assertSee('Checking the file');

        do {
            $json = $this->postJson(route('admin.products.csv.step', $token))->assertOk()->json();
        } while ($json['running']);
        $this->assertSame('previewed', $json['phase']);
        $this->assertSame(0, Product::query()->count());
        $this->get(route('admin.products.csv.run', $token))->assertOk()->assertSee('Check the dry run')->assertSee('Import 5 rows');
        $this->get(route('admin.products.csv.run', [$token, 'show' => 'error']))->assertOk()->assertSee('WOO-BAD')->assertDontSee('WOO-MUG');
        $this->get(route('admin.products.csv.report', [$token, 'pass' => 'preview']))->assertOk()->assertDownload('dry-run-woo-export.csv');

        $this->post(route('admin.products.csv.start', $token))->assertRedirect(route('admin.products.csv.run', $token));
        do {
            $json = $this->postJson(route('admin.products.csv.step', $token))->assertOk()->json();
        } while ($json['running']);
        $this->assertSame('done', $json['phase']);
        $this->assertSame(3, Product::query()->count());
        $this->get(route('admin.products.csv.run', $token))->assertOk()->assertSee('Import finished')->assertSee(route('admin.products.edit', Product::query()->where('sku', 'WOO-MUG')->value('id')), false);
        $report = $this->get(route('admin.products.csv.report', [$token, 'pass' => 'import']))->assertOk()->streamedContent();
        $this->assertStringContainsString('WOO-TEE-M', $report);
        $this->get(route('admin.products.csv.mapping', $token))->assertRedirect(route('admin.products.csv.run', $token));
        $this->get(route('admin.products.csv'))->assertOk()->assertSee('woo-export.csv');

        // export download (all + filtered)
        $csv = $this->get(route('admin.products.csv.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('WOO-TEE-S', $csv);
        $filtered = $this->get(route('admin.products.csv.export', ['scope' => 'filtered', 'q' => 'mug', 'variations' => '0']))->assertOk()->streamedContent();
        $this->assertStringContainsString('WOO-MUG', $filtered);
        $this->assertStringNotContainsString('WOO-TEE', $filtered);

        $this->delete(route('admin.products.csv.destroy', $token))->assertRedirect(route('admin.products.csv'));
        $this->assertNull(ImportSession::find($token));
    }

    public function test_admin_upload_errors_other_staff_and_feature_switch(): void
    {
        $admin = $this->neutralAdmin();
        $this->actingAs($admin)->post(route('admin.products.csv.upload'), ['file' => UploadedFile::fake()->createWithContent('empty.csv', "only-one-header\n")])
            ->assertSessionHasErrors('file');

        $session = ImportSession::create($this->fixture('products.csv'), 'products.csv', $admin->id);
        $manager = \Pine\Commerce\Commerce::userModel()::forceCreate(['name' => 'M', 'first_name' => 'M', 'last_name' => 'M', 'email' => 'm@example.test',
            'password' => bcrypt('Password12345'), 'role' => 'manager', 'is_active' => true]);
        $this->actingAs($manager)->get(route('admin.products.csv.mapping', $session->token))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.products.csv.mapping', 'not-a-token'))->assertNotFound();

        config(['commerce.features.product_csv' => false]);
        $this->actingAs($admin)->get(route('admin.products.csv'))->assertNotFound();
        $this->get(route('admin.products.csv.export'))->assertNotFound();
        $this->get(route('admin.products.index'))->assertOk()->assertDontSee('Import / Export');
        $this->artisan('commerce:products:export', ['--output' => sys_get_temp_dir().'/x.csv'])->assertFailed();
    }

    // ---------------------------------------------------------------------------------------------- commands

    public function test_cli_export_and_import(): void
    {
        $this->catalogue();
        $out = tempnam(sys_get_temp_dir(), 'pcsv');
        $this->artisan('commerce:products:export', ['--output' => $out])->expectsOutputToContain('1 rows written')->assertSuccessful();
        $this->assertStringContainsString('SHIRT-1', (string) file_get_contents($out));

        $this->artisan('commerce:products:import', ['file' => $this->fixture('woocommerce-export.csv'), '--dry-run' => true])
            ->expectsOutputToContain('Dry run – nothing was saved.')->assertSuccessful();
        $this->assertSame(1, Product::query()->count());

        $report = tempnam(sys_get_temp_dir(), 'pcsv');
        $this->artisan('commerce:products:import', ['file' => $this->fixture('woocommerce-export.csv'), '--create-missing' => true, '--download-images' => true, '--report' => $report])
            ->expectsOutputToContain('WooCommerce export')->assertSuccessful();
        $this->assertSame(4, Product::query()->count());
        $this->assertStringContainsString('WOO-BAD', (string) file_get_contents($report));

        $this->artisan('commerce:products:import', ['file' => $this->fixture('woocommerce-export.csv')])->assertSuccessful();
        $this->assertSame(4, Product::query()->count(), 'without --update-existing rows are skipped');
        $this->artisan('commerce:products:import', ['file' => '/no/such/file.csv'])->assertFailed();
    }
}
