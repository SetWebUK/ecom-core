<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\CartException;
use Pine\Commerce\Services\Checkout\CheckoutFragments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Basket endpoints. Every mutation answers JSON for the AJAX side cart / checkout (fragments: side cart
 * HTML, basket count, and the checkout summary when context=checkout) and falls back to a redirect with
 * the side cart opened on the next page for plain form posts.
 */
class CartController extends Controller
{
    /** Short-lived cookie read by the theme JS: open the side cart after a non-AJAX add to basket (config commerce.cart.open_cookie). */
    public static function openCookie(): string
    {
        return (string) (config('commerce.cart.open_cookie') ?: 'commerce_open_cart');
    }

    public function __construct(protected Cart $cart)
    {
    }

    /**
     * GET /basket/ - legacy basket page: straight to checkout when there is something to buy,
     * otherwise to the shop. Pending basket messages stay in the session and the side cart opens on the
     * shop page to show them (as WooCommerce printed its notices there).
     */
    public function show()
    {
        if (! $this->cart->isEmpty()) {
            return redirect()->to(url('checkout'), 302);
        }

        $redirect = redirect()->to(url('shop'), 302)->header('X-Robots-Tag', 'noindex, follow');
        if (session()->has('cart_error')) {
            session()->keep(['cart_error']);
        }
        if (session()->has('cart_error') || session()->has('cart.notices')) {
            $redirect->withCookie(Cookie::make(self::openCookie(), '1', 1, '/', null, null, false, false, 'lax'));
        }

        return $redirect;
    }

    /** POST /cart/add - product_id|add-to-cart, quantity, variation_id or attribute_{slug}=value, options[]. */
    public function add(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'min:1'],
            'add-to-cart' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'variation_id' => ['nullable', 'integer', 'min:0'], // 0 = not chosen by script yet (WooCommerce form default)
            'options' => ['nullable', 'array', 'max:10'],
            'options.*' => ['nullable', 'string', 'max:190'],
        ]);
        $product = Product::published()->find((int) ($data['product_id'] ?? $data['add-to-cart'] ?? 0));
        if (! $product) {
            return $this->respond($request, false, 'Sorry, this product cannot be purchased.', 404);
        }

        try {
            $variation = $product->type === 'variable' ? $this->resolveVariation($product, $request) : null;
            $this->cart->add($product, (int) ($data['quantity'] ?? 1), $variation, (array) ($data['options'] ?? []));
        } catch (CartException $e) {
            return $this->respond($request, false, $e->getMessage(), 422);
        }

        return $this->respond($request, true, sprintf('“%s” has been added to your basket.', $product->name), 200, true);
    }

    /** POST /cart/update - item_id + quantity, or cart[{item_id}][qty] for several lines. */
    public function update(Request $request)
    {
        $request->validate([
            'item_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:999'],
            'cart' => ['nullable', 'array', 'max:100'],
            'cart.*.qty' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $changes = $request->filled('item_id')
            ? [(int) $request->input('item_id') => (int) $request->input('quantity', 1)]
            : collect((array) $request->input('cart', []))->mapWithKeys(fn ($row, $id) => [(int) $id => (int) ($row['qty'] ?? 0)])->all();

        $errors = [];
        foreach ($changes as $itemId => $qty) {
            try {
                $this->cart->update($itemId, $qty);
            } catch (CartException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors
            ? $this->respond($request, false, implode(' ', $errors), 422)
            : $this->respond($request, true, 'Basket updated.');
    }

    public function remove(Request $request)
    {
        $request->validate(['item_id' => ['required', 'integer']]);
        $item = $this->cart->items()->firstWhere('id', (int) $request->input('item_id'));
        $this->cart->remove((int) $request->input('item_id'));

        return $this->respond($request, true, $item ? sprintf('“%s” removed.', $item->product?->name ?? 'Item') : 'Basket updated.');
    }

    public function applyCoupon(Request $request)
    {
        $request->validate(['coupon_code' => ['required', 'string', 'max:100']], ['coupon_code.required' => 'Please enter a coupon code.']);
        $result = $this->cart->applyCoupon((string) $request->input('coupon_code'));

        return $this->respond($request, $result['ok'], $result['message'], $result['ok'] ? 200 : 422);
    }

    public function removeCoupon(Request $request)
    {
        $request->validate(['coupon_code' => ['nullable', 'string', 'max:100']]);
        $this->cart->removeCoupon($request->input('coupon_code'));

        return $this->respond($request, true, 'Coupon has been removed.');
    }

    /** GET /cart/fragment - side cart HTML + count (site.js RLSideCart.refresh()). */
    public function fragment(Request $request): JsonResponse
    {
        return response()->json(CheckoutFragments::cart($this->cart, $request->query('context') === 'checkout'))
            ->header('Cache-Control', 'no-store, private');
    }

    // ------------------------------------------------------------------ helpers

    /** Pick the variation from variation_id or WooCommerce-style attribute_{slug} fields. */
    protected function resolveVariation(Product $product, Request $request): ?ProductVariation
    {
        $variations = $product->variations()->where('is_active', true)->get();
        if ((int) $request->input('variation_id') > 0) {
            return $variations->firstWhere('id', (int) $request->input('variation_id'))
                ?? throw new CartException('Sorry, this product is unavailable. Please choose a different combination.');
        }

        $chosen = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($value) && $value !== '' && str_starts_with($key, 'attribute_')) {
                $chosen[preg_replace('/^pa_/', '', substr($key, 10))] = $value;
            }
        }
        foreach ((array) $request->input('attributes', []) as $key => $value) {
            if (is_string($value) && $value !== '') {
                $chosen[preg_replace('/^(attribute_)?(pa_)?/', '', (string) $key)] = $value;
            }
        }
        if (! $chosen) {
            throw new CartException(sprintf('Please choose product options for "%s".', $product->name));
        }

        $match = $variations->first(function (ProductVariation $v) use ($chosen) {
            foreach ((array) $v->options as $attribute => $value) {
                $attribute = preg_replace('/^pa_/', '', (string) $attribute);
                $wanted = $chosen[$attribute] ?? null;
                if ($value !== '' && $value !== null && $wanted !== null && mb_strtolower((string) $wanted) !== mb_strtolower((string) $value)) {
                    return false;
                }
                if ($wanted === null && $value !== '' && $value !== null) {
                    return false;
                }
            }

            return true;
        });

        return $match ?? throw new CartException('Sorry, no products matched your selection. Please choose a different combination.');
    }

    protected function respond(Request $request, bool $ok, string $message, int $status = 200, bool $open = false): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(array_merge([
                'ok' => $ok,
                'message' => $message,
                'open' => $open && $ok,
            ], CheckoutFragments::cart($this->cart, $request->input('context') === 'checkout')), $ok ? 200 : $status)
                ->header('Cache-Control', 'no-store, private');
        }

        if (! $ok) {
            session()->flash('cart_error', $message);
        }
        $back = redirect()->back(302, [], url('/'));
        if ($open || ! $ok) {
            $back->withCookie(Cookie::make(self::openCookie(), '1', 1, '/', null, null, false, false, 'lax'));
        }

        return $back;
    }
}
