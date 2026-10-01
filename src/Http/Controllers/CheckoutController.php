<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Mail\CustomerNewAccount;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\CartException;
use Pine\Commerce\Services\Checkout\CheckoutFragments;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Pine\Commerce\Contracts\PaymentGateway;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Services\Payments\Gateways\BacsGateway;
use Pine\Commerce\Services\Payments\Gateways\StripeGateway;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Services\Payments\PaymentResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * CheckoutWC-style one page checkout (/checkout/), order received (/checkout/order-received/{number}/?key=)
 * and order pay (/checkout/order-pay/{number}/?key=) pages, plus the payment provider return/cancel URLs.
 */
class CheckoutController extends Controller
{
    /** Session key: ids of the orders this visitor placed, paid for or verified the billing email of (OrderAccess). */
    public const SESSION_ORDERS = \Pine\Commerce\Services\Checkout\OrderAccess::SESSION_KEY;

    public const LOGIN_TO_PAY = 'Please log in to your account below to continue to the payment form.';

    public const EMAIL_NOT_VERIFIED = 'We were unable to verify the email address you provided. Please try again.';

    public const NO_GATEWAYS = 'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.';

    public function __construct(protected Cart $cart, protected CheckoutService $checkout, protected PaymentManager $payments)
    {
    }

    // ------------------------------------------------------------------ checkout page

    public function show(Request $request)
    {
        $this->closePaidAwaitingOrder($request);
        CheckoutService::cancelStaleOrders();
        if ($this->cart->isEmpty()) {
            return redirect()->to(url('basket'));
        }
        if ($signIn = $this->guestNotAllowed($request)) {
            return $signIn;
        }
        $values = array_merge($this->checkout->prefill(), array_filter((array) session()->getOldInput(), 'is_scalar'));
        // a returning customer's saved address picks the shipping zone / tax rates before they type anything
        if (! ($this->cart->destination()['shipping'] ?? null) && ($values['shipping_postcode'] ?? '') !== '') {
            $this->cart->setDestination(CheckoutService::destinationFrom($values, 'shipping'));
        }
        // coupons are re-validated (and dropped with a notice) before totals are shown
        $this->cart->coupons();
        $totals = $this->cart->totals();
        $gateways = $this->payments->available();

        return response(theme_view('checkout.show', [
            'cart' => $this->cart,
            'totals' => $totals,
            'gateways' => $gateways,
            'countries' => CheckoutService::countries(),
            'values' => $values,
            'termsUrl' => $this->checkout->termsUrl(),
            'privacyUrl' => $this->checkout->privacyUrl(),
            'notices' => $this->cart->pullNotices(),
            'error' => session('checkout_error'),
            'stripe' => $this->stripeConfig($gateways, $totals['total']),
            'user' => $request->user(),
            'noGatewaysMessage' => self::NO_GATEWAYS,
        ]))->header('Cache-Control', 'no-store, private');
    }

