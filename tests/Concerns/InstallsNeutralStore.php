<?php

namespace Pine\Commerce\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Theme\Storefront;
use Pine\Commerce\Theme\ThemeManager;

/**
 * A FRESH client for a test: the package's default config (none of this app's client values), the package's default
 * theme and `commerce:install` into phpunit's in-memory SQLite, plus an optional small neutral catalogue.
 *
 * Never touches MySQL: the test is skipped unless the default connection is the in-memory SQLite database; the install
 * runs on a side connection that shares its PDO, so no files, .env or caches are written either.
 * Call setUpNeutralStore() from setUp() and tearDownNeutralStore() from tearDown().
 */
trait InstallsNeutralStore
{
    protected function setUpNeutralStore(array $config = []): void
    {
        // SAFETY: only ever on phpunit's in-memory SQLite (never the shop's MySQL database)
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:'
            || DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs phpunit\'s in-memory SQLite database.');
        }

        // a new client: the package defaults only, its own name and host
        config(['commerce' => require dirname(__DIR__, 2).'/config/commerce.php']);
        config([
            'commerce.theme' => 'default',
            'app.name' => 'Acme Store', 'app.url' => 'https://shop.example.test',
            'mail.from.address' => 'hello@shop.example.test', 'mail.from.name' => 'Acme Store',
        ]);
        config($config);
        URL::forceRootUrl('https://shop.example.test');
        Mail::fake();

        $themes = $this->app->make(ThemeManager::class);
        $themes->activate('default');
        Storefront::flush();
        $this->withoutMiddleware(\Pine\Commerce\Http\Middleware\TrailingSlash::class);

        // commerce:install on a side connection sharing the in-memory PDO (the file/.env/optimize steps are skipped)
        config(['database.connections.neutral_install' => config('database.connections.sqlite')]);
        $pdo = DB::connection('sqlite')->getPdo();
        // the MySQL functions the package's raw SQL uses come from the package itself (Support\SqliteFunctions, attached
        // to every SQLite connection) – no test-only polyfills, so a real SQLite project behaves exactly like this
        DB::connection('neutral_install')->setPdo($pdo);
        $this->artisan('commerce:install', [
            '--connection' => 'neutral_install', '--admin-email' => 'owner@shop.example.test', '--admin-password' => 'Correct-Horse-9',
            '--store-name' => 'Acme Store', '--skip-publish' => true, '--no-optimize' => true, '--no-interaction' => true,
        ])->assertSuccessful();
        Setting::flushMemo();
        // per-process memos filled by earlier tests in this process (client menus/categories on the shop database)
        \Pine\Commerce\View\Components\MenuComponent::flush();
        \Pine\Commerce\Services\Catalog\Categories::flush();
        \Pine\Commerce\Services\Cart::flush();
    }

    protected function tearDownNeutralStore(): void
    {
        $this->app?->make(ThemeManager::class)->reset();
        Storefront::flush();
        \Pine\Commerce\View\Components\MenuComponent::flush();
        \Pine\Commerce\Services\Catalog\Categories::flush();
        \Pine\Commerce\Services\Cart::flush();
        DB::purge('neutral_install');
    }

    /** The administrator commerce:install created. */
    protected function neutralAdmin(): \Pine\Commerce\Models\User
    {
        return \Pine\Commerce\Commerce::userModel()::query()->where('email', 'owner@shop.example.test')->firstOrFail();
    }

    /** @return array{0: Product, 1: Category, 2: Order, 3: Post} */
    protected function catalogue(): array
    {
        $category = Category::create(['name' => 'Shirts', 'slug' => 'shirts', 'is_visible' => true]);
        $product = Product::forceCreate([
            'name' => 'Classic Linen Shirt', 'slug' => 'classic-linen-shirt', 'sku' => 'SHIRT-1', 'type' => 'simple',
            'status' => 'published', 'regular_price' => 40, 'sale_price' => 30, 'price' => 30, 'manage_stock' => true,
            'stock_quantity' => 8, 'backorders' => 'no', 'stock_status' => 'instock', 'primary_category_id' => $category->id,
            'short_description' => '<p>A soft linen shirt.</p>', 'description' => '<p>Breathable and machine washable.</p>',
        ]);
        $product->categories()->attach($category->id);
        $product->specs()->create(['label' => 'Material', 'value' => '100% linen', 'sort_order' => 1]);

        $customer = \Pine\Commerce\Commerce::userModel()::forceCreate([
            'name' => 'Ada Lovelace', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test',
            'password' => Hash::make('Password12345'), 'role' => 'customer', 'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $customer->id, 'email' => 'ada@example.test', 'status' => 'processing',
            'billing_first_name' => 'Ada', 'billing_last_name' => 'Lovelace', 'billing_address_1' => '1 High Street',
            'billing_city' => 'Bristol', 'billing_postcode' => 'BS1 1AA', 'billing_country' => 'GB',
            'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Lovelace', 'shipping_address_1' => '1 High Street',
            'shipping_city' => 'Bristol', 'shipping_postcode' => 'BS1 1AA', 'shipping_country' => 'GB',
            'subtotal' => 30, 'shipping_total' => 0, 'total' => 30, 'payment_method' => 'bacs', 'payment_method_title' => 'Bank transfer',
            'shipping_method' => 'free', 'shipping_method_title' => 'Free delivery',
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
            'unit_price' => 30, 'subtotal' => 30, 'total' => 30]);
        $order->refresh();
        if (! $order->order_key) {
            $order->forceFill(['order_key' => 'wc_order_'.Str::random(13)])->save();
        }

        $post = Post::forceCreate(['title' => 'Welcome to the shop', 'slug' => 'welcome', 'status' => 'published',
            'content' => '<p>Our first post.</p>', 'published_at' => now()->subDay()]);

        return [$product->fresh(), $category, $order, $post];
    }
}
