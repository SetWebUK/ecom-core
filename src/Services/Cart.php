<?php

namespace Pine\Commerce\Services;

use Pine\Commerce\Models\Cart as CartModel;
use Pine\Commerce\Models\CartItem;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Checkout\CartException;
use Pine\Commerce\Services\Checkout\CartLine;
use Pine\Commerce\Services\Checkout\CouponEngine;
use Pine\Commerce\Services\Shipping\ShippingRates;
use Pine\Commerce\Services\Tax\TaxEngine;
use Pine\Commerce\Services\Tax\TaxLocation;
use Pine\Commerce\Services\Tax\TaxRates;
use Pine\Commerce\Services\Tax\TaxSettings;
use Pine\Commerce\Support\Features;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The customer's basket (singleton - resolve with app(\Pine\Commerce\Services\Cart::class)).
 *
 * - Stored in the database (carts / cart_items) and identified by a long-lived cookie token (config
 *   commerce.cart.cookie – a migrated client may keep its legacy name; see cookieName());
 *   a signed-in customer's basket is also found by user_id, and a guest basket is merged into it on login.
 * - Prices are never stored: every read re-prices lines from the current product/variation prices.
 * - Stock (managed quantity, stock status, backorders, sold individually) is re-checked on every read;
 *   lines that can no longer be bought are removed/reduced with a notice (see pullNotices()).
 * - Coupons follow WooCommerce rules (Pine\Commerce\Services\Checkout\CouponEngine), delivery options from the
 *   shipping zone of the customer's address (Pine\Commerce\Services\Shipping\ShippingRates) and tax from the tax rates
 *   of their address (Pine\Commerce\Services\Tax\TaxEngine, Settings › Tax).
 */
class Cart
{
    /** Name of the basket token cookie (config commerce.cart.cookie). */
    public static function cookieName(): string
    {
        return (string) (config('commerce.cart.cookie') ?: 'commerce_cart');
    }

    public const COOKIE_MINUTES = 60 * 24 * 30;

    public const COUNTRY = 'GB';

    protected ?CartModel $cart = null;

    protected bool $resolved = false;

    protected ?EloquentCollection $items = null;

    protected ?array $totals = null;

    protected ?array $validCoupons = null;

    protected array $notices = [];

    public function __construct(protected CouponEngine $engine)
    {
    }

    // ------------------------------------------------------------------ basket row

    /** Current cart row (null when the visitor has no basket yet). */
    public function model(): ?CartModel
    {
        if ($this->resolved) {
            return $this->cart;
        }
        $this->resolved = true;

        $user = $this->user();
        $token = $this->cookieToken();
        $cart = null;

        if ($token) {
            $cart = CartModel::where('token', $token)->whereNull('converted_at')->first();
            if ($cart && $cart->user_id && $cart->user_id !== $user?->id) {
                $cart = null; // another account's basket (e.g. after logging out) - start afresh
            }
        }

        if ($user) {
            $userCart = CartModel::where('user_id', $user->id)->whereNull('converted_at')
                ->when($cart, fn ($q) => $q->where('id', '!=', $cart->id))
                ->latest('updated_at')->first();
            if ($cart && ! $cart->user_id) {
                $cart = $this->mergeGuestCart($cart, $userCart, $user);
            } elseif (! $cart) {
                $cart = $userCart;
            }
            if ($cart && $cart->token !== $token) {
                $this->queueCookie($cart->token);
            }
        }

        return $this->cart = $cart;
    }

    /** Basket lines (CartItem models with product.images, product.primaryCategory and variation loaded). */
    public function items(): Collection
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $cart = $this->model();
        if (! $cart) {
            return $this->items = new EloquentCollection;
        }

        $items = $cart->items()
            ->with(['product' => fn ($q) => $q->with(['images', 'primaryCategory']), 'variation'])
            ->orderBy('id')
            ->get();