    /**
     * POST /checkout/update - shipping method / email / address changed: refreshed summary + totals. Address fields
     * (shipping_country, shipping_postcode, shipping_state, shipping_city, bill_to_different_address, billing_*) pick
     * the shipping zone and the tax rates.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'shipping_method' => ['nullable', 'string', 'max:100'],
            'billing_email' => ['nullable', 'string', 'max:190'],
            'shipping_country' => ['nullable', 'string', 'max:2'],
            'shipping_postcode' => ['nullable', 'string', 'max:20'],
            'shipping_state' => ['nullable', 'string', 'max:100'],
            'shipping_city' => ['nullable', 'string', 'max:100'],
            'billing_country' => ['nullable', 'string', 'max:2'],
            'billing_postcode' => ['nullable', 'string', 'max:20'],
            'billing_state' => ['nullable', 'string', 'max:100'],
            'billing_city' => ['nullable', 'string', 'max:100'],
            'bill_to_different_address' => ['nullable', 'string', 'max:40'],
        ]);
        if ($request->hasAny(['shipping_country', 'shipping_postcode'])) {
            $input = $request->only(['shipping_country', 'shipping_postcode', 'shipping_state', 'shipping_city',
                'billing_country', 'billing_postcode', 'billing_state', 'billing_city']);
            $input['shipping_country'] = (string) ($input['shipping_country'] ?? '') ?: CheckoutService::defaultCountry();
            if (array_key_exists(strtoupper($input['shipping_country']), CheckoutService::countries())) {
                $different = $request->input('bill_to_different_address') === 'different_from_shipping';
                $this->cart->setDestination(CheckoutService::destinationFrom($input, 'shipping'),
                    $different ? CheckoutService::destinationFrom($input, 'billing') : null);
            }
        }
        if ($request->filled('shipping_method')) {
            $this->cart->setShippingMethod((string) $request->input('shipping_method'));
        }
        if ($request->filled('billing_email') && filter_var($request->input('billing_email'), FILTER_VALIDATE_EMAIL)) {
            $this->cart->setEmail((string) $request->input('billing_email'));
        }
        $this->cart->coupons();

        return response()->json(CheckoutFragments::checkout($this->cart, $this->cart->pullNotices()))
            ->header('Cache-Control', 'no-store, private');
    }

    /** POST /checkout/ - validate, create the order, start the payment. */
    public function placeOrder(Request $request)
    {
        if ($this->cart->isEmpty()) {
            // the last lines may just have been removed (sold out meanwhile): say why, and keep the
            // messages for the side cart on the page the customer is sent to
            $notices = $this->cart->pullNotices();
            foreach ($notices as $notice) {
                $this->cart->notice($notice);
            }

            return $this->failure($request, $notices ? implode(' ', $notices) : 'Sorry, your session has expired. Please return to the shop and try again.', [], url('basket'));
        }

        if (! $request->user() && ! Features::enabled('guest_checkout', false)) {
            return $this->failure($request, 'Please sign in or create an account to place your order.', [], route('account', ['redirect' => '/checkout/']));
        }

        $shippingMethod = $request->input('shipping_method');
        if (is_array($shippingMethod)) {
            $shippingMethod = reset($shippingMethod);
        }
        if (is_string($shippingMethod) && $shippingMethod !== '') {
            $this->cart->setShippingMethod($shippingMethod);
        }
        $email = $this->param($request, 'billing_email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->cart->setEmail($email);
        }
        // coupons that stopped being valid for this customer are removed - show why and stop
        $this->cart->coupons();
        if ($notices = $this->cart->pullNotices()) {
            return $this->failure($request, implode(' ', $notices), [], null, true);
        }

        $totals = $this->cart->totals();
        $gateways = $totals['needs_payment'] ? $this->payments->available() : [];
        if ($totals['needs_payment'] && ! $gateways) {
            return $this->failure($request, self::NO_GATEWAYS);
        }

        try {
            $data = $this->checkout->validate($request, $totals['needs_payment'], $gateways);
        } catch (ValidationException $e) {
            if ($this->wantsJson($request)) {
                return response()->json([
                    'result' => 'failure',
                    'messages' => array_values(array_unique(array_merge(...array_values($e->errors())))),
                    'errors' => $e->errors(),
                ], 422);
            }
            throw $e;
        }

        $lock = Cache::lock('checkout-basket-'.$this->cart->model()->id, 30);
        if (! $lock->get()) {
            return $this->failure($request, 'We are already processing your order. Please wait a moment.');
        }

        try {
            /** @var PaymentGateway|null $gateway */
            $gateway = $totals['needs_payment'] ? $gateways[$data['payment_method']] : null;
            if ($data['create_account'] && ! $request->user()) {
                $user = $this->checkout->createAccount($data);
                Auth::login($user);
                $this->sendWelcome($user);
            }
            $order = $this->checkout->placeOrder($data, $request, $gateway?->title());
            $this->rememberOrder($request, $order);
        } catch (CartException $e) {
            return $this->failure($request, $e->getMessage(), [], null, true);
        } finally {
            $lock->release();
        }

        if ((float) $order->total <= 0) {
            CheckoutService::completeFreeOrder($order);

            return $this->success($request, $order->view_url);
        }

        return $this->startPayment($request, $order, $gateway);
    }

