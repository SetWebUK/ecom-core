<?php

namespace Pine\Commerce\Services\Checkout;

use Pine\Commerce\Events\OrderPlaced;
use Pine\Commerce\Http\Controllers\Auth\AuthController;
use Pine\Commerce\Models\Cart as CartModel;
use Pine\Commerce\Models\CartItem;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Scheduling\Scheduler;
use Pine\Commerce\Scheduling\Tasks\CancelUnpaidOrders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Turns the basket into an order (WC_Checkout::process_checkout equivalent).
 *
 *  - validate(): server-side validation of the one-page checkout form (addresses in the countries the shop sells to,
 *    CheckoutService::countries() – the UK only unless Settings › Shipping says otherwise).
 *  - placeOrder(): one DB transaction that locks the basket, product/variation and coupon rows, re-checks
 *    stock and coupons, re-prices every line from the catalogue, writes the order + items, takes the stock
 *    and records coupon usage / total_sales (OrderStock, exactly once).
 *    A pending/failed order already created from the same basket (card declined, customer changed their
 *    mind about PayPal...) is updated in place instead of creating a second order, like WooCommerce's
 *    "order awaiting payment".
 */
class CheckoutService
{
    public const COUNTRIES = ['GB' => 'United Kingdom (UK)'];

    public const SESSION_AWAITING = 'checkout.order_awaiting_payment';

    public function __construct(protected Cart $cart, protected CouponEngine $engine)
    {
    }

    // ------------------------------------------------------------------ validation

    /**
     * Validate and normalise the posted checkout form.
     *
     * @throws ValidationException
     */
    public function validate(Request $request, bool $needsPayment, array $gateways): array
    {
        $input = $request->all();
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }
        // CheckoutWC posts shipping_method[0]
        if (isset($input['shipping_method']) && is_array($input['shipping_method'])) {
            $input['shipping_method'] = (string) (reset($input['shipping_method']) ?: '');
        }
        $default = static::defaultCountry();
        $input['shipping_country'] = strtoupper(is_string($input['shipping_country'] ?? null) ? $input['shipping_country'] : $default) ?: $default;
        $differentBilling = ($input['bill_to_different_address'] ?? 'same_as_shipping') === 'different_from_shipping';
        if ($differentBilling) {
            $input['billing_country'] = strtoupper(is_string($input['billing_country'] ?? null) ? $input['billing_country'] : $default) ?: $default;
        }

        // delivery options depend on the address (shipping zones): price the basket for the posted address first
        $this->cart->setDestination(static::destinationFrom($input, 'shipping'), $differentBilling ? static::destinationFrom($input, 'billing') : null);
        $shippingCodes = array_keys($this->cart->shippingMethods());
        $countries = array_keys(static::countries());
        $user = $this->user();

        $rules = [
            'billing_email' => ['required', 'string', 'max:190', 'email:rfc'],
            'shipping_method' => ['nullable', 'string', 'in:'.implode(',', $shippingCodes ?: ['-'])],
            'order_comments' => ['nullable', 'string', 'max:2000'],
            'bill_to_different_address' => ['nullable', 'in:same_as_shipping,different_from_shipping'],
        ];
        $rules += $this->addressRules('shipping', $countries);
        if ($differentBilling) {
            $rules += $this->addressRules('billing', $countries);
        }
        if ($needsPayment) {
            $rules['payment_method'] = ['required', 'string', 'in:'.implode(',', array_keys($gateways) ?: ['-'])];
        }
        if ($this->termsUrl()) {
            $rules['terms'] = ['accepted'];
        }
        $wantsAccount = ! $user && AuthController::registrationEnabled() && filter_var($input['createaccount'] ?? false, FILTER_VALIDATE_BOOL);
        if ($wantsAccount) {
            $rules['account_password'] = ['required', 'string', 'min:8', 'max:255'];
        }