        return $this->items = $this->sanitise($items);
    }

    /** @return Collection<int, CartLine> priced lines (discount/tax filled in by totals()) */
    public function lines(): Collection
    {
        return $this->totals()['lines'];
    }

    /** Total quantity (header badge). */
    public function count(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    // ------------------------------------------------------------------ mutations

    /**
     * Add a product (or variation) to the basket.
     *
     * @throws CartException with a customer-facing message when it cannot be added
     */
    public function add(Product $product, int $quantity = 1, ?ProductVariation $variation = null, array $options = []): CartItem
    {
        if ($quantity < 1) {
            throw new CartException('Please choose a quantity of 1 or more.');
        }
        $this->assertPurchasable($product, $variation);
        $options = $this->cleanOptions($options);

        $this->model() ?? $this->create();
        $items = $this->items();
        $name = $this->displayName($product, $variation);

        $existing = $items->first(fn (CartItem $i) => $i->product_id === $product->id
            && (int) $i->product_variation_id === (int) $variation?->id
            && $this->cleanOptions((array) $i->options) == $options);

        if ($product->sold_individually) {
            if ($items->contains(fn (CartItem $i) => $i->product_id === $product->id)) {
                throw new CartException(sprintf('You cannot add another "%s" to your basket.', $name));
            }
            $quantity = 1;
        }

        $available = $this->availableStock($product, $variation);
        if ($available !== null) {
            $key = $this->stockKey($product, $variation);
            $inBasket = (int) $items->filter(fn (CartItem $i) => $this->stockKey($i->product, $i->variation) === $key)->sum('quantity');
            if ($available <= 0) {
                throw new CartException(sprintf('You cannot add "%s" to the basket because the product is out of stock.', $name));
            }
            if ($inBasket + $quantity > $available) {
                throw new CartException($inBasket > 0
                    ? sprintf('You cannot add that amount to the basket — we have %d in stock and you already have %d in your basket.', $available, $inBasket)
                    : sprintf('You cannot add that amount of "%s" to the basket because there is not enough stock (%d remaining).', $name, $available));
            }
        }

        if ($existing) {
            $existing->quantity = (int) $existing->quantity + $quantity;
            $existing->save();
            $item = $existing;
        } else {
            $item = $this->cart->items()->create([
                'product_id' => $product->id,
                'product_variation_id' => $variation?->id,
                'quantity' => $quantity,
                'options' => $options ?: null,
            ]);
            $item->setRelation('product', $product->loadMissing(['images', 'primaryCategory']));
            $item->setRelation('variation', $variation);
            $this->items->push($item);
        }

        $this->touched();

        return $item;
    }

    /**
     * Set a line's quantity (0 removes it).
     *
     * @throws CartException when the quantity is not available
     */
    public function update(int $itemId, int $quantity): void
    {
        $item = $this->findItem($itemId);
        if (! $item) {
            throw new CartException('Sorry, that item is no longer in your basket.');
        }
        if ($quantity <= 0) {
            $this->remove($itemId);

            return;
        }
        $name = $this->displayName($item->product, $item->variation);
        if ($item->product->sold_individually && $quantity > 1) {
            throw new CartException(sprintf('You cannot add another "%s" to your basket.', $name));
        }

        $available = $this->availableStock($item->product, $item->variation);
        if ($available !== null) {
            $key = $this->stockKey($item->product, $item->variation);
            $others = (int) $this->items()->filter(fn (CartItem $i) => $i->id !== $item->id && $this->stockKey($i->product, $i->variation) === $key)->sum('quantity');
            if ($others + $quantity > $available) {
                throw new CartException(sprintf('Sorry, we do not have enough "%s" in stock to fulfil your order (%d available). We apologise for any inconvenience caused.', $name, max(0, $available - $others)));
            }
        }

        if ((int) $item->quantity !== $quantity) {
            $item->quantity = $quantity;
            $item->save();
            $this->touched();
        }
    }

    public function remove(int $itemId): void
    {
        $item = $this->findItem($itemId);
        if ($item) {
            $item->delete();
            $this->items = $this->items->reject(fn (CartItem $i) => $i->id === $itemId)->values();
            $this->touched();
        }
    }

    public function clear(): void
    {
        if ($cart = $this->model()) {
            $cart->items()->delete();
            $cart->forceFill(['coupon_code' => null])->save();
        }
        $this->items = new EloquentCollection;
        $this->forgetTotals();
    }

    // ------------------------------------------------------------------ coupons

    /** @return array{ok: bool, message: string} */
    public function applyCoupon(string $code): array
    {
        $code = trim($code);
        if (! Features::enabled('coupons', false)) {
            return ['ok' => false, 'message' => 'Discount codes cannot be used in this shop.'];
        }
        if ($code === '') {
            return ['ok' => false, 'message' => 'Please enter a coupon code.'];
        }
        $coupon = Coupon::findByCode($code);
        if (! $coupon) {
            return ['ok' => false, 'message' => sprintf('Coupon "%s" cannot be applied because it does not exist.', $code)];
        }
        if ($this->isEmpty()) {
            return ['ok' => false, 'message' => 'Your basket is currently empty.'];
        }

        $applied = $this->coupons();
        foreach ($applied as $current) {
            if (mb_strtolower($current->code) === mb_strtolower($coupon->code)) {
                return ['ok' => false, 'message' => sprintf('Coupon code "%s" already applied!', $coupon->code)];
            }
            if ($current->individual_use) {
                return ['ok' => false, 'message' => sprintf('Sorry, coupon "%s" has already been applied and cannot be used in conjunction with other coupons.', $current->code)];
            }
        }

        $lines = $this->pricedLines($this->itemsForCoupons([$coupon]));
        if ($error = $this->engine->validate($coupon, $lines, $this->email(), $this->user()?->id)) {
            return ['ok' => false, 'message' => $error];
        }

        $codes = $coupon->individual_use ? [] : array_map(fn (Coupon $c) => $c->code, $applied);
        $codes[] = $coupon->code;
        $this->model()->forceFill(['coupon_code' => implode(',', $codes)])->save();
        $this->forgetTotals();

        return ['ok' => true, 'message' => 'Coupon code applied successfully.'];
    }

    /** Remove one coupon (or every coupon when no code is given). */
    public function removeCoupon(?string $code = null): void
    {
        $cart = $this->model();
        if (! $cart || ! $cart->coupon_code) {
            return;
        }
        $codes = $code === null ? [] : array_values(array_filter($this->couponCodes(), fn ($c) => mb_strtolower($c) !== mb_strtolower(trim($code))));
        $cart->forceFill(['coupon_code' => $codes ? implode(',', $codes) : null])->save();
        $this->forgetTotals();
    }

    /** Codes stored on the basket (may include ones that are no longer valid); none while feature "coupons" is off. */
    public function couponCodes(): array
    {
        if (! Features::enabled('coupons', false)) {
            return [];
        }
        $raw = (string) $this->model()?->coupon_code;

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }

    /** "<reason> It has now been removed from your order." (unless the reason already says so). */
    public static function removedNotice(string $reason): string
    {
        $reason = rtrim(trim($reason));
        if (str_contains($reason, 'removed from your order')) {
            return $reason;
        }

        return rtrim($reason, '.!').'. It has now been removed from your order.';
    }

    /**
     * Applied coupons that are still valid. Invalid ones are dropped from the basket with a notice.
     *
     * @return array<int, Coupon>
     */
    public function coupons(): array
    {
        if ($this->validCoupons !== null) {
            return $this->validCoupons;
        }
        $codes = $this->couponCodes();
        if (! $codes || $this->items()->isEmpty()) {
            return $this->validCoupons = [];
        }

        $coupons = Coupon::whereIn(DB::raw('LOWER(code)'), array_map('mb_strtolower', $codes))->get();
        $lines = $this->pricedLines($this->itemsForCoupons($coupons->all()));
        $valid = [];
        foreach ($codes as $code) {
            $coupon = $coupons->first(fn (Coupon $c) => mb_strtolower($c->code) === mb_strtolower($code));
            $error = $coupon
                ? $this->engine->validate($coupon, $lines, $this->email(), $this->user()?->id)
                : sprintf('Coupon "%s" cannot be applied because it does not exist.', $code);
            if ($error) {
                // the specific reason (usage limit per customer, minimum spend, expired …), not a generic "invalid"
                $this->notice(static::removedNotice($error));
                continue;
            }
            $valid[] = $coupon;
        }

        if (count($valid) !== count($codes)) {
            $this->model()->forceFill(['coupon_code' => $valid ? implode(',', array_map(fn ($c) => $c->code, $valid)) : null])->save();
        }

        return $this->validCoupons = $valid;
    }

    // ------------------------------------------------------------------ shipping / contact

    /** Shipping options available for this basket: [code => ['method' => ShippingMethod, 'cost' => float]]. */
    public function shippingMethods(): array
    {
        return $this->totals()['shipping_methods'];
    }

    public function shippingMethod(): ?ShippingMethod
    {
        return $this->totals()['shipping_method'];
    }

    public function setShippingMethod(string $code): bool
    {
        $cart = $this->model();
        if (! $cart || ! array_key_exists($code, $this->shippingMethods())) {
            return false;
        }
        if ($cart->shipping_method !== $code) {
            $cart->forceFill(['shipping_method' => $code])->save();
            $this->forgetTotals();
        }

        return true;
    }

    /** Email captured at checkout (coupon email rules + abandoned basket follow-up). */
    public function email(): ?string
    {
        return $this->model()?->email ?: $this->user()?->email;
    }

    public function setEmail(?string $email): void
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        $cart = $this->model();
        if ($cart && $email !== $cart->email && ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $cart->forceFill(['email' => $email])->save();
            $this->validCoupons = null;
            $this->forgetTotals();
        }
    }

    // ------------------------------------------------------------------ totals

    public function subtotal(): float
    {
        return $this->totals()['subtotal'];
    }

    public function discount(): float
    {
        return $this->totals()['discount'];
    }

    public function shipping(): float
    {
        return $this->totals()['shipping'];
    }

    public function tax(): float
    {
        return $this->totals()['tax'];
    }

    public function total(): float
    {
        return $this->totals()['total'];
    }

    /**
     * Everything the basket/checkout views need.
     *
     * @return array{lines: Collection, subtotal: float, discount: float, coupons: array, coupon_models: array,
     *     free_shipping: bool, shipping_methods: array, shipping_method: ?ShippingMethod, shipping: float,
     *     shipping_tax: float, tax: float, tax_rate: float, total: float, count: int, needs_payment: bool}
     */
    public function totals(): array
    {
        if ($this->totals !== null) {
            return $this->totals;
        }
        $items = $this->items();
        $coupons = $this->coupons();

        return $this->totals = $this->calculate($this->pricedLines($items), $coupons, $this->model()?->shipping_method);
    }

    /**
     * Price a set of lines. Public so checkout can re-run the maths on stock-locked rows.
     *
     * Amounts are worked out "as entered" (coupons, shipping), then Pine\Commerce\Services\Tax\TaxEngine adds or
     * extracts tax per line for the customer's address. subtotal / discount / shipping are WITHOUT tax (how orders
     * store them); the *_display keys follow Settings › Tax › "Show prices in the basket".
     *
     * @param  Collection<int, CartLine>  $lines
     * @param  array<int, Coupon>  $coupons  already validated
     * @param  array{shipping?: ?array, billing?: ?array}|null  $destination  addresses (null = the basket's)
     */
    public function calculate(Collection $lines, array $coupons, ?string $shippingCode, ?array $destination = null): array
    {
        $lines = $lines->values();
        $destination ??= $this->destination();
        $subtotal = round($lines->sum(fn (CartLine $l) => $l->subtotal()), 2);
        $couponTotals = $this->engine->apply($coupons, $lines);
        $discount = round($lines->sum(fn (CartLine $l) => $l->discount), 2);
        $freeShipping = collect($coupons)->contains(fn (Coupon $c) => $c->free_shipping);

        $base = TaxSettings::baseLocation();
        $shipTo = TaxLocation::fromAddress($destination['shipping'] ?? null) ?? new TaxLocation($base->country);
        $methods = app(ShippingRates::class)->quote($lines, [
            'subtotal' => $subtotal, 'discount' => $discount, 'free_shipping' => $freeShipping, 'location' => $shipTo,
        ]);
        $selected = $methods[$shippingCode ?? ''] ?? (reset($methods) ?: null);
        $shippingCost = $selected && $lines->isNotEmpty() ? $selected['cost'] : 0.0;

        $taxAt = $this->taxLocation($destination, $shipTo, $selected['method'] ?? null);
        $taxEngine = app(TaxEngine::class);
        $specs = $lines->map(fn (CartLine $l, $i) => ['key' => $i, 'subtotal' => $l->subtotal(), 'total' => $l->total(),
            'class' => $l->taxClass(), 'taxable' => $l->isTaxable()])->all();
        $shippingSpec = $selected && $lines->isNotEmpty() ? ['cost' => $shippingCost, 'taxable' => TaxSettings::shippingTaxable() && $selected['method']->isTaxable()] : null;
        $result = $taxEngine->calculate($specs, $shippingSpec, $taxAt);

        foreach ($lines as $i => $line) {
            $r = $result['lines'][$i];
            $line->tax = $r['tax'];
            $line->taxes = $r['taxes'];
            $line->netSubtotal = $r['net_subtotal'];
            $line->subtotalTax = $r['subtotal_tax'];
            $line->netTotal = $r['net_total'];
        }

        // what each delivery option costs as shown (only differs when shipping is shown the other way round)
        $displayIncl = TaxSettings::displayCartIncl();
        foreach ($methods as $code => $option) {
            $methods[$code]['display_cost'] = $option['cost'];
            if ($displayIncl !== TaxSettings::shippingPricesIncludeTax() && $option['cost'] > 0 && TaxSettings::enabled()) {
                $quote = $code === ($selected['method']->code ?? null) ? $result : $taxEngine->calculate($specs,
                    ['cost' => $option['cost'], 'taxable' => TaxSettings::shippingTaxable() && $option['method']->isTaxable()],
                    $this->taxLocation($destination, $shipTo, $option['method']));
                $methods[$code]['display_cost'] = $displayIncl ? $quote['shipping']['gross'] : $quote['shipping']['net'];
            }
        }

        $total = $result['total'];

        // coupon amounts as shown (scaled when the basket is shown the other way round from how prices are entered)
        $discountDisplay = $displayIncl ? $result['gross_discount'] : $result['discount'];
        $couponsDisplay = $couponTotals;
        if ($discount > 0 && abs($discountDisplay - $discount) > 0.004 && $couponTotals) {
            $left = $discountDisplay;
            $codes = array_keys($couponTotals);
            foreach ($codes as $n => $code) {
                $couponsDisplay[$code] = $n === count($codes) - 1 ? round($left, 2) : round($couponTotals[$code] * $discountDisplay / $discount, 2);
                $left -= $couponsDisplay[$code];
            }
        }

        return [
            'lines' => $lines,
            'subtotal' => $result['subtotal'],
            'discount' => $result['discount'],
            'coupons' => $couponTotals,
            'coupon_models' => $coupons,
            'free_shipping' => $freeShipping,
            'shipping_methods' => $methods,
            'shipping_method' => $selected['method'] ?? null,
            'shipping' => $result['shipping_net'],
            'shipping_tax' => $result['shipping_tax'],
            'tax' => $result['tax'],
            'tax_rate' => TaxRates::totalPercent(TaxRates::find(null, $taxAt)),
            'total' => $total,
            'count' => (int) $lines->sum(fn (CartLine $l) => $l->quantity),
            'needs_payment' => $total > 0,
            // v1.1
            'tax_lines' => array_values($result['rates']),
            'items_tax' => $result['items_tax'],
            'prices_include_tax' => $result['inclusive'],
            'display_incl' => $displayIncl,
            'subtotal_display' => $displayIncl ? $result['gross_subtotal'] : $result['subtotal'],
            'discount_display' => $discountDisplay,
            'coupons_display' => $couponsDisplay,
            'shipping_display' => $displayIncl ? $result['gross_shipping'] : $result['shipping_net'],
            'shipping_zone' => $selected['zone'] ?? app(ShippingRates::class)->zoneFor($shipTo),
            'shipping_location' => $shipTo,
            'tax_location' => $taxAt,
        ];
    }

    public function taxRate(): float
    {
        return TaxRates::totalPercent(TaxRates::find(null, TaxSettings::baseLocation()));
    }

    public function taxLabel(): string
    {
        return TaxSettings::label();
    }

    /**
     * Address tax is worked out for: Settings › Tax "based on" (delivery / billing address or the shop's address),
     * the shop's address for local pickup, and the shop's country until the customer has typed an address.
     */
    public function taxLocation(?array $destination, ?TaxLocation $shipTo = null, ?ShippingMethod $method = null): TaxLocation
    {
        $base = TaxSettings::baseLocation();
        if ($method && $method->typeKey() === 'local_pickup') {
            return $base;
        }

        return match (TaxSettings::basedOn()) {
            'base' => $base,
            'billing' => TaxLocation::fromAddress($destination['billing'] ?? null) ?? $shipTo ?? TaxLocation::fromAddress($destination['shipping'] ?? null) ?? $base,
            default => $shipTo ?? TaxLocation::fromAddress($destination['shipping'] ?? null) ?? $base,
        };
    }

    // ------------------------------------------------------------------ destination

    /**
     * Addresses typed at checkout so far: ['shipping' => [country, state, postcode, city], 'billing' => …|null].
     * Stored on the basket (carts.destination) so zones and tax follow the customer between requests.
     */
    public function destination(): array
    {
        $stored = (array) ($this->model()?->destination ?? []);

        return ['shipping' => $stored['shipping'] ?? null, 'billing' => $stored['billing'] ?? null];
    }

    /** Remember the checkout address (country/state/postcode/city of the delivery and, when different, billing address). */
    public function setDestination(?array $shipping, ?array $billing = null): void
    {
        $clean = function (?array $a): ?array {
            if (! $a || trim((string) ($a['country'] ?? '')) === '') {
                return null;
            }

            return [
                'country' => strtoupper(mb_substr(trim((string) $a['country']), 0, 2)),
                'state' => mb_substr(trim((string) ($a['state'] ?? $a['county'] ?? '')), 0, 100),
                'postcode' => mb_substr(strtoupper(trim((string) ($a['postcode'] ?? ''))), 0, 20),
                'city' => mb_substr(trim((string) ($a['city'] ?? '')), 0, 100),
            ];
        };
        $value = array_filter(['shipping' => $clean($shipping), 'billing' => $clean($billing)]);
        $cart = $this->model();
        if (! $cart || (array) ($cart->destination ?? []) == $value) {
            return;
        }
        try {
            $cart->forceFill(['destination' => $value ?: null])->save();
        } catch (\Throwable) {
            $cart->destination = $value ?: null; // column not migrated yet: this request only
        }
        $this->forgetTotals();
    }

    // ------------------------------------------------------------------ stock

    /** Units that can still be sold (null = unlimited / not tracked). */
    public function availableStock(Product $product, ?ProductVariation $variation = null): ?int
    {
        $backorders = $product->backorders && $product->backorders !== 'no';
        if ($variation && $variation->manage_stock) {
            return $backorders ? null : max(0, (int) $variation->stock_quantity);
        }
        if ($product->manage_stock) {
            return $backorders ? null : max(0, (int) $product->stock_quantity);
        }
        $status = $variation ? $variation->stock_status : $product->stock_status;

        return $status === 'outofstock' ? 0 : null;
    }

    public function stockKey(?Product $product, ?ProductVariation $variation = null): string
    {
        return $variation && $variation->manage_stock ? 'v'.$variation->id : 'p'.$product?->id;
    }

    /** Throws when a product/variation cannot be bought at all. */
    public function assertPurchasable(Product $product, ?ProductVariation $variation = null): void
    {
        $name = $this->displayName($product, $variation);
        if ($product->trashed() || $product->status !== 'published') {
            throw new CartException('Sorry, this product cannot be purchased.');
        }
        if ($product->type === 'variable') {
            if (! $variation) {
                throw new CartException(sprintf('Please choose product options for "%s".', $product->name));
            }
        }
        if ($variation && ((int) $variation->product_id !== (int) $product->id || ! $variation->is_active)) {
            throw new CartException('Sorry, this product is unavailable. Please choose a different combination.');
        }
        $price = $variation ? $variation->currentPrice() : $product->currentPrice();
        if ($price === null) {
            throw new CartException('Sorry, this product cannot be purchased.');
        }
        if ($this->availableStock($product, $variation) === 0) {
            throw new CartException(sprintf('You cannot add "%s" to the basket because the product is out of stock.', $name));
        }
    }

    // ------------------------------------------------------------------ notices & lifecycle

    /** Add a message for the customer (shown in the side cart / checkout). */
    public function notice(string $message): void
    {
        if (! in_array($message, $this->notices, true)) {
            $this->notices[] = $message;
            try {
                if (app()->bound('session') && request()->hasSession()) {
                    $stored = (array) request()->session()->get('cart.notices', []);
                    if (! in_array($message, $stored, true)) {
                        $stored[] = $message;
                        request()->session()->put('cart.notices', $stored);
                    }
                }
            } catch (\Throwable) {
                // no session (console) - keep in memory only
            }
        }
    }

    /** Messages raised since they were last shown; clears them. */
    public function pullNotices(): array
    {
        $notices = $this->notices;
        try {
            if (request()->hasSession()) {
                $notices = array_values(array_unique(array_merge((array) request()->session()->pull('cart.notices', []), $notices)));
            }
        } catch (\Throwable) {
        }
        $this->notices = [];

        return $notices;
    }

    /** Forget cached lines/totals (and optionally the resolved basket row, e.g. after login). */
    public function reset(bool $model = false): void
    {
        $this->items = null;
        $this->forgetTotals();
        if ($model) {
            $this->cart = null;
            $this->resolved = false;
        }
    }

    /** The basket became an order: keep the row for reporting, but start a fresh basket next time. */
    public function markConverted(): void
    {
        if ($cart = $this->model()) {
            $cart->forceFill(['converted_at' => now()])->save();
        }
        $this->forgetCookie();
        $this->cart = null;
        $this->resolved = true;
        $this->items = new EloquentCollection;
        $this->forgetTotals();
    }

    /**
     * Bring a saved basket back to this visitor (abandoned-cart "return to my basket" link).
     *
     * - it already is the visitor's basket: nothing to do;
     * - the visitor has no basket (or an empty one) and may own it (a guest basket, or the signed-in customer's own):
     *   it becomes the visitor's basket (cookie re-issued);
     * - otherwise its lines are merged into the visitor's basket (same line: the larger quantity wins, so a double
     *   click never doubles quantities) with its discount codes and email.
     *
     * @return bool true when $saved itself became the visitor's basket, false when it was merged into another one
     */
    public function restore(CartModel $saved): bool
    {
        $current = $this->model();
        if ($current && $current->id === $saved->id) {
            return true;
        }
        $user = $this->user();
        $ownable = ! $saved->user_id || $saved->user_id === $user?->id;
        if ($ownable && (! $current || ! $current->items()->exists())) {
            if ($user && ! $saved->user_id) {
                $saved->forceFill(['user_id' => $user->id, 'email' => $saved->email ?: $user->email])->save();
            }
            $this->cart = $saved;
            $this->resolved = true;
            $this->items = null;
            $this->touched();

            return true;
        }

        $target = $current ?? $this->create();
        DB::transaction(function () use ($saved, $target) {
            $existing = $target->items()->get();
            foreach ($saved->items()->get() as $item) {
                $match = $existing->first(fn (CartItem $i) => $i->product_id === $item->product_id
                    && (int) $i->product_variation_id === (int) $item->product_variation_id
                    && $this->cleanOptions((array) $i->options) == $this->cleanOptions((array) $item->options));
                if ($match) {
                    if ((int) $item->quantity > (int) $match->quantity) {
                        $match->quantity = (int) $item->quantity; // trimmed to stock on read
                        $match->save();
                    }
                } else {
                    $copy = $item->replicate(['cart_id']);
                    $copy->cart_id = $target->id;
                    $copy->save();
                }
            }
            $codes = array_filter(array_unique(array_merge(
                explode(',', (string) $target->coupon_code),
                explode(',', (string) $saved->coupon_code),
            )));
            $target->forceFill([
                'coupon_code' => $codes ? implode(',', $codes) : null,
                'shipping_method' => $target->shipping_method ?: $saved->shipping_method,
                'email' => $target->email ?: $saved->email,
            ])->save();
        });
        $this->cart = $target;
        $this->resolved = true;
        $this->items = null;
        $this->touched();

        return false;
    }

    /** Drop the basket cookie (logout). */
    public function forgetCookie(): void
    {
        try {
            Cookie::queue(Cookie::forget(self::cookieName()));
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------------ internals

    /** Priced lines for a set of basket items (no discounts yet). */
    protected function pricedLines(Collection $items): Collection
    {
        return $items->map(fn (CartItem $item) => CartLine::fromItem($item))->values();
    }

    /** Items with product categories loaded when any coupon has category rules (avoids N+1 in rules). */
    protected function itemsForCoupons(array $coupons): Collection
    {
        $items = $this->items();
        $needsCategories = collect($coupons)->contains(fn (Coupon $c) => ! empty($c->category_ids) || ! empty($c->excluded_category_ids));
        if ($needsCategories && $items instanceof EloquentCollection) {
            $items->loadMissing('product.categories:id');
        }

        return $items;
    }

    /** Remove lines that can no longer be bought and trim quantities to what is in stock. */
    protected function sanitise(EloquentCollection $items): EloquentCollection
    {
        $pools = [];
        $keep = new EloquentCollection;
        foreach ($items as $item) {
            $product = $item->product;
            $variation = $item->variation;
            $purchasable = $product && $product->status === 'published'
                && ! ($product->type === 'variable' && ! $item->product_variation_id)
                && ! ($item->product_variation_id && (! $variation || ! $variation->is_active || (int) $variation->product_id !== (int) $product->id))
                && ($variation ? $variation->currentPrice() : $product->currentPrice()) !== null;
            if (! $purchasable) {
                $this->notice(sprintf('%s has been removed from your basket because it can no longer be purchased. Please contact us if you need assistance.', $product?->name ?? 'An item'));
                $item->delete();

                continue;
            }

            $key = $this->stockKey($product, $variation);
            if (! array_key_exists($key, $pools)) {
                $pools[$key] = $this->availableStock($product, $variation);
            }
            $qty = max(1, (int) $item->quantity);
            if ($product->sold_individually) {
                $qty = 1;
            }
            if ($pools[$key] !== null) {
                if ($pools[$key] <= 0) {
                    $this->notice(sprintf('Sorry, "%s" is not in stock and has been removed from your basket. We apologise for any inconvenience caused.', $this->displayName($product, $variation)));
                    $item->delete();

                    continue;
                }
                if ($qty > $pools[$key]) {
                    $qty = $pools[$key];
                    $this->notice(sprintf('Sorry, we do not have enough "%s" in stock to fulfil your order (%d available). We apologise for any inconvenience caused.', $this->displayName($product, $variation), $qty));
                }
                $pools[$key] -= $qty;
            }
            if ($qty !== (int) $item->quantity) {
                $item->quantity = $qty;
                $item->save();
            }
            $keep->push($item);
        }

        return $keep;
    }

    /** Fold a guest basket into the signed-in customer's basket. */
    protected function mergeGuestCart(CartModel $guest, ?CartModel $userCart, User $user): CartModel
    {
        if (! $userCart) {
            $guest->forceFill(['user_id' => $user->id, 'email' => $guest->email ?: $user->email])->save();

            return $guest;
        }

        DB::transaction(function () use ($guest, $userCart) {
            $existing = $userCart->items()->get();
            foreach ($guest->items()->get() as $item) {
                $match = $existing->first(fn (CartItem $i) => $i->product_id === $item->product_id
                    && (int) $i->product_variation_id === (int) $item->product_variation_id
                    && $this->cleanOptions((array) $i->options) == $this->cleanOptions((array) $item->options));
                if ($match) {
                    $match->quantity = (int) $match->quantity + (int) $item->quantity; // trimmed to stock on read
                    $match->save();
                } else {
                    $item->cart_id = $userCart->id;
                    $item->save();
                }
            }
            $codes = array_filter(array_unique(array_merge(
                explode(',', (string) $userCart->coupon_code),
                explode(',', (string) $guest->coupon_code),
            )));
            $userCart->forceFill([
                'coupon_code' => $codes ? implode(',', $codes) : null,
                'shipping_method' => $guest->shipping_method ?: $userCart->shipping_method,
            ])->save();
            $userCart->touch();
            $guest->items()->delete();
            $guest->delete();
        });

        return $userCart;
    }

    protected function create(): CartModel
    {
        $user = $this->user();
        $this->cart = CartModel::create([
            'token' => Str::random(40),
            'user_id' => $user?->id,
            'email' => $user?->email,
        ]);
        $this->resolved = true;
        $this->items = new EloquentCollection;
        $this->queueCookie($this->cart->token);

        return $this->cart;
    }

    protected function findItem(int $itemId): ?CartItem
    {
        return $this->items()->first(fn (CartItem $i) => $i->id === $itemId);
    }

    protected function touched(): void
    {
        if ($this->cart) {
            $this->cart->touch();
            $this->queueCookie($this->cart->token); // sliding 30-day expiry
        }
        $this->forgetTotals();
    }

    protected function forgetTotals(): void
    {
        $this->totals = null;
        $this->validCoupons = null;
    }

    /** Forget the per-process shipping/tax memos (tests, long-running processes). */
    public static function flush(): void
    {
        ShippingRates::flush();
        TaxRates::flush();
    }

    protected function displayName(Product $product, ?ProductVariation $variation): string
    {
        if (! $variation) {
            return $product->name;
        }
        $options = (new CartLine(null, $product, $variation, 1, 0))->options();

        return $options ? $product->name.' - '.implode(', ', $options) : $product->name;
    }

    protected function cleanOptions(array $options): array
    {
        $clean = [];
        foreach ($options as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $clean[mb_substr(trim((string) $key), 0, 60)] = mb_substr(trim((string) $value), 0, 190);
            }
        }
        ksort($clean);

        return $clean;
    }

    protected function cookieToken(): ?string
    {
        try {
            $token = request()->cookie(self::cookieName());
        } catch (\Throwable) {
            return null;
        }

        return is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) ? $token : null;
    }

    protected function queueCookie(string $token): void
    {
        try {
            Cookie::queue(Cookie::make(self::cookieName(), $token, self::COOKIE_MINUTES, '/', null, null, true, false, 'lax'));
        } catch (\Throwable) {
        }
    }

    protected function user(): ?User
    {
        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }
}
