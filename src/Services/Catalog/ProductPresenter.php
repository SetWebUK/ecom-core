<?php

namespace Pine\Commerce\Services\Catalog;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductSpec;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Tax\PriceDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Presentation logic for product cards and the product page (static API; themes call it through
 * commerce_presenter(), core through the same helper so a client presenter applies everywhere).
 *
 * Extension point: a client subclasses this class and registers it with config commerce.catalog.presenter or
 * Commerce::presenter() (docs/EXTENDING.md "Product presenter"). Everything here is generic and driven by
 * config / theme config:
 *   condition   commerce.catalog.condition_attribute, condition_label_strip, condition_schema_map (flag product_condition),
 *               descriptions from theme config product.condition_descriptions (keyed by label slug)
 *   brand       commerce.catalog.brand_attribute, known_brands (title match), logos from theme config product.brand_logos
 *   specs       commerce.catalog.spec_attributes (attribute slug => label; flag spec_highlights), product_specs rows
 *   instalments commerce.pay_in_3.{min,max,instalments} (flag pay_in_3)
 * Late static binding (static::) is used throughout, so an override of one method changes every caller.
 * Extra methods without a subclass: Commerce::presenterMethod('name', fn (Product $p) => …) (Macroable, static calls).
 *
 * For lists, eager-load self::CARD_RELATIONS on the query so nothing here hits the database.
 */
class ProductPresenter
{
    use \Illuminate\Support\Traits\Macroable;

    public const CARD_RELATIONS = ['images', 'primaryCategory', 'categories', 'attributeValues.attribute'];

    /** Per-product memo of attrNames() (WeakMap: entries vanish with the model). */
    protected static ?\WeakMap $attrCache = null;

    // ------------------------------------------------------------------ attributes

    /** @return array<string, string[]> "pa_memory" => ["16GB"], ordered like the WooCommerce term order */
    public static function attrNames(Product $product): array
    {
        static::$attrCache ??= new \WeakMap;
        if (isset(static::$attrCache[$product])) {
            return static::$attrCache[$product];
        }
        $product->loadMissing('attributeValues.attribute');
        $out = [];
        $values = $product->attributeValues->filter(fn ($v) => $v->attribute)
            ->sortBy([fn ($a, $b) => $a->sort_order <=> $b->sort_order, fn ($a, $b) => strcasecmp($a->value, $b->value)]);
        foreach ($values as $value) {
            $out['pa_'.$value->attribute->slug][] = $value->value;
        }

        return static::$attrCache[$product] = $out;
    }

    /** Comma-joined value names of an attribute ("pa_{slug}") or "". */
    public static function attr(Product $product, string $taxonomy): string
    {
        return implode(', ', static::attrNames($product)[$taxonomy] ?? []);
    }

    /** What the product is, for copy ("product"; a client presenter may derive a noun from the category or title). */
    public static function noun(Product $product): string
    {
        return 'product';
    }

    // ------------------------------------------------------------------ condition / brand

    /** Attribute slug holding the product condition (config commerce.catalog.condition_attribute). */
    public static function conditionAttribute(): string
    {
        return (string) config('commerce.catalog.condition_attribute', 'condition');
    }

    /** Attribute slug holding the brand (config commerce.catalog.brand_attribute). */
    public static function brandAttribute(): string
    {
        return (string) config('commerce.catalog.brand_attribute', 'brand');
    }

    /** Condition label: the attribute term without the configured prefixes (commerce.catalog.condition_label_strip). */
    public static function conditionLabel(string $term): string
    {
        return trim(str_replace((array) config('commerce.catalog.condition_label_strip', []), '', $term));
    }

    /**
     * Condition badges (flag product_condition): one per condition term, with the description from theme config
     * product.condition_descriptions (keyed by the label's slug).
     *
     * @return array<int, array{term:string,label:string,slug:string,description:string}>
     */
    public static function conditions(Product $product): array
    {
        if (! commerce_feature('product_condition')) {
            return [];
        }

        $descriptions = (array) theme_config('product.condition_descriptions', []);
        $out = [];
        foreach (static::attrNames($product)['pa_'.static::conditionAttribute()] ?? [] as $term) {
            $label = static::conditionLabel($term);
            $slug = Text::slug($label);
            $out[] = ['term' => $term, 'label' => $label, 'slug' => $slug, 'description' => (string) ($descriptions[$slug] ?? '')];
        }

        return $out;
    }

    public static function condition(Product $product): ?array
    {
        return static::conditions($product)[0] ?? null;
    }