        $messages = [
            'required' => ':attribute is a required field.',
            'email' => ':attribute is not a valid email address.',
            'max' => ':attribute is too long.',
            'terms.accepted' => 'Please read and accept the terms and conditions to proceed with your order.',
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'Invalid payment method.',
            'shipping_method.in' => 'Please choose a valid shipping method.',
            'in' => ':attribute is not valid.',
            'account_password.min' => 'Please enter an account password of at least 8 characters.',
        ];
        $attributes = [
            'billing_email' => 'Email address',
            'account_password' => 'Account password',
            'order_comments' => 'Order notes',
        ];
        foreach (['shipping' => 'Shipping', 'billing' => 'Billing'] as $prefix => $label) {
            $attributes += [
                "{$prefix}_first_name" => "$label First name",
                "{$prefix}_last_name" => "$label Last name",
                "{$prefix}_company" => "$label Company name",
                "{$prefix}_address_1" => "$label Street address",
                "{$prefix}_address_2" => "$label Flat, suite, unit, etc.",
                "{$prefix}_city" => "$label Town / City",
                "{$prefix}_state" => "$label County",
                "{$prefix}_postcode" => "$label Postcode",
                "{$prefix}_country" => "$label Country / Region",
                "{$prefix}_phone" => "$label Phone",
            ];
        }

        $validator = Validator::make($input, $rules, $messages, $attributes);
        $validator->after(function ($v) use ($input, $differentBilling, $wantsAccount) {
            foreach ($differentBilling ? ['shipping', 'billing'] : ['shipping'] as $prefix) {
                $postcode = (string) ($input["{$prefix}_postcode"] ?? '');
                if ($postcode !== '' && ! static::validPostcode($postcode, (string) ($input["{$prefix}_country"] ?? ''))) {
                    $v->errors()->add("{$prefix}_postcode", ucfirst($prefix).' Postcode is not a valid postcode / ZIP.');
                }
                $phone = (string) ($input["{$prefix}_phone"] ?? '');
                if ($phone !== '' && ! static::validPhone($phone)) {
                    $v->errors()->add("{$prefix}_phone", ucfirst($prefix).' Phone is not a valid phone number.');
                }
            }
            if ($wantsAccount && User::whereRaw('LOWER(email) = ?', [mb_strtolower((string) ($input['billing_email'] ?? ''))])->exists()) {
                $v->errors()->add('billing_email', 'An account is already registered with your email address. Please log in.');
            }
        });
        $validator->validate();

        $data = [
            'email' => mb_strtolower((string) $input['billing_email']),
            'shipping_method' => (string) ($input['shipping_method'] ?? '') ?: ($shippingCodes[0] ?? null),
            'payment_method' => $needsPayment ? (string) $input['payment_method'] : null,
            'customer_note' => trim((string) ($input['order_comments'] ?? '')) ?: null,
            'different_billing' => $differentBilling,
            'create_account' => $wantsAccount,
            'account_password' => $wantsAccount ? (string) $input['account_password'] : null,
            'shipping' => $this->address($input, 'shipping'),
        ];
        $data['billing'] = $differentBilling ? $this->address($input, 'billing') : $data['shipping'];

