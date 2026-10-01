<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Pine\Commerce\Commerce;
use Pine\Commerce\Console\ImportWordPressCommand;
use Pine\Commerce\Contracts\PaymentGateway;
use Pine\Commerce\Events\OrderStatusChanged;
use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Http\Controllers\Admin\MenuController;
use Pine\Commerce\Import\AdapterRegistry;
use Pine\Commerce\Listeners\SendOrderStatusEmails;
use Pine\Commerce\Mail\CustomerCompletedOrder;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Services\Admin\PageBlocks;
use Pine\Commerce\Services\Admin\StoreSettings;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Catalog\ProductPresenter;
use Pine\Commerce\Services\Payments\Gateways\BacsGateway;
use Pine\Commerce\Services\Payments\Gateways\PaypalGateway;
use Pine\Commerce\Services\Payments\Gateways\StripeGateway;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\Fixtures;
use Pine\Commerce\Tests\TestCase;

/**
 * The static extension API on Pine\Commerce\Commerce (ARCHITECTURE §11, docs/EXTENDING.md): every registry
 * used the way a client provider would, against a fresh package install on in-memory SQLite.
 */
class ExtensionApiTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        view()->addNamespace('fixture', dirname(__DIR__).'/Fixtures/views');
    }

    protected function tearDown(): void
    {
        ProductPresenter::flushMacros();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ payment gateways

    public function test_built_in_gateways_implement_the_contract_in_config_order(): void
    {
        $gateways = app(PaymentManager::class)->all();
        $this->assertSame(['stripe', 'paypal', 'bacs'], array_keys($gateways));
        $this->assertInstanceOf(StripeGateway::class, $gateways['stripe']);
        $this->assertInstanceOf(PaypalGateway::class, $gateways['paypal']);
        $this->assertInstanceOf(BacsGateway::class, $gateways['bacs']);
        foreach ($gateways as $gateway) {
            $this->assertInstanceOf(PaymentGateway::class, $gateway);
            $this->assertArrayHasKey('enabled', $gateway->adminSettings()['fields']);
        }
    }

    public function test_a_client_gateway_gets_its_admin_card_saves_encrypted_secrets_and_shows_at_checkout(): void
    {
        Commerce::gateway('acmepay', Fixtures\AcmePayGateway::class);
        $this->assertSame(['stripe', 'paypal', 'bacs', 'acmepay'], array_keys(Commerce::payments()->all()));

        $this->actingAs($this->neutralAdmin());
        $html = $this->get(route('admin.payments.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('AcmePay', $html);
        $this->assertStringContainsString('name="payments[acmepay][merchant]"', $html);
        $this->assertStringContainsString('name="payments[acmepay][api_key]"', $html);
        $this->assertStringContainsString(route('webhooks.payment', 'acmepay'), $html, 'webhook address box');
        $this->assertStringContainsString('AcmePay › Developers', $html);

        // declared pattern + message; gateway validateSettings(); secrets never flashed back
        $this->from(route('admin.payments.edit'))->put(route('admin.payments.update'), ['payments' => ['acmepay' => ['api_key' => 'nope', 'merchant' => 'forbidden']]])
            ->assertSessionHasErrors(['payments.acmepay.api_key' => 'An AcmePay key starts with ak_.', 'payments.acmepay.merchant']);
        $this->assertNull(session()->getOldInput('payments.acmepay.api_key'));
        $this->assertSame('forbidden', session()->getOldInput('payments.acmepay.merchant'));

        $this->put(route('admin.payments.update'), ['payments' => ['acmepay' => ['enabled' => '1', 'api_key' => 'ak_live1', 'merchant' => 'M-42']]])
            ->assertRedirect(route('admin.payments.edit'));
        $this->assertSame('ak_live1', Crypt::decryptString((string) setting('payments.acmepay.api_key')));
        $this->assertSame('M-42', setting('payments.acmepay.merchant'));
        $this->assertTrue(Commerce::payments()->get('acmepay')->isAvailable());

        // offered at checkout with its own panel HTML
        auth()->logout();
        [$product] = $this->catalogue();
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();
        $checkout = $this->get(route('checkout'))->assertOk()->getContent();
        $this->assertStringContainsString('value="acmepay"', $checkout);
        $this->assertStringContainsString('AcmePay instalments', $checkout);
        $this->assertStringContainsString('acmepay-note', $checkout);
    }

    public function test_gateways_can_be_replaced_or_removed_and_must_implement_the_contract(): void
    {
        Commerce::gateway('bacs', null);
        $this->assertSame(['stripe', 'paypal'], array_keys(Commerce::payments()->all()));

        $this->expectException(InvalidArgumentException::class);
        Commerce::gateway('broken', \stdClass::class);
    }

    // ------------------------------------------------------------------ shipping

    public function test_shipping_calculators_price_or_hide_matching_delivery_options(): void
    {
        [$product] = $this->catalogue();
        ShippingMethod::create(['name' => 'Express', 'code' => 'express_24', 'cost' => 10, 'is_active' => true, 'sort_order' => 5]);
        ShippingMethod::create(['name' => 'Pallet', 'code' => 'pallet', 'cost' => 50, 'is_active' => true, 'sort_order' => 6]);
        Cart::flush();
        Commerce::shippingCalculator('express_*', Fixtures\HalfPriceShipping::class);
        Commerce::shippingCalculator('pallet', fn ($method, $cost, $lines, $context) => $context['subtotal'] > 1000 ? 0.0 : null);

        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2], ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $cart->reset();
        $methods = $cart->shippingMethods();
        $this->assertSame(5.0, $methods['express_24']['cost']);
        $this->assertArrayNotHasKey('pallet', $methods, 'a calculator returning null hides the option');

        $this->expectException(InvalidArgumentException::class);
        Commerce::shippingCalculator('*', \stdClass::class);
    }

    // ------------------------------------------------------------------ back office

    public function test_client_admin_pages_routes_and_sidebar_entries(): void
    {
        Commerce::adminRoutes(function () {
            Route::get('acme-reports', fn () => view('fixture::admin-page'))->name('acme.reports');
        });
        Commerce::adminMenu()
            ->add('Acme reports', 'chart-bar', 'admin.acme.reports', after: 'admin.customers.index')
            ->child('admin.products.index', 'Warranty claims', 'admin.acme.reports')
            ->remove('admin.reports.index');

        $url = route('admin.acme.reports');
        $this->assertSame(url(config('commerce.admin.path').'/acme-reports'), rtrim($url, '/'));
        $this->get($url)->assertRedirect(route('admin.login'));

        $this->actingAs($this->neutralAdmin());
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('Client admin page', $html);
        $this->assertStringContainsString('Acme reports', $html);
        $this->assertGreaterThan(strpos($html, '<span>Customers</span>'), strpos($html, '<span>Acme reports</span>'), 'inserted after Customers');
        $this->assertLessThan(strpos($html, '<span>Discounts</span>'), strpos($html, '<span>Acme reports</span>'));
        $this->assertStringNotContainsString('href="'.route('admin.reports.index').'"', $html, 'core entry removed');
        $this->assertStringContainsString('Warranty claims', $this->get(route('admin.products.index'))->assertOk()->getContent());
    }

    public function test_client_settings_screens_and_extra_fields(): void
    {
        Commerce::settings('acme', ['label' => 'Acme loyalty', 'icon' => 'star', 'description' => 'Points for every order.'], [
            ['title' => 'Points', 'fields' => [
                ['key' => 'acme.points_per_pound', 'label' => 'Points per pound', 'type' => 'int', 'default' => 1, 'max' => 100],
                ['key' => 'acme.greeting', 'label' => 'Greeting', 'type' => 'text', 'default' => 'Hello'],
            ]],
        ]);
        Commerce::settings('acme-secret', ['label' => 'Acme admin only', 'admin' => true], [['title' => 'Keys', 'fields' => [['key' => 'acme.token', 'label' => 'Token', 'type' => 'text']]]]);
        Commerce::settingsFields('checkout', ['title' => 'Trade', 'fields' => [['key' => 'acme.min_order', 'label' => 'Trade minimum order', 'type' => 'money']]]);

        $this->assertContains('acme', StoreSettings::formGroups());
        $this->assertSame('system', array_key_last(StoreSettings::groups()), 'client screens go before System');

        $this->actingAs($this->neutralAdmin());
        $this->assertStringContainsString('Acme loyalty', $this->get(route('admin.settings.index'))->assertOk()->getContent());
        $html = $this->get(route('admin.settings.edit', 'acme'))->assertOk()->getContent();
        $this->assertStringContainsString('name="acme__points_per_pound"', $html);
        $this->put(route('admin.settings.update', 'acme'), ['acme__points_per_pound' => '5', 'acme__greeting' => 'Hi there'])
            ->assertRedirect(route('admin.settings.edit', 'acme'));
        $this->assertSame('5', (string) setting('acme.points_per_pound'));
        $this->assertSame('Hi there', setting('acme.greeting'));
        $this->put(route('admin.settings.update', 'acme'), ['acme__points_per_pound' => '500'])->assertSessionHasErrors('acme__points_per_pound');

        $this->assertStringContainsString('Trade minimum order', $this->get(route('admin.settings.edit', 'checkout'))->assertOk()->getContent());
        $this->get(route('admin.settings.edit', 'no-such-group'))->assertNotFound();

        $manager = \Pine\Commerce\Commerce::userModel()::forceCreate(['name' => 'Manny', 'email' => 'manny@example.test', 'password' => 'Password12345', 'role' => 'manager', 'is_active' => true]);
        $this->actingAs($manager);
        $this->get(route('admin.settings.edit', 'acme'))->assertOk();
        $this->get(route('admin.settings.edit', 'acme-secret'))->assertForbidden();
    }

    public function test_dashboard_widgets(): void
    {
        Commerce::dashboardWidget('greeting', ['title' => 'Greeting', 'view' => 'fixture::widget', 'data' => fn () => ['message' => 'Hello from a widget'], 'sort' => 10]);
        Commerce::dashboardWidget('trade', Fixtures\TradeWidget::class);
        Commerce::dashboardWidget('reviews-only', ['title' => 'Reviews widget', 'view' => 'fixture::widget', 'data' => ['message' => 'Needs reviews'], 'feature' => 'reviews']);
        Commerce::dashboardWidget('broken', ['title' => 'Broken', 'view' => 'fixture::widget', 'data' => fn () => throw new \RuntimeException('boom')]);

        $this->actingAs($this->neutralAdmin());
        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Hello from a widget', $html);
        $this->assertStringContainsString('Trade widget for owner@shop.example.test', $html);
        $this->assertStringContainsString('Needs reviews', $html);
        $this->assertStringNotContainsString('data-dashboard-widget="broken"', $html, 'a failing widget is left out');

        config(['commerce.features.reviews' => false]);
        $this->assertStringNotContainsString('Needs reviews', $this->get(route('admin.dashboard'))->assertOk()->getContent());

        $manager = \Pine\Commerce\Commerce::userModel()::forceCreate(['name' => 'Manny', 'email' => 'manny@example.test', 'password' => 'Password12345', 'role' => 'manager', 'is_active' => true]);
        $this->actingAs($manager);
        $this->assertStringNotContainsString('Trade widget', $this->get(route('admin.dashboard'))->assertOk()->getContent(), 'visible() = administrators only');

        Commerce::removeDashboardWidget('greeting');
        $this->assertArrayNotHasKey('greeting', app(ExtensionRegistry::class)->dashboardWidgets());
        $this->assertStringNotContainsString('Hello from a widget', $this->get(route('admin.dashboard'))->assertOk()->getContent());
    }

    public function test_client_admin_routes_are_staff_only(): void
    {
        Commerce::adminRoutes(function () {
            Route::post('acme-action', fn () => 'done')->name('acme.action');
        });
        $customer = \Pine\Commerce\Commerce::userModel()::forceCreate(['name' => 'Cus', 'email' => 'cus@example.test', 'password' => 'Password12345', 'role' => 'customer', 'is_active' => true]);
        $route = app('router')->getRoutes()->getByName('admin.acme.action');
        $this->assertContains('web', $route->gatherMiddleware(), 'web group = sessions + CSRF');
        $this->actingAs($customer);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post(route('admin.acme.action'))->assertForbidden();
    }

    public function test_feature_helper_and_order_placed_hook(): void
    {
        $this->assertTrue(Commerce::feature('coupons'));
        config(['commerce.features.coupons' => false]);
        $this->assertFalse(Commerce::feature('coupons'));

        [, , $order] = $this->catalogue();
        $placed = [];
        Commerce::onOrderPlaced(function ($o) use (&$placed) {
            $placed[] = $o->number;
        });
        event(new \Pine\Commerce\Events\OrderPlaced($order));
        $this->assertSame([$order->number], $placed);
    }

    // ------------------------------------------------------------------ content

    public function test_page_templates_reach_the_page_builder_and_the_storefront(): void
    {
        Commerce::pageTemplate('landing', ['label' => 'Landing page', 'help' => 'Campaign page', 'view' => 'fixture::landing'], ['headline' => 'text']);
        $this->assertSame('Landing page', PageBlocks::templates()['landing']['label']);
        $this->assertSame(['headline' => 'text'], PageBlocks::schema('landing'));

        $page = Page::forceCreate(['title' => 'Spring sale', 'slug' => 'spring-sale', 'path' => 'spring-sale', 'template' => 'landing',
            'status' => 'published', 'content' => '', 'blocks' => ['headline' => 'Big spring savings']]);
        $this->assertStringContainsString('<h1 class="landing-headline">Big spring savings</h1>', $this->get($page->url)->assertOk()->getContent());

        $this->actingAs($this->neutralAdmin());
        $this->assertStringContainsString('Landing page', $this->get(route('admin.pages.edit', $page))->assertOk()->getContent());
    }

    public function test_shortcodes_and_menu_locations(): void
    {
        Commerce::shortcode('store_hours', fn (array $atts) => '<p class="hours">Open '.e($atts['days'] ?? 'daily').'</p>');
        Commerce::shortcodeAlias('opening_times', 'store_hours');
        $html = \Pine\Commerce\View\Components\PageContent::process('<p>[store_hours days="Mon-Fri"]</p><p>[opening_times]</p>');
        $this->assertStringContainsString('<p class="hours">Open Mon-Fri</p>', $html);
        $this->assertStringContainsString('<p class="hours">Open daily</p>', $html);

        Commerce::menuLocation('top_bar', 'Top bar links');
        $this->assertSame('Top bar links', MenuController::locations()['top_bar']['label']);
        $this->assertArrayHasKey('mega', MenuController::locations());
    }

    // ------------------------------------------------------------------ catalogue

    public function test_presenter_methods_facet_sorter_and_presenter_validation(): void
    {
        [$product] = $this->catalogue();
        Commerce::presenterMethod('deliveryPromise', fn ($product) => 'Ships tomorrow: '.$product->name);
        $presenter = commerce_presenter();
        $this->assertSame('Ships tomorrow: Classic Linen Shirt', $presenter::deliveryPromise($product));

        Commerce::facetSorter(\Pine\Commerce\Services\Catalog\DefaultFacetSorter::class);
        $this->assertInstanceOf(\Pine\Commerce\Services\Catalog\DefaultFacetSorter::class, \Pine\Commerce\Services\Catalog\Facets::sorter());

        $this->expectException(InvalidArgumentException::class);
        Commerce::presenter(\stdClass::class);
    }

    // ------------------------------------------------------------------ orders

    public function test_order_status_hooks_and_order_email_replacement(): void
    {
        [, , $order] = $this->catalogue();
        $seen = [];
        Commerce::onOrderStatus('completed', function ($o, $from, $to) use (&$seen) {
            $seen[] = "{$o->number}:{$from}>{$to}";
        });
        Commerce::orderEmail('customer_completed', Fixtures\FixtureOrderMail::class);
        $this->assertInstanceOf(Fixtures\FixtureOrderMail::class, SendOrderStatusEmails::mail('customer_completed', CustomerCompletedOrder::class, $order));
        $this->assertInstanceOf(\Pine\Commerce\Mail\CustomerProcessingOrder::class, SendOrderStatusEmails::mail('customer_processing', \Pine\Commerce\Mail\CustomerProcessingOrder::class, $order));

        Mail::fake();
        event(new OrderStatusChanged($order, 'processing', 'on-hold'));
        event(new OrderStatusChanged($order, 'processing', 'completed'));
        $this->assertSame([$order->number.':processing>completed'], $seen);
        Mail::assertSent(Fixtures\FixtureOrderMail::class);
        Mail::assertNotSent(CustomerCompletedOrder::class);

        $this->expectException(InvalidArgumentException::class);
        Commerce::orderEmail('no_such_email', Fixtures\FixtureOrderMail::class);
    }

    // ------------------------------------------------------------------ importer

    public function test_importer_adapters_and_steps(): void
    {
        $before = count(app(ExtensionRegistry::class)->importAdapters());
        Commerce::importAdapter(\Pine\Commerce\Import\Adapters\ProductTags::class);
        $this->assertCount($before + 1, app(ExtensionRegistry::class)->importAdapters());

        Commerce::importStep(Fixtures\FixtureStep::class);
        $keys = array_map(fn ($step) => $step->key(), ImportWordPressCommand::pipeline(new AdapterRegistry([], []))->steps());
        $this->assertContains('extras.acme-loyalty', $keys);
        $this->assertContains('catalog.products', $keys, 'core steps stay');

        $this->expectException(InvalidArgumentException::class);
        Commerce::importAdapter(\stdClass::class);
    }
}
