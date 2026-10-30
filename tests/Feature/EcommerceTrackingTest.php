<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Once;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Marketing\ProductIds;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * The shop's events for GA4, Google Ads remarketing, the Meta Pixel and Tag Manager (Services\Marketing\Ecommerce,
 * partials.tracking), like for like with a WooCommerce shop's Google Analytics, Pixel Manager and GTM Kit plugins:
 * imported products keep the old shop's IDs everywhere (feed, remarketing, Pixel), GA4 gets the SKU.
 */
class EcommerceTrackingTest extends TestCase
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

    protected function tags(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::set($key, $value, 'seo');
        }
        Cache::forget('settings.all');
        Setting::flushMemo();
    }

    /** window.storeTracking of a page. */
    protected function tracking(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.storeTracking = (\{.*?\});<\/script>/s', $html, $m), 'the page carries its tracking data');

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /** A product imported from WooCommerce: post ID 134400, no SKU of its own on the size. */
    protected function imported(Product $product): Product
    {
        $product->forceFill(['wp_id' => 134400])->save();
        Once::flush();

        return $product->fresh();
    }

    public function test_imported_products_keep_the_old_shops_ids_and_new_ones_can_never_take_them(): void
    {
        [$product] = $this->catalogue();
        $this->assertSame((string) $product->id, ProductIds::product($product), 'a shop without imports: its own IDs, as before');

        $old = Product::forceCreate(['name' => 'D555 Leonard Shacket', 'slug' => 'leonard', 'sku' => '', 'type' => 'variable', 'status' => 'published',
            'regular_price' => 45, 'price' => 45, 'manage_stock' => false, 'stock_status' => 'instock', 'wp_id' => 134400]);
        $size = ProductVariation::forceCreate(['product_id' => $old->id, 'sku' => null, 'regular_price' => 45, 'stock_status' => 'instock', 'wp_id' => 134401, 'options' => []]);
        $added = ProductVariation::forceCreate(['product_id' => $old->id, 'sku' => 'LEO-8XL', 'regular_price' => 45, 'stock_status' => 'instock', 'options' => []]);
        Once::flush();

        $this->assertSame('134400', ProductIds::product($old), 'the WordPress post ID, as the old feed and Pixel Manager sent');
        $this->assertSame('p'.$product->id, ProductIds::product($product), 'made on this site in a shop with imports: can never be an old post ID');
        $this->assertSame('134401', ProductIds::variation($size));
        $this->assertSame('v'.$added->id, ProductIds::variation($added));
        $this->assertSame('SHIRT-1', ProductIds::analytics($product), 'GA4: the SKU (WooCommerce Google Analytics, "product SKU")');
        $this->assertSame('#134400', ProductIds::analytics($old, $size), 'no SKU anywhere: "#" + the product ID, as the plugin did');
        $this->assertSame('LEO-8XL', ProductIds::analytics($old, $added), 'a size with its own SKU');

        $feed = $this->get(route('feed.google'))->assertOk()->getContent();
        $this->assertStringContainsString('<g:id>134401</g:id>', $feed);
        $this->assertStringContainsString('<g:item_group_id>134400</g:item_group_id>', $feed);
        $this->assertStringContainsString('<g:id>p'.$product->id.'</g:id>', $feed, 'the feed uses the same IDs as the tags');
    }

    public function test_product_and_list_pages_report_their_products_with_both_ids(): void
    {
        [$product, $category] = $this->catalogue();
        $product = $this->imported($product);
        $this->tags(['tracking.gtm_id' => 'GTM-PW4MPNTM']);

        $t = $this->tracking($this->get($product->url)->assertOk()->getContent());
        $this->assertSame('product', $t['page']);
        $this->assertSame('GBP', $t['currency']);
        $this->assertEquals(['id' => '134400', 'item_id' => 'SHIRT-1', 'item_name' => 'Classic Linen Shirt', 'price' => 30, 'quantity' => 1, 'item_category' => 'Shirts'], $t['items'][0]);

        $t = $this->tracking($this->get($category->url)->assertOk()->getContent());
        $this->assertSame('list', $t['page']);
        $this->assertSame('Shirts', $t['list']);
        $this->assertSame(['134400', 'SHIRT-1', 0, 'Shirts'], [$t['items'][0]['id'], $t['items'][0]['item_id'], $t['items'][0]['index'], $t['items'][0]['item_list_name']]);

        $this->assertSame('home', $this->tracking($this->get(route('home'))->getContent())['page']);
        $this->assertSame('Search results', $this->tracking($this->get(route('search', ['s' => 'linen']))->getContent())['list'] ?? null);
    }

    public function test_add_to_basket_returns_the_line_and_every_basket_reply_the_basket(): void
    {
        [$product] = $this->catalogue();
        $this->imported($product);

        $r = $this->postJson(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2])->assertOk()->json();
        $this->assertSame(['134400', 'SHIRT-1', 2], [$r['tracking']['items'][0]['id'], $r['tracking']['items'][0]['item_id'], $r['tracking']['items'][0]['quantity']]);
        $this->assertEquals(60, $r['tracking']['value']);
        $this->assertSame('134400', $r['tracking_cart']['items'][0]['id'], 'the basket, for "checkout started"');
        $this->assertSame('134400', $this->getJson(route('cart.fragment'))->assertOk()->json('tracking_cart.items.0.id'));
    }

    public function test_the_purchase_carries_the_order_number_the_old_ids_and_goes_to_every_platform(): void
    {
        [$product, , $order] = $this->catalogue();
        $this->imported($product);
        $this->tags(['tracking.gtm_id' => 'GTM-PW4MPNTM', 'tracking.ga4_id' => 'G-S8HFLB54V4', 'tracking.ga4_page_views' => false,
            'tracking.meta_pixel_id' => '3917378625191016', 'tracking.google_ads_remarketing' => true,
            'tracking.google_ads_conversion' => 'AW-11320443036/I0ySCO_R-7kbEJyBgZYq, AW-11509923982/B62QCICKkPoZEI6BrvAq']);
        $this->actingAs(\Pine\Commerce\Commerce::userModel()::query()->findOrFail($order->user_id));

        $html = $this->get(route('checkout.thankyou', ['order' => $order->number, 'key' => $order->order_key]))->assertOk()->getContent();
        $t = $this->tracking($html);

        $this->assertSame(['purchase', (string) $order->number, 'store_purchase_'.$order->id], [$t['page'], $t['transaction_id'], $t['once']]);
        $this->assertEquals(30, $t['value']);
        $this->assertSame(['134400', 'SHIRT-1'], [$t['items'][0]['id'], $t['items'][0]['item_id']]);
        $this->assertStringContainsString('gtag/js?id=G-S8HFLB54V4&amp;l=storeGtagLayer', $html, 'next to Tag Manager the shop’s gtag.js has its own queue');
        $this->assertStringContainsString("storeGtag('config', 'G-S8HFLB54V4', {send_page_view: false}); storeGtag('config', 'AW-11320443036'); storeGtag('config', 'AW-11509923982');", $html);
        $this->assertStringContainsString('ads = ["AW-11320443036","AW-11509923982"]', $html, 'remarketing: every account');
        $this->assertStringContainsString('conversions = ["AW-11320443036\/I0ySCO_R-7kbEJyBgZYq","AW-11509923982\/B62QCICKkPoZEI6BrvAq"]', $html);
        $this->assertStringContainsString("gtag('set', 'url_passthrough', true);", $html);
        $this->assertStringNotContainsString('localStorage.setItem(key', $html, 'the order-received view no longer sends a purchase of its own');

        $page = $this->get(route('home'))->getContent();
        $this->assertStringContainsString('conversions = []', $page, 'the conversion labels are only used for purchases');
    }

    public function test_without_tag_manager_there_is_one_queue_and_ga4_counts_page_views(): void
    {
        $this->tags(['tracking.ga4_id' => 'G-S8HFLB54V4']);
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('window.storeGtag = gtag;', $html);
        $this->assertStringContainsString('gtag/js?id=G-S8HFLB54V4"', $html);
        $this->assertStringContainsString("storeGtag('config', 'G-S8HFLB54V4');", $html);
        $this->assertStringContainsString('ads = []', $html, 'no remarketing without the setting');
        $this->assertStringNotContainsString('url_passthrough', $html, 'only with Google Ads');
    }

    public function test_a_theme_adds_its_own_tags_with_the_page_and_the_paid_order(): void
    {
        [, , $order] = $this->catalogue();
        $dir = sys_get_temp_dir().'/pine-theme-tags-'.uniqid();
        mkdir($dir.'/partials', 0777, true);
        file_put_contents($dir.'/partials/tracking-page.blade.php', '<script>/* THEME-TAGS {{ $tracking[\'page\'] }} {{ $trackingOrder?->email }} {{ implode(\',\', $adsAccounts) }} */</script>');
        View::addLocation($dir);
        $this->tags(['tracking.google_ads_conversion' => 'AW-11509923982/B62QCICKkPoZEI6BrvAq']);
        $this->actingAs(\Pine\Commerce\Commerce::userModel()::query()->findOrFail($order->user_id));

        $html = $this->get(route('checkout.thankyou', ['order' => $order->number, 'key' => $order->order_key]))->assertOk()->getContent();

        $this->assertStringContainsString('THEME-TAGS purchase ada@example.test AW-11509923982', $html);
        $this->assertLessThan(strpos($html, 'w.storeTrack = {'), strpos($html, 'THEME-TAGS'), 'before the events, so its store:track listeners see them');
        array_map('unlink', glob($dir.'/partials/*'));
        rmdir($dir.'/partials');
        rmdir($dir);
    }
}
