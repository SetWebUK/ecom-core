<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    public const TYPES = [
        'percent' => 'Percentage discount',
        'fixed_cart' => 'Fixed basket discount',
        'fixed_product' => 'Fixed product discount',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'minimum_spend' => 'decimal:2',
        'maximum_spend' => 'decimal:2',
        'free_shipping' => 'boolean',
        'individual_use' => 'boolean',
        'exclude_sale_items' => 'boolean',
        'is_active' => 'boolean',
        'product_ids' => 'array',
        'excluded_product_ids' => 'array',
        'category_ids' => 'array',
        'excluded_category_ids' => 'array',
        'allowed_emails' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public static function findByCode(string $code): ?self
    {
        return static::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();
    }
}
