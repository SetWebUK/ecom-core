<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Payments\Gateways\PaypalCardGateway;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/** "Debit or credit card" through PayPal: PayPal's keys, PayPal's card form first, PayPal's webhook. */
class PaypalCardGatewayTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Setting::set('payments.paypal.client_id', 'AbCdEfGhIjKlMnOpQrStUvWxYz012345', 'payments');
        Setting::set('payments.paypal.secret', Crypt::encryptString('SeCrEtSeCrEtSeCrEtSeCrEt0123'), 'payments');
        Setting::set('payments.paypal.sandbox', true, 'payments');
        Setting::set('payments.paypal_card.enabled', true, 'payments');
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_it_uses_paypals_keys_and_is_offered_next_to_paypal(): void
    {
        $gateways = app(PaymentManager::class)->all();
        $card = $gateways['paypal_card'];

        $this->assertInstanceOf(PaypalCardGateway::class, $card);
        $this->assertTrue($card->isAvailable(), 'enabled, with the PayPal client id and secret');
        $this->assertTrue($card->isSandbox());
        $this->assertSame('Debit or credit card', $card->title());
        $this->assertStringContainsString('No PayPal account needed', (string) $card->description());

        Setting::set('payments.paypal.client_id', '', 'payments');
        $this->assertFalse($card->isAvailable(), 'no PayPal keys, no card payments');
    }

    public function test_the_payment_opens_on_paypals_card_form_and_the_paypal_webhook_completes_it(): void
    {
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'pending', 'payment_method' => 'paypal_card'])->save();
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/checkout/orders' => Http::response(['id' => 'PAYPALORDER123', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPALORDER123']]], 201),
            '*/v2/payments/captures/CAPTURE12345' => Http::response(['id' => 'CAPTURE12345', 'status' => 'COMPLETED', 'custom_id' => (string) $order->id,
                'amount' => ['value' => (string) $order->total], 'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPALORDER123']]]),
        ]);

        $result = app(PaymentManager::class)->get('paypal_card')->process($order, Request::create('/checkout/', 'POST'));

        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=PAYPALORDER123', $result->redirect);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/checkout/orders')
            && $request['payment_source']['paypal']['experience_context']['landing_page'] === 'GUEST_CHECKOUT'
            && str_contains($request['payment_source']['paypal']['experience_context']['return_url'], 'paypal_card'));
        $this->assertSame('paypal_card', $order->payments()->sole()->gateway);

        // PayPal's one webhook (/webhooks/paypal) completes it under the card method
        $this->postJson(route('webhooks.payment', 'paypal'), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTURE12345']])->assertOk();
        $this->assertSame('succeeded', $order->payments()->sole()->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
    }
}
