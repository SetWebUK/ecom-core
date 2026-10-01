<?php

namespace Pine\Commerce\Http\Middleware;

use Pine\Commerce\Http\Controllers\CartController;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\CartException;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * WooCommerce "?add-to-cart={id}[&quantity=n]" links (ads, emails, old product cards) on any storefront GET:
 * add the product (legacy WordPress ids are accepted), then redirect to the same URL without the
 * parameter with the side cart set to open - the legacy site did the same with CheckoutWC's side cart.
 */
class HandleAddToCartQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->query->has('add-to-cart') || $request->is('admin*', 'webhooks/*')) {
            return $next($request);
        }

        $id = (int) $request->query('add-to-cart');
        $product = $id > 0
            ? (Product::published()->where('wp_id', $id)->first() ?? Product::published()->find($id))
            : null;
        $cart = app(Cart::class);
        if (! $product) {
            session()->flash('cart_error', 'Sorry, this product cannot be purchased.');
        } else {
            try {
                $variation = null;
                if ($product->type === 'variable' && ($variationId = (int) $request->query('variation_id'))) {
                    $variation = $product->variations()->where('is_active', true)->find($variationId);
                }
                $cart->add($product, max(1, min(999, (int) $request->query('quantity', 1))), $variation);
            } catch (CartException $e) {
                session()->flash('cart_error', $e->getMessage());
            }
        }

        // same URL without the parameters, keeping the trailing slash (fullUrlWithoutQuery() drops it,
        // which cost a second redirect through TrailingSlash)
        $query = Arr::query(Arr::except($request->query(), ['add-to-cart', 'quantity', 'variation_id']));
        $url = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().($query !== '' ? '?'.$query : '');

        return redirect()->to($url, 302)
            ->withCookie(Cookie::make(CartController::openCookie(), '1', 1, '/', null, null, false, false, 'lax'))
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
