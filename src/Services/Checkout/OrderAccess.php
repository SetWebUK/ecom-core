<?php

namespace Pine\Commerce\Services\Checkout;

use Illuminate\Http\Request;
use Pine\Commerce\Models\Order;

/**
 * Who may see an order with its key alone (order-received page, guest invoice PDF) – WooCommerce 8.x behaviour:
 * the session that placed, paid for or verified the order, the order's signed-in customer and staff. Anyone else
 * (a forwarded or leaked link) confirms the order's billing email once (CheckoutController::verifyOrderEmail()).
 */
class OrderAccess
{
    /** Session key: ids of the orders this visitor placed, paid for or verified the billing email of (last 20). */
    public const SESSION_KEY = 'commerce.orders';

    public static function mayView(Request $request, Order $order): bool
    {
        $user = $request->user();
        if ($user && (($order->user_id && (int) $user->id === (int) $order->user_id) || $user->canAccessAdmin())) {
            return true;
        }

        return $request->hasSession()
            && in_array((int) $order->id, array_map('intval', (array) $request->session()->get(self::SESSION_KEY, [])), true);
    }

    /** Remember that this session may view the order (placed / paid / verified here). */
    public static function remember(Request $request, Order $order): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $ids = array_map('intval', (array) $request->session()->get(self::SESSION_KEY, []));
        $ids = array_values(array_unique(array_merge(array_diff($ids, [(int) $order->id]), [(int) $order->id])));
        $request->session()->put(self::SESSION_KEY, array_slice($ids, -20));
    }
}
