<?php

namespace Pine\Commerce\Services\Checkout;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Illuminate\Support\Collection;

/**
 * WooCommerce coupon rules: validation (WC_Discounts::is_coupon_valid + checkout email checks) and the
 * discount maths (WC_Discounts::apply_coupon_*), done in integer pence so line discounts always add up.
 *
 * Types: percent (per eligible line), fixed_product (amount x qty per eligible line), fixed_cart (amount
 * split across the basket in proportion to line prices). Rules: active flag, start/expiry dates, total and
 * per-customer usage limits, min/max spend, product/category include & exclude lists, exclude sale items,
 * allowed emails (wildcards like *@example.com), individual use (enforced by Cart::applyCoupon) and free
 * shipping (read by the shipping calculation).
 */
class CouponEngine
{
    /** Types whose discount is limited to "eligible" lines (WooCommerce product coupon types). */
    public const PRODUCT_TYPES = ['percent', 'fixed_product'];

    /** Order statuses that do not count as a coupon use. */
    public const UNUSED_STATUSES = ['cancelled', 'failed'];

    /**
     * Validate a coupon for the given basket lines. Returns the customer-facing error, or null when valid.
     *
     * @param  Collection<int, CartLine>  $lines
     * @param  bool  $atCheckout  true when placing the order: email restrictions become mandatory
     */
    public function validate(Coupon $coupon, Collection $lines, ?string $email = null, ?int $userId = null, bool $atCheckout = false, ?int $excludeOrderId = null): ?string
    {
        $code = $coupon->code;

        if (! $coupon->is_active) {
            return sprintf('Coupon "%s" cannot be applied because it does not exist.', $code);
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return sprintf('Coupon "%s" cannot be applied because it is not valid.', $code);
        }
        if ($coupon->usage_limit && (int) $coupon->usage_count >= (int) $coupon->usage_limit) {
            return sprintf('Usage limit for coupon "%s" has been reached.', $code);
        }
        if ($coupon->usage_limit_per_user && ($email || $userId)
            && $this->usesBy($coupon, $email, $userId, $excludeOrderId) >= (int) $coupon->usage_limit_per_user) {
            return (int) $coupon->usage_limit_per_user === 1
                ? sprintf('Sorry, coupon "%s" can only be used once per customer and you have already used it.', $code)
                : sprintf('Sorry, coupon "%s" can only be used %d times per customer and you have already used it %2$d times.', $code, (int) $coupon->usage_limit_per_user);
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            return sprintf('Coupon "%s" has expired.', $code);
        }

        $subtotal = round($lines->sum(fn (CartLine $l) => $l->subtotal()), 2);
        if ($coupon->minimum_spend !== null && (float) $coupon->minimum_spend > 0 && $subtotal < (float) $coupon->minimum_spend) {
            return sprintf('The minimum spend for coupon "%s" is %s.', $code, money($coupon->minimum_spend));
        }
        if ($coupon->maximum_spend !== null && (float) $coupon->maximum_spend > 0 && $subtotal > (float) $coupon->maximum_spend) {
            return sprintf('The maximum spend for coupon "%s" is %s.', $code, money($coupon->maximum_spend));
        }

        $productIds = $this->ids($coupon->product_ids);
        if ($productIds && ! $lines->contains(fn (CartLine $l) => $this->matchesProducts($l, $productIds))) {
            return sprintf('Sorry, coupon "%s" is not applicable to your cart contents.', $code);
        }
        $categoryIds = $this->ids($coupon->category_ids);
        if ($categoryIds && ! $lines->contains(fn (CartLine $l) => array_intersect($l->categoryIds(), $categoryIds))) {
            return sprintf('Sorry, coupon "%s" is not applicable to your cart contents.', $code);
        }

        if (in_array($coupon->type, self::PRODUCT_TYPES, true)) {
            if (! $lines->contains(fn (CartLine $l) => $this->isValidForLine($coupon, $l))) {
                return $coupon->exclude_sale_items && $lines->isNotEmpty() && $lines->every(fn (CartLine $l) => $l->isOnSale())
                    ? sprintf('Sorry, coupon "%s" is not valid for sale items.', $code)
                    : sprintf('Sorry, coupon "%s" is not applicable to your cart contents.', $code);
            }
        } else {
            $excludedProducts = $this->ids($coupon->excluded_product_ids);
            if ($excludedProducts) {
                $names = $lines->filter(fn (CartLine $l) => $this->matchesProducts($l, $excludedProducts))->map->name()->unique();
                if ($names->isNotEmpty()) {
                    return sprintf('Sorry, coupon "%s" is not applicable to the products: %s.', $code, $names->implode(', '));
                }
            }
            $excludedCategories = $this->ids($coupon->excluded_category_ids);
            if ($excludedCategories) {
                $hit = $lines->flatMap(fn (CartLine $l) => array_intersect($l->categoryIds(), $excludedCategories))->unique()->values()->all();
                if ($hit) {
                    return sprintf('Sorry, coupon "%s" is not applicable to the categories: %s.', $code, Category::whereIn('id', $hit)->pluck('name')->implode(', '));
                }
            }
            if ($coupon->exclude_sale_items && $lines->contains(fn (CartLine $l) => $l->isOnSale())) {
                return sprintf('Sorry, coupon "%s" is not valid for sale items.', $code);
            }
        }

        $allowed = array_values(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) $coupon->allowed_emails)));
        if ($allowed) {
            if (! $email) {
                if ($atCheckout) {
                    return sprintf('Please enter a valid email at checkout to use coupon code "%s".', $code);
                }
            } elseif (! $this->emailAllowed($email, $allowed)) {
                return $atCheckout
                    ? sprintf('Sorry, it seems the coupon "%s" is not yours - it has now been removed from your order.', $code)
                    : sprintf('Please enter a valid email to use coupon code "%s".', $code);
            }
        }

        return null;
    }

    /** Does the coupon discount this line? (WC_Coupon::is_valid_for_product) */
    public function isValidForLine(Coupon $coupon, CartLine $line): bool
    {
        if (! in_array($coupon->type, self::PRODUCT_TYPES, true)) {
            return true; // fixed_cart spreads across every line
        }
        $productIds = $this->ids($coupon->product_ids);
        $categoryIds = $this->ids($coupon->category_ids);
        $valid = ! $productIds && ! $categoryIds;
        if ($productIds && $this->matchesProducts($line, $productIds)) {
            $valid = true;
        }
        if ($categoryIds && array_intersect($line->categoryIds(), $categoryIds)) {
            $valid = true;
        }
        if (($excluded = $this->ids($coupon->excluded_product_ids)) && $this->matchesProducts($line, $excluded)) {
            $valid = false;
        }
        if (($excludedCats = $this->ids($coupon->excluded_category_ids)) && array_intersect($line->categoryIds(), $excludedCats)) {
            $valid = false;
        }
        if ($coupon->exclude_sale_items && $line->isOnSale()) {
            $valid = false;
        }

        return $valid;
    }

    /**
     * Work out the discounts, writing each line's ->discount. Returns [coupon code => amount].
     *
     * @param  array<int, Coupon>  $coupons  already validated
     * @param  Collection<int, CartLine>  $lines
     */
    public function apply(array $coupons, Collection $lines): array
    {
        $lines = $lines->values();
        $original = [];
        $remaining = [];
        foreach ($lines as $i => $line) {
            $original[$i] = $remaining[$i] = (int) round($line->subtotal() * 100);
            $line->discount = 0.0;
        }

        // WooCommerce order: fixed product first, then percentage, then fixed basket
        $priority = ['fixed_product' => 0, 'percent' => 1, 'fixed_cart' => 2];
        usort($coupons, fn (Coupon $a, Coupon $b) => ($priority[$a->type] ?? 3) <=> ($priority[$b->type] ?? 3));

        // Most expensive lines first, like WC_Discounts::sort_by_price (decides who gets rounding pennies)
        $order = array_keys($original);
        usort($order, fn ($a, $b) => $lines[$b]->unitPrice <=> $lines[$a]->unitPrice ?: $a <=> $b);

        $totals = [];
        foreach ($coupons as $coupon) {
            $eligible = array_values(array_filter($order, fn ($i) => $remaining[$i] > 0 && $this->isValidForLine($coupon, $lines[$i])));
            $amount = (int) round((float) $coupon->amount * 100);
            $given = 0;

            if ($eligible && $amount > 0) {
                switch ($coupon->type) {
                    case 'percent':
                        $pct = min(100.0, (float) $coupon->amount);
                        $wanted = 0;
                        foreach ($eligible as $i) {
                            $wanted += $original[$i] * $pct / 100;
                            $d = min($remaining[$i], (int) floor($original[$i] * $pct / 100));
                            $remaining[$i] -= $d;
                            $given += $d;
                        }
                        $given += $this->spreadPennies((int) round($wanted) - $given, $eligible, $remaining);
                        break;

                    case 'fixed_product':
                        foreach ($eligible as $i) {
                            $d = min($remaining[$i], $amount * $lines[$i]->quantity);
                            $remaining[$i] -= $d;
                            $given += $d;
                        }
                        break;

                    default: // fixed_cart
                        $pool = array_sum(array_map(fn ($i) => $remaining[$i], $eligible));
                        $target = min($amount, $pool);
                        foreach ($eligible as $i) {
                            $d = $pool > 0 ? min($remaining[$i], (int) floor($remaining[$i] / $pool * $target)) : 0;
                            $remaining[$i] -= $d;
                            $given += $d;
                        }
                        $given += $this->spreadPennies($target - $given, $eligible, $remaining);
                }
            }

            $totals[$coupon->code] = round($given / 100, 2);
        }

        foreach ($lines as $i => $line) {
            $line->discount = round(($original[$i] - $remaining[$i]) / 100, 2);
        }

        return $totals;
    }

    /** Hand out leftover rounding pennies one at a time. Returns how many were given. */
    protected function spreadPennies(int $left, array $eligible, array &$remaining): int
    {
        $given = 0;
        while ($left > 0) {
            $progress = false;
            foreach ($eligible as $i) {
                if ($left <= 0) {
                    break;
                }
                if ($remaining[$i] > 0) {
                    $remaining[$i]--;
                    $left--;
                    $given++;
                    $progress = true;
                }
            }
            if (! $progress) {
                break;
            }
        }

        return $given;
    }

    /** Orders by this customer that used the coupon (cancelled/failed orders excluded, like WooCommerce). */
    public function usesBy(Coupon $coupon, ?string $email, ?int $userId, ?int $excludeOrderId = null): int
    {
        $code = mb_strtolower($coupon->code);

        return Order::query()
            ->whereNotIn('status', self::UNUSED_STATUSES)
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->where(function ($q) use ($code) {
                $q->whereRaw('LOWER(coupon_code) = ?', [$code])
                    ->orWhereRaw("CONCAT(',', REPLACE(LOWER(coupon_code), ' ', ''), ',') LIKE ?", ['%,'.addcslashes($code, '%_\\').',%']);
            })
            ->where(function ($q) use ($email, $userId) {
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                }
                if ($userId) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->count();
    }

    public function emailAllowed(string $email, array $allowed): bool
    {
        $email = mb_strtolower(trim($email));
        foreach ($allowed as $pattern) {
            if ($pattern === $email) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/u';
                if (preg_match($regex, $email)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function matchesProducts(CartLine $line, array $ids): bool
    {
        // Coupon product lists hold product ids (variations share their parent's id space here)
        return in_array((int) $line->product->id, $ids, true);
    }

    protected function ids($value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $value))));
    }
}
