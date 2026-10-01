<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A tax class (Standard rate, Reduced rate, Zero rate, or a custom one). Products and variations refer to it by slug
 * (products.tax_class: null/'' = standard, like WooCommerce), rates by `tax_rates.tax_class`.
 */
class TaxClass extends Model
{
    public const STANDARD = 'standard';

    protected $guarded = ['id'];

    protected $casts = ['sort_order' => 'integer'];

    /** Normalise a product/variation/rate class value: null, '' and 'standard' all mean the standard class. */
    public static function normalise(?string $slug): string
    {
        $slug = trim((string) $slug);

        return $slug === '' ? self::STANDARD : Str::slug($slug);
    }

    /** @return array<string, string> slug => name, standard first (falls back to the three built-in classes) */
    public static function options(): array
    {
        try {
            $options = static::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'slug')->all();
        } catch (\Throwable) {
            $options = [];
        }

        return $options ?: [self::STANDARD => 'Standard rate', 'reduced-rate' => 'Reduced rate', 'zero-rate' => 'Zero rate'];
    }
}
