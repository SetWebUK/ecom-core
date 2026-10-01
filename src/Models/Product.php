<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'regular_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'published_at' => 'datetime',
        'manage_stock' => 'boolean',
        'sold_individually' => 'boolean',
        'is_featured' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if ($product->type !== 'variable') {
                $product->price = $product->currentPrice();
            }
            if ($product->manage_stock && $product->stock_quantity !== null && $product->backorders === 'no') {
                $product->stock_status = $product->stock_quantity > 0 ? 'instock' : 'outofstock';
            }
        });
    }

    // Relationships -------------------------------------------------------

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('position');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class)->orderBy('sort_order')->orderBy('id'); // id: stable order for equal positions
    }

    public function specs(): HasMany
    {
        return $this->hasMany(ProductSpec::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'related_products', 'product_id', 'related_id')->withPivot('type');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Scopes --------------------------------------------------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->whereIn('stock_status', ['instock', 'onbackorder']);
    }

    // Pricing -------------------------------------------------------------

    public function isOnSale(): bool
    {
        if ($this->sale_price === null || $this->sale_price === '' || (float) $this->sale_price >= (float) $this->regular_price) {
            return false;
        }
        $now = now();
        if ($this->sale_starts_at && $this->sale_starts_at->gt($now)) {
            return false;
        }
        if ($this->sale_ends_at && $this->sale_ends_at->lt($now)) {
            return false;
        }

        return true;
    }

    public function currentPrice(): ?float
    {
        if ($this->isOnSale()) {
            return (float) $this->sale_price;
        }

        return $this->regular_price !== null ? (float) $this->regular_price : null;
    }

    /**
     * Amount saved vs regular price, or null when not on sale.
     * (Named savingAmount – a method called saving() clashes with Eloquent's static Model::saving() event and is a fatal error.)
     */
    public function savingAmount(): ?float
    {
        return $this->isOnSale() ? round((float) $this->regular_price - (float) $this->sale_price, 2) : null;
    }

    public function savingPercent(): ?int
    {
        return $this->isOnSale() && (float) $this->regular_price > 0
            ? (int) round(($this->savingAmount() / (float) $this->regular_price) * 100)
            : null;
    }

    public function refreshVariablePrice(): void
    {
        if ($this->type === 'variable') {
            $prices = $this->variations()->where('is_active', true)->get()->map->currentPrice()->filter();
            $this->price = $prices->min();
            if ($this->regular_price === null) {
                $this->regular_price = $this->variations()->where('is_active', true)->min('regular_price');
            }
            $this->saveQuietly();
        }
    }

    // Presentation --------------------------------------------------------

    public function getUrlAttribute(): string
    {
        $category = $this->primaryCategory ?? $this->categories->first();

        return url(($category ? $category->path.'/' : 'product/').$this->slug); // SlashUrlGenerator appends the trailing slash
    }

    public function getImageUrlAttribute(): string
    {
        $image = $this->relationLoaded('images') ? $this->images->first() : $this->images()->first();

        return $image ? media_url($image->path) : asset('images/placeholder.png');
    }

    public function isInStock(): bool
    {
        return in_array($this->stock_status, ['instock', 'onbackorder'], true);
    }

    /** Map of attribute name => comma list of values (for spec tables). */
    public function attributeMap(): array
    {
        $values = $this->attributeValues()->with('attribute')->get()->groupBy('attribute.name');

        return $values->map(fn ($vals) => $vals->pluck('value')->implode(', '))->all();
    }
}
