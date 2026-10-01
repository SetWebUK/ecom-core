<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Pine\Commerce\Import\Steps;
use Pine\Commerce\Mail\BackInStock;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\StockNotification;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Theme\Storefront;
use PHPUnit\Framework\Attributes\DataProvider;
use Pine\Commerce\Tests\TestCase;

/**
 * Every feature switch (config commerce.features.*, ARCHITECTURE §9.2) on and off: routes (404 when off, names kept),
 * admin routes + sidebar, storefront entry points of the default theme, sitemap, emails, checkout rules and importer
 * steps. Fresh package install on phpunit's in-memory SQLite only. (Client theme gates: tests/Feature/Themes.)
 */
class FeatureFlagsTest extends TestCase
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

    // ------------------------------------------------------------------ definitions / config

    public function test_package_config_and_definitions_agree(): void
    {
        $package = (require dirname(__DIR__, 2).'/config/commerce.php')['features'];
        $this->assertSame(Features::defaults(), $package, 'the package config/commerce.php features = Features::DEFINITIONS defaults, same order');
        foreach (Features::DEFINITIONS as $key => [$default, $description]) {
            $this->assertIsBool($default, $key);
            $this->assertNotSame('', $description, $key);
        }
    }

    public function test_missing_keys_fall_back_to_the_package_default(): void
    {
        config(['commerce.features' => ['blog' => false]]);
        $this->assertFalse(Features::enabled('blog'));
        $this->assertTrue(Features::enabled('coupons'), 'missing key = package default (on)');
        $this->assertFalse(Features::enabled('pay_in_3'), 'missing key = package default (off)');
        $this->assertFalse(Features::enabled('no_such_switch'));
        $this->assertTrue(commerce_feature('coupons'));
    }

    public function test_view_bearing_features_also_need_theme_support_on_the_storefront_only(): void
    {
        $theme = theme();
        $manifest = new \ReflectionProperty($theme, 'manifest');
        $original = $manifest->getValue($theme);
        $manifest->setValue($theme, ['supports' => array_values(array_diff($theme->supported(), ['wishlist']))] + $original);
        try {
            $this->assertFalse(Features::enabled('wishlist'));
            $this->assertTrue(Features::enabled('wishlist', false), 'back office ignores theme support');
            $this->assertTrue(Features::enabled('coupons'), 'not view-bearing: no theme support needed');
        } finally {
            $manifest->setValue($theme, $original);
        }
    }

    // ------------------------------------------------------------------ storefront routes

    public static function storefrontRoutes(): array
    {
        return [
            'blog index' => ['blog', 'get', 'blog.index', []],
            'blog feed' => ['blog', 'get', 'feed.posts', []],
            'blog post' => ['blog', 'get', 'blog.show', ['slug' => 'welcome']],
            'google feed' => ['google_feed', 'get', 'feed.google', []],
            'review' => ['reviews', 'post', 'product.review', ['product' => true]],
            'back-in-stock' => ['stock_alerts', 'post', 'product.notify', ['product' => true]],
            'quick view' => ['quick_view', 'get', 'product.quick-view', ['product' => true]],
            'wishlist toggle' => ['wishlist', 'post', 'wishlist.toggle', []],
            'coupon' => ['coupons', 'post', 'cart.coupon', []],
            'coupon remove' => ['coupons', 'delete', 'cart.coupon.remove', []],
            'newsletter' => ['newsletter', 'post', 'newsletter.subscribe', []],
            'contact' => ['contact_form', 'post', 'contact.submit', []],
            'order tracking' => ['order_tracking', 'post', 'order.track', []],
            'registration' => ['registration', 'post', 'register', []],
        ];
    }

    #[DataProvider('storefrontRoutes')]
    public function test_storefront_routes_answer_404_when_their_feature_is_off(string $feature, string $method, string $route, array $params): void
    {
        [$product] = $this->catalogue();
        if (isset($params['product'])) {
            $params['product'] = $product->id;
        }
        $url = route($route, $params);

        config(["commerce.features.{$feature}" => true]);
        $this->assertNotSame(404, $this->call(strtoupper($method), $url)->status(), "{$route} with {$feature} on");

        config(["commerce.features.{$feature}" => false]);
        $this->call(strtoupper($method), $url)->assertNotFound();
        $this->assertTrue(\Route::has($route), 'route names stay registered');
    }

    public function test_account_wishlist_page_follows_the_switch(): void
    {
        [, , $order] = $this->catalogue();
        $this->actingAs(\Pine\Commerce\Commerce::userModel()::query()->findOrFail($order->user_id));
        $this->get(route('account.wishlist'))->assertOk();
        config(['commerce.features.wishlist' => false]);
        $this->get(route('account.wishlist'))->assertNotFound();
        $this->assertStringNotContainsString(route('account.wishlist'), $this->get(route('account'))->assertOk()->getContent());
    }

    // ------------------------------------------------------------------ back office

    /** feature, admin page, a page of the same sidebar section that stays available (its sub-menu is open there) */
    public static function adminPages(): array
    {
        return [
            'blog posts' => ['blog', 'admin.posts.index', 'admin.pages.index'],
            'blog categories' => ['blog', 'admin.post-categories.index', 'admin.pages.index'],
            'reviews' => ['reviews', 'admin.reviews.index', 'admin.products.index'],
            'stock alerts' => ['stock_alerts', 'admin.stock-alerts.index', 'admin.products.index'],
            'discounts' => ['coupons', 'admin.coupons.index', 'admin.dashboard'],
            'redirects' => ['redirects', 'admin.redirects.index', 'admin.pages.index'],
            'form submissions' => ['contact_form', 'admin.form-submissions.index', 'admin.newsletter.index'],
            'newsletter' => ['newsletter', 'admin.newsletter.index', 'admin.form-submissions.index'],
            'abandoned checkouts' => ['abandoned_carts', 'admin.carts.index', 'admin.orders.index'],
            'analytics' => ['reports', 'admin.reports.index', 'admin.dashboard'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_admin_pages_and_sidebar_items_follow_the_switch(string $feature, string $route, string $sibling): void
    {
        $this->actingAs($this->neutralAdmin());
        $url = route($route);

        $this->assertStringContainsString('href="'.$url.'"', $this->get($url)->assertOk()->getContent(), "sidebar links {$route}");

        config(["commerce.features.{$feature}" => false]);
        $this->get($url)->assertNotFound();
        $this->assertStringNotContainsString('href="'.$url.'"', $this->get(route($sibling))->assertOk()->getContent(),
            "sidebar still links {$route} with {$feature} off");
    }

    public function test_a_section_whose_own_page_is_off_links_to_its_first_visible_page(): void
    {
        $this->actingAs($this->neutralAdmin());
        config(['commerce.features.contact_form' => false]);
        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('admin.newsletter.index').'"', $html, 'Inbox now opens the newsletter');
        $this->assertStringNotContainsString('Latest contact-form submissions', $html, 'dashboard messages card hidden');

        config(['commerce.features.newsletter' => false]);
        $this->assertStringNotContainsString('>Inbox<', $this->get(route('admin.dashboard'))->assertOk()->getContent());
    }

    public function test_settings_system_lists_every_switch_read_only(): void
    {
        $this->actingAs($this->neutralAdmin());
        config(['commerce.features.blog' => false]);
        $html = $this->get(route('admin.settings.system'))->assertOk()->getContent();
        foreach (array_keys(Features::DEFINITIONS) as $key) {
            $this->assertStringContainsString('<code>'.$key.'</code>', $html);
        }
        $this->assertStringContainsString('package default: on', $html, 'blog is off here, on by default');
        $this->assertStringNotContainsString('name="features', $html, 'switches are not editable in the admin');
    }

    public function test_google_feed_settings_are_hidden_when_the_feed_is_off(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->assertStringContainsString('Google Shopping feed', $this->get(route('admin.settings.edit', 'seo'))->assertOk()->getContent());
        config(['commerce.features.google_feed' => false]);
        $this->assertStringNotContainsString('Google Shopping feed', $this->get(route('admin.settings.edit', 'seo'))->assertOk()->getContent());
    }

    // ------------------------------------------------------------------ storefront entry points (default theme)

    public function test_default_theme_hides_entry_points_of_switched_off_features(): void
    {
        [$product] = $this->catalogue();
        $out = Product::forceCreate(['name' => 'Sold Out Shirt', 'slug' => 'sold-out-shirt', 'type' => 'simple', 'status' => 'published',
            'regular_price' => 20, 'price' => 20, 'stock_status' => 'outofstock', 'primary_category_id' => $product->primary_category_id]);
        $out->categories()->attach($product->primary_category_id);
        $out = $out->fresh();

        $page = fn (string $url) => $this->get($url)->assertOk()->getContent();
        $checks = [
            'reviews' => [$product->url, route('product.review', $product)],
            'stock_alerts' => [$out->url, route('product.notify', $out)],
            'wishlist' => [$product->url, route('wishlist.toggle')],
            'quick_view' => [route('shop'), 'data-quick-view'],
            'newsletter' => [route('home'), route('newsletter.subscribe')],
            'blog' => [route('home'), route('feed.posts')],
            'registration' => [route('account'), route('register')],
        ];
        foreach ($checks as $feature => [$url, $needle]) {
            $this->assertStringContainsString($needle, $page($url), "{$feature} on: {$needle} on {$url}");
            config(["commerce.features.{$feature}" => false]);
            Storefront::flush();
            $this->assertStringNotContainsString($needle, $page($url), "{$feature} off: {$needle} still on {$url}");
            config(["commerce.features.{$feature}" => true]);
        }
    }

    public function test_shortcodes_of_switched_off_features_render_nothing(): void
    {
        $this->catalogue();
        $shortcodes = \Pine\Commerce\Commerce::shortcodes();
        $html = \Pine\Commerce\View\Components\PageContent::process('<p>[contact_form]</p><p>[order_tracking]</p><p>[blog_index]</p>');
        $this->assertStringContainsString(route('contact.submit'), $html);
        $this->assertStringContainsString(route('order.track'), $html);

        config(['commerce.features.contact_form' => false, 'commerce.features.order_tracking' => false, 'commerce.features.blog' => false]);
        $html = \Pine\Commerce\View\Components\PageContent::process('<p>[contact_form]</p><p>[order_tracking]</p><p>[blog_index]</p>');
        $this->assertStringNotContainsString(route('contact.submit'), $html);
        $this->assertStringNotContainsString(route('order.track'), $html);
        $this->assertStringNotContainsString('Welcome to the shop', $html);
        $this->assertTrue($shortcodes->has('contact_form'));
    }

    public function test_sitemap_leaves_out_the_blog_when_it_is_off(): void
    {
        [, , , $post] = $this->catalogue();
        $this->assertStringContainsString($post->url, $this->get(route('sitemap'))->assertOk()->getContent());
        config(['commerce.features.blog' => false]);
        $xml = $this->get(route('sitemap'))->assertOk()->getContent();
        $this->assertStringNotContainsString($post->url, $xml);
        $this->assertStringNotContainsString('<loc>'.url('blog').'</loc>', $xml);
        $this->assertStringContainsString(route('shop'), $xml);
    }

    // ------------------------------------------------------------------ checkout, basket, mails

    public function test_coupons_off_removes_the_field_and_ignores_stored_codes(): void
    {
        [$product] = $this->catalogue();
        \Pine\Commerce\Models\Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'amount' => 10, 'is_active' => true]);
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();
        $this->post(route('cart.coupon'), ['coupon_code' => 'SAVE10'], ['Accept' => 'application/json']);
        $cart = app(Cart::class);
        $cart->reset();
        $this->assertSame(['SAVE10'], $cart->couponCodes());
        $this->assertStringContainsString('data-coupon-apply', $this->get(route('checkout'))->assertOk()->getContent());

        config(['commerce.features.coupons' => false]);
        $cart->reset();
        $this->assertSame([], $cart->couponCodes(), 'a stored code is ignored while coupons are off');
        $this->assertSame(0.0, $cart->discount());
        $this->assertFalse($cart->applyCoupon('SAVE10')['ok']);
        $this->assertStringNotContainsString('data-coupon-apply', $this->get(route('checkout'))->assertOk()->getContent());
    }

    public function test_legacy_content_off_leaves_wordpress_markup_unprocessed(): void
    {
        $html = '<p>[us_text text="Welcome" tag="h2"]</p><p><i class="fab fa-cc-visa"></i> Cards</p>';
        $on = \Pine\Commerce\View\Components\PageContent::process($html);
        $this->assertStringContainsString('legacy-text', $on);
        $this->assertStringContainsString('<svg', $on);

        config(['commerce.features.legacy_content' => false]);
        $off = \Pine\Commerce\View\Components\PageContent::process($html);
        $this->assertStringNotContainsString('legacy-text', $off);
        $this->assertStringNotContainsString('<svg', $off);
        $this->assertStringContainsString('[us_text', $off);
    }

    public function test_condition_and_brand_switches_gate_shop_facets_admin_filter_and_csv(): void
    {
        config(['commerce.catalog.condition_attribute' => 'condition', 'commerce.catalog.brand_attribute' => 'brand',
            'commerce.catalog.filters' => ['condition' => ['title' => 'Condition'], 'brand' => ['title' => 'Brand'], 'size' => ['title' => 'Size']]]);
        config(['commerce.features.product_condition' => true, 'commerce.features.product_brand' => true]);
        $this->assertSame(['condition', 'brand', 'size'], array_keys(\Pine\Commerce\Services\Catalog\Facets::filters()));

        config(['commerce.features.product_condition' => false, 'commerce.features.product_brand' => false]);
        $this->assertSame(['size'], array_keys(\Pine\Commerce\Services\Catalog\Facets::filters()));

        $filter = \Pine\Commerce\Services\Admin\Catalogue\ProductFilter::fromRequest(\Illuminate\Http\Request::create('/admin/products', 'GET', ['condition' => 'used', 'brand' => 'Acme']));
        $this->assertNull($filter->condition);
        $this->assertNull($filter->brand);

        $this->actingAs($this->neutralAdmin());
        $csv = $this->get(route('admin.products.export'))->assertOk()->streamedContent();
        $header = strtok($csv, "\n");
        $this->assertStringNotContainsString('Condition', $header);
        $this->assertStringNotContainsString('Brand', $header);
        config(['commerce.features.product_condition' => true, 'commerce.features.product_brand' => true]);
        $this->assertStringContainsString('Condition,Brand', strtok($this->get(route('admin.products.export'))->assertOk()->streamedContent(), "\n"));
    }

    public function test_multi_shipping_off_offers_only_the_first_option(): void
    {
        [$product] = $this->catalogue();
        ShippingMethod::create(['name' => 'Express', 'code' => 'express', 'cost' => 9.5, 'is_active' => true, 'sort_order' => 99]);
        Cart::flush();
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $cart->reset();
        $this->assertGreaterThanOrEqual(2, count($cart->shippingMethods()));

        config(['commerce.features.multi_shipping' => false]);
        $cart->reset();
        $this->assertCount(1, $cart->shippingMethods());
    }

    public function test_guest_checkout_off_sends_guests_to_sign_in_first(): void
    {
        [$product] = $this->catalogue();
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();
        $this->get(route('checkout'))->assertOk();

        config(['commerce.features.guest_checkout' => false]);
        $this->get(route('checkout'))->assertRedirect(route('account', ['redirect' => '/checkout/']));
        $this->postJson(route('checkout.place'), [])->assertJsonPath('result', 'failure');
    }

    public function test_registration_needs_the_switch_and_the_setting(): void
    {
        $this->assertTrue(\Pine\Commerce\Http\Controllers\Auth\AuthController::registrationEnabled());
        config(['commerce.features.registration' => false]);
        $this->assertFalse(\Pine\Commerce\Http\Controllers\Auth\AuthController::registrationEnabled());
        config(['commerce.features.registration' => true]);
        \Pine\Commerce\Models\Setting::set('account.registration', false);
        $this->assertFalse(\Pine\Commerce\Http\Controllers\Auth\AuthController::registrationEnabled());
    }

    public function test_back_in_stock_emails_only_go_out_while_stock_alerts_are_on(): void
    {
        [$product] = $this->catalogue();
        StockNotification::create(['product_id' => $product->id, 'email' => 'wait@example.test']);
        Mail::fake();

        config(['commerce.features.stock_alerts' => false]);
        $this->assertSame(0, CatalogueTools::notifyBackInStock($product)['sent']);
        Mail::assertNothingSent();

        config(['commerce.features.stock_alerts' => true]);
        $this->assertSame(1, CatalogueTools::notifyBackInStock($product)['sent']);
        Mail::assertSent(BackInStock::class);
    }

    public function test_redirect_rules_and_the_wordpress_404_guess_follow_their_switches(): void
    {
        [$product] = $this->catalogue();
        Redirect::create(['from_path' => 'old-offers', 'to_url' => '/shop/', 'status_code' => 301, 'is_active' => true]);

        $this->get(url('old-offers'))->assertRedirect();
        $this->get(url($product->slug))->assertRedirect($product->url);

        config(['commerce.features.redirects' => false, 'commerce.features.wp_404_guess' => false]);
        $this->get(url('old-offers'))->assertNotFound();
        $this->get(url($product->slug))->assertNotFound();
    }

    public function test_review_ratings_leave_the_json_ld_when_reviews_are_off(): void
    {
        [$product] = $this->catalogue();
        $product->reviews()->create(['name' => 'Ada', 'rating' => 5, 'content' => 'Lovely', 'is_approved' => true]);
        $this->assertStringContainsString('aggregateRating', $this->get($product->url)->assertOk()->getContent());
        config(['commerce.features.reviews' => false]);
        $this->assertStringNotContainsString('aggregateRating', $this->get($product->url)->assertOk()->getContent());
    }

    // ------------------------------------------------------------------ importer

    public function test_importer_steps_belong_to_their_features(): void
    {
        $map = [
            Steps\PostsStep::class => 'blog', Steps\ReviewsStep::class => 'reviews', Steps\CouponsStep::class => 'coupons',
            Steps\WishlistsStep::class => 'wishlist', Steps\StockAlertsStep::class => 'stock_alerts', Steps\FormsStep::class => 'contact_form',
            Steps\RedirectsStep::class => 'redirects', Steps\ProductsStep::class => null, Steps\PagesStep::class => null,
        ];
        foreach ($map as $class => $feature) {
            $this->assertSame($feature, Features::forImportStep(app($class)), $class);
        }
    }
}
