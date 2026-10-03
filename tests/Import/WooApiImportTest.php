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

    public function test_pages_follow_x_wp_totalpages_at_per_page_100_by_default(): void
    {
        $shop = FakeWooShop::fake();
        $this->assertSame(0, $this->import(['--only' => 'categories,products', '--no-images' => true]), $this->lastOutput);
        $calls = $shop->calls('wc/v3/products');
        $this->assertSame([['1', '2'], ['2', '2']], array_map(fn ($c) => [$c['query']['page'], $c['query']['per_page']], $calls), 'two pages of two, then stop');
        $this->assertSame(['any', 'id', 'asc'], [$calls[0]['query']['status'], $calls[0]['query']['orderby'], $calls[0]['query']['order']]);
        $this->assertSame(3, DB::table('products')->count());

        config(['commerce.woo_api.per_page' => 100]);
        $shop->requests = [];
        $this->assertSame(0, $this->import(['--only' => 'products', '--no-images' => true]), $this->lastOutput);
        $this->assertSame([['1', '100']], array_map(fn ($c) => [$c['query']['page'], $c['query']['per_page']], $shop->calls('wc/v3/products')));
    }

    public function test_every_authentication_mode_reaches_woocommerce(): void
    {
        $connection = fn (array $c) => Connection::make($c + ['url' => FakeWooShop::BASE, 'key' => FakeWooShop::KEY, 'secret' => FakeWooShop::SECRET]);

        // https: HTTP Basic, nothing secret in the URL
        $shop = FakeWooShop::fake('basic');
        $this->assertSame('basic', $connection([])->wcAuth());
        (new Client($connection([])))->get('system_status');
        $call = $shop->calls('wc/v3/system_status')[0];
        $this->assertArrayNotHasKey('consumer_key', $call['query']);

        // hosts that strip the Authorization header: query string
        $shop = FakeWooShop::fake('query');
        (new Client($connection(['auth' => 'query'])))->get('system_status');
        $this->assertSame(FakeWooShop::KEY, $shop->calls('wc/v3/system_status')[0]['query']['consumer_key']);

        // plain http: OAuth 1.0a one-legged signatures (the fake verifies them like WooCommerce)
        $shop = FakeWooShop::fake('oauth');
        $http = $connection(['url' => 'http://old-shop.example.test']);
        $this->assertSame('oauth', $http->wcAuth());
        $this->assertCount(2, (new Client($http))->get('products', ['per_page' => 2, 'status' => 'any'])->items());
        $q = $shop->calls('wc/v3/products')[0]['query'];
        $this->assertSame(['HMAC-SHA256', FakeWooShop::KEY], [$q['oauth_signature_method'], $q['oauth_consumer_key']]);
        $this->assertArrayNotHasKey('consumer_secret', $q);

        // Basic / query auth never travel over plain http
        try {
            (new Client($connection(['url' => 'http://old-shop.example.test', 'auth' => 'basic'])))->get('system_status');
            $this->fail('Basic auth over http must be refused');
        } catch (WooApiException $e) {
            $this->assertSame('blocked', $e->reason);
        }

        // a wrong secret: 401 with a hint, and the secret is never in the message
        FakeWooShop::fake('basic');
        try {
            (new Client(Connection::make(['url' => FakeWooShop::BASE, 'key' => FakeWooShop::KEY, 'secret' => 'cs_wrong_secret_value'])))->get('system_status');
            $this->fail('expected a 401');
        } catch (WooApiException $e) {
            $this->assertSame(['auth', 401], [$e->reason, $e->status]);
            $this->assertStringContainsString('query string', $e->getMessage());
            $this->assertStringNotContainsString('cs_wrong_secret_value', $e->getMessage());
        }
    }

    public function test_the_oauth1_signature_matches_a_known_test_vector(): void
    {
        $query = ['per_page' => '100', 'page' => '1', 'search' => 'a b+c'];
        $url = 'http://old-shop.example.test/wp-json/wc/v3/products';
        $signed = OAuth1::sign('GET', $url, $query, 'ck_test', 'cs_test', 1700000000, 'abcdef0123456789');

        // computed independently (Python hmac/base64) from WooCommerce's check_oauth_signature() rules
        $this->assertSame('GET&http%3A%2F%2Fold-shop.example.test%2Fwp-json%2Fwc%2Fv3%2Fproducts&oauth_consumer_key%3Dck_test%26oauth_nonce%3Dabcdef0123456789'
            .'%26oauth_signature_method%3DHMAC-SHA256%26oauth_timestamp%3D1700000000%26page%3D1%26per_page%3D100%26search%3Da%2520b%252Bc',
            OAuth1::baseString('GET', $url, array_diff_key($signed, ['oauth_signature' => 1])));
        $this->assertSame('/aPGTG4KyC6b4HNiFzn59GOQ4bJ3d928MV5LqC1dDyg=', $signed['oauth_signature']);
        $sha1 = OAuth1::sign('GET', $url, $query, 'ck_test', 'cs_test', 1700000000, 'abcdef0123456789', 'HMAC-SHA1');
        $this->assertSame('mmP8lzML3do9dex7MICi+TB4UnY=', $sha1['oauth_signature']);
    }

    public function test_rate_limits_and_server_errors_are_retried_with_backoff(): void
    {
        config(['commerce.woo_api.max_backoff' => 60, 'commerce.woo_api.retries' => 3]);
        $shop = FakeWooShop::fake();
        $shop->failures['wc/v3/products'] = [[429, ['Retry-After' => '3']], [503, []]];
        $slept = [];
        $client = new Client(Connection::make(['url' => FakeWooShop::BASE, 'key' => FakeWooShop::KEY, 'secret' => FakeWooShop::SECRET]),
            function (float $seconds) use (&$slept) { $slept[] = $seconds; });
        $this->assertCount(2, $client->get('products', ['per_page' => 2])->items());
        $this->assertSame([3.0, 2.0], $slept, 'Retry-After honoured, then exponential backoff');
        $this->assertCount(3, $shop->calls('wc/v3/products'));

        $shop->failures['wc/v3/orders'] = array_fill(0, 5, [500, []]);
        try {
            $client->get('orders');
            $this->fail('expected the server error after the retries');
        } catch (WooApiException $e) {
            $this->assertSame(['server', 500], [$e->reason, $e->status]);
        }
        $this->assertCount(4, $shop->calls('wc/v3/orders'), '1 + 3 retries');
    }

    public function test_an_incremental_run_only_reads_what_changed_since(): void
    {
        $shop = FakeWooShop::fake();
        $this->assertSame(0, $this->import(['--only' => 'categories,products', '--no-images' => true]), $this->lastOutput);
        $products = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/woo-api/wc-v3/products.json'), true);
        $products[1]['name'] = 'Polo (new season)';
        $products[1]['date_modified_gmt'] = '2025-07-01T10:00:00';
        $shop->override['wc/v3/products'] = $products;
        $shop->requests = [];

        $this->assertSame(0, $this->import(['--only' => 'products', '--no-images' => true, '--since' => '2025-06-01']), $this->lastOutput);
        $this->assertSame('2025-06-01T00:00:00', $shop->calls('wc/v3/products')[0]['query']['modified_after']);
        $run = WooApiImport::query()->latest('id')->first();
        $this->assertSame([1, 0, 1], [$run->entityProgress('products')['fetched'], $run->entityProgress('products')['created'], $run->entityProgress('products')['updated']]);
        $this->assertSame('Polo (new season)', DB::table('products')->where('wp_id', 110)->value('name'));
        $this->assertSame('Linen Shirt', DB::table('products')->where('wp_id', 100)->value('name'));
        $this->assertSame([], $shop->calls('wc/v3/products/100/variations'));
    }

    public function test_the_ssrf_guard_blocks_private_and_reserved_hosts(): void
    {
        $shop = FakeWooShop::fake('basic', '10.0.0.5');
        $this->assertSame(1, $this->import(['--test' => true]));
        $this->assertStringContainsString('private or reserved address', $this->lastOutput);
        $this->assertSame([], $shop->requests, 'nothing was sent');

        config(['commerce.woo_api.allow_private_hosts' => true]);
        $this->assertSame(0, $this->import(['--test' => true]), $this->lastOutput);
        $this->assertStringContainsString('Old Shop & Co', $this->lastOutput);

        foreach (['127.0.0.1', '10.1.2.3', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '224.0.0.1'] as $ip) {
            $this->assertFalse(UrlGuard::isPublic($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(UrlGuard::isPublic($ip), $ip);
        }
        foreach (['file:///etc/passwd', 'gopher://old-shop.example.test/', 'ftp://old-shop.example.test/', 'https://user:pw@old-shop.example.test/'] as $url) {
            try {
                UrlGuard::check($url, true);
                $this->fail("$url must be refused");
            } catch (WooApiException $e) {
                $this->assertSame('blocked', $e->reason);
            }
        }
    }

    public function test_a_dry_run_reads_and_maps_everything_but_writes_nothing(): void
    {
        $shop = FakeWooShop::fake();
        $this->assertSame(0, $this->import(['--dry-run' => true]), $this->lastOutput);
        $run = WooApiImport::query()->latest('id')->first();
        $this->assertTrue($run->dry_run);
        $this->assertSame(['completed', 3, 3], [$run->status, $run->entityProgress('products')['created'], $run->entityProgress('orders')['created']]);
        foreach (['products', 'product_variations', 'categories', 'orders', 'coupons', 'tax_rates', 'pages', 'posts', 'media', 'redirects'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], array_values(array_filter($shop->requests, fn ($r) => str_contains($r['path'], '/wp-content/uploads/'))), 'no downloads');
    }

    public function test_the_public_store_api_imports_the_catalogue_without_a_key(): void
    {
        $shop = FakeWooShop::fake();
        $code = \Illuminate\Support\Facades\Artisan::call('commerce:import-woo-api', ['--url' => FakeWooShop::BASE, '--store' => true, '--only' => 'categories,attributes,products']);
        $this->assertSame(0, $code, \Illuminate\Support\Facades\Artisan::output());
        $this->assertSame([], array_values(array_filter($shop->requests, fn ($r) => str_contains($r['path'], '/wc/v3/'))), 'no keyed API used');

        $shirt = DB::table('products')->where('wp_id', 100)->first();
        $this->assertEquals([50, 40, 40], [$shirt->regular_price, $shirt->sale_price, $shirt->price], 'minor units converted');
        $this->assertSame((int) DB::table('categories')->where('path', 'clothing/shirts')->value('id'), (int) $shirt->primary_category_id);
        $polo = DB::table('products')->where('wp_id', 110)->first();
        $variations = DB::table('product_variations')->where('product_id', $polo->id)->orderBy('sort_order')->get();
        $this->assertSame(['{"colour":"red"}', '{"colour":"blue"}'], $variations->pluck('options')->all());
        $this->assertEquals([20, 22], $variations->pluck('regular_price')->all());
        $this->assertSame(['instock', 'outofstock'], $variations->pluck('stock_status')->all());
        $this->assertSame('store', WooApiImport::query()->latest('id')->value('mode'));
    }

    public function test_remote_ids_of_another_shop_never_collide(): void
    {
        // the database importer brought in ANOTHER shop whose product 100 is also "linen-shirt"
        DB::table('products')->insert(['name' => 'Other shop shirt', 'slug' => 'linen-shirt', 'type' => 'simple', 'status' => 'published', 'wp_id' => 100,
            'created_at' => now(), 'updated_at' => now()]);
        FakeWooShop::fake();
        $this->assertSame(0, $this->import(['--only' => 'categories,products', '--no-images' => true]), $this->lastOutput);

        $this->assertSame('Other shop shirt', DB::table('products')->whereNull('import_source')->where('wp_id', 100)->value('name'), 'left alone');
        $api = DB::table('products')->where('import_source', 'woo:old-shop.example.test')->where('wp_id', 100)->first();
        $this->assertSame(['Linen Shirt', 'linen-shirt-2'], [$api->name, $api->slug]);
        $this->assertSame('/clothing/shirts/linen-shirt-2/', DB::table('redirects')->where('from_path', 'clothing/shirts/linen-shirt')->value('to_url'));

        // the database importer's own maps never see the API rows
        $this->assertSame([100 => (int) DB::table('products')->whereNull('import_source')->where('wp_id', 100)->value('id')],
            (new Upserter(null))->map('products'));
    }

    public function test_the_same_site_as_the_database_import_is_updated_in_place(): void
    {
        DB::table('categories')->insert(['name' => 'Shirts', 'slug' => 'shirts', 'path' => 'clothing/shirts', 'wp_id' => 11, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert(['name' => 'Linen Shirt (old)', 'slug' => 'linen-shirt', 'type' => 'simple', 'status' => 'published', 'wp_id' => 100,
            'created_at' => now(), 'updated_at' => now()]);
        \Pine\Commerce\Models\Setting::set('import.wordpress.site_url', 'https://www.old-shop.example.test', 'import');
        FakeWooShop::fake();
        $this->assertSame(0, $this->import(['--only' => 'categories,products', '--no-images' => true]), $this->lastOutput);

        $run = WooApiImport::query()->latest('id')->first();
        $this->assertNull($run->source, 'recognised as the database-imported site');
        $this->assertSame(1, DB::table('products')->where('wp_id', 100)->count());
        $this->assertSame(['Linen Shirt', 'linen-shirt', null], array_values((array) DB::table('products')->where('wp_id', 100)->first(['name', 'slug', 'import_source'])));
        $this->assertSame(1, DB::table('categories')->where('wp_id', 11)->count());
    }

    public function test_a_cancelled_run_stops_after_the_page_and_resumes_from_its_checkpoint(): void
    {
        $shop = FakeWooShop::fake();
        \Pine\Commerce\Import\WooApi\StoredConnection::save(['url' => FakeWooShop::BASE, 'key' => FakeWooShop::KEY, 'secret' => FakeWooShop::SECRET]);
        $shop->onRequest = function (string $route, array $query) {
            if ($route === 'wc/v3/products' && ($query['page'] ?? '') === '1') {
                WooApiImport::query()->where('status', 'running')->update(['status' => 'cancelling']);
            }
        };
        $this->assertSame(1, $this->import(['--only' => 'categories,products', '--no-images' => true]));
        $run = WooApiImport::query()->latest('id')->first();
        $this->assertSame('cancelled', $run->status);
        $this->assertSame(['entity' => 'products', 'page' => 2], array_intersect_key($run->checkpoint, ['entity' => 1, 'page' => 1]));
        $this->assertSame(2, DB::table('products')->count(), 'the first page was kept');

        $shop->onRequest = null;
        $shop->requests = [];
        $code = \Illuminate\Support\Facades\Artisan::call('commerce:import-woo-api', ['run' => $run->id]);
        $this->assertSame(0, $code, \Illuminate\Support\Facades\Artisan::output());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(['2'], array_map(fn ($c) => $c['query']['page'], $shop->calls('wc/v3/products')), 'page 1 is not read again');
        $this->assertSame([], $shop->calls('wc/v3/products/categories'), 'finished entities are skipped');
        $this->assertSame(3, DB::table('products')->count());
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
