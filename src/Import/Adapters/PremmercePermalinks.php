<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\PermalinkProvider;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * Premmerce "Permalink Manager for WooCommerce" (woo-permalink-manager[-premium]): option premmerce_permalink_manager
 * { category: ''|slug|hierarchical, product: ''|slug|category_slug|hierarchical, use_primary_category: on|'' } removes
 * the product/category bases. Product category (PermalinkListener::getProductCategory): the SEO plugin's primary term
 * (Yoast via use_primary_category, Rank Math through the wc_product_post_type_link_product_cat filter), else the
 * product's category with the HIGHEST term id.
 */
class PremmercePermalinks extends AbstractAdapter implements PermalinkProvider
{
    public function key(): string
    {
        return 'premmerce-permalinks';
    }

    public function label(): string
    {
        return 'Premmerce permalinks';
    }

    public function priority(): int
    {
        return 50;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('woo-permalink-manager/premmerce-url-manager.php', 'woo-permalink-manager-premium/premmerce-url-manager.php',
            'woo-permalink-manager*/*.php');
    }

    public function permalinks(): array
    {
        $o = (array) $this->ctx->wp->option('premmerce_permalink_manager', []);
        $categoryMode = (string) ($o['category'] ?? '');
        $productMode = (string) ($o['product'] ?? '');
        $links = $this->ctx->permalinks();
        $builder = $links->builder();
        $out = ['products' => [], 'categories' => []];

        if ($categoryMode !== '') {
            foreach ($links->categoryTree() as $id => $t) {
                $out['categories'][$id] = $categoryMode === 'slug' ? $t['slug'] : $builder->categoryTreePath($id);
            }
        }
        if ($productMode !== '') {
            foreach ($links->productData() as $id => $p) {
                if ($p['status'] !== 'publish' || $p['slug'] === '') {
                    continue;
                }
                $known = array_values(array_filter($p['categories'], fn ($id) => isset($links->categoryTree()[$id])));
                $term = $p['primary'] ?? ($known ? max($known) : null);
                $out['products'][$id] = match ($productMode) {
                    'slug' => $p['slug'],
                    'category_slug' => ($term ? $links->categoryTree()[$term]['slug'].'/' : '').$p['slug'],
                    default => ($term ? $builder->categoryTreePath($term).'/' : '').$p['slug'],
                };
            }
        }

        return $out;
    }
}
