<?php

namespace Pine\Commerce\Services\Payments\Gateways;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\Gateway;
use Pine\Commerce\Services\Payments\PaymentException;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Services\Payments\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe card payments (plus Apple Pay / Google Pay / Link) with the Payment Element and PaymentIntents.
 *
 * Flow ("deferred intent"): the checkout mounts the Payment Element in payment mode, the order is placed
 * over AJAX, process() creates a PaymentIntent and hands its client secret back, the browser confirms it
 * (3-D Secure etc.) and Stripe redirects to the return URL where handleReturn() re-reads the intent from
 * Stripe (never trusting the query string). The payment_intent.succeeded / .payment_failed webhooks (signed
 * with the endpoint secret) complete orders whose customers never came back.
 *
 * Settings: payments.stripe.enabled, .title, .public_key, .secret_key (encrypted), .webhook_secret
 * (encrypted), .test_mode. Webhook URL: /webhooks/stripe
 */
class StripeGateway extends Gateway
{
    public function code(): string
    {
        return 'stripe';
    }

    protected function defaultTitle(): string
    {
        return 'Pay securely via Credit / Debit Card';
    }

    public function description(): ?string
    {
        return parent::description() ?? 'Pay with your credit or debit card, Apple Pay or Google Pay.';
    }

    public function adminSettings(): array
    {
        return [
            'label' => 'Card payments (Stripe)',
            'icon' => 'credit-card',
            'description' => 'Credit and debit cards, Apple Pay, Google Pay and Link. Keys: Stripe dashboard › Developers › API keys.',
            'fields' => [
                'enabled' => ['type' => 'bool', 'label' => 'Accept card payments'],
                'title' => ['type' => 'text', 'label' => 'Name at checkout', 'default' => 'Pay securely via Credit / Debit Card', 'wide' => true],
                'description' => ['type' => 'text', 'label' => 'Text under the name', 'default' => 'Pay with your credit or debit card, Apple Pay or Google Pay.', 'wide' => true],
                'test_mode' => ['type' => 'bool', 'label' => 'Test mode', 'help' => 'Use test keys (pk_test_…) – no real money is taken. Switch off before going live.'],
                'public_key' => ['type' => 'text', 'label' => 'Publishable key', 'placeholder' => 'pk_live_…', 'pattern' => '/^pk_(live|test)_[A-Za-z0-9]+$/', 'mono' => true, 'wide' => true,
                    'message' => 'A publishable key starts with pk_live_ or pk_test_.'],
                'secret_key' => ['type' => 'secret', 'label' => 'Secret key', 'placeholder' => 'sk_live_… or rk_live_…', 'pattern' => '/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', 'wide' => true,
                    'message' => 'A secret key starts with sk_live_, sk_test_, rk_live_ or rk_test_.'],
                'webhook_secret' => ['type' => 'secret', 'label' => 'Webhook signing secret', 'placeholder' => 'whsec_…', 'pattern' => '/^whsec_[A-Za-z0-9]+$/', 'wide' => true,
                    'message' => 'A webhook signing secret starts with whsec_.',
                    'help' => 'Add the webhook address below in Stripe (events: payment_intent.succeeded, payment_intent.payment_failed), then paste its signing secret here.'],
            ],
            'webhook' => ['provider' => 'Stripe', 'where' => 'Stripe › Developers › Webhooks'],
        ];
    }

    /** Test mode and the key types must agree (pk_test_ with test mode on, pk_live_ with it off). */
    public function validateSettings(array $values, Validator $validator): void
    {
        $test = filter_var($values['test_mode'] ?? false, FILTER_VALIDATE_BOOL);
        $pk = (string) ($values['public_key'] ?? '');
        if ($pk !== '' && ! $validator->errors()->has('payments.stripe.public_key') && str_starts_with($pk, 'pk_test_') !== $test) {
            $validator->errors()->add('payments.stripe.public_key', $test
                ? 'Test mode is on, so use your test key (pk_test_…).'
                : 'This is a test key – switch on test mode, or use your live key (pk_live_…).');
        }
        $sk = (string) ($values['secret_key'] ?? '');
        if ($sk !== '' && ! $validator->errors()->has('payments.stripe.secret_key') && str_contains($sk, '_test_') !== $test) {
            $validator->errors()->add('payments.stripe.secret_key', $test ? 'Test mode is on, so use a test secret key.' : 'This is a test secret key – switch on test mode, or use your live key.');
        }
    }

