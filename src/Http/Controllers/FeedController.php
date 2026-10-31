<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Catalog\Categories;
use Pine\Commerce\Services\Catalog\Text;
use Illuminate\Support\Facades\Cache;

/**
 * Google Merchant Center product feed (RSS 2.0 + g: namespace) at /feeds/google-shopping.xml:
 * published, in-stock products with a price. Variable products are listed per in-stock variation, grouped by
 * g:item_group_id. Cached for 15 minutes.
 *
 * Settings: feeds.google_product_category (default Google category id when a product has none),
 *           feeds.shipping_price (default 0.00 - free UK delivery), feeds.include_shipping (off: no g:shipping, Merchant
 *           Center's delivery settings apply, e.g. free over a threshold), feeds.google_gender / feeds.google_age_group
 *           (for products without a gender / age attribute).
 * Clothing (Google requires them for apparel): g:color from the colour attribute, g:size from a variation's size
 * option plus its fitting / length option ("+2XL Short", so sizes of one product don't look like duplicates),
 * g:gender and g:age_group from the product's attributes, else the settings (config commerce.feeds.google.*_attributes
 * names the attributes). Descriptions lose page-builder shortcodes ([vc_row] …) and HTML.
 */
class FeedController extends Controller
{
    public const TTL = 900;