        return $data;
    }

    protected function addressRules(string $prefix, array $countries): array
    {
        return [
            "{$prefix}_first_name" => ['required', 'string', 'max:100'],
            "{$prefix}_last_name" => ['required', 'string', 'max:100'],
            "{$prefix}_company" => ['nullable', 'string', 'max:150'],
            "{$prefix}_address_1" => ['required', 'string', 'max:190'],
            "{$prefix}_address_2" => ['nullable', 'string', 'max:190'],
            "{$prefix}_city" => ['required', 'string', 'max:100'],
            "{$prefix}_state" => ['nullable', 'string', 'max:100'],
            "{$prefix}_postcode" => ['required', 'string', 'max:12'],
            "{$prefix}_country" => ['required', 'in:'.implode(',', $countries)],
            "{$prefix}_phone" => ['required', 'string', 'max:40'],
        ];
    }

    protected function address(array $input, string $prefix): array
    {
        $get = fn ($field) => trim(strip_tags((string) ($input["{$prefix}_{$field}"] ?? ''))) ?: null;

        return [
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'company' => $get('company'),
            'address_1' => $get('address_1'),
            'address_2' => $get('address_2'),
            'city' => $get('city'),
            'county' => $get('state'),
            'postcode' => static::formatPostcode((string) $get('postcode'), (string) ($get('country') ?? static::defaultCountry())),
            'country' => strtoupper((string) ($get('country') ?? static::defaultCountry())),
            'phone' => $get('phone'),
        ];
    }

    /**
     * Countries customers can order to: setting "checkout.countries" (Settings › Shipping), else the keys of config
     * commerce.store.countries (default: the UK only). Labels from that config, else the ISO name.
     *
     * @return array<string, string> code => label
     */
    public static function countries(): array
    {
        $configured = (array) config('commerce.store.countries', self::COUNTRIES);
        $codes = setting('checkout.countries');
        $codes = is_array($codes) && $codes ? $codes : array_keys($configured ?: self::COUNTRIES);
        $out = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z]{2}$/', $code)) {
                $out[$code] = (string) ($configured[$code] ?? \Pine\Commerce\Services\Admin\Countries::name($code) ?? $code);
            }
        }

        return $out ?: self::COUNTRIES;
    }

    /** The country pre-selected at checkout: the shop's country when it sells there, else the first allowed one. */
    public static function defaultCountry(): string
    {
        $countries = static::countries();
        $base = \Pine\Commerce\Services\Tax\TaxSettings::baseLocation()->country;

        return array_key_exists($base, $countries) ? $base : (string) array_key_first($countries);
    }

    /** Country/state/postcode/city of the posted shipping_* / billing_* fields (for zones and tax). */
    public static function destinationFrom(array $input, string $prefix): ?array
    {
        $country = strtoupper(trim(is_string($input["{$prefix}_country"] ?? null) ? $input["{$prefix}_country"] : ''));
        if ($country === '') {
            return null;
        }
        $get = fn ($f) => is_string($input["{$prefix}_{$f}"] ?? null) ? trim($input["{$prefix}_{$f}"]) : '';

        return ['country' => $country, 'state' => $get('state'), 'postcode' => static::formatPostcode($get('postcode'), $country) ?? '', 'city' => $get('city')];
    }

    /** UK postcodes must be real UK postcodes; elsewhere 2–12 letters, digits, spaces and dashes. */
    public static function validPostcode(string $postcode, string $country = 'GB'): bool
    {
        $country = strtoupper(trim($country)) ?: 'GB';
        if (in_array($country, ['GB', 'IM', 'JE', 'GG'], true)) {
            return static::normalisePostcode($postcode) !== null;
        }

        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{0,10}[A-Za-z0-9]$/', trim($postcode));
    }

    /** Tidy a postcode for storage: UK postcodes as "SP4 0AA", others upper-cased and trimmed. */
    public static function formatPostcode(string $postcode, string $country = 'GB'): ?string
    {
        $postcode = trim($postcode);
        if ($postcode === '') {
            return null;
        }
        if (in_array(strtoupper($country) ?: 'GB', ['GB', 'IM', 'JE', 'GG'], true)) {
            return static::normalisePostcode($postcode) ?? $postcode;
        }

        return strtoupper((string) preg_replace('/\s+/', ' ', $postcode));
    }

    /** "sp40aa" -> "SP4 0AA"; null when it is not a UK postcode (incl. GIR 0AA and BFPO). */
    public static function normalisePostcode(string $postcode): ?string
    {
        $pc = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $postcode));
        if ($pc === 'GIR0AA') {
            return 'GIR 0AA';
        }
        if (preg_match('/^BFPO(\d{1,4})$/', $pc, $m)) {
            return 'BFPO '.$m[1];
        }
        if (preg_match('/^([A-Z]{1,2}[0-9][A-Z0-9]?)([0-9][A-Z]{2})$/', $pc, $m)) {
            return $m[1].' '.$m[2];
        }

        return null;
    }

    /** WooCommerce's WC_Validation::is_phone + at least 7 digits. */
    public static function validPhone(string $phone): bool
    {
        return trim((string) preg_replace('/[\s\#0-9_\-\+\/\(\)\.]/', '', $phone)) === ''
            && strlen((string) preg_replace('/\D/', '', $phone)) >= 7;
    }

    // ------------------------------------------------------------------ order creation

    /**
     * Create (or refresh the awaiting) pending order from the basket.
     *
     * @throws CartException when stock / coupons / shipping changed underneath the customer
     */
    public function placeOrder(array $data, Request $request, ?string $paymentTitle = null): Order
    {
        $cartModel = $this->cart->model();
        if (! $cartModel || $this->cart->isEmpty()) {
            throw new CartException('Sorry, your session has expired. Please return to the shop and try again.');
        }
        $user = $this->user();
        $attribution = Attribution::fromRequest($request);

        // Two checkouts at the same moment can compute the same next order number (unique index): the losing
        // transaction is rolled back completely, so it is simply run again with the next number.
        $order = retry(3, fn () => DB::transaction(function () use ($cartModel, $data, $request, $user, $attribution, $paymentTitle) {
            $cart = CartModel::whereKey($cartModel->id)->lockForUpdate()->first();
            if (! $cart || $cart->converted_at) {
                throw new CartException('Sorry, your session has expired. Please return to the shop and try again.');
            }

            // An unpaid order from this basket is reused - put its stock and coupon usage back first
            $order = $this->awaitingOrder($request, $cart);
            if ($order) {
                OrderStock::restore($order, false);
                OrderStock::reverseUsage($order);
            }

            $items = $cart->items()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw new CartException('Sorry, your session has expired. Please return to the shop and try again.');
            }
            $products = Product::whereIn('id', $items->pluck('product_id')->unique())
                ->with(['images', 'primaryCategory', 'categories:id'])->lockForUpdate()->get()->keyBy('id');
            $variations = ProductVariation::whereIn('id', $items->pluck('product_variation_id')->filter()->unique())
                ->lockForUpdate()->get()->keyBy('id');

            $lines = $this->lockedLines($items, $products, $variations);
            $coupons = $this->lockedCoupons($lines, $data['email'], $user?->id, $order?->id);
            $totals = $this->cart->calculate($lines, $coupons, $data['shipping_method'], ['shipping' => $data['shipping'], 'billing' => $data['billing']]);
            if (! $totals['shipping_method']) {
                throw new CartException('No shipping method has been selected. Please double check your address, or contact us if you need any help.');
            }

            $shipping = $data['shipping'];
            $billing = $data['billing'];
            $meta = array_merge((array) ($order?->meta ?? []), [
                'cart_id' => $cart->id,
                'stock_reduced' => false,
                'usage_recorded' => false,
                'coupon_discounts' => $totals['coupons'],
                'tax_rate' => $totals['tax_rate'],
                'shipping_tax' => $totals['shipping_tax'],
                'prices_include_tax' => ($totals['prices_include_tax'] ?? false) ? 'yes' : 'no',
                'shipping_zone' => $totals['shipping_zone']?->name,
                'different_billing' => $data['different_billing'],
                'attribution' => $attribution['meta'],
            ]);

            $attributes = [
                'user_id' => $user?->id,
                'currency' => 'GBP',
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount'],
                'shipping_total' => $totals['shipping'],
                'tax_total' => $totals['tax'],
                'shipping_tax' => $totals['shipping_tax'],
                'prices_include_tax' => (bool) ($totals['prices_include_tax'] ?? false),
                'total' => $totals['total'],
                'coupon_code' => $coupons ? implode(',', array_map(fn (Coupon $c) => $c->code, $coupons)) : null,
                'shipping_method' => $totals['shipping_method']->code,
                'shipping_method_title' => $totals['shipping_method']->name,
                'payment_method' => $totals['total'] > 0 ? $data['payment_method'] : null,
                'payment_method_title' => $totals['total'] > 0 ? $paymentTitle : null,
                'email' => $data['email'],
                'phone' => $billing['phone'],
                'billing_first_name' => $billing['first_name'],
                'billing_last_name' => $billing['last_name'],
                'billing_company' => $billing['company'],
                'billing_address_1' => $billing['address_1'],
                'billing_address_2' => $billing['address_2'],
                'billing_city' => $billing['city'],
                'billing_county' => $billing['county'],
                'billing_postcode' => $billing['postcode'],
                'billing_country' => $billing['country'],
                'shipping_first_name' => $shipping['first_name'],
                'shipping_last_name' => $shipping['last_name'],
                'shipping_company' => $shipping['company'],
                'shipping_address_1' => $shipping['address_1'],
                'shipping_address_2' => $shipping['address_2'],
                'shipping_city' => $shipping['city'],
                'shipping_county' => $shipping['county'],
                'shipping_postcode' => $shipping['postcode'],
                'shipping_country' => $shipping['country'],
                'shipping_phone' => $shipping['phone'],
                'customer_note' => $data['customer_note'],
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'source' => $attribution['source'],
                'source_type' => $attribution['source_type'],
                'meta' => $meta,
            ];

            if ($order) {
                $order->forceFill($attributes)->save();
                $order->items()->delete();
                if ($order->status === 'failed') {
                    $order->updateStatus('pending', 'Customer is retrying payment from the checkout.');
                }
            } else {
                $order = new Order;
                $order->forceFill($attributes + ['status' => 'pending', 'created_via' => 'checkout']);
                $order->save();
            }

            $order->taxLines()->delete();
            foreach ($totals['tax_lines'] ?? [] as $taxLine) {
                $order->taxLines()->create([
                    'tax_rate_id' => $taxLine['id'], 'label' => $taxLine['label'], 'rate' => $taxLine['rate'],
                    'compound' => $taxLine['compound'], 'tax_total' => $taxLine['tax'], 'shipping_tax_total' => $taxLine['shipping_tax'],
                ]);
            }
            foreach ($lines as $line) {
                $order->items()->create([
                    'product_id' => $line->product->id,
                    'product_variation_id' => $line->variation?->id,
                    'name' => $line->name(),
                    'sku' => $line->sku(),
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'subtotal' => $line->netSubtotal,   // without tax, like WooCommerce (= as entered when prices exclude tax)
                    'total' => $line->netTotal,
                    'tax' => $line->tax,
                    'subtotal_tax' => $line->subtotalTax,
                    'tax_class' => $line->isTaxable() ? \Pine\Commerce\Models\TaxClass::normalise($line->taxClass()) : null,
                    'taxes' => $line->taxes ?: null,
                    'options' => $line->options() ?: null,
                ]);
            }

            OrderStock::reduce($order);
            OrderStock::recordUsage($order);

            return $order;
        }), 50, fn ($e) => $e instanceof UniqueConstraintViolationException);

        try {
            $request->session()->put(self::SESSION_AWAITING, $order->id);
        } catch (\Throwable) {
        }

        event(new OrderPlaced($order));

        return $order->refresh();
    }

    /**
     * Before paying for an existing order again (order-pay page), take back the stock a failed payment
     * released - refusing when it has sold out in the meantime.
     *
     * @throws CartException
     */
    public function reclaimStock(Order $order): void
    {
        if (! OrderStock::tracks($order)) {
            return;
        }
        if ($order->meta['stock_reduced'] ?? false) {
            OrderStock::recordUsage($order);

            return;
        }

        DB::transaction(function () use ($order) {
            $items = $order->items()->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->filter())->lockForUpdate()->get()->keyBy('id');
            $variations = ProductVariation::whereIn('id', $items->pluck('product_variation_id')->filter())->lockForUpdate()->get()->keyBy('id');
            $pools = [];
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if (! $product) {
                    continue;
                }
                $variation = $item->product_variation_id ? $variations->get($item->product_variation_id) : null;
                $available = $this->cart->availableStock($product, $variation);
                if ($available === null) {
                    continue;
                }
                $key = $this->cart->stockKey($product, $variation);
                $pools[$key] ??= $available;
                $qty = max(0, (int) $item->quantity - (int) $item->refunded_quantity);
                if ($qty > $pools[$key]) {
                    throw new CartException(sprintf('Sorry, "%s" is no longer in stock so this order cannot be paid for. We apologise for any inconvenience caused.', $item->name));
                }
                $pools[$key] -= $qty;
            }
            OrderStock::reduce($order);
            OrderStock::recordUsage($order);
        });
        $order->refresh();
    }

    /** The account holder's / session's unpaid order for this basket, locked for update. */
    protected function awaitingOrder(Request $request, CartModel $cart): ?Order
    {
        try {
            $id = (int) $request->session()->get(self::SESSION_AWAITING);
        } catch (\Throwable) {
            $id = 0;
        }
        if (! $id) {
            return null;
        }
        $order = Order::whereKey($id)->lockForUpdate()->first();
        if (! $order || ! in_array($order->status, ['pending', 'failed'], true) || $order->paid_at
            || (int) ($order->meta['cart_id'] ?? 0) !== (int) $cart->id || ! OrderStock::tracks($order)
            || $order->payments()->where('status', 'succeeded')->exists()) {
            return null;
        }

        return $order;
    }

    /**
     * Priced lines built from the locked product rows, with the stock re-checked.
     *
     * @return Collection<int, CartLine>
     */
    protected function lockedLines(Collection $items, Collection $products, Collection $variations): Collection
    {
        $pools = [];
        $lines = collect();
        foreach ($items as $item) {
            /** @var CartItem $item */
            $product = $products->get($item->product_id);
            $variation = $item->product_variation_id ? $variations->get($item->product_variation_id) : null;
            if (! $product || ($item->product_variation_id && ! $variation)) {
                throw new CartException('Sorry, an item in your basket is no longer available and has been removed. Please review your basket.');
            }
            $item->setRelation('product', $product);
            $item->setRelation('variation', $variation);
            $this->cart->assertPurchasable($product, $variation);

            $qty = (int) $item->quantity;
            $name = $variation ? $product->name.' - '.implode(', ', (new CartLine(null, $product, $variation, 1, 0))->options()) : $product->name;
            if ($product->sold_individually && $qty > 1) {
                throw new CartException(sprintf('You cannot add another "%s" to your basket.', $product->name));
            }
            $available = $this->cart->availableStock($product, $variation);
            if ($available !== null) {
                $key = $this->cart->stockKey($product, $variation);
                $pools[$key] ??= $available;
                if ($qty > $pools[$key]) {
                    throw new CartException(sprintf('Sorry, we do not have enough "%s" in stock to fulfil your order (%d available). We apologise for any inconvenience caused.', rtrim($name, ' -'), max(0, $pools[$key])));
                }
                $pools[$key] -= $qty;
            }
            $lines->push(CartLine::fromItem($item));
        }

        return $lines;
    }

    /** Re-validate the basket's coupons against the locked rows (usage limits can change between page load and order). */
    protected function lockedCoupons(Collection $lines, string $email, ?int $userId, ?int $excludeOrderId): array
    {
        $codes = $this->cart->couponCodes();
        if (! $codes) {
            return [];
        }
        $rows = Coupon::whereIn(DB::raw('LOWER(code)'), array_map('mb_strtolower', $codes))->lockForUpdate()->get();
        $valid = [];
        foreach ($codes as $code) {
            $coupon = $rows->first(fn (Coupon $c) => mb_strtolower($c->code) === mb_strtolower($code));
            if (! $coupon) {
                throw new CartException(sprintf('Coupon "%s" cannot be applied because it does not exist.', $code));
            }
            if ($error = $this->engine->validate($coupon, $lines, $email, $userId, true, $excludeOrderId)) {
                throw new CartException($error);
            }
            $valid[] = $coupon;
        }

        return $valid;
    }

    // ------------------------------------------------------------------ after the order

    /** The basket became this order: close it (current visitor + the stored row). */
    public static function closeBasket(Order $order): void
    {
        $cartId = (int) ($order->meta['cart_id'] ?? 0);
        if ($cartId) {
            CartModel::whereKey($cartId)->whereNull('converted_at')->update(['converted_at' => now()]);
        }
        try {
            $cart = app(Cart::class);
            if ($cart->model()?->id === $cartId || ! $cartId) {
                $cart->markConverted();
            }
            if (request()->hasSession() && (int) request()->session()->get(self::SESSION_AWAITING) === (int) $order->id) {
                request()->session()->forget(self::SESSION_AWAITING);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Fallback of the scheduled task "orders.cancel-unpaid" (Scheduling\Tasks\CancelUnpaidOrders) for shops without
     * cron: unpaid card/PayPal orders hold stock only for "checkout.hold_stock_minutes" (default 60, WooCommerce's
     * "Hold stock"), so the checkout runs this sweep at most every 10 minutes – unless cron is running (heartbeat
     * "scheduler.last_run") or the task is switched off in config commerce.scheduler.tasks.
     */
    public static function cancelStaleOrders(): void
    {
        $minutes = CancelUnpaidOrders::minutes();
        if ($minutes <= 0 || ! Scheduler::configured('orders.cancel-unpaid') || ! Cache::add('checkout.stale-order-sweep', 1, 600)) {
            return;
        }
        try {
            if (! Scheduler::cronRunning()) {
                app(CancelUnpaidOrders::class)->cancel();
            }
        } catch (\Throwable $e) {
            Log::warning('Stale order sweep failed: '.$e->getMessage());
        }
    }

    /** Orders that need no payment (100% discount) are complete straight away. */
    public static function completeFreeOrder(Order $order): void
    {
        $order->paid_at ??= now();
        $order->save();
        $order->updateStatus('processing', 'Order does not require payment.');
        static::closeBasket($order);
    }

    /** Create the customer's account from the checkout (optional "Create an account?" box). */
    public function createAccount(array $data): User
    {
        $user = new User;
        $user->forceFill([
            'name' => trim(($data['billing']['first_name'] ?? '').' '.($data['billing']['last_name'] ?? '')) ?: $data['email'],
            'first_name' => $data['billing']['first_name'] ?? null,
            'last_name' => $data['billing']['last_name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['billing']['phone'] ?? null,
            'role' => 'customer',
            'is_active' => true,
            'password' => $data['account_password'],
        ])->save();

        return $user;
    }

    /** Save the checkout addresses as the customer's defaults (WooCommerce stores them on the customer). */
    public static function saveAddresses(User $user, Order $order): void
    {
        foreach (['billing', 'shipping'] as $type) {
            $values = [
                'first_name' => $order->{$type.'_first_name'},
                'last_name' => $order->{$type.'_last_name'},
                'company' => $order->{$type.'_company'},
                'address_1' => $order->{$type.'_address_1'},
                'address_2' => $order->{$type.'_address_2'},
                'city' => $order->{$type.'_city'},
                'county' => $order->{$type.'_county'},
                'postcode' => $order->{$type.'_postcode'},
                'country' => $order->{$type.'_country'} ?: 'GB',
                'phone' => $type === 'billing' ? $order->phone : $order->shipping_phone,
                'email' => $type === 'billing' ? $order->email : null,
            ];
            if (! $values['address_1']) {
                continue;
            }
            $address = $user->addresses()->where('type', $type)->where('is_default', true)->first();
            $address ? $address->forceFill($values)->save() : $user->addresses()->forceCreate($values + ['type' => $type, 'is_default' => true]);
        }
        $user->forceFill([
            'first_name' => $user->first_name ?: $order->billing_first_name,
            'last_name' => $user->last_name ?: $order->billing_last_name,
            'phone' => $user->phone ?: $order->phone,
        ])->save();
    }

    // ------------------------------------------------------------------ page data

    /** Values to pre-fill the form with: old input > signed-in customer's saved addresses > basket email. */
    public function prefill(): array
    {
        $user = $this->user();
        $country = static::defaultCountry();
        $values = ['billing_email' => $this->cart->email() ?? '', 'shipping_country' => $country, 'billing_country' => $country];
        if ($user) {
            $values['billing_email'] = $values['billing_email'] ?: $user->email;
            $addresses = $user->addresses()->where('is_default', true)->get()->keyBy('type');
            foreach (['shipping', 'billing'] as $type) {
                $a = $addresses->get($type) ?? $addresses->get($type === 'shipping' ? 'billing' : 'shipping');
                $values += [
                    "{$type}_first_name" => $a->first_name ?? $user->first_name,
                    "{$type}_last_name" => $a->last_name ?? $user->last_name,
                    "{$type}_company" => $a->company ?? null,
                    "{$type}_address_1" => $a->address_1 ?? null,
                    "{$type}_address_2" => $a->address_2 ?? null,
                    "{$type}_city" => $a->city ?? null,
                    "{$type}_state" => $a->county ?? null,
                    "{$type}_postcode" => $a->postcode ?? null,
                    "{$type}_country_saved" => $a->country ?? null,
                    "{$type}_phone" => $a->phone ?? $user->phone,
                ];
            }
        }

        foreach (['shipping', 'billing'] as $type) {
            $saved = $values["{$type}_country_saved"] ?? null;
            unset($values["{$type}_country_saved"]);
            if ($saved && array_key_exists(strtoupper($saved), static::countries())) {
                $values["{$type}_country"] = strtoupper($saved);
            }
        }

        return array_map(fn ($v) => $v ?? '', $values);
    }

    public function termsUrl(): ?string
    {
        $page = trim((string) setting('checkout.terms_page', 'terms-conditions'));
        if ($page === '' || $page === '0') {
            return null;
        }

        return preg_match('#^https?://#', $page) ? $page : url(trim($page, '/'));
    }

    public function privacyUrl(): string
    {
        $page = trim((string) setting('checkout.privacy_page', 'privacy-policy'));

        return preg_match('#^https?://#', $page) ? $page : url(trim($page, '/'));
    }

    protected function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
