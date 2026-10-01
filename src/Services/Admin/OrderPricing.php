<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Services\Shipping\ShippingRates;
use Pine\Commerce\Services\Tax\TaxEngine;
use Pine\Commerce\Services\Tax\TaxLocation;
use Pine\Commerce\Services\Tax\TaxRates;
use Pine\Commerce\Services\Tax\TaxSettings;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prices a back-office order (create / edit order): product lines with optional price overrides, coupon codes
 * (validated and applied with the storefront's WooCommerce coupon rules when Pine\Commerce\Services\Checkout\CouponEngine
 * exists), an optional manual discount, a shipping method (priced by its type) with optional cost override, and tax
 * from Settings › Tax for the order's address (input 'address' => ['shipping' => [country, state, postcode, city], 'billing' => …]).
 * The same quote is shown live in the form and used when the order is saved, so what staff see is what is stored.
 *
 *   $quote = OrderPricing::quote([
 *       'lines' => [['product_id' => 12, 'variation_id' => null, 'quantity' => 1, 'unit_price' => null]],
 *       'coupon_code' => 'SAVE10', 'manual_discount' => 0, 'shipping_method' => 'free_shipping', 'shipping_cost' => null,
 *       'email' => 'jo@example.com', 'user_id' => null, 'exclude_order_id' => null,
 *       'trusted_coupons' => [],   // codes already on an order being edited: applied without re-checking dates/limits
 *   ]);
 */
class OrderPricing
{
    public const COUPON_ENGINE = 'Pine\\Commerce\\Services\\Checkout\\CouponEngine';

    public const CART_LINE = 'Pine\\Commerce\\Services\\Checkout\\CartLine';

