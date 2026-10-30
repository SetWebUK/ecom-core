<?php

namespace Pine\Commerce\Services\Marketing;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;

/**
 * The product IDs the ad platforms see, the same everywhere: the Google Shopping feed (g:id / g:item_group_id),
 * Google Ads remarketing, the Meta Pixel and a theme's own tags (Services\Marketing\Ecommerce).
 *  - A product imported from WooCommerce keeps its WordPress post ID, so the catalogues, audiences and history built
 *    on the old shop still match (WooCommerce's feed and Pixel Manager plugins send the post ID).
 *  - A product made on this site uses its own ID, with a "p" in front in a shop that has imported products, so it can
 *    never take an old product's ID.
 *  - A variation: its WordPress ID, else "v" + its ID.
 * Google Analytics item IDs follow WooCommerce's Google Analytics plugin ("product SKU" setting): the SKU, else "#"
 * and the product ID.
 */
final class ProductIds
{
    public static function product(Product $product): string
    {
        if ($product->wp_id) {
            return (string) $product->wp_id;
        }

        return (static::shopHasImports() ? 'p' : '').$product->id;
    }

    public static function variation(ProductVariation $variation): string
    {
        return (string) ($variation->wp_id ?: 'v'.$variation->id);
    }

    /** GA4 item_id: the SKU given (e.g. an order line's), else the variation's own, else the product's, else "#" + product(). */
    public static function analytics(Product $product, ?ProductVariation $variation = null, ?string $sku = null): string
    {
        foreach ([$sku, $variation?->sku, $product->sku] as $candidate) {
            if (($candidate = trim((string) $candidate)) !== '') {
                return $candidate;
            }
        }

        return '#'.static::product($product);
    }

    /** Has this shop imported products (WooCommerce database or REST importer)? Once per request. */
    public static function shopHasImports(): bool
    {
        return once(fn () => Product::query()->whereNotNull('wp_id')->exists());
    }
}
