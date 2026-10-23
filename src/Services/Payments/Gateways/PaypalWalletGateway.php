<?php

namespace Pine\Commerce\Services\Payments\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\PaymentResult;

/**
 * Google Pay / Apple Pay through the shop's PayPal account (PayPal JS SDK components "googlepay" / "applepay"), with
 * PayPal's keys (Admin › Settings › Payments › PayPal). The checkout shows the method only once the browser and the
 * PayPal account can pay this way (js/checkout.js asks PayPal and Google / Apple), and swaps "Place order" for the
 * wallet's own button.
 *
 * Flow: the customer authorises in the wallet sheet → the order is placed over AJAX → process() creates the PayPal
 * order and hands its id back (PaymentResult::action) → the browser confirms it with the wallet's payment data
 * (paypal.Googlepay().confirmOrder / paypal.Applepay().confirmOrder, plus 3-D Secure when PayPal asks) → the return
 * URL captures it (PaypalGateway::handleReturn) exactly as for PayPal. Refunds and /webhooks/paypal work as for PayPal.
 */
abstract class PaypalWalletGateway extends PaypalGateway
{
    use SharesPaypalKeys;

    /** "googlepay" | "applepay": the SDK component and the checkout's wallet button. */
    abstract public function wallet(): string;

    public function description(): ?string
    {
        return trim((string) $this->setting('description')) ?: null;
    }

    /** Shown at checkout only after the browser confirms it can pay this way (theme payment-methods partial). */
    public function browserWallet(): ?string
    {
        return $this->wallet();
    }

    public function icons(): string
    {
        return \Pine\Commerce\Theme\Storefront::paymentIcons([$this->wallet()]);
    }

    /** No payment_source: the wallet supplies it when the browser confirms the order. */
    protected function paymentSource(Order $order): array
    {
        return [];
    }

    public function process(Order $order, Request $request): PaymentResult
    {
        $response = $this->createOrder($order);
        if ($response === null) {
            return PaymentResult::failure('Sorry, we could not connect to PayPal. Please try again or choose another payment method.');
        }
        $id = (string) $response->json('id');
        if (! $response->successful() || ! preg_match('/^[A-Z0-9]{8,40}$/', $id)) {
            Log::warning('PayPal create order ('.$this->wallet().') rejected for '.$order->number.': '.$response->body());

            return PaymentResult::failure('Sorry, '.$this->defaultTitle().' could not start your payment. Please try again or choose another payment method.');
        }
        $this->recordStarted($order, $id);

        return PaymentResult::action([
            'wallet' => $this->wallet(),
            'paypal_order_id' => $id,
            'return_url' => route('checkout.payment.return', ['gateway' => $this->code(), 'order' => $order->number, 'key' => $order->order_key, 'token' => $id]),
        ]);
    }

    /** The wallet's own script (Google Pay API / Apple Pay button), loaded next to PayPal's SDK. */
    abstract public function walletScript(): string;

    /**
     * What the checkout needs for the wallets among $gateways (null when none is offered): one PayPal SDK URL with
     * every wallet component, the wallets' own scripts, and the method code of each wallet.
     *
     * @param  array<string, \Pine\Commerce\Contracts\PaymentGateway>  $gateways
     * @return array{methods: array<string, string>, sandbox: bool, currency: string, country: string, storeName: string, scripts: list<string>}|null
     */
    public static function checkoutConfig(array $gateways): ?array
    {
        $wallets = array_filter($gateways, fn ($gateway) => $gateway instanceof self);
        if (! $wallets) {
            return null;
        }
        $currency = strtoupper((string) config('commerce.currency.code', 'GBP'));
        $methods = $scripts = [];
        foreach ($wallets as $code => $wallet) {
            $methods[$wallet->wallet()] = (string) $code;
            $scripts[] = $wallet->walletScript();
        }
        $first = reset($wallets);
        array_unshift($scripts, 'https://www.paypal.com/sdk/js?'.http_build_query([
            'client-id' => (string) $first->clientId(),
            'components' => implode(',', array_keys($methods)),
            'currency' => $currency,
            'intent' => 'capture',
        ]));

        return [
            'methods' => $methods,
            'sandbox' => $first->isSandbox(),
            'currency' => $currency,
            'country' => \Pine\Commerce\Services\Checkout\CheckoutService::defaultCountry(),
            'storeName' => (string) setting('store.name', config('app.name')),
            'scripts' => $scripts,
        ];
    }
}