    /**
     * @param  array{lines?: array, coupon_code?: ?string, manual_discount?: float|string|null, shipping_method?: ?string,
     *               shipping_cost?: float|string|null, email?: ?string, user_id?: ?int, exclude_order_id?: ?int}  $input
     * @return array{lines: list<array>, subtotal: float, coupon_discount: float, manual_discount: float, discount: float,
     *               coupons: array<string, float>, coupon_codes: list<string>, coupon_errors: list<string>, shipping: float,
     *               shipping_method: ?ShippingMethod, shipping_title: ?string, tax: float, tax_rate: float, total: float,
     *               count: int, warnings: list<string>}
     */
    public static function quote(array $input): array
    {
        $rawLines = array_values(array_filter((array) ($input['lines'] ?? []), 'is_array'));
        $productIds = collect($rawLines)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $variationIds = collect($rawLines)->pluck('variation_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $products = $productIds->isEmpty() ? collect() : Product::withTrashed()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1), 'categories:id'])
            ->whereIn('id', $productIds)->get()->keyBy('id');
        $variations = $variationIds->isEmpty() ? collect() : ProductVariation::whereIn('id', $variationIds)->get()->keyBy('id');

        $lines = [];
        $warnings = [];
        $stockUse = [];
        foreach ($rawLines as $i => $raw) {
            $qty = max(1, min(9999, (int) ($raw['quantity'] ?? 1)));
            $product = isset($raw['product_id']) ? $products->get((int) $raw['product_id']) : null;
            $variation = null;
            if ($product && ! empty($raw['variation_id'])) {
                $variation = $variations->get((int) $raw['variation_id']);
                if ($variation && $variation->product_id !== $product->id) {
                    $variation = null;
                }
            }
            if (! $product && ! isset($raw['name'])) {
                continue; // unknown product (deleted since the page loaded)
            }

            $listPrice = $variation?->currentPrice() ?? $product?->currentPrice();
            $override = static::money($raw['unit_price'] ?? null);
            $unit = $override ?? ($listPrice !== null ? round($listPrice, 2) : 0.0);

            $options = $raw['options'] ?? null;
            if ($variation) {
                $options = static::variationOptions($variation);
            }

            $line = [
                'key' => (string) ($raw['key'] ?? $i),
                'order_item_id' => isset($raw['order_item_id']) ? (int) $raw['order_item_id'] : null,
                'product_id' => $product?->id,
                'variation_id' => $variation?->id,
                'name' => $product?->name ?? (string) $raw['name'],
                'sku' => $variation?->sku ?: ($product?->sku ?? ($raw['sku'] ?? null)),
                'options' => $options ?: null,
                'image' => ($variation?->image ?: $product?->images->first()?->path) ? media_url($variation?->image ?: $product->images->first()->path) : null,
                'quantity' => $qty,
                'unit_price' => round($unit, 2),
                'list_price' => $listPrice !== null ? round($listPrice, 2) : null,
                'price_overridden' => $override !== null && $listPrice !== null && abs($override - $listPrice) > 0.004,
                'subtotal' => round($unit * $qty, 2),
                'discount' => 0.0,
                'total' => round($unit * $qty, 2),
                'tax' => 0.0,
                'taxable' => ! in_array($product?->tax_status ?? 'taxable', ['none', 'shipping'], true),
                'tax_class' => $variation && $variation->tax_class && $variation->tax_class !== 'parent' ? $variation->tax_class : $product?->tax_class,
                'is_variable' => $product?->type === 'variable',
                'stock' => null,
                'warning' => null,
            ];

            // Stock warnings (staff may still sell – e.g. a phone order for stock that is on its way)
            $holder = $variation && $variation->manage_stock ? $variation : ($product && $product->manage_stock ? $product : null);
            if ($product?->trashed()) {
                $line['warning'] = 'This product has been deleted.';
            } elseif ($product && $product->type === 'variable' && ! $variation) {
                $line['warning'] = 'Choose an option.';
            } elseif ($holder) {
                $stockKey = ($holder instanceof ProductVariation ? 'v' : 'p').$holder->id;
                $stockUse[$stockKey] = ($stockUse[$stockKey] ?? 0) + $qty - (int) ($raw['reserved'] ?? 0);
                $line['stock'] = (int) $holder->stock_quantity;
                if ($stockUse[$stockKey] > (int) $holder->stock_quantity) {
                    $line['warning'] = (int) $holder->stock_quantity > 0 ? 'Only '.(int) $holder->stock_quantity.' in stock.' : 'Out of stock.';
                }
            } elseif ($variation ? $variation->stock_status === 'outofstock' : $product?->stock_status === 'outofstock') {
                $line['warning'] = 'Marked as out of stock.';
            }
            if ($line['warning']) {
                $warnings[] = $line['name'].': '.$line['warning'];
            }

            $lines[] = ['data' => $line, 'product' => $product, 'variation' => $variation];
        }

        $subtotal = round(array_sum(array_map(fn ($l) => $l['data']['subtotal'], $lines)), 2);

        // Coupons ------------------------------------------------------------------------------
        [$couponTotals, $couponCodes, $couponErrors, $couponModels] = static::applyCoupons($lines, (string) ($input['coupon_code'] ?? ''), $input);
        $couponDiscount = round(array_sum($couponTotals), 2);

        // Manual discount, spread over what is left of each line (in pennies so lines add up) ------
        $manual = min(max(0.0, static::money($input['manual_discount'] ?? null) ?? 0.0), round($subtotal - $couponDiscount, 2));
        if ($manual > 0) {
            $remaining = array_map(fn ($l) => (int) round(($l['data']['subtotal'] - $l['data']['discount']) * 100), $lines);
            $pool = array_sum($remaining);
            $target = (int) round($manual * 100);
            $given = 0;
            foreach ($lines as $i => $l) {
                $d = $pool > 0 ? min($remaining[$i], (int) floor($remaining[$i] / $pool * $target)) : 0;
                $lines[$i]['data']['discount'] = round($l['data']['discount'] + $d / 100, 2);
                $remaining[$i] -= $d;
                $given += $d;
            }
            for ($i = 0; $given < $target && $lines; $i = ($i + 1) % count($lines)) {
                if ($remaining[$i] > 0) {
                    $lines[$i]['data']['discount'] = round($lines[$i]['data']['discount'] + 0.01, 2);
                    $remaining[$i]--;
                    $given++;
                } elseif (array_sum($remaining) <= 0) {
                    break;
                }
            }
        }
        $discount = round($couponDiscount + $manual, 2);

        // Shipping ---------------------------------------------------------------------------------
        $method = ! empty($input['shipping_method']) ? ShippingMethod::where('code', $input['shipping_method'])->first() : null;
        $shippingOverride = static::money($input['shipping_cost'] ?? null);
        $freeCoupon = $couponModels && collect($couponModels)->contains(fn (Coupon $c) => $c->free_shipping);
        $shipping = $shippingOverride ?? ($method ? static::methodCost($method, $lines, $subtotal, $discount, $freeCoupon) : 0.0);
        if ($method && $freeCoupon && str_starts_with((string) $method->code, 'free')) {
            $shipping = $shippingOverride ?? 0.0;
        }
        if (! $lines) {
            $shipping = 0.0;
        }

        // Tax (Settings › Tax: rates for the order's address) ------------------------------------
        $address = (array) ($input['address'] ?? []);
        $base = TaxSettings::baseLocation();
        $shipTo = TaxLocation::fromAddress($address['shipping'] ?? null) ?? TaxLocation::fromAddress($address['billing'] ?? null);
        $taxAt = match (true) {
            $method && $method->typeKey() === 'local_pickup', TaxSettings::basedOn() === 'base' => $base,
            TaxSettings::basedOn() === 'billing' => TaxLocation::fromAddress($address['billing'] ?? null) ?? $shipTo ?? $base,
            default => $shipTo ?? $base,
        };
        $specs = [];
        foreach ($lines as $i => $l) {
            $data = $l['data'];
            $data['total'] = round($data['subtotal'] - $data['discount'], 2);
            $lines[$i]['data'] = $data;
            $specs[] = ['key' => $i, 'subtotal' => $data['subtotal'], 'total' => $data['total'], 'class' => $data['tax_class'], 'taxable' => $data['taxable']];
        }
        $result = app(TaxEngine::class)->calculate($specs, $lines ? ['cost' => $shipping, 'taxable' => TaxSettings::shippingTaxable() && (! $method || $method->isTaxable())] : null, $taxAt);
        $out = [];
        foreach ($lines as $i => $l) {
            $data = $l['data'];
            $r = $result['lines'][$i];
            $data['tax'] = $r['tax'];
            $data['subtotal_tax'] = $r['subtotal_tax'];
            $data['taxes'] = $r['taxes'];
            $data['tax_class'] = $data['taxable'] ? $r['class'] : null;
            // subtotal/total stay "as entered" (what the form shows); the order stores amounts without tax
            $data['net_subtotal'] = $r['net_subtotal'];
            $data['net_total'] = $r['net_total'];
            $out[] = $data;
        }

        return [
            'lines' => $out,
            'subtotal' => $result['subtotal'],
            'coupon_discount' => $couponDiscount,
            'manual_discount' => round($manual, 2),
            'discount' => $result['discount'],
            'coupons' => $couponTotals,
            'coupon_codes' => $couponCodes,
            'coupon_errors' => $couponErrors,
            'shipping' => $result['shipping_net'],
            'shipping_tax' => $result['shipping_tax'],
            'shipping_method' => $method,
            'shipping_title' => $method?->name,
            'tax' => $result['tax'],
            'tax_rate' => TaxRates::totalPercent(TaxRates::find(null, $taxAt)),
            'tax_lines' => array_values($result['rates']),
            'prices_include_tax' => $result['inclusive'],
            'total' => $result['total'],
            'count' => (int) array_sum(array_column($out, 'quantity')),
            'warnings' => $warnings,
        ];
    }

    /** A method's price for these lines by its type (flat, free, weight/price table, pickup); its flat cost as a fallback. */
    protected static function methodCost(ShippingMethod $method, array $lines, float $subtotal, float $discount, bool $freeCoupon): float
    {
        $cartLines = collect();
        foreach ($lines as $l) {
            if ($l['product'] && class_exists(self::CART_LINE)) {
                $class = self::CART_LINE;
                $cartLine = new $class(null, $l['product'], $l['variation'], (int) $l['data']['quantity'], (float) $l['data']['unit_price']);
                $cartLine->discount = (float) $l['data']['discount'];
                $cartLines->push($cartLine);
            }
        }
        try {
            $cost = app(ShippingRates::class)->cost($method, $cartLines, $subtotal, $discount, $freeCoupon);
        } catch (Throwable) {
            $cost = null;
        }

        return round($cost ?? (float) $method->cost, 2);
    }

    /** JSON-safe version of a quote for the live order form. */
    public static function present(array $quote): array
    {
        return array_merge($quote, [
            'shipping_method' => $quote['shipping_method']?->code,
            'tax_lines' => array_map(fn ($t) => $t + ['formatted' => money($t['tax'] + $t['shipping_tax'])], $quote['tax_lines'] ?? []),
            'formatted' => [
                'subtotal' => money($quote['subtotal']),
                'discount' => money($quote['discount']),
                'shipping' => money($quote['shipping']),
                'tax' => money($quote['tax']),
                'total' => money($quote['total']),
            ],
        ]);
    }

    /** Combined standard rate at the shop's address (kept for callers of the pre-v1.1 single rate). */
    public static function taxRate(): float
    {
        return TaxRates::totalPercent(TaxRates::find(null, TaxSettings::baseLocation()));
    }

    /** "12", "£1,025.50" -> float; blank -> null. */
    public static function money(mixed $value): ?float
    {
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $clean = is_string($value) ? str_replace(['£', ',', ' '], '', $value) : $value;

        return is_numeric($clean) ? max(0.0, round((float) $clean, 2)) : null;
    }

    /** @return array<string, string> e.g. ['Memory' => '16GB'] */
    public static function variationOptions(ProductVariation $variation): array
    {
        try {
            if (class_exists(self::CART_LINE)) {
                $class = self::CART_LINE;

                return (new $class(null, $variation->product, $variation, 1, 0))->options();
            }
        } catch (Throwable) {
            // fall through
        }

        return $variation->namedOptions();
    }

    /**
     * @param  list<array{data: array, product: ?Product, variation: ?ProductVariation}>  $lines  (discounts written in place)
     * @return array{0: array<string, float>, 1: list<string>, 2: list<string>, 3: list<Coupon>}
     */
    protected static function applyCoupons(array &$lines, string $codes, array $input): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => trim($c), preg_split('/[\s,]+/', $codes) ?: []))));
        if (! $codes || ! $lines) {
            return [[], [], [], []];
        }

        $found = Coupon::query()->whereIn(DB::raw('LOWER(code)'), array_map('mb_strtolower', $codes))->get()
            ->keyBy(fn (Coupon $c) => mb_strtolower($c->code));
        $errors = [];
        $valid = [];
        $trusted = array_map(fn ($c) => mb_strtolower(trim((string) $c)), (array) ($input['trusted_coupons'] ?? []));
        $productLines = array_filter($lines, fn ($l) => $l['product'] !== null);

        $engine = class_exists(self::COUPON_ENGINE) && class_exists(self::CART_LINE) ? app(self::COUPON_ENGINE) : null;
        $cartLines = collect();
        $map = [];
        if ($engine) {
            $class = self::CART_LINE;
            foreach ($productLines as $i => $l) {
                $cartLine = new $class(null, $l['product'], $l['variation'], (int) $l['data']['quantity'], (float) $l['data']['unit_price']);
                $cartLines->push($cartLine);
                $map[] = $i;
            }
        }

        foreach ($codes as $code) {
            $coupon = $found->get(mb_strtolower($code));
            if (! $coupon) {
                $errors[] = 'Discount code "'.$code.'" doesn’t exist.';

                continue;
            }
            if ($valid && ($coupon->individual_use || collect($valid)->contains(fn (Coupon $c) => $c->individual_use))) {
                $errors[] = 'Discount code "'.$coupon->code.'" can’t be combined with other codes.';

                continue;
            }
            $error = null;
            if (in_array(mb_strtolower($coupon->code), $trusted, true)) {
                // Already on the order being edited: keep it even if it has since expired or run out
            } elseif ($engine) {
                try {
                    $error = $engine->validate($coupon, $cartLines, $input['email'] ?? null, isset($input['user_id']) ? (int) $input['user_id'] : null, false, $input['exclude_order_id'] ?? null);
                } catch (Throwable $e) {
                    $error = 'Discount code "'.$coupon->code.'" couldn’t be checked.';
                }
            } elseif (! $coupon->is_active || ($coupon->expires_at && $coupon->expires_at->isPast())) {
                $error = 'Discount code "'.$coupon->code.'" isn’t active.';
            }
            if ($error) {
                $errors[] = $error;

                continue;
            }
            $valid[] = $coupon;
        }

        if (! $valid) {
            return [[], [], $errors, []];
        }

        $totals = [];
        if ($engine) {
            $totals = $engine->apply($valid, $cartLines);
            foreach ($cartLines as $n => $cartLine) {
                $lines[$map[$n]]['data']['discount'] = round((float) $cartLine->discount, 2);
            }
        } else {
            // Minimal fallback: percentage / fixed amounts across all product lines
            foreach ($valid as $coupon) {
                $base = array_sum(array_map(fn ($l) => $l['data']['subtotal'] - $l['data']['discount'], $productLines));
                $amount = match ($coupon->type) {
                    'percent' => round($base * min(100, (float) $coupon->amount) / 100, 2),
                    'fixed_product' => round((float) $coupon->amount * array_sum(array_map(fn ($l) => $l['data']['quantity'], $productLines)), 2),
                    default => (float) $coupon->amount,
                };
                $amount = min($amount, $base);
                foreach ($productLines as $i => $l) {
                    $share = $base > 0 ? round($amount * ($lines[$i]['data']['subtotal'] - $lines[$i]['data']['discount']) / $base, 2) : 0;
                    $lines[$i]['data']['discount'] = round($lines[$i]['data']['discount'] + $share, 2);
                }
                $totals[$coupon->code] = round($amount, 2);
            }
        }

        return [$totals, array_map(fn (Coupon $c) => $c->code, $valid), $errors, $valid];
    }
}