    // ------------------------------------------------------------------ order received / pay

    /** GET /checkout/order-received/{number}/?key= */
    public function thankYou(Request $request, string $order)
    {
        $model = $this->findOrder($order, $this->param($request, 'key'));
        if (! $model) {
            abort(404);
        }
        // like WooCommerce 8.x: the key alone is not enough once the order left this browser - ask for its billing email
        if (! $this->mayViewOrder($request, $model)) {
            return response(theme_view('checkout.verify-email', [
                'order' => $model,
                'action' => route('checkout.thankyou.verify', ['order' => $model->number]),
                'key' => $model->order_key,
                'error' => session('checkout_error'),
            ]))->header('Cache-Control', 'no-store, private');
        }
        if (in_array($model->status, ['processing', 'completed', 'on-hold'], true)) {
            CheckoutService::closeBasket($model);
        }
        $model->load(['items.product.images', 'items.variation', 'notes' => fn ($q) => $q->where('is_customer_note', true)]);
        $bacs = $model->payment_method === 'bacs' ? $this->payments->get('bacs') : null;

        return response(theme_view('checkout.thankyou', [
            'order' => $model,
            'bacs' => $bacs instanceof BacsGateway ? $bacs : null,
            'error' => session('checkout_error'),
            'canPay' => $this->canPay($request, $model),
        ]))->header('Cache-Control', 'no-store, private');
    }

    /** POST /checkout/order-received/{number}/ - the billing email check of the order-received page. */
    public function verifyOrderEmail(Request $request, string $order)
    {
        $model = $this->findOrder($order, $this->param($request, 'key'));
        if (! $model) {
            abort(404);
        }
        $email = mb_strtolower(trim($this->param($request, 'email')));
        if ($email === '' || ! hash_equals(mb_strtolower(trim((string) $model->email)), $email)) {
            return redirect()->to($model->view_url)->with('checkout_error', self::EMAIL_NOT_VERIFIED);
        }
        $this->rememberOrder($request, $model);

        return redirect()->to($model->view_url);
    }

    /** GET /checkout/order-pay/{number}/?key= - retry payment for a pending / failed order. */
    public function pay(Request $request, string $order)
    {
        $model = $this->findOrder($order, $this->param($request, 'key'));
        if (! $model) {
            abort(404);
        }
        if ($login = $this->loginToPay($request, $model)) {
            return $login;
        }
        $gateways = $this->payments->available();
        $model->load(['items.product.images', 'items.variation']);

        return response(theme_view('checkout.pay', [
            'order' => $model,
            'gateways' => $gateways,
            'problem' => $this->payProblem($request, $model),
            'error' => session('checkout_error'),
            'termsUrl' => $this->checkout->termsUrl(),
            'stripe' => $this->stripeConfig($gateways, (float) $model->total),
            'noGatewaysMessage' => self::NO_GATEWAYS,
        ]))->header('Cache-Control', 'no-store, private');
    }

    /** POST /checkout/order-pay/{number}/ */
    public function payOrder(Request $request, string $order)
    {
        $model = $this->findOrder($order, $this->param($request, 'key'));
        if (! $model) {
            abort(404);
        }
        if ($login = $this->loginToPay($request, $model)) {
            return $this->wantsJson($request) ? $this->failure($request, self::LOGIN_TO_PAY, [], $login->getTargetUrl()) : $login;
        }
        if ($problem = $this->payProblem($request, $model)) {
            return $this->failure($request, $problem);
        }
        $gateways = $this->payments->available();
        if (! $gateways) {
            return $this->failure($request, self::NO_GATEWAYS);
        }
        $rules = ['payment_method' => ['required', 'string', 'in:'.implode(',', array_keys($gateways))]];
        if ($this->checkout->termsUrl()) {
            $rules['terms'] = ['accepted'];
        }
        $request->validate($rules, [
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'Invalid payment method.',
            'terms.accepted' => 'Please read and accept the terms and conditions to proceed with your order.',
        ]);
        $gateway = $gateways[$request->input('payment_method')];
        $this->rememberOrder($request, $model); // they hold the key and are paying: no email check on the order-received page

        try {
            $this->checkout->reclaimStock($model);
        } catch (CartException $e) {
            return $this->failure($request, $e->getMessage());
        }
        $model->forceFill(['payment_method' => $gateway->code(), 'payment_method_title' => $gateway->title()])->save();
        if ($model->status === 'failed') {
            $model->updateStatus('pending', 'Customer is retrying payment.');
        }

        return $this->startPayment($request, $model->refresh(), $gateway);
    }