    public function publicKey(): ?string
    {
        $key = trim((string) $this->setting('public_key'));

        return $key !== '' ? $key : null;
    }

    protected function secretKey(): ?string
    {
        return $this->secret('secret_key');
    }

    public function isTestMode(): bool
    {
        return $this->flag('test_mode') || str_starts_with((string) $this->publicKey(), 'pk_test_');
    }

    public function isConfigured(): bool
    {
        return str_starts_with((string) $this->publicKey(), 'pk_')
            && preg_match('/^(sk|rk)_/', (string) $this->secretKey()) === 1;
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function icons(): string
    {
        return theme_view('checkout.partials.card-icons')->render();
    }

    protected function client(): StripeClient
    {
        return new StripeClient(['api_key' => $this->secretKey(), 'max_network_retries' => 2]);
    }

    public function returnUrl(Order $order): string
    {
        return route('checkout.payment.return', ['gateway' => $this->code(), 'order' => $order->number, 'key' => $order->order_key]);
    }

    public function process(Order $order, Request $request): PaymentResult
    {
        $amount = $this->pence((float) $order->total);
        try {
            $client = $this->client();
            $intent = null;
            $pending = $order->payments()->where('gateway', $this->code())->where('status', 'pending')->latest('id')->first();
            if ($pending && str_starts_with((string) $pending->reference, 'pi_')) {
                $intent = $client->paymentIntents->retrieve($pending->reference);
                if (! in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                    $intent = null;
                } elseif ($intent->amount !== $amount) {
                    $intent = $client->paymentIntents->update($intent->id, ['amount' => $amount]);
                    $pending->update(['amount' => $order->total]);
                }
            }

            if (! $intent) {
                $intent = $client->paymentIntents->create(array_filter([
                    'amount' => $amount,
                    'currency' => strtolower($order->currency ?: 'gbp'),
                    'automatic_payment_methods' => ['enabled' => true],
                    'description' => setting('store.name', config('app.name')).' - Order '.$order->number,
                    'receipt_email' => $order->email,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'order_number' => (string) $order->number,
                        'order_key' => $order->order_key,
                        'site_url' => url('/'),
                    ],
                    'shipping' => $order->shipping_address_1 ? [
                        'name' => $order->shipping_name ?: $order->billing_name,
                        'phone' => $order->shipping_phone ?: $order->phone,
                        'address' => [
                            'line1' => $order->shipping_address_1,
                            'line2' => $order->shipping_address_2,
                            'city' => $order->shipping_city,
                            'state' => $order->shipping_county,
                            'postal_code' => $order->shipping_postcode,
                            'country' => $order->shipping_country ?: 'GB',
                        ],
                    ] : null,
                ]));
                $order->payments()->create([
                    'gateway' => $this->code(),
                    'reference' => $intent->id,
                    'amount' => $order->total,
                    'status' => 'pending',
                    'payload' => ['mode' => $this->isTestMode() ? 'test' : 'live'],
                ]);
                $order->addNote('Stripe payment started (Payment Intent ID: '.$intent->id.').');
            }
        } catch (ApiErrorException $e) {
            Log::warning('Stripe PaymentIntent failed for order '.$order->number.': '.$e->getMessage());

            return PaymentResult::failure('Sorry, we could not start the card payment. Please try again or choose another payment method.');
        }

        return PaymentResult::action([
            'gateway' => $this->code(),
            'client_secret' => $intent->client_secret,
            'return_url' => $this->returnUrl($order),
        ]);
    }

