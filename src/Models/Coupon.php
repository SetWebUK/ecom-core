<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    public const TYPES = [
        'percent' => 'Percentage discount',
        'fixed_cart' => 'Fixed basket discount',
        'fixed_product' => 'Fixed product discount',
        'multibuy' => 'Multi-buy (X for £Y)',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'buy_quantity' => 'integer',
        'minimum_spend' => 'decimal:2',
        'maximum_spend' => 'decimal:2',
        'free_shipping' => 'boolean',
        'individual_use' => 'boolean',
        'exclude_sale_items' => 'boolean',
        'is_active' => 'boolean',
        'auto_apply' => 'boolean',
        'show_countdown' => 'boolean',
        'product_ids' => 'array',
        'excluded_product_ids' => 'array',
        'category_ids' => 'array',
        'excluded_category_ids' => 'array',
        'allowed_emails' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @var Collection<int, self>|null automatic discounts running now, for this request */
    protected static ?Collection $running = null;

    protected static function booted(): void
    {
        static::saved(fn () => static::$running = null);
        static::deleted(fn () => static::$running = null);
    }

    public static function findByCode(string $code): ?self
    {
        return static::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();
    }

    /** Active automatic discounts inside their dates (usage limits are checked per basket by CouponEngine). */
    public function scopeAutomaticNow(Builder $query): Builder
    {
        $now = now();

        return $query->where('auto_apply', true)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    /**
     * The automatic discounts running now, loaded once per request (a basket, a product page and every product card
     * ask). Ordered so the basket applies them in a stable order.
     *
     * @return Collection<int, self>
     */
    public static function running(): Collection
    {
        if (static::$running === null) {
            try {
                static::$running = static::query()->automaticNow()->orderBy('id')->get();
            } catch (\Throwable $e) {
                static::$running = new Collection; // before the migration has run
            }
        }

        return static::$running;
    }

    /** Forget the per-request list (tests, long-running workers). */
    public static function flushRunning(): void
    {
        static::$running = null;
    }

    /** What customers see: the label of an automatic discount, else the code. */
    public function displayName(): string
    {
        $label = trim((string) $this->label);

        return $label !== '' ? $label : (string) $this->code;
    }
}