    /** GET /checkout/payment/{gateway}/return?order=&key= - customer back from Stripe 3-D Secure / PayPal. */
    public function paymentReturn(Request $request, string $gateway)
    {
        $order = $this->findOrder($this->param($request, 'order'), $this->param($request, 'key'));
        $handler = $this->payments->get($gateway);
        if (! $order || ! $handler || ! $handler->isConfigured()) {
            abort(404);
        }

        $result = $handler->handleReturn($order, $request);
        $order->refresh();
        if ($result->status === 'success') {
            if (in_array($order->status, ['processing', 'completed', 'on-hold'], true)) {
                CheckoutService::closeBasket($order);
            }

            return redirect()->to($result->redirect ?: $order->view_url);
        }

        return redirect()->to($result->redirect ?: $this->payUrl($order))->with('checkout_error', $result->message);
    }

    /** GET /checkout/payment/{gateway}/cancel?order=&key= - customer backed out of PayPal. */
    public function paymentCancel(Request $request, string $gateway)
    {
        $order = $this->findOrder($this->param($request, 'order'), $this->param($request, 'key'));
        if (! $order) {
            abort(404);
        }
        if (! $order->isPaid()) {
            $order->addNote(sprintf('Customer cancelled the %s payment and returned to the store.', $this->payments->get($gateway)?->title() ?? $gateway));
        }
        $message = 'Your payment was cancelled. You can try again or choose another payment method.';
        $inBasket = (int) ($order->meta['cart_id'] ?? 0) === (int) $this->cart->model()?->id && ! $this->cart->isEmpty();

        return redirect()->to($inBasket ? url('checkout') : $this->payUrl($order))->with('checkout_error', $message);
    }

    // ------------------------------------------------------------------ internals

    /** Feature "guest_checkout" off: guests sign in (or register) on My account first, then come back here. */
    protected function guestNotAllowed(Request $request)
    {
        if ($request->user() || Features::enabled('guest_checkout', false)) {
            return null;
        }

        return redirect()->to(route('account', ['redirect' => '/checkout/']));
    }

    protected function startPayment(Request $request, Order $order, PaymentGateway $gateway)
    {
        try {
            $result = $gateway->process($order, $request);
        } catch (\Throwable $e) {
            Log::error('Payment start failed for order '.$order->number.': '.$e->getMessage());
            $result = PaymentResult::failure('Sorry, we could not start your payment. Please try again or choose another payment method.');
        }
        $order->refresh();

        switch ($result->status) {
            case 'success':
                if (in_array($order->status, ['processing', 'completed', 'on-hold'], true)) {
                    CheckoutService::closeBasket($order);
                }

                return $this->success($request, $result->redirect ?: $order->view_url);

            case 'redirect':
                return $this->success($request, (string) $result->redirect);

            case 'action':
                if (! $this->wantsJson($request)) {
                    return redirect()->to($this->payUrl($order));
                }

                return response()->json(array_merge($result->data, [
                    'result' => 'action',
                    'order' => $order->number,
                    'pay_url' => $this->payUrl($order),
                ]));

            default:
                return $this->failure($request, (string) ($result->message ?: 'Payment failed. Please try again.'), [], $result->redirect);
        }
    }

    protected function success(Request $request, string $redirect)
    {
        return $this->wantsJson($request)
            ? response()->json(['result' => 'success', 'redirect' => $redirect])
            : redirect()->to($redirect);
    }