    /**
     * schema.org itemCondition URL for JSON-LD: the first commerce.catalog.condition_schema_map needle found in the
     * condition terms (case-insensitive), else the '*' entry.
     */
    public static function itemCondition(Product $product): string
    {
        $map = (array) config('commerce.catalog.condition_schema_map', ['*' => 'NewCondition']);
        $terms = commerce_feature('product_condition') ? strtolower(static::attr($product, 'pa_'.static::conditionAttribute())) : '';
        foreach ($map as $needle => $type) {
            if ($needle !== '*' && $terms !== '' && str_contains($terms, strtolower((string) $needle))) {
                return 'https://schema.org/'.$type;
            }
        }

        return 'https://schema.org/'.($map['*'] ?? 'NewCondition');
    }

    /** Brand: the brand attribute, else a known brand (commerce.catalog.known_brands) found in the title. */
    public static function brandName(Product $product): ?string
    {
        $brand = static::attr($product, 'pa_'.static::brandAttribute());
        if ($brand !== '') {
            return $brand;
        }

        return static::brandFromTitle($product->name);
    }

    /** First of commerce.catalog.known_brands (in config order) contained in the title, case-insensitive. */
    public static function brandFromTitle(string $title): ?string
    {
        $title = strtolower($title);
        foreach ((array) config('commerce.catalog.known_brands', []) as $brand) {
            if ($brand !== '' && str_contains($title, strtolower((string) $brand))) {
                return (string) $brand;
            }
        }

        return null;
    }

    /** @return array<string,string> brand => logo (public-disk path or URL), theme config product.brand_logos */
    public static function brandLogos(): array
    {
        return (array) theme_config('product.brand_logos', []);
    }

