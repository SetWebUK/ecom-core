<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Catalog\ProductPresenter;
use Pine\Commerce\Theme\Storefront;
use Pine\Commerce\Theme\ThemeManager;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * A FRESH client: package default config (the package config/commerce.php – none of this app's client values),
 * the package's default theme, `commerce:install` into phpunit's in-memory SQLite plus a small neutral catalogue.
 * Every storefront page type (and the default-theme emails) must render, and must not contain any client string:
 * everything client-specific lives in the client layer (app/, config/, themes/{client}), never in core.
 *
 * The package names no client, so the banned strings are configurable and EMPTY here: a client project that runs
 * these tests against its copy of the package (or CI) passes them in through the environment –
 *   COMMERCE_NEUTRALITY_BANNED        comma-separated, case-insensitive needles (e.g. "acme,acme-staging.example")
 *   COMMERCE_NEUTRALITY_BANNED_REGEX  one PCRE pattern matched against the lower-cased output (e.g. "/acme[-_][a-z]/")
 *
 * Never touches MySQL: skipped unless the default connection is the in-memory SQLite database; the install runs on a
 * side connection that shares its PDO, so no files, .env or caches are written either.
 */
class NeutralInstallRenderTest extends TestCase
{
    /** @return list<string> case-insensitive needles that must never appear in a neutral install's output */
    public static function bannedStrings(): array
    {
        $raw = (string) (static::environment('COMMERCE_NEUTRALITY_BANNED') ?? '');

        return array_values(array_filter(array_map(fn ($needle) => strtolower(trim($needle)), explode(',', $raw)), 'strlen'));
    }

    /** A PCRE pattern matched against the lower-cased output, or null. */
    public static function bannedPattern(): ?string
    {
        $pattern = trim((string) (static::environment('COMMERCE_NEUTRALITY_BANNED_REGEX') ?? ''));

        return $pattern === '' ? null : $pattern;
    }

    protected static function environment(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return $value === false || $value === null ? null : (string) $value;
    }

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

    public function test_every_default_theme_page_type_renders_without_client_strings(): void
    {
        [$product, $category, $order, $post] = $this->catalogue();

        $pages = Page::query()->where('status', 'published')->get();
        $this->assertGreaterThanOrEqual(5, $pages->count(), 'commerce:install seeds home/about/contact/terms/privacy');

        $urls = [
            route('home'), route('shop'), route('shop', ['orderby' => 'price']), route('search', ['s' => 'shirt']),
            $product->url, $category->url, route('blog.index'), $post->url, route('account'), route('password.request'),
            route('cart'),
        ];
        foreach ($pages as $page) {
            $urls[] = $page->url;
        }

        foreach ($urls as $url) {
            $response = $this->get($url);
            $this->assertContains($response->status(), [200, 302], "{$url} returned {$response->status()} ".($response->exception?->getMessage() ?? '')); // basket: 302 when empty
            if ($response->status() === 200) {
                $this->assertStringContainsString('</html>', $response->getContent(), "{$url} is not a complete page");
                $this->assertNeutral($response->getContent(), $url);
                $this->assertValidJsonLd($response->getContent(), $url);
            }
        }
        $missing = $this->get(url('this-page-does-not-exist-'.Str::lower(Str::random(6))))->assertNotFound()->getContent();
        $this->assertNeutral($missing, '404');
        $this->assertStringContainsString('Classic Linen Shirt', $this->get($product->url)->getContent());
        $this->assertStringContainsString('class="theme-default', $this->get(route('home'))->getContent());

        // fragments, JSON endpoints, feeds
        $this->assertNeutral($this->get(route('product.quick-view', $product))->assertOk()->getContent(), 'quick-view');
        $this->assertNeutral($this->get(route('shop'), [(string) config('commerce.catalog.ajax_header') => '1'])->assertOk()->getContent(), 'ajax catalog');
        $this->assertNeutral($this->get(route('search.suggest', ['q' => 'shirt']))->assertOk()->getContent(), 'search suggest');
        foreach ([route('sitemap'), route('robots'), route('feed.google'), route('feed.posts')] as $url) {
            $response = $this->get($url);
            $this->assertContains($response->status(), [200, 404], "{$url} returned {$response->status()}");
            $this->assertNeutral($response->getContent(), $url);
        }

        // basket + checkout with an item
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();
        $this->assertNeutral($this->get(route('cart.fragment'))->assertOk()->getContent(), 'cart fragment');
        $this->assertNeutral($this->get(route('checkout'))->assertOk()->getContent(), 'checkout');
        $this->assertNeutral($this->postJson(route('checkout.update'), [])->assertOk()->getContent(), 'checkout update');

        // order pages + customer account
        // a visitor with the key only: the billing-email check of the order-received page, the login for order-pay
        $this->assertNeutral($this->get(route('checkout.thankyou', ['order' => $order->number, 'key' => $order->order_key]))->assertOk()->getContent(), 'thank-you email check');
        $this->get(route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]))->assertRedirect();
        $this->actingAs(\Pine\Commerce\Commerce::userModel()::query()->findOrFail($order->user_id));
        $this->assertNeutral($this->get(route('checkout.thankyou', ['order' => $order->number, 'key' => $order->order_key]))->assertOk()->getContent(), 'thank-you');
        $this->assertNeutral($this->get(route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]))->assertOk()->getContent(), 'order-pay');
        foreach (['account', 'account.orders', 'account.addresses', 'account.details', 'account.wishlist', 'account.downloads'] as $route) {
            $this->assertNeutral($this->get(route($route))->assertOk()->getContent(), $route);
        }
        $this->assertNeutral($this->get(route('account.order', ['number' => $order->number]))->assertOk()->getContent(), 'account order');

        // presenter output of the core presenter
        $this->assertSame(ProductPresenter::class, commerce_presenter());
        $this->assertStringContainsString('class="card-price"', ProductPresenter::cardPriceHtml($product));
        $this->assertNeutral(ProductPresenter::cardPriceHtml($product).ProductPresenter::priceHtml($product), 'presenter');
    }

    public function test_default_theme_emails_are_neutral(): void
    {
        [, , $order] = $this->catalogue();
        foreach (['admin-new-order', 'customer-processing-order', 'customer-completed-order', 'customer-on-hold-order', 'customer-note'] as $template) {
            $html = view('emails.orders.'.$template, ['order' => $order, 'heading' => 'Heading', 'storeName' => 'Acme Store', 'note' => 'Note',
                'partial' => false, 'amount' => 1, 'trackingUrl' => null, 'bacsAccounts' => [], 'bacsInstructions' => ''])->render();
            $this->assertNeutral($html, 'email '.$template);
        }
    }

    /** Every JSON-LD block is valid JSON with a schema.org @context (Blade must not compile "@context" as a directive). */
    protected function assertValidJsonLd(string $html, string $where): void
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);
        $this->assertNotEmpty($blocks[1], "{$where}: no JSON-LD");
        foreach ($blocks[1] as $json) {
            $data = json_decode($json, true);
            $this->assertIsArray($data, "{$where}: invalid JSON-LD: ".substr($json, 0, 200));
            $this->assertSame('https://schema.org', $data['@context'] ?? null, "{$where}: JSON-LD without @context: ".substr($json, 0, 200));
        }
    }

    protected function assertNeutral(string $html, string $where): void
    {
        $lower = strtolower($html);
        foreach (static::bannedStrings() as $needle) {
            $at = strpos($lower, $needle);
            $this->assertFalse($at, "{$where}: contains client string \"{$needle}\" near: ".($at === false ? '' : substr($html, max(0, $at - 200), 400)));
        }
        if ($pattern = static::bannedPattern()) {
            $this->assertDoesNotMatchRegularExpression($pattern, $lower, "{$where}: matches the banned pattern {$pattern}");
        }
    }
}
