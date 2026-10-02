<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\ProductMapper;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\Data\WpTerm;
use Pine\Commerce\Import\Mapping\ProductChildren;
use Pine\Commerce\Import\Mapping\ProductRows;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Products (+ images/gallery, categories, global and local attributes, spec rows, up-sells/cross-sells, grouped
 * children), processed in chunks of 500. Simple and variable products map 1:1; grouped and external products are
 * imported as simple products (the schema has no such types) with a warning – grouped children become related
 * products of type 'grouped'. The primary category is the one in the product's real URL on the source site (see
 * Permalinks), else the SEO plugin's primary term, else the deepest category. Columns beyond WooCommerce core
 * (brand, subtitle, condition, cost …) come from ProductMapper adapters; `commerce-import.attributes.map`
 * (attribute slug => products column) copies attribute values into columns.
 */
class ProductsStep extends AbstractStep
{
    public const CHUNK = 500;

    public function key(): string
    {
        return 'catalog.products';
    }

    public function section(): string
    {
        return 'catalog';
    }

    public function after(): array
    {
        return ['catalog.attributes'];
    }

    protected function clear(): void
    {
        $this->ctx->owned('products')->delete(); // cascades to images, pivots, variations, specs
    }

    protected function import(): void
    {
        $categoryPaths = $this->ctx->owned('categories')->pluck('path', 'wp_id')->all();
        $categoryIds = $this->ctx->map('categories');
        $attributeIds = DB::table('attributes')->pluck('id', 'slug')->all();
        $productCats = $this->wp->terms('product_cat');
        $attrTaxonomies = array_map(fn ($s) => 'pa_'.$s, array_keys($attributeIds));
        $termInfo = $this->wp->terms(array_merge(['product_type', 'product_visibility'], $attrTaxonomies));
        $termMeta = $this->wp->termMeta($termInfo->keys()->all());
        $wpUrls = $this->ctx->permalinks()->products();
        $pathToTerm = array_flip($categoryPaths);
        $mappers = $this->ctx->adapters->providers(ProductMapper::class);
        $attributeMap = (array) $this->ctx->config('attributes.map', []);
        $builder = $this->ctx->permalinks()->builder();

        $posts = $this->wp->posts('product');
        $ids = $posts->pluck('ID')->all();

        // Local (non-taxonomy) attributes become global attributes too.
        $local = [];
        foreach ($this->wp->postMeta($ids, ['_product_attributes']) as $m) {
            foreach ((array) (WordPressSource::unserialize($m['_product_attributes'] ?? '') ?: []) as $attr) {
                if (is_array($attr) && empty($attr['is_taxonomy']) && ! empty($attr['name'])) {
                    $local[] = ['name' => (string) $attr['name'], 'values' => explode('|', (string) ($attr['value'] ?? ''))];
                }
            }
        }
        $localValues = ProductChildren::localAttributes($local, $attributeIds, $this->now());

        $usedSlugs = [];
        $allRows = [];
        $productIds = [];
        $related = [];
        $types = [];
        foreach ($posts->chunk(self::CHUNK) as $chunk) {
            $chunkIds = $chunk->pluck('ID')->all();
            $meta = $this->wp->postMeta($chunkIds);
            $objectTerms = $this->wp->objectTerms($chunkIds, array_merge(['product_cat', 'product_type', 'product_visibility'], $attrTaxonomies));
            $rows = [];
            $children = [];
            foreach ($chunk as $post) {
                $m = $meta[$post->ID] ?? [];
                $name = Formatter::decode($post->post_title);
                $terms = collect($objectTerms[$post->ID] ?? []);
                $cats = $terms->where('taxonomy', 'product_cat')->pluck('term_id')->map(fn ($v) => (int) $v)->all();
                $type = optional($termInfo->get($terms->firstWhere('taxonomy', 'product_type')?->term_id))->slug ?? 'simple';
                $types[$type] = ($types[$type] ?? 0) + 1;
                $visibility = $terms->where('taxonomy', 'product_visibility')->map(fn ($t) => $termInfo[$t->term_id]->slug ?? null)->filter()->all();

                // Attribute values (ordered like wc_get_product_terms: term order, then name)
                $attrNames = [];
                foreach ($terms->filter(fn ($t) => str_starts_with($t->taxonomy, 'pa_')) as $t) {
                    $info = $termInfo[$t->term_id] ?? null;
                    if ($info) {
                        $attrNames[$t->taxonomy][] = ['name' => Formatter::decode($info->name), 'order' => (int) ($termMeta[$t->term_id]['order'] ?? $termMeta[$t->term_id]['order_'.$t->taxonomy] ?? 0), 'term' => $t->term_id];
                    }
                }
                $wpTerms = [];
                foreach ($attrNames as $tax => $list) {
                    usort($list, fn ($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
                    $attrNames[$tax] = $list;
                    $wpTerms[$tax] = array_map(fn ($a) => WpTerm::fromRow($termInfo[$a['term']], $termMeta[$a['term']] ?? []), $list);
                }
                $sortedCats = $cats;
                sort($sortedCats);
                $wpTerms['product_cat'] = array_values(array_filter(array_map(fn ($id) => isset($productCats[$id]) ? WpTerm::fromRow($productCats[$id]) : null, $sortedCats)));
                $attrJoined = array_map(fn ($list) => implode(', ', array_column($list, 'name')), $attrNames);
                $wpPost = WpPost::fromRow($post);
                $product = new WpProduct($wpPost, $m, $wpTerms, $cats,
                    (array) (WordPressSource::unserialize($m['_product_attributes'] ?? '') ?: []), $type);

                // Primary category = the one in the product's real URL on the source site
                $primary = null;
                if (isset($wpUrls[$post->ID])) {
                    $catPath = Str::beforeLast($wpUrls[$post->ID], '/');
                    $primary = $pathToTerm[$catPath] ?? null;
                    while (! $primary && str_contains($catPath, '/')) { // strip a product base: shop/clothing/shirts -> clothing/shirts
                        $catPath = Str::after($catPath, '/');
                        $candidate = $pathToTerm[$catPath] ?? null;
                        $primary = $candidate && in_array((int) $candidate, $cats, true) ? $candidate : null;
                    }
                }
                if (! $primary && ($seoPrimary = $this->ctx->primaryTermId($wpPost, $m, 'product_cat')) && in_array($seoPrimary, $cats, true)) {
                    $primary = $seoPrimary;
                }
                if (! $primary && $cats) {
                    $primary = $builder->deepestCategory($cats);
                }
                // the SEO plugin's breadcrumb may show another of the product's categories than its URL (Rank Math
                // without a primary term: the first category by name) - kept so breadcrumbs match the old site
                $breadcrumb = $cats ? $this->ctx->breadcrumbTermId($wpPost, $m, 'product_cat', array_values(array_filter(array_map(
                    fn ($id) => isset($productCats[$id]) ? ['id' => (int) $id, 'name' => (string) $productCats[$id]->name, 'parent' => (int) $productCats[$id]->parent] : null,
                    $cats)))) : null;

                $slug = ProductRows::uniqueSlug($post->post_name !== '' ? urldecode($post->post_name) : Str::slug($name), $usedSlugs);
                $vars = ['title' => $name, 'excerpt' => Formatter::excerpt($post->post_excerpt ?: $post->post_content, 30)];
                $seo = $this->ctx->postSeo($wpPost, $m, $vars);
                if ($warning = ProductRows::typeWarning((int) $post->ID, $name, $type, $m['_product_url'] ?? null)) {
                    $this->ctx->warn($warning);
                }

                $row = ProductRows::row($product, [
                    'slug' => $slug,
                    'primary_category_id' => $primary ? ($categoryIds[$primary] ?? null) : null,
                    'breadcrumb_category_id' => $breadcrumb && (int) $breadcrumb !== (int) $primary ? ($categoryIds[$breadcrumb] ?? null) : null,
                    'seo' => $seo,
                    'featured' => in_array('featured', $visibility, true),
                ], $this->now());
                foreach ($attributeMap as $attribute => $column) {
                    if (($attrJoined['pa_'.$attribute] ?? '') !== '') {
                        $row[$column] = Str::limit($attrJoined['pa_'.$attribute], 250, '');
                    }
                }
                $specs = [];
                foreach ($mappers as $mapper) {
                    $row = $mapper->mapProduct($row, $product, $this->ctx);
                    $specs = $mapper->specRows($product, $this->ctx) ?? $specs;
                }
                $rows[] = $row;

                $children[$post->ID] = [
                    'categories' => $cats,
                    'images' => $this->imageIds($m),
                    'attributes' => $product->attributes,
                    'attrTerms' => $attrNames,
                    'specs' => $specs,
                ];
                foreach (['upsell' => '_upsell_ids', 'cross_sell' => '_crosssell_ids', 'grouped' => '_children'] as $relType => $key) {
                    foreach ((array) (WordPressSource::unserialize($m[$key] ?? '') ?: []) as $relatedWp) {
                        $related[] = [(int) $post->ID, (int) $relatedWp, $relType];
                    }
                }
            }

            $this->ctx->adopt('products', $rows, 'slug');
            $chunkMap = $this->ctx->save('products', $rows, 'wp_id', ['created_at']);
            $productIds += $chunkMap;
            $this->productChildren($children, $chunkMap, $categoryIds, $attributeIds, $localValues);
            foreach ($rows as $row) {
                $allRows[] = ['wp_id' => $row['wp_id'], 'slug' => $row['slug'], 'status' => $row['status'], 'primary_category_id' => $row['primary_category_id']];
            }
        }
        $this->relatedProducts($related, $productIds);

        // URL parity with the source site
        $mismatch = [];
        $legacy = 0;
        $pathByProduct = [];
        $catByLaravelId = array_flip($categoryIds);
        foreach ($allRows as $row) {
            $cat = $row['primary_category_id'] ? ($catByLaravelId[$row['primary_category_id']] ?? null) : null;
            $pathByProduct[$row['wp_id']] = ($cat ? $categoryPaths[$cat].'/' : 'product/').$row['slug'];
        }
        foreach ($wpUrls as $wpId => $wpPath) {
            if (! isset($pathByProduct[$wpId]) || $pathByProduct[$wpId] === $wpPath) {
                continue;
            }
            if ($wpPath === 'product/'.Str::afterLast($pathByProduct[$wpId], '/')) {
                $legacy++; // /product/{slug}/ is redirected by the storefront itself

                continue;
            }
            $mismatch[] = "/$wpPath/ vs /{$pathByProduct[$wpId]}/";
        }
        foreach ($mismatch as $m) {
            $this->ctx->warn('Product URL differs (WP vs Laravel, redirected): '.$m);
        }
        $this->ctx->setState('products.sourcePaths', $wpUrls);
        $published = collect($allRows)->where('status', 'published')->count();
        $typeNote = collect($types)->map(fn ($c, $t) => "$t $c")->implode(', ');
        $this->ctx->count('Products', $posts->count().' ('.$posts->where('post_status', 'publish')->count().' published)',
            $this->ctx->owned('products')->count().' ('.$published.' published)',
            ($wpUrls ? (count($mismatch) || $legacy ? count($mismatch).' URLs redirected, '.$legacy.' via /product/ fallback' : 'all '.count($wpUrls).' product URLs match WP') : '')
            .($typeNote ? '; '.$typeNote : ''));
    }

    /** Resolve WordPress ids (terms, attachments, attribute terms) to local ids, then write via ProductChildren. */
    private function productChildren(array $children, array $productIds, array $categoryIds, array $attributeIds, array $localValues): void
    {
        $valueIds = $this->ctx->owned('attribute_values')->pluck('id', 'wp_id')->all();
        $attachments = $this->ctx->attachments();
        $resolved = [];
        foreach ($children as $wpId => $c) {
            $r = ['categories' => [], 'images' => [], 'attributes' => [], 'values' => [], 'specs' => $c['specs']];
            foreach (array_unique($c['categories']) as $termId) {
                if (isset($categoryIds[$termId])) {
                    $r['categories'][] = $categoryIds[$termId];
                }
            }
            foreach ($c['images'] as $attachmentId) {
                if ($a = $attachments[$attachmentId] ?? null) {
                    $r['images'][] = ['path' => $a['path'], 'alt' => $a['alt'], 'exists' => $a['exists']];
                }
            }
            $position = 0;
            foreach ((array) $c['attributes'] as $key => $attr) {
                if (! is_array($attr)) {
                    continue;
                }
                $isTaxonomy = ! empty($attr['is_taxonomy']);
                $slug = $isTaxonomy ? substr((string) ($attr['name'] ?? $key), 3) : Str::slug((string) ($attr['name'] ?? $key));
                $attributeId = $attributeIds[$slug] ?? null;
                if (! $attributeId) {
                    continue;
                }
                $r['attributes'][] = ['attribute_id' => $attributeId, 'position' => (int) ($attr['position'] ?? $position),
                    'is_visible' => ! empty($attr['is_visible']), 'is_variation' => ! empty($attr['is_variation'])];
                $position++;
                if (! $isTaxonomy) {
                    foreach (array_filter(array_map('trim', explode('|', (string) ($attr['value'] ?? '')))) as $v) {
                        if ($valueId = $localValues[$attributeId.'|'.Str::slug($v)] ?? null) {
                            $r['values'][] = $valueId;
                        }
                    }
                }
            }
            foreach ($c['attrTerms'] as $list) {
                foreach ($list as $t) {
                    if ($valueId = $valueIds[$t['term']] ?? null) {
                        $r['values'][] = $valueId;
                    }
                }
            }
            $resolved[$wpId] = $r;
        }
        $counts = ProductChildren::write($resolved, $productIds, $this->now(), $this->ctx->upserter());

        $this->add('Product images', $counts['images'], $counts['missing_images'] ? '%d image files missing' : '', $counts['missing_images']);
        $this->add('Product ↔ category links', $counts['categories']);
        $this->add('Product attribute values', $counts['values']);
        $this->add('Product spec rows', $counts['specs'], 'Technical specification table');
    }

    /** Accumulate a summary row over chunks. */
    private function add(string $label, int $count, string $noteFormat = '', int $noteValue = 0): void
    {
        $totals = $this->ctx->state('products.totals', []);
        $totals[$label] = [($totals[$label][0] ?? 0) + $count, ($totals[$label][1] ?? 0) + $noteValue];
        $this->ctx->setState('products.totals', $totals);
        [$total, $noteTotal] = $totals[$label];
        $this->ctx->count($label, '—', $total, $noteFormat && $noteTotal ? sprintf($noteFormat, $noteTotal) : '');
    }

    private function relatedProducts(array $related, array $productIds): void
    {
        $count = ProductChildren::related($related, $productIds, array_values($productIds), $this->ctx->upserter());
        $this->ctx->count('Up-sells / cross-sells', '—', $count);
    }

    private function imageIds(array $m): array
    {
        $ids = [];
        if (! empty($m['_thumbnail_id'])) {
            $ids[] = (int) $m['_thumbnail_id'];
        }
        foreach (explode(',', (string) ($m['_product_image_gallery'] ?? '')) as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
