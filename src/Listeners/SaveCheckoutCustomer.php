<?php

namespace Pine\Commerce\Listeners;

use Pine\Commerce\Events\OrderPlaced;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Illuminate\Support\Facades\Log;

/**
 * After an order is placed at the checkout, a signed-in customer's billing and shipping addresses become
 * their saved defaults (WooCommerce keeps them on the customer for the next checkout / My Account).
 */
class SaveCheckoutCustomer
{
    public function handle(OrderPlaced $event): void
    {
        $order = $event->order;
        if (! $order->user_id || $order->created_via !== 'checkout') {
            return;
        }
        try {
            $user = User::find($order->user_id);
            if ($user) {
                CheckoutService::saveAddresses($user, $order);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not save addresses for order '.$order->number.': '.$e->getMessage());
        }
    }
}
