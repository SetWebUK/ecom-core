<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Payments\Gateways\PaypalApplePayGateway;
use Pine\Commerce\Services\Payments\Gateways\PaypalGooglePayGateway;
use Pine\Commerce\Services\Payments\Gateways\PaypalWalletGateway;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/** Google Pay / Apple Pay through PayPal: PayPal's keys, the order created for the browser to confirm, then captured. */
class PaypalWalletGatewayTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Setting::set('payments.paypal.client_id', 'AbCdEfGhIjKlMnOpQrStUvWxYz012345', 'payments');
        Setting::set('payments.paypal.secret', Crypt::encryptString('SeCrEtSeCrEtSeCrEtSeCrEt0123'), 'payments');
        Setting::set('payments.paypal.sandbox', true, 'payments');
        Setting::set('payments.paypal.enabled', true, 'payments');
        Cart::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_both_wallets_use_paypals_keys_and_are_off_until_switched_on(): void
    {
        $gateways = app(PaymentManager::class)->all();
        $this->assertInstanceOf(PaypalGooglePayGateway::class, $gateways['paypal_googlepay']);
        $this->assertInstanceOf(PaypalApplePayGateway::class, $gateways['paypal_applepay']);
        $this->assertFalse($gateways['paypal_googlepay']->isAvailable(), 'off by default');
        $this->assertNull(PaypalWalletGateway::checkoutConfig(app(PaymentManager::class)->available()));

        Setting::set('payments.paypal_googlepay.enabled', true, 'payments');
        Setting::set('payments.paypal_applepay.enabled', true, 'payments');
        $google = $gateways['paypal_googlepay'];
        $this->assertTrue($google->isAvailable());
        $this->assertTrue($google->isSandbox());
        $this->assertSame('Google Pay', $google->title());
        $this->assertSame('googlepay', $google->browserWallet());
        $this->assertSame('Apple Pay', $gateways['paypal_applepay']->title());

        $config = PaypalWalletGateway::checkoutConfig(app(PaymentManager::class)->available());
        $this->assertSame(['googlepay' => 'paypal_googlepay', 'applepay' => 'paypal_applepay'], $config['methods']);
        $this->assertTrue($config['sandbox']);
        $this->assertSame('GBP', $config['currency']);
        $this->assertStringStartsWith('https://www.paypal.com/sdk/js?client-id=AbCdEfGhIjKlMnOpQrStUvWxYz012345&components=googlepay%2Capplepay&currency=GBP&intent=capture', $config['scripts'][0]);
        $this->assertContains('https://pay.google.com/gp/p/js/pay.js', $config['scripts']);

        Setting::set('payments.paypal.client_id', '', 'payments');
        $this->assertFalse($google->isAvailable(), 'no PayPal keys, no wallets');
    }

    public function test_the_order_is_created_for_the_browser_and_captured_on_return(): void
    {
        Setting::set('payments.paypal_googlepay.enabled', true, 'payments');
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'pending', 'payment_method' => 'paypal_googlepay'])->save();
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/checkout/orders' => Http::response(['id' => 'WALLETORDER123', 'status' => 'CREATED', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=WALLETORDER123']]], 201),
            '*/v2/checkout/orders/WALLETORDER123/capture' => Http::response(['id' => 'WALLETORDER123', 'status' => 'COMPLETED', 'purchase_units' => [[
                'custom_id' => (string) $order->id,
                'payments' => ['captures' => [['id' => 'CAPTURE98765', 'status' => 'COMPLETED', 'amount' => ['value' => (string) $order->total]]]],
            ]]], 201),
        ]);

        $result = app(PaymentManager::class)->get('paypal_googlepay')->process($order, Request::create('/checkout/', 'POST'));

        $this->assertSame('action', $result->status);
        $this->assertSame('googlepay', $result->data['wallet']);
        $this->assertSame('WALLETORDER123', $result->data['paypal_order_id']);
        $this->assertStringContainsString('token=WALLETORDER123', $result->data['return_url']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/checkout/orders')
            && ! isset($request['payment_source']) && $request['intent'] === 'CAPTURE'
            && $request['purchase_units'][0]['amount']['value'] === number_format((float) $order->total, 2, '.', ''));
        $this->assertSame('paypal_googlepay', $order->payments()->sole()->gateway);

        // the browser confirmed it with Google Pay; the return URL, asked over AJAX, captures and answers JSON
        $this->getJson($result->data['return_url'])
            ->assertOk()->assertJson(['result' => 'success', 'redirect' => $order->fresh()->view_url]);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('CAPTURE98765', $order->fresh()->transaction_id);
    }

    public function test_a_declined_capture_answers_json_with_the_reason(): void
    {
        Setting::set('payments.paypal_applepay.enabled', true, 'payments');
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'pending', 'payment_method' => 'paypal_applepay'])->save();
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/checkout/orders' => Http::response(['id' => 'APPLEORDER456', 'status' => 'CREATED'], 201),
            '*/v2/checkout/orders/APPLEORDER456/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422),
        ]);

        $result = app(PaymentManager::class)->get('paypal_applepay')->process($order, Request::create('/checkout/', 'POST'));
        $this->assertSame('applepay', $result->data['wallet']);

        $this->getJson($result->data['return_url'])->assertStatus(422)
            ->assertJson(['result' => 'failure'])
            ->assertJsonPath('messages.0', 'Your PayPal payment method was declined. Please try again with a different funding source.');
        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_paypals_webhook_completes_a_wallet_payment_under_its_own_method(): void
    {
        Setting::set('payments.paypal_applepay.enabled', true, 'payments');
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'pending', 'payment_method' => 'paypal_applepay'])->save();
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/checkout/orders' => Http::response(['id' => 'APPLEORDER789', 'status' => 'CREATED'], 201),
            '*/v2/payments/captures/CAPTURE55555' => Http::response(['id' => 'CAPTURE55555', 'status' => 'COMPLETED', 'custom_id' => (string) $order->id,
                'amount' => ['value' => (string) $order->total], 'supplementary_data' => ['related_ids' => ['order_id' => 'APPLEORDER789']]]),
        ]);
        app(PaymentManager::class)->get('paypal_applepay')->process($order, Request::create('/checkout/', 'POST'));

        $this->postJson(route('webhooks.payment', 'paypal'), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTURE55555']])->assertOk();

        $payment = $order->payments()->where('status', 'succeeded')->sole();
        $this->assertSame('paypal_applepay', $payment->gateway);
        $this->assertSame('processing', $order->fresh()->status);
        $this->postJson(route('webhooks.payment', 'paypal_applepay'), [])->assertNotFound();
    }

    public function test_the_checkout_offers_the_wallets_hidden_until_the_browser_confirms_them(): void
    {
        Setting::set('payments.paypal_googlepay.enabled', true, 'payments');
        Setting::set('payments.paypal_applepay.enabled', true, 'payments');
        $product = Product::forceCreate(['name' => 'Tee', 'slug' => 'tee', 'sku' => 'TEE', 'type' => 'simple', 'status' => 'published',
            'regular_price' => 20, 'price' => 20, 'manage_stock' => false, 'stock_status' => 'instock']);
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();

        $html = $this->get(url('checkout'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-gateway="paypal_googlepay"\s+data-wallet="googlepay" hidden/', $html);
        $this->assertMatchesRegularExpression('/data-gateway="paypal_applepay"\s+data-wallet="applepay" hidden/', $html);
        $this->assertStringContainsString('<script src="https://www.paypal.com/sdk/js?client-id=AbCdEfGhIjKlMnOpQrStUvWxYz012345&amp;components=googlepay%2Capplepay', $html);
        $this->assertStringContainsString('<script src="https://pay.google.com/gp/p/js/pay.js" defer></script>', $html);
        preg_match('#<script type="application/json" id="checkout-config">(.*?)</script>#s', $html, $m);
        $config = json_decode($m[1], true);
        $this->assertSame(['googlepay' => 'paypal_googlepay', 'applepay' => 'paypal_applepay'], $config['wallets']['methods']);
        $this->assertArrayNotHasKey('scripts', $config['wallets']);
        $this->assertMatchesRegularExpression('/data-gateway="paypal"\s*>/', $html, 'PayPal itself shows straight away');
    }
}
