<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\ProductMapper;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\TaxonomyAttributeStep;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Brand taxonomies – WooCommerce Brands (product_brand), Perfect Brands (pwb-brand), YITH Brands
 * (yith_product_brand): products.brand = the first brand, plus a non-filterable "brand" attribute with the terms.
 */
class ProductBrands extends AbstractAdapter implements ProductMapper
{
    public const TAXONOMIES = ['product_brand', 'pwb-brand', 'yith_product_brand'];

    private ?string $taxonomy = null;

    private ?array $brands = null;

    public function key(): string
    {
        return 'product-brands';
    }

    public function label(): string
    {
        return 'Product brands';
    }

    public function priority(): int
    {
        return 50;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        foreach (self::TAXONOMIES as $taxonomy) {
            if ($wp->countTerms($taxonomy) > 0) {
                $this->taxonomy = $taxonomy;

                return true;
            }
        }

        return false;
    }

    public function steps(): array
    {
        return $this->taxonomy ? [new TaxonomyAttributeStep('catalog.brands', $this->taxonomy, 'brand', 'Brand')] : [];
    }

    public function mapProduct(array $row, WpProduct $product, ImportContext $ctx): array
    {
        if ($this->brands === null) {
            $this->brands = [];
            $terms = $ctx->wp->terms($this->taxonomy);
            $ids = $ctx->wp->table('posts')->where('post_type', 'product')->pluck('ID')->all();
            foreach ($ctx->wp->objectTerms($ids, $this->taxonomy) as $productId => $list) {
                $first = $list[0] ?? null;
                if ($first && isset($terms[$first->term_id])) {
                    $this->brands[(int) $productId] = Formatter::decode($terms[$first->term_id]->name);
                }
            }
        }
        if (isset($this->brands[$product->post->id])) {
            $row['brand'] = mb_substr($this->brands[$product->post->id], 0, 250);
        }

        return $row;
    }

    public function specRows(WpProduct $product, ImportContext $ctx): ?array
    {
        return null;
    }
}
