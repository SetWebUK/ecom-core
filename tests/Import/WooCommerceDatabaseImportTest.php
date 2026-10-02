<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pine\Commerce\Import\AdapterRegistry;
use Pine\Commerce\Import\Adapters\WooCommerce;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Pipeline;
use Pine\Commerce\Import\RenderedSite\RenderedSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps;
use Pine\Commerce\Tests\TestCase;

/**
 * The database importer end to end on a small WooCommerce shop (SQLite fixture, never MySQL): categories, a global
 * attribute, simple + variable products with variations, a customer account, guest + registered orders with tax,
 * shipping, coupon and fee lines, a refund, a coupon and a review. Pins the mapping the REST API importer shares
 * (Import\Mapping) and checks a second run changes nothing.
 */
class WooCommerceDatabaseImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs phpunit\'s in-memory SQLite target.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
        WpFixture::boot();
        $this->seedShop();
    }

    protected function tearDown(): void
    {
        DB::purge(WpFixture::CONNECTION);
        parent::tearDown();
    }

    private function seedShop(): void
    {
        $s = Schema::connection(WpFixture::CONNECTION);
        $s->create('woocommerce_attribute_taxonomies', function (Blueprint $t) {
            $t->bigIncrements('attribute_id');
            $t->string('attribute_name');
            $t->string('attribute_label')->nullable();
            $t->string('attribute_type')->default('select');
            $t->string('attribute_orderby')->default('menu_order');
            $t->integer('attribute_public')->default(0);
        });
        $s->create('users', function (Blueprint $t) {
            $t->bigIncrements('ID');
            $t->string('user_login');
            $t->string('user_pass');
            $t->string('user_email');
            $t->string('display_name')->default('');
            $t->dateTime('user_registered')->nullable();
        });
        $s->create('usermeta', function (Blueprint $t) {
            $t->bigIncrements('umeta_id');
            $t->unsignedBigInteger('user_id');
            $t->string('meta_key')->nullable();
            $t->longText('meta_value')->nullable();
        });
        $s->create('commentmeta', function (Blueprint $t) {
            $t->bigIncrements('meta_id');
            $t->unsignedBigInteger('comment_id');
            $t->string('meta_key')->nullable();
            $t->longText('meta_value')->nullable();
        });
        $db = DB::connection(WpFixture::CONNECTION);

        WpFixture::option('siteurl', 'https://old-shop.example.test');
        WpFixture::option('home', 'https://old-shop.example.test');
        WpFixture::option('active_plugins', ['woocommerce/woocommerce.php']);
        WpFixture::option('woocommerce_version', '9.9.0');
        WpFixture::option('woocommerce_currency', 'GBP');
        WpFixture::option('permalink_structure', '/%postname%/');

        $clothing = WpFixture::term(10, 'product_cat', 'Clothing', 'clothing');
        $shirts = WpFixture::term(11, 'product_cat', 'Shirts &amp; Tops', 'shirts', 10);
        $db->table('attribute_values'); // (target table exists after migrate)
        $db->table('woocommerce_attribute_taxonomies')->insert(['attribute_id' => 1, 'attribute_name' => 'colour', 'attribute_label' => 'Colour']);
        $red = WpFixture::term(20, 'pa_colour', 'Red', 'red');
        $blue = WpFixture::term(21, 'pa_colour', 'Blue', 'blue');
        $simple = WpFixture::term(2, 'product_type', 'simple', 'simple');
        $variable = WpFixture::term(4, 'product_type', 'variable', 'variable');
        $featured = WpFixture::term(6, 'product_visibility', 'featured', 'featured');

        WpFixture::post(['ID' => 500, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Shirt', 'post_mime_type' => 'image/jpeg'],
            ['_wp_attached_file' => '2025/01/shirt.jpg', '_wp_attachment_image_alt' => 'A linen shirt']);

        WpFixture::post(['ID' => 100, 'post_type' => 'product', 'post_title' => 'Linen Shirt', 'post_name' => 'linen-shirt', 'post_status' => 'publish',
            'post_content' => 'A linen shirt.', 'post_excerpt' => 'Light and airy', 'menu_order' => 3], [
                '_sku' => 'LS-1', '_regular_price' => '50', '_sale_price' => '40', '_price' => '40', '_manage_stock' => 'yes', '_stock' => '5',
                '_stock_status' => 'instock', '_backorders' => 'no', '_weight' => '0.3', '_length' => '30', '_width' => '20', '_height' => '2',
                '_tax_status' => 'taxable', '_tax_class' => '', '_thumbnail_id' => '500', 'total_sales' => '12', '_wc_average_rating' => '5.00',
                '_wc_review_count' => '1', '_sold_individually' => 'no', '_global_unique_id' => '5012345678900',
            ]);
        WpFixture::relate(100, $shirts);
        WpFixture::relate(100, $simple);
        WpFixture::relate(100, $featured);

        WpFixture::post(['ID' => 110, 'post_type' => 'product', 'post_title' => 'Polo', 'post_name' => 'polo', 'post_status' => 'publish'], [
            '_sku' => 'POLO', '_manage_stock' => 'no', '_stock_status' => 'instock', '_tax_status' => 'taxable',
            '_product_attributes' => ['pa_colour' => ['name' => 'pa_colour', 'value' => '', 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 1]],
        ]);
        WpFixture::relate(110, $shirts);
        WpFixture::relate(110, $clothing);
        WpFixture::relate(110, $variable);
        WpFixture::relate(110, $red);
        WpFixture::relate(110, $blue);
        WpFixture::post(['ID' => 111, 'post_type' => 'product_variation', 'post_title' => 'Polo - Red', 'post_parent' => 110, 'post_status' => 'publish', 'menu_order' => 1],
            ['attribute_pa_colour' => 'red', '_sku' => 'POLO-R', '_regular_price' => '20', '_manage_stock' => 'yes', '_stock' => '2', '_stock_status' => 'instock']);
        WpFixture::post(['ID' => 112, 'post_type' => 'product_variation', 'post_title' => 'Polo - Blue', 'post_parent' => 110, 'post_status' => 'publish', 'menu_order' => 2],
            ['attribute_pa_colour' => 'blue', '_sku' => 'POLO-B', '_regular_price' => '22', '_manage_stock' => 'no', '_stock_status' => 'outofstock']);

        // customer account
        $db->table('users')->insert(['ID' => 7, 'user_login' => 'ann', 'user_pass' => '$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.', 'user_email' => 'Ann@Example.com',
            'display_name' => 'Ann', 'user_registered' => '2024-12-01 10:00:00']);
        foreach (['wp_capabilities' => serialize(['customer' => true]), 'first_name' => 'Ann', 'last_name' => 'Lee', 'billing_address_1' => '1 High St',
            'billing_city' => 'Leeds', 'billing_postcode' => 'LS1 1AA', 'billing_country' => 'GB', 'billing_phone' => '0113 000'] as $k => $v) {
            $db->table('usermeta')->insert(['user_id' => 7, 'meta_key' => $k, 'meta_value' => $v]);
        }

        // orders (posts storage)
        $order = function (int $id, string $status, array $meta, array $items, string $created, string $note = '') use ($db) {
            $db->table('posts')->insert(['ID' => $id, 'post_type' => 'shop_order', 'post_status' => $status, 'post_excerpt' => $note,
                'post_date_gmt' => $created, 'post_modified_gmt' => $created, 'post_title' => 'Order']);
            foreach ($meta as $k => $v) {
                $db->table('postmeta')->insert(['post_id' => $id, 'meta_key' => $k, 'meta_value' => (string) $v]);
            }
            foreach ($items as [$itemId, $type, $name, $im]) {
                $db->table('woocommerce_order_items')->insert(['order_item_id' => $itemId, 'order_item_name' => $name, 'order_item_type' => $type, 'order_id' => $id]);
                foreach ($im as $k => $v) {
                    $db->table('woocommerce_order_itemmeta')->insert(['order_item_id' => $itemId, 'meta_key' => $k, 'meta_value' => (string) $v]);
                }
            }
        };
        $address = fn (string $prefix, array $a) => collect($a)->mapWithKeys(fn ($v, $k) => ['_'.$prefix.'_'.$k => $v])->all();
        $order(200, 'wc-processing', [
            '_order_currency' => 'GBP', '_cart_discount' => '10', '_cart_discount_tax' => '2', '_order_shipping' => '4.99', '_order_shipping_tax' => '1',
            '_order_tax' => '14', '_order_total' => '100.99', '_payment_method' => 'stripe', '_payment_method_title' => 'Card', '_transaction_id' => 'pi_1',
            '_customer_user' => '7', '_created_via' => 'checkout', '_order_key' => 'wc_order_aaa', '_date_paid' => (string) strtotime('2025-02-01 09:01:00 UTC'),
            '_prices_include_tax' => 'no', '_wc_order_attribution_utm_source' => 'google',
        ] + $address('billing', ['first_name' => 'Ann', 'last_name' => 'Lee', 'address_1' => '1 High St', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'email' => 'ann@example.com', 'phone' => '0113 000'])
          + $address('shipping', ['first_name' => 'Ann', 'last_name' => 'Lee', 'address_1' => '1 High St', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country' => 'GB']), [
            [1001, 'line_item', 'Linen Shirt', ['_product_id' => 100, '_variation_id' => 0, '_qty' => 1, '_line_subtotal' => 50, '_line_total' => 40, '_line_tax' => 8]],
            [1002, 'line_item', 'Polo - Red', ['_product_id' => 110, '_variation_id' => 111, '_qty' => 2, '_line_subtotal' => 40, '_line_total' => 40, '_line_tax' => 8, 'pa_colour' => 'red']],
            [1003, 'shipping', 'Flat rate', ['method_id' => 'flat_rate', 'cost' => 4.99, 'total_tax' => 1]],
            [1004, 'tax', 'GB-VAT-1', ['rate_id' => 1, 'label' => 'VAT', 'compound' => '', 'tax_amount' => 16, 'shipping_tax_amount' => 1, 'rate_percent' => 20]],
            [1005, 'coupon', 'save10', ['discount_amount' => 10, 'discount_amount_tax' => 2]],
            [1006, 'fee', 'Gift wrap', ['_line_total' => 2, '_line_tax' => 0]],
        ], '2025-02-01 09:00:00', 'Leave at the door');
        $order(201, 'wc-completed', [
            '_order_currency' => 'GBP', '_cart_discount' => '0', '_order_shipping' => '0', '_order_shipping_tax' => '0', '_order_tax' => '8', '_order_total' => '48',
            '_payment_method' => 'paypal', '_payment_method_title' => 'PayPal', '_customer_user' => '0', '_created_via' => 'checkout', '_order_key' => 'wc_order_bbb',
            '_date_paid' => (string) strtotime('2025-03-01 12:00:00 UTC'), '_date_completed' => (string) strtotime('2025-03-02 12:00:00 UTC'),
        ] + $address('billing', ['first_name' => 'Bo', 'last_name' => 'Ng', 'address_1' => '2 Low Rd', 'city' => 'York', 'postcode' => 'YO1 1AA', 'country' => 'GB', 'email' => 'BO@example.com']), [
            [1007, 'line_item', 'Linen Shirt', ['_product_id' => 100, '_variation_id' => 0, '_qty' => 1, '_line_subtotal' => 40, '_line_total' => 40, '_line_tax' => 8]],
        ], '2025-03-01 11:59:00');
        // refund of order 201
        $db->table('posts')->insert(['ID' => 202, 'post_type' => 'shop_order_refund', 'post_status' => 'wc-completed', 'post_parent' => 201,
            'post_date_gmt' => '2025-03-05 10:00:00', 'post_modified_gmt' => '2025-03-05 10:00:00', 'post_excerpt' => 'Too small']);
        foreach (['_refund_amount' => '48', '_refund_reason' => 'Too small', '_refunded_by' => '1', '_order_total' => '-48'] as $k => $v) {
            $db->table('postmeta')->insert(['post_id' => 202, 'meta_key' => $k, 'meta_value' => $v]);
        }
        $db->table('woocommerce_order_items')->insert(['order_item_id' => 1008, 'order_item_name' => 'Linen Shirt', 'order_item_type' => 'line_item', 'order_id' => 202]);
        foreach (['_product_id' => 100, '_qty' => -1, '_line_total' => -40, '_line_tax' => -8, '_refunded_item_id' => 1007] as $k => $v) {
            $db->table('woocommerce_order_itemmeta')->insert(['order_item_id' => 1008, 'meta_key' => $k, 'meta_value' => (string) $v]);
        }
        $db->table('comments')->insert(['comment_post_ID' => 200, 'comment_author' => 'WooCommerce', 'comment_type' => 'order_note', 'comment_content' => 'Payment received.',
            'comment_date_gmt' => '2025-02-01 09:01:00']);

        // coupon + review
        WpFixture::post(['ID' => 300, 'post_type' => 'shop_coupon', 'post_title' => 'SAVE10', 'post_excerpt' => 'Ten off', 'post_status' => 'publish'],
            ['discount_type' => 'percent', 'coupon_amount' => '10', 'product_ids' => '100', 'usage_count' => '3', 'date_expires' => (string) strtotime('2030-01-01 00:00:00 UTC')]);
        $db->table('comments')->insert(['comment_ID' => 50, 'comment_post_ID' => 100, 'comment_author' => 'Ann', 'comment_author_email' => 'ann@example.com',
            'comment_type' => 'review', 'comment_content' => 'Lovely', 'comment_approved' => '1', 'comment_date_gmt' => '2025-02-10 10:00:00']);
        $db->table('commentmeta')->insert([['comment_id' => 50, 'meta_key' => 'rating', 'meta_value' => '5'], ['comment_id' => 50, 'meta_key' => 'verified', 'meta_value' => '1']]);
    }

    private function import(): ImportContext
    {
        $wp = new WordPressSource(WpFixture::CONNECTION);
        $ctx = (new ImportContext(null, $wp, SiteProfile::detect($wp), new AdapterRegistry([WooCommerce::class]), new RenderedSource([]), []))->boot();
        $steps = [Steps\UsersStep::class, Steps\CategoriesStep::class, Steps\AttributesStep::class, Steps\ProductsStep::class, Steps\VariationsStep::class,
            Steps\OrdersStep::class, Steps\ReviewsStep::class, Steps\CouponsStep::class];
        Pipeline::run($ctx, (new Pipeline([], $steps))->steps());

        return $ctx;
    }

    public function test_a_woocommerce_shop_is_imported_and_a_second_run_changes_nothing(): void
    {
        $ctx = $this->import();

        $shirts = DB::table('categories')->where('wp_id', 11)->first();
        $this->assertSame('clothing/shirts', $shirts->path);
        $this->assertSame('Shirts & Tops', $shirts->name);

        $shirt = DB::table('products')->where('wp_id', 100)->first();
        $this->assertSame(['linen-shirt', 'LS-1', 'simple', 'published'], [$shirt->slug, $shirt->sku, $shirt->type, $shirt->status]);
        $this->assertEquals([50, 40, 40], [$shirt->regular_price, $shirt->sale_price, $shirt->price]);
        $this->assertEquals([1, 5, 'instock', 1], [$shirt->manage_stock, $shirt->stock_quantity, $shirt->stock_status, $shirt->is_featured]);
        $this->assertSame((int) $shirts->id, (int) $shirt->primary_category_id);
        $this->assertSame('5012345678900', $shirt->gtin);
        $this->assertSame('uploads/2025/01/shirt.jpg', DB::table('product_images')->where('product_id', $shirt->id)->value('path'));

        $polo = DB::table('products')->where('wp_id', 110)->first();
        $this->assertSame('variable', $polo->type);
        $this->assertEquals(20, $polo->price, 'cheapest variation');
        $variations = DB::table('product_variations')->where('product_id', $polo->id)->orderBy('sort_order')->get();
        $this->assertSame(['{"colour":"red"}', '{"colour":"blue"}'], $variations->pluck('options')->all());
        $this->assertSame(['instock', 'outofstock'], $variations->pluck('stock_status')->all());
        $this->assertSame(2, DB::table('attribute_value_product')->where('product_id', $polo->id)->count());

        $ann = DB::table('users')->where('email', 'ann@example.com')->first();
        $this->assertSame('customer', $ann->role);
        $this->assertSame(1, DB::table('addresses')->where('user_id', $ann->id)->where('type', 'billing')->count());
        $this->assertNotNull(DB::table('users')->where('email', 'bo@example.com')->value('id'), 'guest from the order');

        $o200 = DB::table('orders')->where('wp_id', 200)->first();
        $this->assertSame([(int) $ann->id, 'processing', 'save10', 'flat_rate'], [(int) $o200->user_id, $o200->status, $o200->coupon_code, $o200->shipping_method]);
        $this->assertEquals([90, 10, 4.99, 15, 100.99], [$o200->subtotal, $o200->discount_total, $o200->shipping_total, $o200->tax_total, $o200->total]);
        $this->assertSame(2, DB::table('order_items')->where('order_id', $o200->id)->count());
        $this->assertSame('{"Colour":"Red"}', DB::table('order_items')->where('order_id', $o200->id)->where('sku', 'POLO-R')->value('options'));
        $meta = json_decode($o200->meta, true);
        $this->assertSame([['name' => 'Gift wrap', 'total' => 2, 'tax' => 0]], $meta['fees']);
        $this->assertSame('google', $meta['attribution_utm_source']);
        $tax = DB::table('order_tax_lines')->where('order_id', $o200->id)->first();
        $this->assertSame(['VAT', 16.0, 1.0, 20.0], [$tax->label, (float) $tax->tax_total, (float) $tax->shipping_tax_total, (float) $tax->rate]);
        $this->assertSame(1, DB::table('order_notes')->where('order_id', $o200->id)->count());
        $this->assertSame(1, DB::table('payments')->where('order_id', $o200->id)->count());

        $o201 = DB::table('orders')->where('wp_id', 201)->first();
        $this->assertEquals(48, $o201->refunded_total);
        $this->assertSame(1, (int) DB::table('order_items')->where('order_id', $o201->id)->value('refunded_quantity'));
        $this->assertSame('Too small', DB::table('refunds')->where('order_id', $o201->id)->value('reason'));

        $coupon = DB::table('coupons')->where('code', 'save10')->first();
        $this->assertSame(['percent', '[' . $shirt->id . ']', '2030-01-01 00:00:00'], [$coupon->type, $coupon->product_ids, (string) $coupon->expires_at]);
        $this->assertSame(5, (int) DB::table('product_reviews')->where('product_id', $shirt->id)->value('rating'));

        $before = $this->snapshot();
        $this->import();
        $this->assertSame($before, $this->snapshot(), 'a second run updates in place');
    }

    /** Row counts + key columns of every table the importer writes. */
    private function snapshot(): array
    {
        $out = [];
        foreach (['users', 'addresses', 'categories', 'attributes', 'attribute_values', 'products', 'product_variations', 'product_images',
            'category_product', 'attribute_value_product', 'orders', 'order_items', 'order_tax_lines', 'refunds', 'order_notes', 'payments',
            'coupons', 'product_reviews'] as $table) {
            $out[$table] = DB::table($table)->count();
        }
        $out['products.cols'] = DB::table('products')->orderBy('id')->get(['id', 'slug', 'price', 'primary_category_id'])->toArray();
        $out['orders.cols'] = DB::table('orders')->orderBy('id')->get(['id', 'number', 'total', 'refunded_total', 'user_id'])->toArray();

        return json_decode(json_encode($out), true);
    }
}