    /** @return array{url:string, alt:string}|null */
    public static function brandLogo(Product $product): ?array
    {
        if (! commerce_feature('product_brand')) {
            return null; // flag product_brand
        }

        $make = static::brandName($product);
        if (! $make) {
            return null;
        }
        foreach (static::brandLogos() as $brand => $path) {
            if (stripos($make, (string) $brand) !== false) {
                return ['url' => media_url($path), 'alt' => $make];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ specs

    /** @return array<string,string> "pa_{slug}" => label from config commerce.catalog.spec_attributes */
    protected static function specAttributes(): array
    {
        $out = [];
        foreach ((array) config('commerce.catalog.spec_attributes', []) as $slug => $label) {
            $out['pa_'.$slug] = (string) $label;
        }

        return $out;
    }

    /** Formatted value of a spec attribute (hook for client presenters: units, abbreviations …). */
    public static function specValue(string $taxonomy, string $value): string
    {
        return trim($value);
    }

    /** Spec rows for listing cards (flag spec_highlights): [{label, value, description}] of the spec attributes. */
    public static function cardSpecs(Product $product): array
    {
        return array_map(fn ($card) => ['label' => $card['label'], 'value' => $card['value'], 'description' => $card['description']], static::highlights($product));
    }

    /** One-line spec summary joined with " · " (flag spec_highlights). */
    public static function specLine(Product $product): string
    {
        return implode(' · ', array_column(static::highlights($product), 'value'));
    }

    /** Spec highlight cards (flag spec_highlights): [{taxonomy, label, value, description}] of the spec attributes. */
    public static function highlights(Product $product): array
    {
        if (! commerce_feature('spec_highlights')) {
            return [];
        }

        $cards = [];
        foreach (static::specAttributes() as $taxonomy => $label) {
            $value = static::attr($product, $taxonomy);
            $value = $value !== '' ? static::specValue($taxonomy, $value) : '';
            if ($value === '') {
                continue;
            }
            $cards[] = ['taxonomy' => $taxonomy, 'label' => $label, 'value' => $value, 'description' => ''];
        }

        return $cards;
    }

    /** Technical specification rows (product_specs: admin-editable, filled by the importer's ProductMapper adapters). */
    public static function specRows(Product $product): Collection
    {
        $rows = $product->relationLoaded('specs') ? $product->specs : $product->specs()->get();

        return $rows->filter(fn (ProductSpec $s) => trim((string) $s->label) !== '' && trim((string) $s->value) !== '')->values();
    }

    // ------------------------------------------------------------------ prices

    /** WooCommerce wc_price() markup. */
    public static function amount(float $value, bool $bdi = true): string
    {
        return '<span class="woocommerce-Price-amount amount">'.static::inner($value, $bdi).'</span>';
    }

    /** Currency symbol as an HTML entity where one exists ("£" -> "&pound;"), config commerce.currency.symbol. */
    public static function currencySymbolHtml(): string
    {
        return htmlentities((string) config('commerce.currency.symbol', '£'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Currency symbol as plain text (config commerce.currency.symbol). */
    public static function currencySymbol(): string
    {
        return (string) config('commerce.currency.symbol', '£');
    }

    /** Active (current) price of the product; for variable products the lowest variation price. */
    public static function price(Product $product): ?float
    {
        if ($product->type === 'variable') {
            $prices = static::variationPrices($product);

            return $prices->isNotEmpty() ? (float) $prices->min() : PriceDisplay::shop($product->price !== null ? (float) $product->price : null, $product);
        }
        $price = $product->currentPrice();

        return PriceDisplay::shop($price ?? ($product->price !== null ? (float) $product->price : null), $product);
    }

    /** Current prices of the active variations as shown in the shop (Settings › Tax display). */
    protected static function variationPrices(Product $product): Collection
    {
        return static::activeVariations($product)
            ->map(fn (ProductVariation $v) => PriceDisplay::shop($v->currentPrice(), $product, $v))
            ->filter(fn ($p) => $p !== null);
    }

    /** Regular + sale pair when a simple product is on sale, else null. */
    public static function sale(Product $product): ?array
    {
        if ($product->type === 'variable' || ! $product->isOnSale()) {
            return null;
        }
        $regular = (float) PriceDisplay::shop((float) $product->regular_price, $product);
        $sale = (float) PriceDisplay::shop((float) $product->sale_price, $product);
        if ($regular <= 0 || $sale >= $regular) {
            return null;
        }

        return ['regular' => $regular, 'sale' => $sale, 'saving' => round($regular - $sale, 2), 'percent' => (int) round((($regular - $sale) / $regular) * 100)];
    }

    /** CSS block class of cardPriceHtml() ({class}, {class}-now, -sub, -was, -save); a client presenter may rename it. */
    public static function cardPriceClass(): string
    {
        return 'card-price';
    }

    /** Card price: current price, plus was/save when on sale. */
    public static function cardPriceHtml(Product $product, bool $bdi = false): string
    {
        $now = static::price($product);
        if ($now === null) {
            return '';
        }
        $sale = static::sale($product);
        $c = static::cardPriceClass();
        $html = '<span class="'.$c.'"><span class="'.$c.'-now">'.static::amount($now, $bdi).'</span>';
        if ($sale) {
            $html .= '<span class="'.$c.'-sub"><del class="'.$c.'-was">'.static::amount($sale['regular'], $bdi).'</del><span class="'.$c.'-save">Save '.static::amount($sale['saving'], $bdi).'</span></span>';
        }

        return $html.'</span>'.PriceDisplay::suffixHtml($now, $product);
    }

    /** "Save £160" (rounded to whole units of the currency). */
    public static function saveShort(Product $product): ?string
    {
        $sale = static::sale($product);

        return $sale ? 'Save '.static::currencySymbol().number_format($sale['saving'], 0) : null;
    }

    /** "Save £90 (24%)". */
    public static function saveBadge(Product $product): ?string
    {
        $sale = static::sale($product);
        if (! $sale) {
            return null;
        }
        // Unrounded: 39.99 - 29.99 is not a whole number in floating point, so it shows "£10.00".
        $saving = $sale['regular'] - $sale['sale'];
        $amount = floor($saving) == $saving ? number_format($saving, 0) : number_format($saving, 2);

        return 'Save '.static::currencySymbol().$amount.' ('.$sale['percent'].'%)';
    }

    /** WC_Product::get_price_html() - del/ins for sales, ranges for variable products. */
    public static function priceHtml(Product $product, bool $bdi = true): string
    {
        $html = static::priceHtmlWithoutSuffix($product, $bdi);

        return $html === '' ? '' : $html.PriceDisplay::suffixHtml(static::price($product), $product);
    }

    protected static function priceHtmlWithoutSuffix(Product $product, bool $bdi = true): string
    {
        if ($product->type === 'variable') {
            $variations = static::activeVariations($product);
            $prices = static::variationPrices($product);
            if ($prices->isEmpty()) {
                return $product->price !== null ? static::amount((float) PriceDisplay::shop((float) $product->price, $product), $bdi) : '';
            }
            $min = (float) $prices->min();
            $max = (float) $prices->max();
            if ($min !== $max) {
                return '<span class="woocommerce-Price-amount amount" aria-hidden="true">'.static::inner($min, $bdi).'</span> <span aria-hidden="true">&ndash;</span> <span class="woocommerce-Price-amount amount" aria-hidden="true">'.static::inner($max, $bdi).'</span><span class="screen-reader-text">Price range: '.money($min).' through '.money($max).'</span>';
            }
            $regulars = $variations->map(fn (ProductVariation $v) => $v->regular_price !== null ? PriceDisplay::shop((float) $v->regular_price, $product, $v) : null)->filter();
            if ($variations->contains(fn ($v) => $v->isOnSale()) && $regulars->isNotEmpty() && $regulars->min() === $regulars->max() && (float) $regulars->min() > $min) {
                return static::delIns((float) $regulars->min(), $min, $bdi);
            }

            return static::amount($min, $bdi);
        }
        $sale = static::sale($product);
        if ($sale) {
            return static::delIns($sale['regular'], $sale['sale'], $bdi);
        }
        $price = static::price($product);

        return $price !== null ? static::amount($price, $bdi) : '';
    }

    public static function delIns(float $regular, float $sale, bool $bdi = true): string
    {
        return '<del aria-hidden="true">'.static::amount($regular, $bdi).'</del> <span class="screen-reader-text">Original price was: '.money($regular).'.</span>'
            .'<ins aria-hidden="true">'.static::amount($sale, $bdi).'</ins><span class="screen-reader-text">Current price is: '.money($sale).'.</span>';
    }

    protected static function inner(float $value, bool $bdi): string
    {
        $inner = '<span class="woocommerce-Price-currencySymbol">'.static::currencySymbolHtml().'</span>'.money($value, false);

        return $bdi ? '<bdi>'.$inner.'</bdi>' : $inner;
    }

    /**
     * Instalment amount for a "pay in N" line (flag pay_in_3): price / commerce.pay_in_3.instalments, rounded down to
     * the penny, when the price is within commerce.pay_in_3.min … max; else null.
     */
    public static function payIn3(?float $price): ?float
    {
        if (! commerce_feature('pay_in_3')) {
            return null; // flag pay_in_3
        }
        $min = (float) config('commerce.pay_in_3.min', 30);
        $max = (float) config('commerce.pay_in_3.max', 2000);
        $instalments = max(1, (int) config('commerce.pay_in_3.instalments', 3));

        if ($price === null || $price < $min || $price > $max) {
            return null;
        }

        return floor(($price / $instalments) * 100) / 100;
    }

    public static function activeVariations(Product $product): Collection
    {
        $variations = $product->relationLoaded('variations') ? $product->variations : $product->variations()->get();

        return $variations->filter(fn (ProductVariation $v) => $v->is_active)->values();
    }

    // ------------------------------------------------------------------ stock / delivery

    /**
     * Stock / dispatch line: status in|low|out, the stock left when low, and the working-day dispatch window.
     *
     * @return array{status:string, stock:?int, from:?string, to:?string}
     */
    public static function stockDelivery(Product $product, int $minDays = 2, int $maxDays = 5, int $lowStock = 3): array
    {
        if (! static::inStock($product)) {
            return ['status' => 'out', 'stock' => null, 'from' => null, 'to' => null];
        }
        $stock = $product->type !== 'variable' && $product->manage_stock && $product->stock_quantity !== null ? (int) $product->stock_quantity : null;
        $now = CarbonImmutable::now(config('app.display_timezone', 'Europe/London'));
        $addWorkingDays = function (CarbonImmutable $date, int $days) {
            while ($days > 0) {
                $date = $date->addDay();
                if ((int) $date->format('N') < 6) {
                    $days--;
                }
            }

            return $date;
        };
        $from = $addWorkingDays($now, max(1, $minDays));
        $to = $addWorkingDays($now, max(1, $maxDays));

        return [
            'status' => $stock !== null && $stock <= $lowStock ? 'low' : 'in',
            'stock' => $stock,
            'from' => $from->format($from->format('n') === $to->format('n') ? 'D j' : 'D j M'),
            'to' => $to->format('D j M'),
        ];
    }

    public static function inStock(Product $product): bool
    {
        if ($product->type === 'variable') {
            $variations = static::activeVariations($product);
            if ($variations->isNotEmpty()) {
                return $product->isInStock() && $variations->contains(fn (ProductVariation $v) => $v->isInStock());
            }
        }

        return $product->isInStock();
    }

    // ------------------------------------------------------------------ images

    /** URL of a WordPress-generated size variant ("-300x300") when it exists on disk, else the original. */
    public static function sized(?string $path, string $size = '300x300'): string
    {
        if (! $path || preg_match('#^(https?:)?//#', $path)) {
            return media_url($path);
        }
        static $seen = [];
        $key = $path.'@'.$size;
        if (! array_key_exists($key, $seen)) {
            $variant = preg_replace('/(\.[a-z0-9]+)$/i', '-'.$size.'$1', $path);
            $seen[$key] = $variant && is_file(public_path('storage/'.ltrim($variant, '/'))) ? $variant : $path;
        }

        return media_url($seen[$key]);
    }
}