    protected function failure(Request $request, string $message, array $errors = [], ?string $redirect = null, bool $refresh = false)
    {
        if ($this->wantsJson($request)) {
            return response()->json(array_filter([
                'result' => 'failure',
                'messages' => [$message],
                'errors' => $errors ?: null,
                'redirect' => $redirect,
                'refresh' => $refresh ?: null,
            ]), 422);
        }

        return ($redirect ? redirect()->to($redirect) : redirect()->back())
            ->withInput($request->except(['account_password', '_token']))
            ->with('checkout_error', $message);
    }

    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    /** A query / form value as a string - array values (?key[]=...) count as missing instead of erroring. */
    protected function param(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    protected function findOrder(string $number, string $key): ?Order
    {
        if ($number === '' || $key === '' || strlen($key) > 64) {
            return null;
        }
        $order = Order::where('number', $number)->first();

        return $order && hash_equals((string) $order->order_key, $key) ? $order : null;
    }

    /**
     * A registered customer's order is paid for by that customer only: a visitor who is not signed in is sent to the
     * login form first and comes back here afterwards (WooCommerce behaviour). Guest orders keep working with the key.
     */
    protected function loginToPay(Request $request, Order $order): ?\Illuminate\Http\RedirectResponse
    {
        if (! $order->user_id || $request->user()) {
            return null;
        }
        $path = '/'.ltrim((string) parse_url($this->payUrl($order), PHP_URL_PATH), '/').'?key='.rawurlencode((string) $order->order_key);

        return redirect()->to(route('account', ['redirect' => $path]))->with('account_notice', self::LOGIN_TO_PAY);
    }

    /** May this visitor see the order with the key alone? (OrderAccess: placing session, its customer, staff) */
    protected function mayViewOrder(Request $request, Order $order): bool
    {
        return \Pine\Commerce\Services\Checkout\OrderAccess::mayView($request, $order);
    }

    protected function rememberOrder(Request $request, Order $order): void
    {
        \Pine\Commerce\Services\Checkout\OrderAccess::remember($request, $order);
    }

    protected function payUrl(Order $order): string
    {
        return route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]);
    }

    /** Why this order cannot be paid for right now (null = it can). */
    protected function payProblem(Request $request, Order $order): ?string
    {
        if (! in_array($order->status, ['pending', 'failed'], true)) {
            return sprintf('This order’s status is “%s”—it cannot be paid for. Please contact us if you need assistance.', $order->status_label);
        }
        if ($order->user_id && $request->user() && (int) $request->user()->id !== (int) $order->user_id) {
            return 'This order cannot be paid for. Please contact us if you need assistance.';
        }
        if ((float) $order->total <= 0) {
            return 'This order does not require payment.';
        }

        return null;
    }

    protected function canPay(Request $request, Order $order): bool
    {
        return $this->payProblem($request, $order) === null;
    }

    protected function stripeConfig(array $gateways, float $total): ?array
    {
        $stripe = $gateways['stripe'] ?? null;
        if (! $stripe instanceof StripeGateway) {
            return null;
        }

        return [
            'key' => $stripe->publicKey(),
            'amount' => max(1, (int) round($total * 100)),
            'currency' => 'gbp',
        ];
    }

    /** A webhook may have completed the awaiting order while the customer was away: empty their basket. */
    protected function closePaidAwaitingOrder(Request $request): void
    {
        $id = (int) $request->session()->get(CheckoutService::SESSION_AWAITING);
        if (! $id) {
            return;
        }
        $order = Order::find($id);
        if (! $order) {
            $request->session()->forget(CheckoutService::SESSION_AWAITING);

            return;
        }
        if (in_array($order->status, ['processing', 'completed', 'on-hold'], true)) {
            CheckoutService::closeBasket($order);
        }
    }

    protected function sendWelcome(User $user): void
    {
        try {
            Mail::to($user->email)->send(new CustomerNewAccount($user));
        } catch (\Throwable $e) {
            Log::warning('Welcome email failed for '.$user->email.': '.$e->getMessage());
        }
    }
}