    public function handleReturn(Order $order, Request $request): PaymentResult
    {
        $id = is_string($request->query('payment_intent')) ? $request->query('payment_intent') : '';
        if (! str_starts_with($id, 'pi_')) {
            return PaymentResult::failure('We could not confirm your payment. Please try again.', route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]));
        }
        try {
            $intent = $this->client()->paymentIntents->retrieve($id);
        } catch (ApiErrorException $e) {
            Log::warning('Stripe return lookup failed for order '.$order->number.': '.$e->getMessage());

            return PaymentResult::failure('We could not confirm your payment with the card processor. If you were charged, please contact us.', route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]));
        }
        if ((string) ($intent->metadata['order_id'] ?? '') !== (string) $order->id) {
            return PaymentResult::failure('This payment does not belong to your order. Please try again.', route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]));
        }

        return $this->applyIntent($order, $intent);
    }

    /** Update the order from a PaymentIntent fetched from Stripe. */
    protected function applyIntent(Order $order, PaymentIntent $intent): PaymentResult
    {
        switch ($intent->status) {
            case 'succeeded':
                PaymentManager::complete($order, $this->code(), $intent->id, ($intent->amount_received ?: $intent->amount) / 100, [
                    'payment_method_types' => $intent->payment_method_types,
                    'livemode' => $intent->livemode,
                ]);

                return PaymentResult::success($order->view_url);

            case 'processing':
                $order->addNote('Stripe payment is processing (Payment Intent ID: '.$intent->id.'). The order will update when Stripe confirms it.');

                return PaymentResult::success($order->view_url);

            default:
                $message = $intent->last_payment_error->message ?? 'Your payment was not completed.';
                PaymentManager::fail($order, $this->code(), $intent->id, 'Stripe payment failed: '.$message);

                return PaymentResult::failure($message.' Please try again or use a different payment method.', route('checkout.pay', ['order' => $order->number, 'key' => $order->order_key]));
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $secret = $this->secret('webhook_secret');
        if (! $secret || ! $this->isConfigured()) {
            return response('Stripe webhooks are not configured.', 400);
        }
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return response('Invalid signature.', 400);
        }

        $object = $event->data->object ?? null;
        if (in_array($event->type, ['payment_intent.succeeded', 'payment_intent.payment_failed'], true) && $object instanceof PaymentIntent) {
            $order = Order::find((int) ($object->metadata['order_id'] ?? 0));
            if (! $order || ! hash_equals((string) $order->order_key, (string) ($object->metadata['order_key'] ?? ''))) {
                return response('Order not found.', 200); // not ours (shared Stripe account) - acknowledge
            }
            if ($event->type === 'payment_intent.succeeded') {
                $this->applyIntent($order, $object);
            } elseif (in_array($order->status, ['pending', 'failed'], true)) {
                PaymentManager::fail($order, $this->code(), $object->id, 'Stripe payment failed: '.($object->last_payment_error->message ?? 'declined').'.');
            }
        }

        return response('OK', 200);
    }

    public function refund(Order $order, float $amount, ?string $reason = null): ?string
    {
        $payment = $order->payments()->where('gateway', $this->code())->where('status', 'succeeded')->latest('id')->first();
        $reference = $payment?->reference ?: $order->transaction_id;
        $params = [
            'amount' => $this->pence($amount),
            'reason' => 'requested_by_customer',
            'metadata' => ['order_id' => (string) $order->id, 'order_number' => (string) $order->number, 'reason' => mb_substr((string) $reason, 0, 450)],
        ];
        if (str_starts_with((string) $reference, 'pi_')) {
            $params['payment_intent'] = $reference;
        } elseif (str_starts_with((string) $reference, 'ch_')) {
            $params['charge'] = $reference;
        } else {
            throw new PaymentException('No Stripe payment was found for this order.');
        }

        try {
            $refund = $this->client()->refunds->create($params);
        } catch (ApiErrorException $e) {
            throw new PaymentException($e->getMessage());
        }
        if (in_array($refund->status, ['failed', 'canceled'], true)) {
            throw new PaymentException('Stripe could not process the refund ('.$refund->status.').');
        }

        $order->payments()->create([
            'gateway' => $this->code(),
            'reference' => $refund->id,
            'amount' => -1 * round($amount, 2),
            'status' => 'refunded',
            'payload' => ['status' => $refund->status, 'reason' => $reason],
        ]);
        $order->addNote(sprintf('Refunded %s via Stripe (Refund ID: %s).', money($amount), $refund->id));

        return $refund->id;
    }
}
