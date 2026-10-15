<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Http\Request;
use Pine\Commerce\Services\Checkout\Attribution;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Order attribution: the storefront tells js/app.js the cookie name (it records the source there – before this nothing
 * wrote the cookie, so every order was "Direct"), and checkout reads the cookie exactly as the script writes it.
 */
class OrderAttributionTest extends TestCase
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

    public function test_the_storefront_gives_the_script_the_attribution_cookie_name(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match('#<script type="application/json" id="store-config">(.*?)</script>#s', $html, $m);

        $this->assertSame(Attribution::cookieName(), json_decode($m[1], true)['attributionCookie']);
    }

    /** encodeURIComponent(JSON.stringify(data)) – what js/app.js writes */
    private function requestWithCookie(array $data, string $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile'): Request
    {
        $value = rawurlencode(json_encode($data, JSON_UNESCAPED_SLASHES));
        $request = Request::create('https://shop.example.test/checkout/', 'POST', [], [Attribution::cookieName() => $value], [], ['HTTP_USER_AGENT' => $ua]);

        return $request;
    }

    public function test_campaign_search_referral_and_direct_visits(): void
    {
        $utm = Attribution::fromRequest($this->requestWithCookie(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn sale', 'landing' => '/sale/?utm_source=newsletter', 'ts' => '2026-10-15T09:00:00.000Z', 'pages' => 4]));
        $this->assertSame(['utm', 'newsletter'], [$utm['source_type'], $utm['source']]);
        $this->assertSame('autumn sale', $utm['meta']['utm_campaign']);
        $this->assertSame('4', $utm['meta']['session_pages']);
        $this->assertSame('Mobile', $utm['meta']['device_type']);

        $ads = Attribution::fromRequest($this->requestWithCookie(['utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => '1', 'landing' => '/']));
        $this->assertSame(['utm', 'google'], [$ads['source_type'], $ads['source']]);
        $this->assertSame('yes', $ads['meta']['gclid']);

        $search = Attribution::fromRequest($this->requestWithCookie(['referrer' => 'https://www.google.co.uk/', 'landing' => '/big-tee-shirts/']));
        $this->assertSame(['organic', 'google'], [$search['source_type'], $search['source']]);

        $referral = Attribution::fromRequest($this->requestWithCookie(['referrer' => 'https://www.facebook.com/', 'landing' => '/']));
        $this->assertSame(['referral', 'facebook.com'], [$referral['source_type'], $referral['source']]);

        $direct = Attribution::fromRequest(Request::create('https://shop.example.test/checkout/', 'POST'));
        $this->assertSame(['typein', '(direct)'], [$direct['source_type'], $direct['source']]);
    }
}
