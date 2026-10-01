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
 *           feeds.shipping_price (default 0.00 - free UK delivery).
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
        $shipping = number_format((float) setting('feeds.shipping_price', 0), 2, '.', '');

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
    protected function baseFields(Product $product, string $defaultCategory, string $shipping): array
    {
        $images = $product->images->map(fn ($img) => media_url($img->path))->values();
        $category = $product->primaryCategory ?? $product->categories->first();
        $productType = $category ? collect(Categories::ancestors($category))->push($category)->pluck('name')->implode(' > ') : null;
        // g:condition: the condition attribute (feature product_condition), else commerce.feeds.google.default_condition
        $conditionAttr = commerce_feature('product_condition')
            ? strtolower(commerce_presenter()::attr($product, 'pa_'.config('commerce.catalog.condition_attribute', 'condition'))) : '';
        $condition = $conditionAttr === '' ? (string) config('commerce.feeds.google.default_condition', 'new')
            : static::googleCondition($conditionAttr);
        $description = Text::plain($product->description ?: $product->short_description) ?: Text::plain($product->short_description) ?: $product->name;
        $identifierExists = filled($product->gtin) || filled($product->mpn);

        return [
            'g:id' => (string) ($product->wp_id ?: $product->id),
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
            'g:shipping' => ['g:country' => 'GB', 'g:service' => 'Free UK Delivery', 'g:price' => $shipping.' GBP'],
        ];
    }

    protected function variationFields(Product $product, ProductVariation $variation, array $base): array
    {
        $price = (float) $variation->currentPrice();
        $regular = $variation->regular_price !== null ? (float) $variation->regular_price : $price;
        $options = collect((array) $variation->options)->map(fn ($v) => ucwords(str_replace('-', ' ', (string) $v)))->implode(' / ');

        return array_merge($base, [
            'g:id' => (string) ($variation->wp_id ?: 'v'.$variation->id),
            'g:item_group_id' => $base['g:id'],
            'g:title' => mb_substr($base['g:title'].($options !== '' ? ' - '.$options : ''), 0, 150),
            'g:link' => $product->url,
            'g:image_link' => $variation->image ? media_url($variation->image) : $base['g:image_link'],
            'g:mpn' => $variation->sku ?: $base['g:mpn'],
            'g:price' => $this->money($variation->isOnSale() ? $regular : $price),
            'g:sale_price' => $variation->isOnSale() ? $this->money($price) : null,
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
