<?php

namespace Pine\Commerce\Http\Controllers;

use Illuminate\Http\Request;
use Pine\Commerce\Models\Cart as CartModel;
use Pine\Commerce\Models\CartRecoveryEmail;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;
use Pine\Commerce\Support\Features;

/**
 * Links in abandoned-cart reminders (all signed, so they cannot be guessed or altered):
 *   GET  basket/restore/{cart}/?e={email}&…      restore the basket into this visitor's session, apply the reminder's
 *                                                coupon and go to the checkout (the click is recorded on the email row)
 *   GET  basket/unsubscribe/{cart}/?e=…          confirmation page with one button
 *   POST basket/unsubscribe/{cart}/?e=…          unsubscribe (also the RFC 8058 one-click POST from mail apps; CSRF-exempt)
 */
class CartRecoveryController extends Controller
{
    public function __construct(protected Cart $cart, protected AbandonedCartRecovery $recovery)
    {
    }

    public function restore(Request $request, int $cart)
    {
        $saved = CartModel::query()->find($cart);
        $email = $this->emailRow($request, $cart);
        if ($email) {
            $this->recovery->recordClick($email);
        }

        if (! $saved || $saved->converted_at || ! $saved->items()->exists()) {
            $this->cart->notice('That basket has already been checked out or emptied, so there was nothing to restore.');

            return $this->noStore(redirect()->to(url('basket')));
        }

        if (! $this->cart->restore($saved)) {
            $this->recovery->stop($saved, 'merged');
        }
        if ($email?->coupon_code && Features::enabled('coupons', false) && ! in_array(mb_strtolower($email->coupon_code), array_map('mb_strtolower', $this->cart->couponCodes()), true)) {
            $this->cart->applyCoupon($email->coupon_code); // an expired/used code simply isn't applied
        }

        return $this->noStore(redirect()->to(url('checkout')));
    }

    public function unsubscribe(Request $request, int $cart)
    {
        $saved = CartModel::query()->find($cart);
        $email = $this->emailRow($request, $cart);
        $address = $email?->email ?: ($saved ? AbandonedCartRecovery::contactEmail($saved) : null);
        $done = false;
        if ($request->isMethod('post')) {
            if ($saved) {
                $this->recovery->unsubscribe($saved, $address, $request->has('List-Unsubscribe') ? 'one-click' : 'link');
            } elseif ($address) {
                \Pine\Commerce\Models\EmailUnsubscribe::add($address, \Pine\Commerce\Models\EmailUnsubscribe::ABANDONED_CART, 'link');
            }
            $done = true;
            if (! $request->acceptsHtml() || $request->has('List-Unsubscribe')) {
                return response('Unsubscribed', 200)->header('Cache-Control', 'no-store, private');
            }
        }

        return response(theme_view('cart.unsubscribe', [
            'done' => $done,
            'email' => $address,
            'action' => $request->fullUrl(),
            'storeName' => (string) setting('store.name', config('app.name')),
        ]))->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    protected function emailRow(Request $request, int $cart): ?CartRecoveryEmail
    {
        $id = (int) $request->query('e');

        return $id ? CartRecoveryEmail::query()->where('cart_id', $cart)->find($id) : null;
    }

    protected function noStore($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
