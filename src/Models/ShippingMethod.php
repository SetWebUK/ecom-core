<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery option. It belongs to a shipping zone (null = "unzoned": offered wherever its own country list allows,
 * which is how every method behaved before zones existed) and has a type:
 *
 *  - flat_rate      settings.calculation order|item|class, cost expression in `cost` or settings.cost ([qty], [cost],
 *                   [fee percent="10" min_fee="" max_fee=""]), settings.class_costs {shipping class id: expression},
 *                   settings.no_class_cost, settings.class_mode sum|max
 *  - free_shipping  settings.requires ''|coupon|min_amount|either|both (min amount = min_order_amount),
 *                   settings.ignore_discounts
 *  - weight_table   settings.rates [{min, max, cost}] on the basket weight (kg); settings.per_kg adds cost per kg above min
 *  - price_table    settings.rates [{min, max, cost}] on the basket value (after discounts)
 *  - local_pickup   `cost` (usually 0); tax is charged at the store's address
 *
 * `min_order_amount` hides any method below that basket value; `countries` further limits where it is offered.
 */
class ShippingMethod extends Model
{
    public const TYPES = [
        'flat_rate' => 'Flat rate',
        'free_shipping' => 'Free shipping',
        'weight_table' => 'Weight-based rates',
        'price_table' => 'Price-based rates',
        'local_pickup' => 'Local pickup',
    ];

    protected $guarded = ['id'];

    protected $casts = ['countries' => 'array', 'is_active' => 'boolean', 'cost' => 'decimal:2', 'min_order_amount' => 'decimal:2',
        'settings' => 'array'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function typeKey(): string
    {
        $type = (string) ($this->attributes['type'] ?? '');

        return array_key_exists($type, self::TYPES) ? $type : 'flat_rate';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->typeKey()];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get((array) ($this->settings ?? []), $key, $default);
    }

    public function isTaxable(): bool
    {
        return ($this->attributes['tax_status'] ?? 'taxable') !== 'none';
    }

    /** Short admin summary, e.g. "£4.95 per order", "Free over £50", "3 weight bands". */
    public function summary(): string
    {
        $cost = (string) ($this->setting('cost') ?? '');
        $flat = $cost !== '' && ! is_numeric($cost) ? $cost : ((float) $this->cost > 0 ? money($this->cost) : 'Free');

        return match ($this->typeKey()) {
            'free_shipping' => match ((string) $this->setting('requires', '')) {
                'coupon' => 'Free with a free-shipping coupon',
                'min_amount' => 'Free over '.money($this->min_order_amount ?? 0),
                'either' => 'Free over '.money($this->min_order_amount ?? 0).' or with a coupon',
                'both' => 'Free over '.money($this->min_order_amount ?? 0).' with a coupon',
                default => 'Free',
            },
            'weight_table' => count((array) $this->setting('rates', [])).' weight bands',
            'price_table' => count((array) $this->setting('rates', [])).' price bands',
            'local_pickup' => 'Collection · '.((float) $this->cost > 0 ? money($this->cost) : 'free'),
            default => $flat.match ((string) $this->setting('calculation', 'order')) {
                'item' => ' per item',
                'class' => ' + shipping class costs',
                default => (is_numeric($cost) || $cost === '') && (float) $this->cost == 0.0 ? '' : ' per order',
            },
        };
    }
}
