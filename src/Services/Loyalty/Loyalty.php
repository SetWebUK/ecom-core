<?php

namespace Pine\Commerce\Services\Loyalty;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\LoyaltyPoint;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Support\Features;

/**
 * Loyalty points, like WooCommerce Points & Rewards (feature "loyalty", Admin › Settings › Loyalty points):
 *  - earned on paid orders of signed-in customers: loyalty.earn_per_pound points per £1 of the items' price after
 *    discounts (VAT included, delivery not), once per order, when it becomes processing / completed
 *  - spent at checkout: loyalty.points_per_pound points = £1 off, up to loyalty.max_discount_percent of the items,
 *    once the balance reaches loyalty.min_redeem; shown as a discount line ("Points (400)") like a coupon
 *  - a cancelled, failed or refunded order takes back what it earned and gives back what it spent
 *  - loyalty.signup_points for a new account (the storefront registration and "create an account" at checkout)
 * The balance is the sum of the customer's LoyaltyPoint rows (a ledger: nothing is ever overwritten).
 */
class Loyalty
{
    /** Code of the basket discount line for spent points (never a real coupon). */
    public const CODE = 'LOYALTY-POINTS';

    public static function enabled(): bool
    {
        return Features::enabled('loyalty', false);
    }

    /** Points per £1 spent. */
    public static function earnRate(): float
    {
        return max(0.0, (float) setting('loyalty.earn_per_pound', config('commerce.loyalty.earn_per_pound', 1)));
    }

    /** Points for £1 off. */
    public static function redeemRate(): int
    {
        return max(1, (int) setting('loyalty.points_per_pound', config('commerce.loyalty.points_per_pound', 100)));
    }

    public static function signupPoints(): int
    {
        return max(0, (int) setting('loyalty.signup_points', config('commerce.loyalty.signup_points', 0)));
    }

    public static function minRedeem(): int
    {
        return max(0, (int) setting('loyalty.min_redeem', config('commerce.loyalty.min_redeem', 0)));
    }

    /** At most this share of the items' price can be paid with points. */
    public static function maxPercent(): int
    {
        return min(100, max(1, (int) setting('loyalty.max_discount_percent', config('commerce.loyalty.max_discount_percent', 100))));
    }

    /** "points" – what the shop calls them. */
    public static function label(): string
    {
        return trim((string) setting('loyalty.label', config('commerce.loyalty.label', 'points'))) ?: 'points';
    }

    public static function balance(?int $userId): int
    {
        return $userId ? (int) LoyaltyPoint::query()->where('user_id', $userId)->sum('points') : 0;
    }

    /** What the points are worth in pounds (rounded down to the penny). */
    public static function value(int $points): float
    {
        return floor(max(0, $points) / static::redeemRate() * 100) / 100;
    }

    /** Points earned by spending this much. */
    public static function pointsFor(float $spend): int
    {
        return (int) floor(max(0.0, $spend) * static::earnRate() + 1e-9);
    }

    /** Add a ledger row (0 points are not recorded). */
    public static function add(int $userId, int $points, string $type, ?int $orderId = null, ?string $note = null, ?int $staffId = null): ?LoyaltyPoint
    {
        if ($points === 0) {
            return null;
        }

        return LoyaltyPoint::query()->create([
            'user_id' => $userId, 'points' => $points, 'type' => $type, 'order_id' => $orderId,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'created_by' => $staffId,
        ]);
    }

    // ------------------------------------------------------------------ orders

    /** A paid order earns its points (once). */
    public static function earnFor(Order $order): void
    {
        if (! static::enabled() || ! $order->user_id || static::has($order, 'earn')) {
            return;
        }
        $spend = (float) $order->items()->sum(DB::raw('total + tax'));
        $points = static::pointsFor($spend);
        if ($points > 0) {
            static::add((int) $order->user_id, $points, 'earn', $order->id, 'Order #'.$order->number);
        }
    }

    /** A cancelled / failed / refunded order takes back what it earned and gives back what it spent (once each). */
    public static function reverseFor(Order $order): void
    {
        if (! $order->user_id) {
            return;
        }
        $earned = (int) LoyaltyPoint::query()->where('order_id', $order->id)->where('type', 'earn')->value('points');
        if ($earned > 0 && ! static::has($order, 'reverse')) {
            static::add((int) $order->user_id, -$earned, 'reverse', $order->id, 'Order #'.$order->number.' '.$order->status);
        }
        $spent = (int) LoyaltyPoint::query()->where('order_id', $order->id)->where('type', 'redeem')->value('points');
        if ($spent < 0 && ! static::has($order, 'refund')) {
            static::add((int) $order->user_id, -$spent, 'refund', $order->id, 'Order #'.$order->number.' '.$order->status);
        }
    }

    /**
     * Record the points spent on a freshly placed order (inside the checkout transaction). A retried order replaces
     * its earlier row, so the points are only ever spent once.
     */
    public static function redeemFor(Order $order, int $points): void
    {
        if (! $order->user_id) {
            return;
        }
        LoyaltyPoint::query()->where('order_id', $order->id)->where('type', 'redeem')->delete();
        if ($points > 0) {
            static::add((int) $order->user_id, -$points, 'redeem', $order->id, 'Order #'.$order->number);
        }
    }

    protected static function has(Order $order, string $type): bool
    {
        return LoyaltyPoint::query()->where('order_id', $order->id)->where('type', $type)->exists();
    }

    // ------------------------------------------------------------------ basket

    /**
     * How many of the customer's points can pay for items worth $itemsTotal: the balance (once it reaches the
     * minimum), capped at the maximum share of the items, in whole points.
     */
    public static function usable(?int $userId, float $itemsTotal): int
    {
        if (! static::enabled() || ! $userId) {
            return 0;
        }
        $balance = static::balance($userId);
        if ($balance <= 0 || $balance < static::minRedeem()) {
            return 0;
        }
        $cap = (int) floor($itemsTotal * static::maxPercent() / 100 * static::redeemRate());

        return max(0, min($balance, $cap));
    }

    /** The basket / order discount line for spent points: an unsaved fixed-basket Coupon. */
    public static function coupon(int $points): Coupon
    {
        $coupon = new Coupon;
        $coupon->forceFill([
            'code' => self::CODE, 'label' => ucfirst(static::label()).' ('.number_format($points).')',
            'type' => 'fixed_cart', 'amount' => static::value($points), 'auto_apply' => true, 'is_active' => true,
        ]);

        return $coupon;
    }
}
