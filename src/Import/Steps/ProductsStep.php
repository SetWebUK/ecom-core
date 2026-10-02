<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\ProductMapper;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\Data\WpTerm;
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
        $localValues = $this->localAttributes($this->wp->postMeta($ids, ['_product_attributes']), $attributeIds);

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

                $slug = $post->post_name !== '' ? urldecode($post->post_name) : Str::slug($name);
                $base = $slug;
                $i = 2;
                while (isset($usedSlugs[$slug])) {
                    $slug = $base.'-'.$i++;
                }
                $usedSlugs[$slug] = true;

                $sku = trim((string) ($m['_sku'] ?? '')) ?: null;
                $regular = WordPressSource::decimal($m['_regular_price'] ?? null);
                $sale = WordPressSource::decimal($m['_sale_price'] ?? null);
                $saleFrom = WordPressSource::ts($m['_sale_price_dates_from'] ?? null);
                $saleTo = WordPressSource::ts($m['_sale_price_dates_to'] ?? null);
                $manage = WordPressSource::yes($m['_manage_stock'] ?? 'no');
                $stock = isset($m['_stock']) && is_numeric($m['_stock']) ? (int) $m['_stock'] : null;
                $status = match ($post->post_status) {
                    'publish' => 'published',
                    'private' => 'private',
                    default => 'draft',
                };
                $vars = ['title' => $name, 'excerpt' => Formatter::excerpt($post->post_excerpt ?: $post->post_content, 30)];
                $seo = $this->ctx->postSeo($wpPost, $m, $vars);
                if ($type === 'external' || $type === 'grouped') {
                    $this->ctx->warn("Product #{$post->ID} '$name' is a $type product – imported as a simple product"
                        .($type === 'external' ? ' (external URL: '.($m['_product_url'] ?? '?').')' : ' (children linked as related products)'));
                }

                $row = [
                    'wp_id' => $post->ID,
                    'name' => $name,
                    'slug' => $slug,
                    'sku' => $sku,
                    'type' => $type === 'variable' ? 'variable' : 'simple',
                    'status' => $status,
                    'primary_category_id' => $primary ? ($categoryIds[$primary] ?? null) : null,
                    'breadcrumb_category_id' => $breadcrumb && (int) $breadcrumb !== (int) $primary ? ($categoryIds[$breadcrumb] ?? null) : null,
                    'short_description' => Formatter::clean(Formatter::autop($post->post_excerpt)),
                    'description' => Formatter::clean(Formatter::autop($post->post_content)),
                    'subtitle' => null,
                    'condition' => null,
                    'brand' => null,
                    'regular_price' => $regular,
                    'sale_price' => $sale,
                    'sale_starts_at' => $saleFrom,
                    'sale_ends_at' => $saleTo,
                    'price' => $this->effectivePrice($regular, $sale, $saleFrom, $saleTo),
                    'cost_price' => null,
                    'manage_stock' => $manage,
                    'stock_quantity' => $manage ? $stock : null,
                    'stock_status' => in_array($m['_stock_status'] ?? '', ['instock', 'outofstock', 'onbackorder'], true) ? $m['_stock_status'] : 'instock',
                    'backorders' => in_array($m['_backorders'] ?? '', ['no', 'notify', 'yes'], true) ? $m['_backorders'] : 'no',
                    'low_stock_threshold' => isset($m['_low_stock_amount']) && is_numeric($m['_low_stock_amount']) ? (int) $m['_low_stock_amount'] : null,
                    'sold_individually' => WordPressSource::yes($m['_sold_individually'] ?? 'no'),
                    'weight' => $this->dimension($m['_weight'] ?? null),
                    'length' => $this->dimension($m['_length'] ?? null),
                    'width' => $this->dimension($m['_width'] ?? null),
                    'height' => $this->dimension($m['_height'] ?? null),
                    'tax_status' => $m['_tax_status'] ?? 'taxable',
                    'tax_class' => ($m['_tax_class'] ?? '') !== '' ? $m['_tax_class'] : null,
                    'is_featured' => in_array('featured', $visibility, true),
                    'sort_order' => (int) $post->menu_order,
                    'total_sales' => max(0, (int) ($m['total_sales'] ?? 0)),
                    'average_rating' => round((float) ($m['_wc_average_rating'] ?? 0), 2),
                    'review_count' => (int) ($m['_wc_review_count'] ?? 0),
                    'meta_title' => Str::limit((string) $seo?->title, 250, '') ?: null,
                    'meta_description' => $seo?->description,
                    'focus_keyword' => Str::limit((string) $seo?->focusKeyword, 250, '') ?: null,
                    'gtin' => trim((string) ($m['_global_unique_id'] ?? '')) ?: null,
                    'published_at' => $post->post_status === 'publish' ? WordPressSource::gmt($post->post_date_gmt) : null,
                    'created_at' => WordPressSource::gmt($post->post_date_gmt) ?? WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
                    'updated_at' => WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
                    'deleted_at' => null,
                ];
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

    private function productChildren(array $children, array $productIds, array $categoryIds, array $attributeIds, array $localValues): void
    {
        $ids = array_values($productIds);
        foreach (['category_product', 'product_images', 'product_attributes', 'attribute_value_product', 'product_specs'] as $table) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table($table)->whereIn('product_id', $chunk)->delete();
            }
        }

        $valueIds = $this->ctx->owned('attribute_values')->pluck('id', 'wp_id')->all();
        $attachments = $this->ctx->attachments();
        $cats = $images = $attrs = $values = $specs = [];
        $missingImages = 0;

        foreach ($children as $wpId => $c) {
            $pid = $productIds[$wpId] ?? null;
            if (! $pid) {
                continue;
            }
            foreach (array_unique($c['categories']) as $termId) {
                if (isset($categoryIds[$termId])) {
                    $cats[] = ['category_id' => $categoryIds[$termId], 'product_id' => $pid];
                }
            }
            foreach ($c['images'] as $pos => $attachmentId) {
                $a = $attachments[$attachmentId] ?? null;
                if (! $a) {
                    continue;
                }
                if (! $a['exists']) {
                    $missingImages++;
                }
                $images[] = ['product_id' => $pid, 'path' => $a['path'], 'alt' => $a['alt'], 'sort_order' => $pos,
                    'created_at' => $this->now(), 'updated_at' => $this->now()];
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
                $attrs[$pid.'|'.$attributeId] = ['product_id' => $pid, 'attribute_id' => $attributeId, 'position' => (int) ($attr['position'] ?? $position),
                    'is_visible' => ! empty($attr['is_visible']), 'is_variation' => ! empty($attr['is_variation'])];
                $position++;
                if (! $isTaxonomy) {
                    foreach (array_filter(array_map('trim', explode('|', (string) ($attr['value'] ?? '')))) as $v) {
                        if ($valueId = $localValues[$attributeId.'|'.Str::slug($v)] ?? null) {
                            $values[$pid.'|'.$valueId] = ['attribute_value_id' => $valueId, 'product_id' => $pid];
                        }
                    }
                }
            }
            foreach ($c['attrTerms'] as $list) {
                foreach ($list as $t) {
                    if ($valueId = $valueIds[$t['term']] ?? null) {
                        $values[$pid.'|'.$valueId] = ['attribute_value_id' => $valueId, 'product_id' => $pid];
                    }
                }
            }
            foreach ($c['specs'] as $i => $s) {
                $specs[] = ['product_id' => $pid, 'key' => $s['key'], 'label' => $s['label'], 'value' => $s['value'], 'description' => $s['description'], 'sort_order' => $i];
            }
        }

        $this->ctx->insert('category_product', $cats);
        $this->ctx->insert('product_images', $images);
        $this->ctx->insert('product_attributes', array_values($attrs));
        $this->ctx->insert('attribute_value_product', array_values($values));
        $this->ctx->insert('product_specs', $specs);

        $this->add('Product images', count($images), $missingImages ? '%d image files missing' : '', $missingImages);
        $this->add('Product ↔ category links', count($cats));
        $this->add('Product attribute values', count($values));
        $this->add('Product spec rows', count($specs), 'Technical specification table');
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
        $ids = array_values($productIds);
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('related_products')->whereIn('product_id', $chunk)->delete();
        }
        $rows = [];
        foreach ($related as [$wpId, $relatedWp, $type]) {
            $pid = $productIds[$wpId] ?? null;
            $rid = $productIds[$relatedWp] ?? null;
            if ($pid && $rid && $rid !== $pid) {
                $rows[$pid.'|'.$rid.'|'.$type] = ['product_id' => $pid, 'related_id' => $rid, 'type' => $type];
            }
        }
        $this->ctx->insert('related_products', array_values($rows));
        $this->ctx->count('Up-sells / cross-sells', '—', count($rows));
    }

    /** Local (non-taxonomy) product attributes -> global attributes + values. Returns ["attrId|slug" => id]. */
    private function localAttributes(array $meta, array &$attributeIds): array
    {
        $values = [];
        foreach ($meta as $m) {
            foreach ((array) (WordPressSource::unserialize($m['_product_attributes'] ?? '') ?: []) as $attr) {
                if (! is_array($attr) || ! empty($attr['is_taxonomy']) || empty($attr['name'])) {
                    continue;
                }
                $name = Formatter::decode($attr['name']);
                $slug = Str::slug($name);
                if (! isset($attributeIds[$slug])) {
                    $attributeIds[$slug] = DB::table('attributes')->insertGetId([
                        'name' => $name, 'slug' => $slug, 'type' => 'select', 'is_filterable' => false,
                        'sort_order' => 50, 'created_at' => $this->now(), 'updated_at' => $this->now(),
                    ]);
                }
                foreach (array_filter(array_map('trim', explode('|', (string) ($attr['value'] ?? '')))) as $i => $v) {
                    $values[$attributeIds[$slug].'|'.Str::slug($v)] = ['attribute_id' => $attributeIds[$slug], 'value' => $v, 'slug' => Str::slug($v), 'sort_order' => $i, 'wp_id' => null];
                }
            }
        }

        return $values ? AttributesStep::saveValues(array_values($values), $this->now()) : [];
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

    private function effectivePrice(?float $regular, ?float $sale, ?string $from, ?string $to): ?float
    {
        $now = gmdate('Y-m-d H:i:s');
        // Same rule as Product::isOnSale()
        $onSale = $sale !== null && $regular !== null && $sale < $regular
            && (! $from || $from <= $now) && (! $to || $to >= $now);

        return $onSale ? $sale : $regular;
    }

    private function dimension($value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
