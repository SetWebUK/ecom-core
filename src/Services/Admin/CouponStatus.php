<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\Coupon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A coupon's lifecycle state for the back office.
 *   active    – switched on, started, not expired, uses left
 *   scheduled – switched on, start date in the future
 *   expired   – end date passed or usage limit reached
 *   disabled  – switched off by staff
 */
class CouponStatus
{
    public const LABELS = [
        'active' => 'Active',
        'scheduled' => 'Scheduled',
        'expired' => 'Expired',
        'disabled' => 'Disabled',
    ];

    public const COLORS = [
        'active' => 'success',
        'scheduled' => 'info',
        'expired' => 'gray',
        'disabled' => 'warning',
    ];

    public static function of(Coupon $coupon): string
    {
        $now = now();

        return match (true) {
            ! $coupon->is_active => 'disabled',
            static::isUsedUp($coupon), $coupon->expires_at && $coupon->expires_at->lte($now) => 'expired',
            $coupon->starts_at && $coupon->starts_at->gt($now) => 'scheduled',
            default => 'active',
        };
    }

    public static function isUsedUp(Coupon $coupon): bool
    {
        return $coupon->usage_limit !== null && (int) $coupon->usage_count >= (int) $coupon->usage_limit;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst($status);
    }

    public static function color(string $status): string
    {
        return self::COLORS[$status] ?? 'gray';
    }

    /** Restrict a query to one status. */
    public static function scope(Builder $query, string $status): Builder
    {
        $now = now();
        $expired = fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', $now))
            ->orWhere(fn (Builder $q) => $q->whereNotNull('usage_limit')->whereColumn('usage_count', '>=', 'usage_limit')));
        $notExpired = fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where(fn (Builder $q) => $q->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'));

        return match ($status) {
            'disabled' => $query->where('is_active', false),
            'expired' => $query->where('is_active', true)->where($expired),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now)->where($notExpired),
            'active' => $query->where('is_active', true)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where($notExpired),
            default => $query,
        };
    }

    /** Counts for every status tab in one query. @return array{all:int, active:int, scheduled:int, expired:int, disabled:int} */
    public static function counts(): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $expired = "((expires_at IS NOT NULL AND expires_at <= ?) OR (usage_limit IS NOT NULL AND usage_count >= usage_limit))";
        $row = DB::table('coupons')->selectRaw(
            "COUNT(*) AS `all`,
             SUM(is_active = 0) AS disabled,
             SUM(is_active = 1 AND {$expired}) AS expired,
             SUM(is_active = 1 AND NOT {$expired} AND starts_at IS NOT NULL AND starts_at > ?) AS scheduled,
             SUM(is_active = 1 AND NOT {$expired} AND (starts_at IS NULL OR starts_at <= ?)) AS active",
            [$now, $now, $now, $now, $now]
        )->first();

        return [
            'all' => (int) ($row->all ?? 0),
            'active' => (int) ($row->active ?? 0),
            'scheduled' => (int) ($row->scheduled ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'disabled' => (int) ($row->disabled ?? 0),
        ];
    }

    /** "20% off", "£5.00 off basket", "£2.00 off each item" (+ free shipping). */
    public static function summary(Coupon $coupon): string
    {
        $amount = (float) $coupon->amount;
        $text = match ($coupon->type) {
            'percent' => rtrim(rtrim(number_format($amount, 2), '0'), '.').'% off',
            'fixed_product' => money($amount).' off each item',
            default => money($amount).' off the basket',
        };
        if ($amount <= 0 && $coupon->free_shipping) {
            return 'Free shipping';
        }

        return $coupon->free_shipping ? $text.' + free shipping' : $text;
    }
}
