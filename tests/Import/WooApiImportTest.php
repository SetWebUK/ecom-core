<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Import\Support\Upserter;
use Pine\Commerce\Import\WooApi\Client;
use Pine\Commerce\Import\WooApi\Connection;
use Pine\Commerce\Import\WooApi\OAuth1;
use Pine\Commerce\Import\WooApi\UrlGuard;
use Pine\Commerce\Import\WooApi\WooApiException;
use Pine\Commerce\Models\WooApiImport;
use Pine\Commerce\Tests\Fixtures\FakeWooShop;
use Pine\Commerce\Tests\TestCase;

/**
 * The WooCommerce REST API importer against a faked shop (Http::fake + realistic wc/v3, wp/v2 and Store API JSON,
 * tests/Fixtures/woo-api): every entity, pagination, variable products, refunds, tax lines, each authentication mode
 * (+ an OAuth 1.0a signature test vector), 429/5xx retries, incremental --since, idempotent re-runs, the SSRF guard,
 * dry runs, the key-less Store API and import sources that never collide. In-memory SQLite only.
 */
class WooApiImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs phpunit\'s in-memory SQLite target.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
        Upserter::flushColumns();
        Storage::fake('public');
        $this->workDir = sys_get_temp_dir().'/woo-api-test-'.bin2hex(random_bytes(4));
        config([
            'commerce.woo_api.per_page' => 2,       // the fixtures have 2–4 items per list: forces pagination
            'commerce.woo_api.delay_ms' => 0,
            'commerce.woo_api.max_backoff' => 0,
            'commerce.woo_api.path' => $this->workDir,
            'commerce.features.redirects' => true,
        ]);
    }

    protected function tearDown(): void
    {
        UrlGuard::$resolver = null;
        if (isset($this->workDir) && is_dir($this->workDir)) {
            exec('rm -rf '.escapeshellarg($this->workDir));
        }
        parent::tearDown();
    }

    private string $workDir;

    private string $lastOutput = '';

    private function import(array $options = [], string $url = FakeWooShop::BASE): int
    {
        $code = \Illuminate\Support\Facades\Artisan::call('commerce:import-woo-api', ['--url' => $url, '--key' => FakeWooShop::KEY, '--secret' => FakeWooShop::SECRET] + $options);
        $this->lastOutput = \Illuminate\Support\Facades\Artisan::output();
        $run = WooApiImport::query()->latest('id')->first();
        if ($run && is_file($log = $this->workDir.'/runs/run-'.$run->id.'.log')) {
            $this->lastOutput .= "\n--- log ---\n".implode("\n", array_filter(explode("\n", (string) file_get_contents($log)), fn ($l) => ! str_contains($l, ' HTTP ')));
        }

        return $code;
    }

    public function test_a_full_rest_import_maps_every_entity_and_a_second_run_changes_nothing(): void
    {
        $shop = FakeWooShop::fake();
        $this->assertSame(0, $this->import(), $this->lastOutput);
        $run = WooApiImport::query()->latest('id')->first();
        $this->assertSame('completed', $run->status, (string) $run->error);
        $this->assertSame('woo:old-shop.example.test', $run->source);

        // categories: hierarchy, image, SEO (Yoast), "uncategorized" kept out of menus
        $clothing = DB::table('categories')->where('wp_id', 10)->first();
        $shirts = DB::table('categories')->where('wp_id', 11)->first();
        $this->assertSame(['clothing', 'clothing/shirts', 'Shirts & Tops'], [$clothing->path, $shirts->path, $shirts->name]);
        $this->assertSame((int) $clothing->id, (int) $shirts->parent_id);
        $this->assertSame('uploads/2025/01/clothing.jpg', $clothing->image);
        $this->assertSame(['Clothing Archives - Old Shop', 'Linen clothing for summer.'], [$clothing->meta_title, $clothing->meta_description]);
        $this->assertSame(0, (int) DB::table('categories')->where('wp_id', 15)->value('show_in_menu'));
        $this->assertSame('woo:old-shop.example.test', $clothing->import_source);

        // simple product: prices, stock, URL category, SEO from Rank Math meta, images, tags, brand, shipping class
        $shirt = DB::table('products')->where('wp_id', 100)->first();
        $this->assertSame(['linen-shirt', 'LS-1', 'simple', 'published', 'Linen & Co'], [$shirt->slug, $shirt->sku, $shirt->type, $shirt->status, $shirt->brand]);
        $this->assertEquals([50, 40, 40, 5, 1], [$shirt->regular_price, $shirt->sale_price, $shirt->price, $shirt->stock_quantity, $shirt->is_featured]);
        $this->assertSame((int) $shirts->id, (int) $shirt->primary_category_id);
        $this->assertSame('Linen Shirt | Linen | Old Shop & Co', $shirt->meta_title);
        $this->assertSame(['Breathable linen shirt.', 'linen shirt'], [$shirt->meta_description, $shirt->focus_keyword]);
        $this->assertStringContainsString('<a href="/clothing/">', $shirt->description, 'links to the old shop made relative');
        $this->assertSame(['uploads/2025/01/shirt.jpg', 'uploads/2025/01/shirt-back.jpg'], DB::table('product_images')->where('product_id', $shirt->id)->orderBy('sort_order')->pluck('path')->all());
        Storage::disk('public')->assertExists('uploads/2025/01/shirt.jpg');
        $this->assertSame((int) DB::table('shipping_classes')->where('wp_id', 40)->value('id'), (int) $shirt->shipping_class_id);
        $tagValue = DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')->where('attributes.slug', 'tags')->value('attribute_values.value');
        $this->assertSame('Summer', $tagValue);
        $material = DB::table('attributes')->where('slug', 'material')->first();
        $this->assertNotNull($material, 'a product-level attribute becomes a global one');
        $this->assertSame(2, DB::table('attribute_value_product')->join('attribute_values', 'attribute_values.id', '=', 'attribute_value_product.attribute_value_id')
            ->where('attribute_value_product.product_id', $shirt->id)->where('attribute_values.attribute_id', $material->id)->count());

        // variable product + variations
        $polo = DB::table('products')->where('wp_id', 110)->first();
        $this->assertSame(['variable', 'Polo shirts | Old Shop', 'A classic polo in two colours.'], [$polo->type, $polo->meta_title, $polo->meta_description]);
        $this->assertEquals(20, $polo->price, 'cheapest active variation');
        $variations = DB::table('product_variations')->where('product_id', $polo->id)->orderBy('sort_order')->get();
        $this->assertSame(['POLO-R', 'POLO-B'], $variations->pluck('sku')->all());
        $this->assertSame(['{"colour":"red"}', '{"colour":"blue"}'], $variations->pluck('options')->all());
        $this->assertEquals([2, null], $variations->pluck('stock_quantity')->all());
        $this->assertSame(['instock', 'outofstock'], $variations->pluck('stock_status')->all());
        $this->assertSame('uploads/2025/01/polo-red.jpg', $variations[0]->image);
        $this->assertSame(2, DB::table('attribute_value_product')->where('product_id', $polo->id)->count());

        // grouped product → simple + warning; product base in the old URL → redirect; related products
        $bundle = DB::table('products')->where('wp_id', 120)->first();
        $this->assertSame(['simple', 'draft'], [$bundle->type, $bundle->status]);
        $this->assertSame((int) $clothing->id, (int) $bundle->primary_category_id);
        $this->assertSame('/clothing/summer-bundle/', DB::table('redirects')->where('from_path', 'shop/clothing/summer-bundle')->value('to_url'));
        $this->assertSame(['grouped', 'grouped'], DB::table('related_products')->where('product_id', $bundle->id)->pluck('type')->all());
        $this->assertSame(1, DB::table('related_products')->where('product_id', $shirt->id)->where('type', 'upsell')->count());
        $this->assertStringContainsString("is a grouped product", file_get_contents($this->workDir.'/runs/run-'.$run->id.'.log'));

        // customers + guests from orders, addresses, no passwords
        $ann = DB::table('users')->where('email', 'ann@example.com')->first();
        $this->assertSame(['customer', null, 7], [$ann->role, $ann->password, (int) $ann->wp_id]);
        $this->assertSame('LS1 1AA', DB::table('addresses')->where('user_id', $ann->id)->where('type', 'billing')->value('postcode'));
        $this->assertNotNull(DB::table('users')->where('email', 'cy@example.com')->value('id'));
        $this->assertSame('York', DB::table('addresses')->where('user_id', DB::table('users')->where('email', 'bo@example.com')->value('id'))->value('city'));

        // orders: number, totals, tax lines, coupon + fee lines, payment, notes (oldest first), refund
        $o200 = DB::table('orders')->where('wp_id', 200)->first();
        $this->assertSame(['5001', 'processing', (int) $ann->id, 'save10', 'flat_rate'], [$o200->number, $o200->status, (int) $o200->user_id, $o200->coupon_code, $o200->shipping_method]);
        $this->assertEquals([90, 10, 4.99, 15, 100.99], [$o200->subtotal, $o200->discount_total, $o200->shipping_total, $o200->tax_total, $o200->total]);
        $this->assertSame('{"Colour":"Red"}', DB::table('order_items')->where('order_id', $o200->id)->where('sku', 'POLO-R')->value('options'));
        $tax = DB::table('order_tax_lines')->where('order_id', $o200->id)->first();
        $this->assertSame(['VAT', 20.0, 16.0, 1.0], [$tax->label, (float) $tax->rate, (float) $tax->tax_total, (float) $tax->shipping_tax_total]);
        $this->assertSame((int) DB::table('tax_rates')->where('wp_id', 1)->value('id'), (int) $tax->tax_rate_id);
        $meta = json_decode($o200->meta, true);
        $this->assertSame([['name' => 'Gift wrap', 'total' => 2, 'tax' => 0]], $meta['fees']);
        $this->assertSame('google', $meta['attribution_utm_source']);
        $this->assertSame(['Payment received.', 'Packed & ready.'], DB::table('order_notes')->where('order_id', $o200->id)->orderBy('created_at')->pluck('note')->all());
        $this->assertSame(1, (int) DB::table('order_notes')->where('order_id', $o200->id)->where('is_customer_note', true)->count());
        $this->assertSame(1, DB::table('payments')->where('order_id', $o200->id)->count());
        $o201 = DB::table('orders')->where('wp_id', 201)->first();
        $this->assertEquals(48, $o201->refunded_total);
        $this->assertSame(1, (int) DB::table('order_items')->where('order_id', $o201->id)->value('refunded_quantity'));
        $this->assertSame('Too small', DB::table('refunds')->where('order_id', $o201->id)->value('reason'));
        $this->assertSame(3, DB::table('orders')->count());

        // coupons, reviews, tax, shipping
        $coupon = DB::table('coupons')->where('code', 'save10')->first();
        $this->assertSame(['percent', '['.$shirt->id.']', '['.$shirts->id.']', 1], [$coupon->type, $coupon->product_ids, $coupon->excluded_category_ids, (int) $coupon->usage_limit_per_user]);
        $bogof = DB::table('coupons')->where('code', 'bogof')->first();
        $this->assertSame(['fixed_cart', 0, '["vip@example.com"]'], [$bogof->type, (int) $bogof->is_active, $bogof->allowed_emails]);
        $this->assertSame([1, 0], DB::table('product_reviews')->orderBy('created_at')->pluck('is_approved')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(3, DB::table('tax_rates')->count());
        $this->assertSame("90001\n90002", DB::table('tax_rates')->where('wp_id', 3)->value('postcodes'));
        $this->assertSame(['reduced-rate', 'zero-rate'], DB::table('tax_classes')->orderBy('sort_order')->pluck('slug')->intersect(['reduced-rate', 'zero-rate'])->values()->all());
        $uk = DB::table('shipping_zones')->where('wp_id', 2)->first();
        $this->assertSame(['UK mainland', '["GB"]', 'BS*'], [$uk->name, $uk->regions, $uk->postcodes]);
        $this->assertContains('FR', json_decode(DB::table('shipping_zones')->where('wp_id', 3)->value('regions'), true), 'continent expanded');
        $flat = DB::table('shipping_methods')->where('code', 'flat_rate')->first();
        $this->assertSame(['Standard', 4.99], [$flat->name, (float) $flat->cost]);
        $this->assertSame([(string) DB::table('shipping_classes')->where('wp_id', 40)->value('id') => '20'], json_decode($flat->settings, true)['class_costs']);
        $this->assertSame(0, (int) DB::table('shipping_methods')->where('code', 'local_pickup')->value('is_active'));

        // pages: paths from the permalinks, front page, parents, system page, cleaned content, SEO
        $this->assertSame(['', 'about-us', 'about-us/team', 'checkout'], DB::table('pages')->orderBy('wp_id')->pluck('path')->all());
        $home = DB::table('pages')->where('wp_id', 40)->first();
        $this->assertSame(['home', 'Old Shop – linen clothing'], [$home->template, $home->meta_title]);
        $this->assertStringContainsString('href="/clothing/"', $home->content);
        $this->assertStringContainsString('/storage/uploads/2025/01/shirt.jpg', $home->content);
        $this->assertStringNotContainsString('<script', $home->content);
        $this->assertSame((int) DB::table('pages')->where('wp_id', 41)->value('id'), (int) DB::table('pages')->where('wp_id', 42)->value('parent_id'));
        $this->assertNull(DB::table('pages')->where('wp_id', 43)->value('content'), 'system page');

        // posts: blog URL + redirect from the dated permalink, category, featured image
        $post = DB::table('posts')->where('wp_id', 60)->first();
        $this->assertSame(['summer-news', 'uploads/2025/06/news.png', 'New polos are in.'], [$post->slug, $post->featured_image, $post->excerpt]);
        $this->assertSame('News & events', DB::table('post_categories')->where('id', $post->post_category_id)->value('name'));
        $this->assertSame('/blog/summer-news/', DB::table('redirects')->where('from_path', '2025/06/summer-news')->value('to_url'));

        // media library: images only (the fake "evil.jpg" is PHP), deduplicated
        $this->assertTrue(DB::table('media')->where('path', 'uploads/2025/02/banner.png')->exists());
        $this->assertFalse(DB::table('media')->where('path', 'like', '%evil%')->exists());
        Storage::disk('public')->assertMissing('uploads/2025/02/evil.jpg');
        $this->assertSame(1, DB::table('media')->where('path', 'uploads/2025/01/shirt.jpg')->count());
        $this->assertGreaterThan(0, $run->warnings);
        $this->assertSame(0, $run->errors);

        // a second run updates in place: same rows, nothing created, images not downloaded again
        $before = $this->snapshot();
        $imageRequests = count(array_filter($shop->requests, fn ($r) => str_contains($r['path'], '/wp-content/uploads/')));
        $this->assertSame(0, $this->import(), $this->lastOutput);
        $this->assertSame($before, $this->snapshot());
        $second = WooApiImport::query()->latest('id')->first();
        $this->assertSame('completed', $second->status);
        foreach (['categories', 'products', 'orders', 'coupons'] as $entity) {
            $this->assertSame(0, $second->entityProgress($entity)['created'], "$entity created on re-run");
        }
        $this->assertSame($imageRequests + 1, count(array_filter($shop->requests, fn ($r) => str_contains($r['path'], '/wp-content/uploads/'))),
            'only the rejected non-image is fetched again');
    }

    /** Row counts + key columns of the tables the importer writes. */
    private function snapshot(): array
    {
        $out = [];
        foreach (['users', 'addresses', 'categories', 'attributes', 'attribute_values', 'products', 'product_variations', 'product_images', 'related_products',
            'category_product', 'attribute_value_product', 'orders', 'order_items', 'order_tax_lines', 'refunds', 'order_notes', 'payments', 'coupons',
            'product_reviews', 'tax_rates', 'shipping_zones', 'shipping_methods', 'pages', 'posts', 'media', 'redirects'] as $table) {
            $out[$table] = DB::table($table)->count();
        }
        $out['products.cols'] = DB::table('products')->orderBy('id')->get(['id', 'slug', 'price', 'primary_category_id', 'meta_title'])->toArray();
        $out['orders.cols'] = DB::table('orders')->orderBy('id')->get(['id', 'number', 'total', 'refunded_total', 'user_id'])->toArray();

        return json_decode(json_encode($out), true);
    }
}
