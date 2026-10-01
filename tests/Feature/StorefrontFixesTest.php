<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Http\Controllers\CheckoutController;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Menu;
use Pine\Commerce\Models\MenuItem;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductReview;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Catalog\ProductListing as ProductListingAlias;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;
use Pine\Commerce\View\Components\MenuComponent;

/**
 * Storefront bug fixes of pine/commerce 1.1 on a fresh neutral store (default theme, in-memory SQLite):
 * review replies, draft products, menu setting tokens, 410 redirects, order-pay / order-received access, archive page
 * numbers past the end, the previous URL's trailing slash, full meta descriptions and breadcrumb categories.
 */
class StorefrontFixesTest extends TestCase
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

    public function test_a_review_reply_from_the_shop_is_shown_under_the_review(): void
    {
        [$product] = $this->catalogue();
        ProductReview::create(['product_id' => $product->id, 'name' => 'Grace', 'email' => 'grace@example.test', 'rating' => 5,
            'content' => 'Lovely shirt.', 'is_approved' => true, 'reply' => "Thank you, Grace!\nSee you again.", 'replied_at' => now()]);
        ProductReview::create(['product_id' => $product->id, 'name' => 'Alan', 'rating' => 4, 'content' => 'Nice.', 'is_approved' => true]);

        $html = $this->get($product->url)->assertOk()->getContent();
        $this->assertStringContainsString('Response from Acme Store', $html);
        $this->assertStringContainsString('Thank you, Grace!<br />', $html);
        $this->assertSame(1, substr_count($html, 'class="review__reply"'), 'only the review with a reply gets the reply block');
    }

    public function test_a_draft_product_is_a_404_for_customers_and_a_preview_for_staff(): void
    {
        [$product, $category] = $this->catalogue();
        foreach (['draft', 'private'] as $status) {
            $product->forceFill(['status' => $status])->save();

            // WordPress answered 404 – not a 301 to the category through the 404 fallbacks
            $this->get($product->url)->assertNotFound();
            $this->get('/product/'.$product->slug.'/')->assertNotFound();

            $this->actingAs($this->neutralAdmin());
            $this->get($product->url)->assertOk()->assertSee(ucfirst($status).' - not visible to customers');
            auth()->logout();
        }

        $product->forceFill(['status' => 'published'])->save();
        $this->get($product->url)->assertOk()->assertDontSee('not visible to customers');
        $this->assertNotNull($category);
    }

    public function test_an_explicit_redirect_rule_still_wins_for_a_draft_product_url(): void
    {
        [$product] = $this->catalogue();
        $product->forceFill(['status' => 'draft'])->save();
        Redirect::create(['from_path' => trim(parse_url($product->url, PHP_URL_PATH), '/'), 'to_url' => '/shop/', 'status_code' => 301, 'is_active' => true]);

        $this->get($product->url)->assertRedirect(url('/shop/'))->assertStatus(301);
    }

    public function test_menu_labels_and_links_can_use_store_setting_tokens(): void
    {
        Setting::set('store.phone', '0117 496 0000', 'store');
        Setting::set('store.email', 'hello@shop.example.test', 'store');
        Setting::flushMemo();
        $menu = Menu::firstOrCreate(['location' => 'mobile_nav'], ['name' => 'Mobile']);
        MenuItem::where('menu_id', $menu->id)->delete();
        MenuItem::create(['menu_id' => $menu->id, 'label' => 'Need help? Call {store.phone}', 'url' => 'tel:{store.phone}', 'sort_order' => 1]);
        MenuItem::create(['menu_id' => $menu->id, 'label' => 'Email {store.email}', 'url' => 'mailto:{store.email}', 'sort_order' => 2]);
        MenuItem::create(['menu_id' => $menu->id, 'label' => 'Secret {payments.stripe_secret} {store.nope}', 'url' => '/x/', 'sort_order' => 3]);
        MenuComponent::flush();

        $items = MenuComponent::treeFor('mobile_nav');
        $this->assertSame('Need help? Call 0117 496 0000', $items[0]['label']);
        $this->assertSame('tel:01174960000', $items[0]['url']);
        $this->assertSame('mailto:hello@shop.example.test', $items[1]['url']);
        // only store.* settings are expanded; an unknown store setting is empty
        $this->assertSame('Secret {payments.stripe_secret} ', $items[2]['label']);

        // a changed setting shows at once (tokens are expanded after the menu cache)
        Setting::set('store.phone', '0800 000 111', 'store');
        Setting::flushMemo();
        MenuComponent::flush();
        $this->assertSame('tel:0800000111', MenuComponent::treeFor('mobile_nav')[0]['url']);
    }

    public function test_a_410_redirect_rule_answers_gone_with_the_themes_removed_page(): void
    {
        Redirect::create(['from_path' => 'old-range', 'to_url' => '', 'status_code' => 410, 'is_active' => true]);

        $this->get('/old-range/')->assertStatus(410)->assertSee('This page has been removed')->assertSee('410');
        $this->assertSame(1, (int) Redirect::where('from_path', 'old-range')->value('hits'));
    }

    public function test_staff_can_create_a_410_rule_without_a_target_and_import_one(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.redirects.create'))->assertOk()->assertSee('Gone (410)');

        $this->post(route('admin.redirects.store'), ['from_path' => '/discontinued/', 'to_url' => '', 'status_code' => 410, 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('redirects', ['from_path' => 'discontinued', 'to_url' => '', 'status_code' => 410]);
        $this->post(route('admin.redirects.store'), ['from_path' => '/other/', 'to_url' => '', 'status_code' => 301])->assertSessionHasErrors('to_url');
        $this->get(route('admin.redirects.index'))->assertOk()->assertSee('Gone');

        $csv = \Illuminate\Http\UploadedFile::fake()->createWithContent('r.csv', "/gone-too/,,410\n/moved/,/shop/,301\n");
        $this->post(route('admin.redirects.import'), ['file' => $csv, 'mode' => 'update'])->assertSessionHas('success');
        $this->assertDatabaseHas('redirects', ['from_path' => 'gone-too', 'to_url' => '', 'status_code' => 410]);
        $this->assertDatabaseHas('redirects', ['from_path' => 'moved', 'to_url' => '/shop/', 'status_code' => 301]);
    }

    public function test_order_pay_for_a_registered_customers_order_asks_that_customer_to_log_in(): void
    {
        [, , $order] = $this->catalogue(); // Ada's (registered) order
        $order->forceFill(['status' => 'pending'])->save();
        $pay = route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]);

        $response = $this->get($pay);
        $response->assertRedirect()->assertSessionHas('account_notice', CheckoutController::LOGIN_TO_PAY);
        $target = $response->headers->get('Location');
        $this->assertStringContainsString('redirect='.rawurlencode('/checkout/order-pay/'.$order->number.'/?key='.$order->order_key), $target);
        $this->get($target)->assertOk()->assertSee(CheckoutController::LOGIN_TO_PAY)
            ->assertSee('name="redirect" value="/checkout/order-pay/'.$order->number.'/?key='.$order->order_key.'"', false);
        $this->post(route('checkout.pay.submit', ['order' => $order->number]), ['key' => $order->order_key, 'payment_method' => 'bacs'])->assertRedirect();
        $this->assertSame('pending', $order->fresh()->status, 'a guest cannot pay a registered customer\'s order');

        // signed in as that customer: the form
        $this->actingAs($order->user);
        $this->get($pay)->assertOk()->assertSee('Pay for order');
    }

    public function test_order_pay_for_a_guest_order_keeps_working_with_the_key(): void
    {
        [, , $order] = $this->catalogue();
        $order->forceFill(['user_id' => null, 'status' => 'pending'])->save();

        $this->get(route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]))->assertOk()->assertSee('Pay for order');
        $this->get(route('checkout.pay', ['order' => $order->number, 'key' => 'wrong']))->assertNotFound();
    }

    public function test_order_received_asks_for_the_billing_email_once_outside_the_session_that_placed_it(): void
    {
        [, , $order] = $this->catalogue();
        $order->forceFill(['user_id' => null])->save();
        $url = route('checkout.thankyou', ['order' => $order->number, 'key' => $order->order_key]);

        $this->get($url)->assertOk()->assertSee('name="email"', false)->assertDontSee('1 High Street')->assertDontSee('Classic Linen Shirt');
        $this->post(route('checkout.thankyou.verify', ['order' => $order->number]), ['key' => $order->order_key, 'email' => 'someone@example.test'])
            ->assertRedirect($url)->assertSessionHas('checkout_error', CheckoutController::EMAIL_NOT_VERIFIED);
        $this->post(route('checkout.thankyou.verify', ['order' => $order->number]), ['key' => 'wrong', 'email' => 'ada@example.test'])->assertNotFound();

        $this->post(route('checkout.thankyou.verify', ['order' => $order->number]), ['key' => $order->order_key, 'email' => ' ADA@example.test '])
            ->assertRedirect($url)->assertSessionHas(CheckoutController::SESSION_ORDERS, [$order->id]);
        $this->get($url)->assertOk()->assertSee('Classic Linen Shirt')->assertDontSee('name="email"', false);

        // staff and the session that placed the order are never asked
        $this->flushSession();
        $this->withSession([CheckoutController::SESSION_ORDERS => [$order->id]])->get($url)->assertOk()->assertSee('Classic Linen Shirt');
        $this->flushSession();
        $this->actingAs($this->neutralAdmin())->get($url)->assertOk()->assertSee('Classic Linen Shirt');
    }

    public function test_archive_page_numbers_past_the_end_redirect_to_the_last_page(): void
    {
        [$product, $category] = $this->catalogue();
        $pages = (int) ceil((ProductListingAlias::PER_PAGE + 1) / ProductListingAlias::PER_PAGE); // 2
        for ($i = 1; $i <= ProductListingAlias::PER_PAGE; $i++) { // PER_PAGE + 1 products = 2 pages
            $copy = Product::forceCreate(['name' => 'Shirt '.$i, 'slug' => 'shirt-'.$i, 'type' => 'simple', 'status' => 'published',
                'regular_price' => 10 + $i, 'price' => 10 + $i, 'stock_status' => 'instock', 'primary_category_id' => $category->id]);
            $copy->categories()->attach($category->id);
        }

        $this->get('/shirts/page/9/')->assertStatus(301)->assertRedirect(url('shirts/page/'.$pages));
        $this->get('/shirts/page/'.$pages.'/')->assertOk();
        $this->get('/shop/page/7/?orderby=price')->assertStatus(301)->assertRedirect(url('shop/page/'.$pages).'?orderby=price');

        // an empty archive: page 1
        $empty = Category::create(['name' => 'Hats', 'slug' => 'hats', 'is_visible' => true]);
        \Pine\Commerce\Services\Catalog\Categories::flush();
        $this->get('/hats/page/2/')->assertStatus(301)->assertRedirect(url('hats'));
        $this->assertNotNull($empty->id);
        $this->assertNotNull($product->id);
    }

    public function test_the_previous_url_keeps_the_trailing_slash(): void
    {
        $generator = app('url');
        $this->assertSame('https://shop.example.test/shirts/?x=1', $generator->withTrailingSlash('https://shop.example.test/shirts?x=1'));
        $this->assertSame('https://shop.example.test/my-account/orders/#o', $generator->withTrailingSlash('https://shop.example.test/my-account/orders#o'));
        $this->assertSame('https://shop.example.test/shirts/', $generator->withTrailingSlash('https://shop.example.test/shirts/'));
        $this->assertSame('https://shop.example.test/sitemap.xml', $generator->withTrailingSlash('https://shop.example.test/sitemap.xml'));
        $this->assertSame('https://elsewhere.test/page', $generator->withTrailingSlash('https://elsewhere.test/page'));

        // back() after a failed form goes straight to the slashed URL (no extra 301)
        [$product] = $this->catalogue();
        $this->from('https://shop.example.test/shirts/classic-linen-shirt')
            ->post(route('product.review', $product), [])
            ->assertRedirect('https://shop.example.test/shirts/classic-linen-shirt/');
    }

    public function test_meta_descriptions_are_not_truncated(): void
    {
        [, $category] = $this->catalogue();
        $long = str_repeat('Soft breathable linen shirts for every season. ', 9); // ~430 characters
        $category->forceFill(['meta_description' => $long])->save();
        \Pine\Commerce\Services\Catalog\Categories::flush();

        $this->get($category->url)->assertOk()->assertSee('<meta name="description" content="'.e(trim($long)).'">', false);

        // a theme can still cap it (config seo.description_limit)
        $theme = app(\Pine\Commerce\Theme\ThemeManager::class)->active();
        $manifest = new \ReflectionProperty($theme, 'manifest');
        $data = $manifest->getValue($theme);
        $data['config']['seo'] = ['description_limit' => 50] + (array) ($data['config']['seo'] ?? []);
        $manifest->setValue($theme, $data);
        $this->get($category->url)->assertOk()->assertSee('<meta name="description" content="'.e(mb_substr(trim($long), 0, 50)).'">', false);
    }

    public function test_the_breadcrumb_can_show_another_assigned_category_than_the_url(): void
    {
        [$product, $category] = $this->catalogue();
        $sale = Category::create(['name' => 'Sale', 'slug' => 'sale', 'is_visible' => true]);
        $product->categories()->attach($sale->id);
        $product->forceFill(['breadcrumb_category_id' => $sale->id])->save();
        \Pine\Commerce\Services\Catalog\Categories::flush();
        $product = $product->fresh();

        $this->assertSame(url('shirts/classic-linen-shirt'), $product->url, 'the URL keeps the primary category');
        $crumbs = $this->get($product->url)->assertOk()->viewData('crumbs');
        $this->assertSame(['Sale', 'Classic Linen Shirt'], array_column($crumbs, 'label'));

        // no longer assigned: back to the URL category
        $product->categories()->detach($sale->id);
        $crumbs = $this->get($product->fresh()->url)->assertOk()->viewData('crumbs');
        $this->assertSame(['Shirts', 'Classic Linen Shirt'], array_column($crumbs, 'label'));
        $this->assertNotNull($category);
    }

    public function test_money_puts_the_minus_sign_before_the_currency_symbol(): void
    {
        $this->assertSame('-£5.00', money(-5));
        $this->assertSame('-£1,234.50', money('-1234.5'));
        $this->assertSame('£0.00', money(-0.001));
        $this->assertSame('-5.00', money(-5, false));
        $this->assertSame('£12.30', money(12.3));
    }
}
