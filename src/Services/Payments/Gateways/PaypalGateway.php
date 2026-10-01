<?php

namespace Pine\Commerce\Services\Payments\Gateways;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\Gateway;
use Pine\Commerce\Services\Payments\PaymentException;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Services\Payments\PaymentResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayPal Checkout via the REST Orders v2 API: create order -> customer approves on PayPal -> capture on
 * return. Webhooks (PAYMENT.CAPTURE.COMPLETED / DENIED) are treated as hints only: the capture is re-read
 * from PayPal with our own credentials before the order is touched (and, when payments.paypal.webhook_id
 * is set, the webhook signature is verified with PayPal as well).
 *
 * Settings: payments.paypal.enabled, .title, .client_id, .secret (encrypted), .sandbox, [.webhook_id]
 * Webhook URL: /webhooks/paypal
 */
class PaypalGateway extends Gateway
{
    public function code(): string
    {
        return 'paypal';
    }

    protected function defaultTitle(): string
    {
        return 'PayPal';
    }

    public function description(): ?string
    {
        return parent::description() ?? 'You will be taken to PayPal to complete your payment securely.';
    }

    public function adminSettings(): array
    {
        return [
            'label' => 'PayPal',
            'icon' => 'wallet',
            'description' => 'Customers pay with their PayPal account (and Pay Later). Keys: developer.paypal.com › Apps & Credentials.',
            'fields' => [
                'enabled' => ['type' => 'bool', 'label' => 'Accept PayPal'],
                'title' => ['type' => 'text', 'label' => 'Name at checkout', 'default' => 'PayPal', 'wide' => true],
                'description' => ['type' => 'text', 'label' => 'Text under the name', 'default' => 'You will be taken to PayPal to complete your payment securely.', 'wide' => true],
                'sandbox' => ['type' => 'bool', 'label' => 'Sandbox (test) mode', 'help' => 'Use sandbox credentials – no real money is taken.'],
                'client_id' => ['type' => 'text', 'label' => 'Client ID', 'pattern' => '/^[A-Za-z0-9_-]{20,128}$/', 'mono' => true, 'wide' => true,
                    'message' => 'That doesn’t look like a PayPal client ID.'],
                'secret' => ['type' => 'secret', 'label' => 'Secret', 'pattern' => '/^[A-Za-z0-9_-]{20,128}$/', 'wide' => true,
                    'message' => 'That doesn’t look like a PayPal secret.'],
                'webhook_id' => ['type' => 'text', 'label' => 'Webhook ID', 'optional' => true, 'pattern' => '/^[A-Za-z0-9-]{5,64}$/', 'mono' => true,
                    'message' => 'That doesn’t look like a PayPal webhook ID.',
                    'help' => 'Optional. Lets the shop check that PayPal notifications are genuine.'],
            ],
            'webhook' => ['provider' => 'PayPal', 'where' => 'PayPal › Apps › Webhooks'],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== null && $this->secret('secret') !== null;
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function isSandbox(): bool
    {
        return $this->flag('sandbox');
    }

    /** Theme partial checkout.partials.paypal-icon (the default theme ships a neutral PayPal mark). */
    public function icons(): string
    {
        return theme_view('checkout.partials.paypal-icon')->render();
    }

    protected function clientId(): ?string
    {
        $id = trim((string) $this->setting('client_id'));

        return $id !== '' ? $id : null;
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    protected function accessToken(): string
    {
        $key = 'paypal.token.'.md5($this->baseUrl().$this->clientId());

        $token = Cache::get($key);
        if ($token) {
            return $token;
        }
        $response = Http::asForm()->timeout(20)
            ->withBasicAuth((string) $this->clientId(), (string) $this->secret('secret'))
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new PaymentException('Could not authenticate with PayPal ('.$response->status().').');
        }
        Cache::put($key, $response->json('access_token'), max(60, (int) $response->json('expires_in', 3600) - 120));

        return $response->json('access_token');
    }

    protected function api(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->asJson()->timeout(30)->baseUrl($this->baseUrl());
    }

    /** Order reference prefix for PayPal invoice / request ids (config commerce.orders.reference_prefix, e.g. "ORD"). */
    protected function reference(): string
    {
        return (string) (config('commerce.orders.reference_prefix') ?: 'ORD');
    }

    public function process(Order $order, Request $request): PaymentResult
    {
        $value = number_format((float) $order->total, 2, '.', '');
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $order->number,
                'custom_id' => (string) $order->id,
                'invoice_id' => $this->reference().'-'.$order->number.'-'.substr(md5($order->order_key.$value.now()->timestamp), 0, 6),
                'description' => mb_substr(setting('store.name', config('app.name')).' order '.$order->number, 0, 127),
                'amount' => ['currency_code' => $order->currency ?: 'GBP', 'value' => $value],
                'shipping' => [
                    'name' => ['full_name' => mb_substr($order->shipping_name ?: $order->billing_name, 0, 300)],
                    'address' => array_filter([
                        'address_line_1' => mb_substr((string) $order->shipping_address_1, 0, 300),
                        'address_line_2' => mb_substr((string) $order->shipping_address_2, 0, 300) ?: null,
                        'admin_area_2' => mb_substr((string) $order->shipping_city, 0, 120),
                        'admin_area_1' => mb_substr((string) $order->shipping_county, 0, 300) ?: null,
                        'postal_code' => (string) $order->shipping_postcode,
                        'country_code' => $order->shipping_country ?: 'GB',
                    ]),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'email_address' => $order->email,
                    'experience_context' => [
                        'brand_name' => mb_substr((string) setting('store.name', config('app.name')), 0, 127),
                        'locale' => 'en-GB',
                        'shipping_preference' => 'SET_PROVIDED_ADDRESS',
                        'user_action' => 'PAY_NOW',
                        'return_url' => route('checkout.payment.return', ['gateway' => $this->code(), 'order' => $order->number, 'key' => $order->order_key]),
                        'cancel_url' => route('checkout.payment.cancel', ['gateway' => $this->code(), 'order' => $order->number, 'key' => $order->order_key]),
                    ],
                ],
            ],
        ];

        try {
            $response = $this->api()->withHeaders(['PayPal-Request-Id' => strtolower($this->reference()).'-create-'.$order->id.'-'.md5(json_encode($payload))])
                ->post('/v2/checkout/orders', $payload);
        } catch (\Throwable $e) {
            Log::warning('PayPal create order failed for '.$order->number.': '.$e->getMessage());

            return PaymentResult::failure('Sorry, we could not connect to PayPal. Please try again or choose another payment method.');
        }

        $approve = collect($response->json('links', []))->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true));
        if (! $response->successful() || ! $approve) {
            Log::warning('PayPal create order rejected for '.$order->number.': '.$response->body());

            return PaymentResult::failure('Sorry, PayPal could not start your payment. Please try again or choose another payment method.');
        }

        $order->payments()->create([
            'gateway' => $this->code(),
            'reference' => $response->json('id'),
            'amount' => $order->total,
            'status' => 'pending',
            'payload' => ['sandbox' => $this->isSandbox()],
        ]);
        $order->addNote('PayPal payment started (PayPal Order ID: '.$response->json('id').').');

        return PaymentResult::redirect($approve['href']);
    }

    public function handleReturn(Order $order, Request $request): PaymentResult
    {
        $paypalOrderId = is_string($request->query('token')) ? $request->query('token') : '';
        $payRetry = route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]);
        $payment = $order->payments()->where('gateway', $this->code())->where('reference', $paypalOrderId)->first();
        if ($paypalOrderId === '' || ! $payment) {
            return PaymentResult::failure('We could not match your PayPal payment to this order. Please try again.', $payRetry);
        }
        if ($payment->status === 'succeeded') {
            return PaymentResult::success($order->view_url);
        }

        try {
            $response = $this->api()->withHeaders(['PayPal-Request-Id' => strtolower($this->reference()).'-capture-'.$paypalOrderId])
                ->post('/v2/checkout/orders/'.rawurlencode($paypalOrderId).'/capture', (object) []);
            if ($response->status() === 422 && str_contains($response->body(), 'ORDER_ALREADY_CAPTURED')) {
                $response = $this->api()->get('/v2/checkout/orders/'.rawurlencode($paypalOrderId));
            }
        } catch (\Throwable $e) {
            Log::warning('PayPal capture failed for '.$order->number.': '.$e->getMessage());

            return PaymentResult::failure('We could not confirm your PayPal payment. If you were charged, please contact us.', $payRetry);
        }

        return $this->applyOrderResponse($order, $response, $payRetry);
    }

    /** Read a PayPal order (capture response) and update our order. */
    protected function applyOrderResponse(Order $order, HttpResponse $response, string $retryUrl): PaymentResult
    {
        if (! $response->successful()) {
            $issue = $response->json('details.0.issue') ?? $response->json('name') ?? 'UNKNOWN';
            PaymentManager::fail($order, $this->code(), $response->json('id'), 'PayPal payment failed ('.$issue.').');

            return PaymentResult::failure($issue === 'INSTRUMENT_DECLINED'
                ? 'Your PayPal payment method was declined. Please try again with a different funding source.'
                : 'Your PayPal payment could not be completed. Please try again or choose another payment method.', $retryUrl);
        }

        $unit = $response->json('purchase_units.0', []);
        if ((string) ($unit['custom_id'] ?? '') !== '' && (string) $unit['custom_id'] !== (string) $order->id) {
            return PaymentResult::failure('This PayPal payment does not belong to your order.', $retryUrl);
        }
        $capture = $unit['payments']['captures'][0] ?? null;
        $status = $capture['status'] ?? $response->json('status');

        if ($status === 'COMPLETED') {
            PaymentManager::complete($order, $this->code(), $capture['id'] ?? $response->json('id'), (float) ($capture['amount']['value'] ?? $order->total), [
                'paypal_order_id' => $response->json('id'),
                'payer' => $response->json('payer.email_address'),
            ], null, $response->json('id'));

            return PaymentResult::success($order->view_url);
        }
        if ($status === 'PENDING') {
            $order->transaction_id = $capture['id'] ?? $order->transaction_id;
            $order->save();
            $order->updateStatus('on-hold', 'PayPal payment is pending ('.($capture['status_details']['reason'] ?? 'review').'). The order will update when PayPal completes it.');

            return PaymentResult::success($order->view_url);
        }

        PaymentManager::fail($order, $this->code(), $response->json('id'), 'PayPal payment not completed (status '.$status.').');

        return PaymentResult::failure('Your PayPal payment could not be completed. Please try again or choose another payment method.', $retryUrl);
    }

    public function handleWebhook(Request $request): Response
    {
        if (! $this->isConfigured()) {
            return response('PayPal is not configured.', 400);
        }
        $event = $request->json()->all();
        $type = (string) ($event['event_type'] ?? '');
        if (! in_array($type, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'], true)) {
            return response('Ignored', 200);
        }
        if (($webhookId = trim((string) $this->setting('webhook_id'))) !== '' && ! $this->verifySignature($request, $webhookId, $event)) {
            return response('Invalid signature.', 400);
        }

        $captureId = (string) ($event['resource']['id'] ?? '');
        if (! preg_match('/^[A-Z0-9]{8,40}$/', $captureId)) {
            return response('Bad resource.', 400);
        }
        try {
            // Never trust the webhook body: fetch the capture from PayPal ourselves
            $capture = $this->api()->get('/v2/payments/captures/'.$captureId);
        } catch (\Throwable $e) {
            return response('Lookup failed.', 500);
        }
        if (! $capture->successful()) {
            return response('Unknown capture.', 200);
        }
        $order = Order::find((int) $capture->json('custom_id'));
        $paypalOrderId = $capture->json('supplementary_data.related_ids.order_id');
        if (! $order || ! $order->payments()->where('gateway', $this->code())->where('reference', $paypalOrderId)->exists()) {
            return response('Order not found.', 200);
        }

        if ($capture->json('status') === 'COMPLETED') {
            PaymentManager::complete($order, $this->code(), $captureId, (float) $capture->json('amount.value'), ['paypal_order_id' => $paypalOrderId], null, $paypalOrderId);
        } elseif (in_array($capture->json('status'), ['DECLINED', 'FAILED'], true) && in_array($order->status, ['pending', 'on-hold'], true)) {
            PaymentManager::fail($order, $this->code(), $paypalOrderId, 'PayPal payment was declined.');
        }

        return response('OK', 200);
    }

    protected function verifySignature(Request $request, string $webhookId, array $event): bool
    {
        // PayPal checks the signature against the event exactly as it was sent: re-encoding the decoded array
        // changes it ("{}" -> "[]", escaped slashes...) and makes genuine webhooks fail, so the raw body is
        // spliced into the request as-is.
        $raw = trim((string) $request->getContent());
        if ($raw === '' || ! is_array(json_decode($raw, true))) {
            return false;
        }
        $fields = json_encode([
            'auth_algo' => (string) $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => (string) $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => (string) $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
        ], JSON_UNESCAPED_SLASHES);

        try {
            $response = $this->api()->withBody(substr($fields, 0, -1).',"webhook_event":'.$raw.'}', 'application/json')
                ->post('/v1/notifications/verify-webhook-signature');
        } catch (\Throwable) {
            return false;
        }

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    public function refund(Order $order, float $amount, ?string $reason = null): ?string
    {
        $payment = $order->payments()->where('gateway', $this->code())->where('status', 'succeeded')->latest('id')->first();
        $captureId = $order->transaction_id ?: $payment?->reference;
        if (! $captureId) {
            throw new PaymentException('No PayPal capture was found for this order.');
        }

        try {
            $response = $this->api()->withHeaders(['PayPal-Request-Id' => strtolower($this->reference()).'-refund-'.$order->id.'-'.md5($captureId.$amount.microtime())])
                ->post('/v2/payments/captures/'.rawurlencode($captureId).'/refund', array_filter([
                    'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency_code' => $order->currency ?: 'GBP'],
                    'note_to_payer' => $reason ? mb_substr($reason, 0, 255) : null,
                ]));
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('Could not reach PayPal: '.$e->getMessage());
        }
        if (! $response->successful() || ! in_array($response->json('status'), ['COMPLETED', 'PENDING'], true)) {
            throw new PaymentException($response->json('details.0.description') ?? $response->json('message') ?? 'PayPal declined the refund.');
        }

        $order->payments()->create([
            'gateway' => $this->code(),
            'reference' => $response->json('id'),
            'amount' => -1 * round($amount, 2),
            'status' => 'refunded',
            'payload' => ['status' => $response->json('status'), 'reason' => $reason],
        ]);
        $order->addNote(sprintf('Refunded %s via PayPal (Refund ID: %s).', money($amount), $response->json('id')));

        return $response->json('id');
    }
}
