<?php

namespace Pine\Commerce\Services\Checkout;

use Pine\Commerce\Services\Cart;

/**
 * HTML fragments + totals returned by the basket / checkout AJAX endpoints, so the side cart and the
 * checkout order summary always show the server's numbers (nothing is priced in the browser).
 */
class CheckoutFragments
{
    public static function cart(Cart $cart, bool $withCheckout = false): array
    {
        // totals first: reading the basket re-checks stock/coupons and raises the notices shown below
        $totals = $cart->totals();
        $notices = $cart->pullNotices();
        $error = session()->pull('cart_error');

        $data = [
            'count' => $totals['count'],
            'subtotal' => money($totals['subtotal_display'] ?? $totals['subtotal']),
            'total' => money($totals['total']),
            'html' => theme_view('cart.side-cart', ['cart' => $cart, 'totals' => $totals, 'notices' => $notices, 'error' => $error])->render(),
        ];
        if ($withCheckout) {
            $data['checkout'] = static::checkout($cart, $notices);
        }

        return $data;
    }

    public static function checkout(Cart $cart, array $notices = []): array
    {
        $totals = $cart->totals();
        $empty = $totals['count'] === 0;

        return [
            'empty' => $empty,
            'redirect' => $empty ? url('basket') : null,
            'summary' => theme_view('checkout.partials.summary', ['cart' => $cart, 'totals' => $totals])->render(),
            'shipping_methods' => theme_view('checkout.partials.shipping-methods', ['totals' => $totals])->render(),
            'mobile_total' => money($totals['total']),
            'total' => $totals['total'],
            'amount' => (int) round($totals['total'] * 100),
            'needs_payment' => $totals['needs_payment'],
            'shipping_method' => $totals['shipping_method']?->code,
            'shipping_label' => $totals['shipping_method'] ? static::shippingLabel($totals['shipping_method']->name, (float) ($totals['shipping_display'] ?? $totals['shipping'])) : null,
            'notices' => $notices,
        ];
    }

    /** "Free shipping" / "Saturday Delivery · £25.00" (review pane "Method"). */
    public static function shippingLabel(string $name, float $cost): string
    {
        return $cost > 0 ? $name.' · '.money($cost) : $name;
    }
}