    public function google()
    {
        $xml = Cache::remember('feeds.google-shopping', self::TTL, fn () => $this->build());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    protected function build(): string
    {
        $siteName = (string) setting('store.name', config('app.name'));
        $defaultCategory = trim((string) setting('feeds.google_product_category', (string) config('commerce.feeds.google.default_category'))); // Google taxonomy id/path
        $shipping = filter_var(setting('feeds.include_shipping', true), FILTER_VALIDATE_BOOL)
            ? number_format((float) setting('feeds.shipping_price', 0), 2, '.', '') : null;

        $w = new \XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('rss');
        $w->writeAttribute('version', '2.0');
        $w->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $w->startElement('channel');
        $w->writeElement('title', $siteName);
        $w->writeElement('link', url('/').'/');
        $w->writeElement('description', $siteName.' - product feed');

        Product::query()->published()->inStock()->whereNotNull('price')
            ->with(['images', 'primaryCategory', 'categories', 'attributeValues.attribute', 'variations'])
            ->orderBy('id')
            ->chunk(200, function ($products) use ($w, $defaultCategory, $shipping) {
                foreach ($products as $product) {
                    $base = $this->baseFields($product, $defaultCategory, $shipping);
                    if ($product->type === 'variable') {
                        foreach (commerce_presenter()::activeVariations($product) as $variation) {
                            if ($variation->isInStock() && $variation->currentPrice() !== null) {
                                $this->writeItem($w, $this->variationFields($product, $variation, $base));
                            }
                        }

                        continue;
                    }
                    $sale = commerce_presenter()::sale($product);
                    $price = commerce_presenter()::price($product);
                    if ($price === null || $price <= 0) {
                        continue;
                    }
                    $this->writeItem($w, $base + [
                        'g:price' => $this->money($sale ? $sale['regular'] : $price),
                        'g:sale_price' => $sale ? $this->money($sale['sale']) : null,
                    ]);
                }
            });

        $w->endElement(); // channel
        $w->endElement(); // rss
        $w->endDocument();

        return $w->outputMemory();
    }

    /** Fields shared by a product and its variations (g:id / price are set by the caller). */
    protected function baseFields(Product $product, string $defaultCategory, ?string $shipping): array
    {
        $images = $product->images->map(fn ($img) => media_url($img->path))->values();
        $category = $product->primaryCategory ?? $product->categories->first();
        $productType = $category ? collect(Categories::ancestors($category))->push($category)->pluck('name')->implode(' > ') : null;
        // g:condition: the condition attribute (feature product_condition), else commerce.feeds.google.default_condition
        $conditionAttr = commerce_feature('product_condition')
            ? strtolower(commerce_presenter()::attr($product, 'pa_'.config('commerce.catalog.condition_attribute', 'condition'))) : '';
        $condition = $conditionAttr === '' ? (string) config('commerce.feeds.google.default_condition', 'new')
            : static::googleCondition($conditionAttr);
        $description = Text::plain(static::withoutShortcodes($product->description ?: $product->short_description))
            ?: Text::plain(static::withoutShortcodes($product->short_description)) ?: $product->name;
        $sizes = commerce_presenter()::attrNames($product);
        $first = fn (array $slugs) => collect($slugs)->map(fn ($s) => $sizes['pa_'.$s] ?? [])->first(fn ($v) => $v !== []) ?? [];
        $simpleSize = $product->type !== 'variable' && count($own = $first($this->attributes('size_attributes', ['size']))) === 1 ? $own[0] : null;
        $identifierExists = filled($product->gtin) || filled($product->mpn);

        return [
            'g:id' => \Pine\Commerce\Services\Marketing\ProductIds::product($product),
            'g:title' => mb_substr(html_entity_decode(Text::title($product->name), ENT_QUOTES, 'UTF-8'), 0, 150),
            'g:description' => mb_substr($description, 0, 5000),
            'g:link' => $product->url,
            'g:image_link' => $images->first() ?? $product->image_url,
            'g:additional_image_link' => $images->slice(1, 10)->values()->all(),
            'g:availability' => 'in_stock',
            'g:brand' => commerce_presenter()::brandName($product) ?: (string) setting('store.name', config('app.name')),
            'g:condition' => $condition,
            'g:gtin' => $product->gtin ?: null,
            'g:mpn' => $product->mpn ?: ($product->sku ?: null),
            'g:identifier_exists' => $identifierExists ? null : 'no',
            'g:google_product_category' => $product->google_product_category ?: ($defaultCategory !== '' ? $defaultCategory : null),
            'g:product_type' => $productType,
            'g:color' => implode('/', array_slice($first($this->attributes('colour_attributes', ['colour', 'color'])), 0, 3)) ?: null,
            'g:size' => $simpleSize,
            'g:gender' => static::gender(implode(' ', $first($this->attributes('gender_attributes', ['gender'])))) ?? static::setting('feeds.google_gender', ['male', 'female', 'unisex']),
            'g:age_group' => static::ageGroup(implode(' ', $first($this->attributes('age_attributes', ['age', 'age-group'])))) ?? static::setting('feeds.google_age_group', ['newborn', 'infant', 'toddler', 'kids', 'adult']),
            'g:shipping' => $shipping === null ? null : ['g:country' => 'GB', 'g:service' => 'Free UK Delivery', 'g:price' => $shipping.' GBP'],
        ];
    }

    protected function variationFields(Product $product, ProductVariation $variation, array $base): array
    {
        $price = (float) commerce_presenter()::discountedPrice($product, $variation, $variation->currentPrice());
        $regular = $variation->regular_price !== null ? (float) $variation->regular_price : $price;
        $reduced = $price < $regular;
        $options = collect((array) $variation->options)->map(fn ($v) => ucwords(str_replace('-', ' ', (string) $v)))->implode(' / ');
        // the options' names (+2XL, Short) from the product's attribute values
        $names = [];
        foreach ($product->attributeValues as $value) {
            if ($value->attribute) {
                $names[$value->attribute->slug][$value->slug] = $value->value;
            }
        }
        $option = function (array $slugs) use ($variation, $names): ?string {
            foreach ($slugs as $slug) {
                $chosen = ((array) $variation->options)[$slug] ?? ((array) $variation->options)['pa_'.$slug] ?? null;
                if ($chosen !== null && $chosen !== '') {
                    return (string) ($names[$slug][$chosen] ?? ucwords(str_replace('-', ' ', (string) $chosen)));
                }
            }

            return null;
        };
        $size = $option($this->attributes('size_attributes', ['size', 'shoe-size']));
        $extra = $option($this->attributes('size_extra_attributes', ['fitting', 'length', 'leg-length']));
        $size = $size !== null && $extra !== null ? $size.' '.ucfirst(mb_strtolower($extra)) : ($size ?? $extra);

        return array_merge($base, [
            'g:id' => \Pine\Commerce\Services\Marketing\ProductIds::variation($variation),
            'g:item_group_id' => $base['g:id'],
            'g:title' => mb_substr($base['g:title'].($options !== '' ? ' - '.$options : ''), 0, 150),
            'g:link' => $product->url,
            'g:image_link' => $variation->image ? media_url($variation->image) : $base['g:image_link'],
            'g:mpn' => $variation->sku ?: $base['g:mpn'],
            'g:color' => $option($this->attributes('colour_attributes', ['colour', 'color'])) ?? $base['g:color'],
            'g:size' => $size !== null ? mb_substr($size, 0, 100) : null,
            'g:price' => $this->money($reduced ? $regular : $price),
            'g:sale_price' => $reduced ? $this->money($price) : null,
        ]);
    }

    protected function writeItem(\XMLWriter $w, array $fields): void
    {
        $w->startElement('item');
        foreach ($fields as $name => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($name === 'g:additional_image_link') {
                foreach ($value as $link) {
                    $w->writeElement($name, $link);
                }

                continue;
            }
            if (is_array($value)) {
                $w->startElement($name);
                foreach ($value as $child => $childValue) {
                    $w->writeElement($child, (string) $childValue);
                }
                $w->endElement();

                continue;
            }
            $w->writeElement($name, $this->clean((string) $value));
        }
        $w->endElement();
    }

    /** Attribute slugs of a config list (commerce.feeds.google.{key}). @return list<string> */
    protected function attributes(string $key, array $default): array
    {
        return array_values(array_filter(array_map('strval', (array) config('commerce.feeds.google.'.$key, $default))));
    }

    /** Page-builder and WordPress shortcodes ([vc_row], [/vc_column_text], [caption …]) out of a description. */
    public static function withoutShortcodes(?string $html): string
    {
        return (string) preg_replace('~\[/?(?:[a-z0-9]+_[a-z0-9_]*|caption|gallery|embed|video|audio|row|col|section|button|contact-form-7)\b[^\]]*\]~i', ' ', (string) $html);
    }

    /** Google g:gender for an attribute value (MALE, Mens, Ladies, Unisex …), or null. */
    protected static function gender(string $value): ?string
    {
        $v = mb_strtolower($value);

        return match (true) {
            $v === '' => null,
            str_contains($v, 'unisex') => 'unisex',
            (bool) preg_match('/female|wom[ae]n|ladies|girl/', $v) => 'female',
            (bool) preg_match('/\bmale|\bmen|\bmens|gents|boy/', $v) => 'male',
            default => null,
        };
    }

    /** Google g:age_group for an attribute value (ADULT, Kids, Baby …), or null. */
    protected static function ageGroup(string $value): ?string
    {
        $v = mb_strtolower($value);

        return match (true) {
            $v === '' => null,
            str_contains($v, 'newborn') => 'newborn',
            (bool) preg_match('/infant|baby/', $v) => 'infant',
            str_contains($v, 'toddler') => 'toddler',
            (bool) preg_match('/kid|child|junior/', $v) => 'kids',
            str_contains($v, 'adult') => 'adult',
            default => null,
        };
    }

    /** A setting's value when it is one of $allowed, else null. */
    protected static function setting(string $key, array $allowed): ?string
    {
        $value = strtolower(trim((string) setting($key, '')));

        return in_array($value, $allowed, true) ? $value : null;
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2, '.', '').' GBP';
    }

    /** Strip characters that are invalid in XML 1.0. */
    protected function clean(string $value): string
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }

    /**
     * Google g:condition for a (lower-cased) condition term: the first commerce.feeds.google.condition_map needle
     * contained in it (needle => a Google condition value, in config order), else "new".
     */
    protected static function googleCondition(string $term): string
    {
        foreach ((array) config('commerce.feeds.google.condition_map', ['used' => 'used']) as $needle => $value) {
            if ($needle !== '' && str_contains($term, strtolower((string) $needle))) {
                return (string) $value;
            }
        }

        return 'new';
    }
}
