<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
        'regular_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'manage_stock' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductVariation $variation) {
            if ($variation->manage_stock && $variation->stock_quantity !== null) {
                $variation->stock_status = $variation->stock_quantity > 0 ? 'instock' : 'outofstock';
            }
        });
        static::saved(fn (ProductVariation $variation) => $variation->product?->refreshVariablePrice());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** A sale price below the regular price, inside its sale dates (UTC; none = no limit), like Product::isOnSale(). */
    public function isOnSale(): bool
    {
        if ($this->sale_price === null || (float) $this->sale_price <= 0 || (float) $this->sale_price >= (float) $this->regular_price) {
            return false;
        }
        $now = now();

        return ! ($this->sale_starts_at && $this->sale_starts_at->gt($now)) && ! ($this->sale_ends_at && $this->sale_ends_at->lt($now));
    }

    public function currentPrice(): ?float
    {
        if ($this->isOnSale()) {
            return (float) $this->sale_price;
        }

        return $this->regular_price !== null ? (float) $this->regular_price : null;
    }

    public function isInStock(): bool
    {
        return in_array($this->stock_status, ['instock', 'onbackorder'], true);
    }

    /** Human label e.g. "16GB" or "16GB, Silver", resolved from attribute value names. */
    public function getLabelAttribute(): string
    {
        $labels = [];
        foreach ((array) $this->options as $attributeSlug => $valueSlug) {
            $value = AttributeValue::whereHas('attribute', fn ($q) => $q->where('slug', $attributeSlug))
                ->where('slug', $valueSlug)->value('value');
            $labels[] = $value ?? $valueSlug;
        }

        return implode(', ', $labels);
    }

    /** Options keyed by attribute display name, for order line items. */
    public function namedOptions(): array
    {
        $named = [];
        foreach ((array) $this->options as $attributeSlug => $valueSlug) {
            $attribute = Attribute::where('slug', $attributeSlug)->first();
            $value = $attribute?->values()->where('slug', $valueSlug)->value('value');
            $named[$attribute->name ?? $attributeSlug] = $value ?? $valueSlug;
        }

        return $named;
    }
}
