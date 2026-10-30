<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Admin › Settings › SEO & tracking: the Meta Pixel (after marketing consent) and the Google Ads purchase conversion,
 * on every storefront page and the order-received page, never on the checkout where card details are entered.
 */
class MarketingTagsTest extends TestCase
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

    public function test_nothing_loads_until_an_id_is_set(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('fbevents.js', $html);
        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('data-cookie-banner', $html, 'auto banner: no tracking, no banner');
    }

    public function test_the_pixel_and_google_ads_load_on_shop_pages_behind_consent(): void
    {
        [$product] = $this->catalogue();
        $this->tags(['tracking.meta_pixel_id' => '3917378625191016', 'tracking.google_ads_conversion' => 'AW-11320443036/I0ySCO_R-7kbEJyBgZYq']);

        $html = $this->get($product->url)->assertOk()->getContent();

        $this->assertStringContainsString("fbq('init', '3917378625191016')", $html);
        $this->assertStringContainsString("indexOf('marketing') > -1) { w.storeStartMarketing(); }", $html, 'the pixel waits for marketing consent');
        $this->assertStringContainsString("j['@type'] === 'Product'", $html, 'product views read the Product JSON-LD');
        $this->assertStringContainsString('gtag/js?id=AW-11320443036', $html);
        $this->assertStringContainsString("gtag('config', 'AW-11320443036');", $html);
        $this->assertStringNotContainsString('I0ySCO_R-7kbEJyBgZYq', $html, 'the conversion label is only used for purchases');
        $this->assertStringContainsString('data-cookie-banner', $html, 'auto banner: shown once marketing tags are set');
    }

    public function test_purchases_are_sent_from_the_order_received_page_but_nothing_loads_on_the_checkout(): void
    {
        [$product, , $order] = $this->catalogue();
        $this->tags(['tracking.gtm_id' => 'GTM-PW4MPNTM', 'tracking.ga4_id' => 'G-S8HFLB54V4', 'tracking.meta_pixel_id' => '3917378625191016',
            'tracking.google_ads_conversion' => 'AW-11320443036/I0ySCO_R-7kbEJyBgZYq, AW-11509923982/B62QCICKkPoZEI6BrvAq']);

        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();
        $checkout = $this->get(route('checkout'))->assertOk()->getContent();
        foreach (['googletagmanager.com', 'fbevents.js', 'GTM-PW4MPNTM'] as $needle) {
            $this->assertStringNotContainsString($needle, $checkout);
        }

        $this->actingAs(\Pine\Commerce\Commerce::userModel()::query()->findOrFail($order->user_id));
        $html = $this->get(route('checkout.thankyou', ['order' => $order->number, 'key' => $order->order_key]))->assertOk()->getContent();

        $this->assertStringContainsString("'dataLayer','GTM-PW4MPNTM'", $html);
        $this->assertStringContainsString('ns.html?id=GTM-PW4MPNTM', $html);
        $this->assertStringContainsString('"event":"purchase"', $html);
        $this->assertStringContainsString('gtag(\'event\', \'purchase\', {"transaction_id":"'.$order->number.'"', $html);
        $this->assertStringContainsString('"send_to":"G-S8HFLB54V4"', $html);
        $this->assertStringContainsString('gtag(\'event\', \'conversion\', {"send_to":"AW-11320443036\/I0ySCO_R-7kbEJyBgZYq","value":'.json_encode((float) $order->total), $html);
        $this->assertStringContainsString('gtag(\'event\', \'conversion\', {"send_to":"AW-11509923982\/B62QCICKkPoZEI6BrvAq","value":'.json_encode((float) $order->total), $html, 'every account (e.g. an agency\'s) counts the sale');
        $this->assertStringContainsString("gtag('config', 'G-S8HFLB54V4'); gtag('config', 'AW-11320443036'); gtag('config', 'AW-11509923982');", $html);
        $this->assertStringContainsString("fbq('track', 'Purchase', {\"value\":".json_encode((float) $order->total), $html);
        $this->assertStringContainsString('{ eventID: "order-'.$order->number.'" }', $html);
    }

    public function test_the_settings_reject_ids_in_the_wrong_format(): void
    {
        $this->actingAs($this->neutralAdmin());

        $this->put(route('admin.settings.update', 'seo'), ['tracking__meta_pixel_id' => 'fb-123', 'tracking__google_ads_conversion' => 'AW-11320443036'])
            ->assertSessionHasErrors(['tracking__meta_pixel_id', 'tracking__google_ads_conversion']);

        $this->put(route('admin.settings.update', 'seo'), ['tracking__google_ads_conversion' => 'AW-11320443036/I0ySCO_R-7kbEJyBgZYq; AW-1'])
            ->assertSessionHasErrors(['tracking__google_ads_conversion']);

        $this->put(route('admin.settings.update', 'seo'), ['tracking__meta_pixel_id' => ' 3917378625191016 ', 'tracking__google_ads_conversion' => 'AW-11320443036/I0ySCO_R-7kbEJyBgZYq,AW-11509923982/B62QCICKkPoZEI6BrvAq'])
            ->assertSessionHasNoErrors();
        Setting::flushMemo();
        $this->assertSame('3917378625191016', setting('tracking.meta_pixel_id'));
        $this->assertSame('AW-11320443036/I0ySCO_R-7kbEJyBgZYq,AW-11509923982/B62QCICKkPoZEI6BrvAq', setting('tracking.google_ads_conversion'));
    }
}
