<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Services\Payments\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /webhooks/{gateway} (CSRF-exempt, see bootstrap/app.php). Each gateway verifies its own signature and
 * re-reads the payment from the provider before touching an order.
 *   Stripe: /webhooks/stripe  (payment_intent.succeeded, payment_intent.payment_failed)
 *   PayPal: /webhooks/paypal  (PAYMENT.CAPTURE.COMPLETED / DENIED / DECLINED)
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentManager $payments, string $gateway): Response
    {
        $handler = $payments->get($gateway);
        if (! $handler) {
            return response('Unknown payment gateway.', 404);
        }

        try {
            return $handler->handleWebhook($request);
        } catch (\Throwable $e) {
            Log::error('Payment webhook ('.$gateway.') failed: '.$e->getMessage(), ['exception' => $e]);

            return response('Webhook error.', 500);
        }
    }
}
