<?php

namespace Pine\Commerce\Services\Checkout;

use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\CartItem;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;

/**
 * One priced basket line. Prices always come from the product/variation at read time (never stored in
 * the basket), discounts and tax are filled in by Cart::calculate().
 */
class CartLine
{
    public float $discount = 0.0;

    public float $tax = 0.0;

    /** Filled by Cart::calculate() (Pine\Commerce\Services\Tax\TaxEngine): amounts without tax, tax per rate id. */
    public float $netSubtotal = 0.0;

    public float $subtotalTax = 0.0;

    public float $netTotal = 0.0;

    /** @var array<int, float> */
    public array $taxes = [];

    /** Per-request cache of attribute slug => [name, [value slug => value]] used for option labels. */
    protected static array $attributeCache = [];

    public function __construct(
        public ?CartItem $item,
        public Product $product,
        public ?ProductVariation $variation,
        public int $quantity,
        public float $unitPrice,
    ) {
    }

    public static function fromItem(CartItem $item): self
    {
        $variation = $item->variation;
        $price = $variation ? $variation->currentPrice() : $item->product->currentPrice();

        return new self($item, $item->product, $variation, (int) $item->quantity, round((float) $price, 2));
    }

    public function id(): ?int
    {
        return $this->item?->id;
    }

    /** Identifies the stock pool this line draws from. */
    public function stockKey(): string
    {
        if ($this->variation && $this->variation->manage_stock) {
            return 'v'.$this->variation->id;
        }

        return 'p'.$this->product->id;
    }

    public function subtotal(): float
    {
        return round($this->unitPrice * $this->quantity, 2);
    }

    public function total(): float
    {
        return round($this->subtotal() - $this->discount, 2);
    }

    public function name(): string
    {
        return $this->product->name;
    }

    public function sku(): ?string
    {
        return $this->variation?->sku ?: $this->product->sku;
    }

    public function url(): string
    {
        return $this->product->url;
    }

    public function isOnSale(): bool
    {
        return $this->variation ? $this->variation->isOnSale() : $this->product->isOnSale();
    }

    public function regularPrice(): ?float
    {
        $regular = $this->variation ? $this->variation->regular_price : $this->product->regular_price;

        return $regular !== null ? (float) $regular : null;
    }

    public function isTaxable(): bool
    {
        return ! in_array($this->product->tax_status, ['none', 'shipping'], true);
    }

    /** Tax class slug: the variation's own class, else the product's ('' / null = standard). */
    public function taxClass(): ?string
    {
        $class = $this->variation?->tax_class;

        return $class !== null && $class !== '' && $class !== 'parent' ? $class : $this->product->tax_class;
    }

    /** Shipping class id: the variation's own, else the product's. */
    public function shippingClassId(): ?int
    {
        $id = $this->variation?->shipping_class_id ?: $this->product->shipping_class_id;

        return $id ? (int) $id : null;
    }

    /** Weight of one unit (kg): the variation's, else the product's, else 0. */
    public function weight(): float
    {
        $weight = $this->variation?->weight;

        return (float) ($weight !== null && (float) $weight > 0 ? $weight : ($this->product->weight ?? 0));
    }

    /** Line amount before discounts as shown to the customer (incl./excl. tax per Settings › Tax). */
    public function displaySubtotal(?bool $incl = null): float
    {
        $incl ??= \Pine\Commerce\Services\Tax\TaxSettings::displayCartIncl();

        return round($incl ? $this->netSubtotal + $this->subtotalTax : $this->netSubtotal, 2);
    }

    /** Line amount after discounts as shown to the customer. */
    public function displayTotal(?bool $incl = null): float
    {
        $incl ??= \Pine\Commerce\Services\Tax\TaxSettings::displayCartIncl();

        return round($incl ? $this->netTotal + $this->tax : $this->netTotal, 2);
    }

    /** Unit price as shown to the customer. */
    public function displayUnitPrice(?bool $incl = null): float
    {
        return $this->quantity > 0 ? round($this->displaySubtotal($incl) / $this->quantity, 2) : 0.0;
    }

    public function imageUrl(): string
    {
        if ($this->variation && $this->variation->image) {
            return media_url($this->variation->image);
        }

        return $this->product->image_url;
    }

    public function imageAlt(): string
    {
        $image = $this->product->relationLoaded('images') ? $this->product->images->first() : null;

        return $image?->alt ?: $this->product->name;
    }

    /** Category ids the product is assigned to (primary + all), used for coupon rules. */
    public function categoryIds(): array
    {
        $ids = $this->product->relationLoaded('categories')
            ? $this->product->categories->pluck('id')->all()
            : $this->product->categories()->pluck('categories.id')->all();
        if ($this->product->primary_category_id) {
            $ids[] = $this->product->primary_category_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Human readable options, e.g. ['Memory' => '16GB'] for a variation plus any custom options stored on
     * the basket row (e.g. ['Warranty' => '2 years']).
     */
    public function options(): array
    {
        $named = [];
        foreach ((array) ($this->variation?->options ?? []) as $attributeSlug => $valueSlug) {
            [$label, $values] = static::attribute((string) $attributeSlug);
            $named[$label] = $values[$valueSlug] ?? ucwords(str_replace('-', ' ', (string) $valueSlug));
        }
        foreach ((array) ($this->item?->options ?? []) as $key => $value) {
            if (is_scalar($value) && $value !== '' && ! str_starts_with((string) $key, '_')) {
                $named[(string) $key] = (string) $value;
            }
        }

        return $named;
    }

    protected static function attribute(string $slug): array
    {
        if (! array_key_exists($slug, static::$attributeCache)) {
            $attribute = Attribute::with('values:id,attribute_id,slug,value')->where('slug', $slug)->first();
            static::$attributeCache[$slug] = $attribute
                ? [$attribute->name, $attribute->values->pluck('value', 'slug')->all()]
                : [ucwords(str_replace(['pa_', '-', '_'], ['', ' ', ' '], $slug)), []];
        }

        return static::$attributeCache[$slug];
    }
}
