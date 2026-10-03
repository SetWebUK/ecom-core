<?php

namespace Pine\Commerce\Import\WooApi\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Mapping\CatalogRows;
use Pine\Commerce\Import\Mapping\ProductChildren;
use Pine\Commerce\Import\Mapping\ProductRows;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Support\Upserter;
use Pine\Commerce\Import\WooApi\ApiMap;
use Pine\Commerce\Import\WooApi\StoreApi;
use Pine\Commerce\Import\WooApi\WooApiException;
use Pine\Commerce\Models\Product;
use Throwable;

/**
 * Categories, attributes + terms, products (+ variations, tags, images, related products) of the WooCommerce API
 * import. Categories keep WooCommerce's hierarchy (path = hierarchical slugs, the storefront URL); a product's
 * primary category is the one in its real permalink, so product URLs stay what they were on the old shop – where
 * they cannot, a 301 from the old path is added.
 */
trait ImportsCatalog
{
    /** remote attribute id => slug (without "pa_") */
    protected ?array $attributeSlugs = null;

    /** lookups for the product pages (categories, attributes, values, shipping classes) */
    protected ?array $catalog = null;

    protected function importCategories(): void
    {
        $entity = 'categories';
        $items = $this->store()
            ? array_map([StoreApi::class, 'category'], $this->all($entity, 'products/categories', [], 'wc/store/v1'))
            : $this->all($entity, 'products/categories', ['orderby' => 'id', 'order' => 'asc']);
        $this->bump($entity, 'fetched', count($items));
        $links = $this->store() ? array_column($items, 'link', 'id') : $this->categoryLinks();

        $byId = [];
        foreach ($items as $c) {
            $byId[(int) $c['id']] = $c;
        }
        $depth = function (int $id, int $guard = 0) use (&$depth, $byId): int {
            $parent = (int) ($byId[$id]['parent'] ?? 0);

            return $parent && isset($byId[$parent]) && $guard < 50 ? 1 + $depth($parent, $guard + 1) : 0;
        };
        $levels = [];
        foreach (array_keys($byId) as $id) {
            $levels[$depth($id)][] = $id;
        }
        ksort($levels);

        $this->transaction(function () use ($levels, $byId, $links, $entity) {
            $own = $this->u->map('categories');
            $finalPath = [];
            $map = $own;
            foreach ($levels as $ids) {
                $rows = [];
                foreach ($ids as $id) {
                    $c = $byId[$id];
                    try {
                        $term = ApiMap::term($c, 'product_cat');
                        $parent = (int) ($c['parent'] ?? 0);
                        $slug = urldecode((string) ($c['slug'] ?? '')) ?: Str::slug((string) $c['name']) ?: 'category-'.$id;
                        $path = (isset($finalPath[$parent]) ? $finalPath[$parent].'/' : '').$slug;
                        if (! isset($own[$id]) && ($this->foreign('categories', 'path', [$path])[$path] ?? null)) {
                            $taken = $path;
                            $path = $this->unique('categories', 'path', $path);
                            $this->issue('warning', $entity, $id, "/$taken/ already belongs to another shop's category – imported as /$path/");
                        }
                        $finalPath[$id] = $path;
                        $name = Formatter::decode((string) ($c['name'] ?? ''));
                        $description = Formatter::clean(Formatter::autop((string) ($c['description'] ?? '')));
                        $seo = $this->seo->read($c, ['term' => $name, 'term_description' => Formatter::excerpt($description, 100000)],
                            $links[$id] ?? null, $description);
                        $rows[] = CatalogRows::category($term, $path, $parent ? ($map[$parent] ?? null) : null,
                            $this->image($entity, $id, is_array($c['image'] ?? null) ? $c['image'] : null), $seo, null, $slug !== 'uncategorized',
                            $this->now(), $description);
                    } catch (Throwable $e) {
                        $this->issue('error', $entity, $id, $e->getMessage());
                    }
                }
                $rows = $this->withoutExisting($entity, 'categories', $rows);
                if (! $this->dryRun) {
                    $this->u->adopt('categories', $rows, 'path');
                }
                $this->saveRows($entity, 'categories', $rows, 'wp_id', ['created_at']);
                $map = $this->u->map('categories');
            }
            // old category URLs the storefront serves elsewhere
            foreach ($finalPath as $id => $path) {
                $source = ApiMap::path($links[$id] ?? null, $this->home);
                if ($source !== null && $source !== $path && $source !== 'product-category/'.$path) {
                    $this->redirect($source, $path);
                }
            }
        });
        $this->catalog = null;
    }

    /** Category id => link from the public wp/v2/product_cat endpoint (WooCommerce's own API has no category links). */
    protected function categoryLinks(): array
    {
        try {
            $links = [];
            foreach ($this->client->pages('product_cat', ['_fields' => 'id,link'], 'wp/v2') as [$page, $items]) {
                foreach ($items as $t) {
                    $links[(int) $t['id']] = (string) ($t['link'] ?? '');
                }
            }

            return $links;
        } catch (WooApiException $e) {
            $this->log->info('Category links unavailable ('.$e->getMessage().') – old category URLs are not compared.');

            return [];
        }
    }

    protected function importAttributes(): void
    {
        $entity = 'attributes';
        $namespace = $this->store() ? 'wc/store/v1' : 'wc/v3';
        $attributes = $this->all($entity, 'products/attributes', [], $namespace);
        $filters = array_values((array) config('commerce-import.attributes.filterable', []));
        $rows = [];
        foreach ($attributes as $a) {
            $slug = $this->rememberAttribute($a);
            $filterPos = array_search($slug, $filters, true);
            $rows[] = ['slug' => $slug, 'name' => Formatter::decode((string) ($a['name'] ?? '')) ?: Str::headline($slug), 'type' => 'select',
                'is_filterable' => $filterPos !== false, 'sort_order' => $filterPos !== false ? $filterPos + 1 : 10 + (int) $a['id'],
                'created_at' => $this->now(), 'updated_at' => $this->now()];
        }
        $this->bump($entity, 'fetched', count($rows));
        $keep = $filters ? ['created_at'] : ['created_at', 'is_filterable', 'sort_order']; // the admin's filter choices survive a re-import
        $ids = $this->transaction(fn () => $this->upsert($entity, 'attributes', $rows, 'slug', $keep));

        foreach ($attributes as $a) {
            $slug = $this->attributeSlugs[(int) $a['id']];
            $terms = $this->all('', 'products/attributes/'.(int) $a['id'].'/terms', $this->store() ? [] : ['orderby' => 'id'], $namespace);
            $this->bump($entity, 'fetched', count($terms));
            if ($this->dryRun || ! isset($ids[$slug])) {
                continue;
            }
            $values = [];
            foreach ($terms as $t) {
                $values[] = ['attribute_id' => $ids[$slug], 'value' => Formatter::decode((string) ($t['name'] ?? '')),
                    'slug' => urldecode((string) ($t['slug'] ?? '')) ?: Str::slug((string) $t['name']),
                    'sort_order' => (int) ($t['menu_order'] ?? 0), 'wp_id' => (int) $t['id']] + $this->sourceColumn('attribute_values');
            }
            $before = DB::table('attribute_values')->where('attribute_id', $ids[$slug])->count();
            $this->transaction(fn () => ProductChildren::saveValues($values, $this->now()));
            $created = DB::table('attribute_values')->where('attribute_id', $ids[$slug])->count() - $before;
            $this->bump($entity, 'created', $created);
            $this->bump($entity, 'updated', count($values) - $created);
            $this->checkCancel();
        }
        $this->catalog = null;
    }

    /** Remember (and return) an attribute's slug without "pa_". */
    protected function rememberAttribute(array $a): string
    {
        $slug = (string) ($a['slug'] ?? $a['taxonomy'] ?? '');
        $slug = str_starts_with($slug, 'pa_') ? substr($slug, 3) : ($slug !== '' ? $slug : Str::slug((string) ($a['name'] ?? '')));
        $this->attributeSlugs[(int) $a['id']] = $slug;

        return $slug;
    }

    protected function attributeSlugs(): array
    {
        if ($this->attributeSlugs === null) {
            $this->attributeSlugs = [];
            try {
                foreach ($this->all('', 'products/attributes', [], $this->store() ? 'wc/store/v1' : 'wc/v3') as $a) {
                    $this->rememberAttribute($a);
                }
            } catch (WooApiException $e) {
                $this->log->warning('Attribute list unavailable: '.$e->getMessage());
            }
        }

        return $this->attributeSlugs;
    }

    /** ['import_source' => source] for inserts into a scoped table (or nothing). */
    protected function sourceColumn(string $table): array
    {
        return $this->u->source !== null && Upserter::scoped($table) ? [Upserter::COLUMN => $this->u->source] : [];
    }

    // ------------------------------------------------------------------ products

    protected function importProducts(): void
    {
        $this->attributeSlugs();
        $query = $this->store() ? [] : $this->since(['status' => 'any', 'orderby' => 'id', 'order' => 'asc']);
        if ($this->option('since') && $this->store()) {
            $this->log->warning('The Store API cannot list changed products only – every product is read.');
        }
        $this->paged('products', 'products', $query, $this->store() ? 'wc/store/v1' : 'wc/v3', fn (array $items) => $this->productPage($items));

        // up-sells, cross-sells, grouped children: all products exist now
        $pairs = (array) ($this->checkpoint['related'] ?? []);
        if ($pairs && ! $this->dryRun) {
            $map = $this->u->map('products');
            $owners = array_values(array_filter(array_map(fn ($id) => $map[$id] ?? null, array_unique(array_column($pairs, 0)))));
            $count = $this->transaction(fn () => ProductChildren::related($pairs, $map, $owners, $this->u));
            $this->log->info('Related products (up-sells, cross-sells, grouped): '.$count);
        }
        unset($this->checkpoint['related']);
    }

    protected function catalogLookups(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $categories = DB::table('categories')->get(['id', 'path']);
        $values = DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attribute_values.id', 'attribute_values.attribute_id', 'attribute_values.value', 'attribute_values.slug', 'attributes.slug as a']);
        $byName = $bySlug = $slugByName = [];
        foreach ($values as $v) {
            $byName[$v->attribute_id.'|'.mb_strtolower((string) $v->value)] = $v->id;
            $bySlug[$v->attribute_id.'|'.$v->slug] = $v->id;
            $slugByName[$v->a.'|'.mb_strtolower((string) $v->value)] = $v->slug;
        }

        return $this->catalog = [
            'categories' => $this->u->map('categories'),
            'catPath' => $categories->pluck('path', 'id')->all(),
            'pathToCat' => $categories->pluck('id', 'path')->all(),
            'attributes' => DB::table('attributes')->pluck('id', 'slug')->all(),
            'valueByName' => $byName,
            'valueBySlug' => $bySlug,
            'slugByName' => $slugByName,
            'classes' => $this->u->map('shipping_classes'),
            'shipping' => in_array('shipping', $this->run->entities(), true),
        ];
    }

    protected function productPage(array $items): void
    {
        $entity = 'products';
        $this->catalogLookups();
        $l = &$this->catalog; // attributes / values created on the fly stay known for the next pages
        $rows = $children = $variable = $sources = [];
        foreach ($items as $raw) {
            $p = $this->store() ? StoreApi::product($raw) : $raw;
            $id = (int) ($p['id'] ?? 0);
            try {
                $product = ApiMap::product($p, $this->attributeSlugs ?? []);
                $name = Formatter::decode($product->post->title);
                $cats = array_values(array_filter(array_map(fn ($c) => $l['categories'][$c] ?? null, $product->categoryIds)));
                $sourcePath = ApiMap::path($p['permalink'] ?? null, $this->home);
                $primary = $this->primaryCategory($sourcePath, $cats, $l);
                $short = Formatter::clean((string) ($p['short_description'] ?? ''));
                $description = Formatter::clean((string) ($p['description'] ?? ''));
                $seo = $this->seo->read($p, ['title' => $name, 'excerpt' => Formatter::excerpt($short ?: $description, 30)], $p['permalink'] ?? null, $short ?: $description);
                if ($warning = ProductRows::typeWarning($id, $name, $product->type, $p['external_url'] ?? null)) {
                    $this->issue('warning', $entity, $id, $warning);
                }
                $row = ProductRows::row($product, [
                    'slug' => urldecode($product->post->name) ?: Str::slug($name) ?: 'product-'.$id,
                    'primary_category_id' => $primary,
                    'breadcrumb_category_id' => null,
                    'seo' => $seo,
                    'featured' => ! empty($p['featured']),
                    'short_description' => $short,
                    'description' => $description,
                ], $this->now());
                if (! empty($p['brands'][0]['name'])) {
                    $row['brand'] = Str::limit(Formatter::decode((string) $p['brands'][0]['name']), 250, '');
                }
                if ($l['shipping']) {
                    $row['shipping_class_id'] = $l['classes'][(int) ($p['shipping_class_id'] ?? 0)] ?? null;
                }
                $rows[$id] = $row;
                $sources[$id] = $sourcePath;
                $children[$id] = ['product' => $product, 'cats' => $cats, 'json' => $p];
                if ($product->type === 'variable') {
                    $variable[$id] = $p;
                }
                foreach (['upsell' => '_upsell_ids', 'cross_sell' => '_crosssell_ids', 'grouped' => '_children'] as $type => $key) {
                    foreach ((array) $product->meta[$key] as $related) {
                        $this->checkpoint['related'][] = [$id, (int) $related, $type];
                    }
                }
            } catch (Throwable $e) {
                $this->issue('error', $entity, $id ?: null, $e->getMessage());
            }
        }

        $rows = $this->withoutExisting($entity, 'products', array_values($rows));
        // a slug another shop's product already uses gets a suffix (and the old URL a redirect)
        [$own] = $this->u->plan('products', $rows);
        $foreign = $this->foreign('products', 'slug', array_column($rows, 'slug'));
        foreach ($rows as $i => $row) {
            if (! isset($own[$row['wp_id']]) && isset($foreign[$row['slug']])) {
                $rows[$i]['slug'] = $this->unique('products', 'slug', $row['slug']);
                $this->issue('warning', $entity, $row['wp_id'], "slug '{$row['slug']}' already belongs to another shop's product – imported as '{$rows[$i]['slug']}'");
            }
        }
        // everything that needs the shop (images, variations) before the page's write transaction
        $resolved = [];
        $variations = [];
        foreach ($rows as $row) {
            $id = $row['wp_id'];
            $resolved[$id] = $this->productChildren($id, $children[$id], $l);
            if (isset($variable[$id])) {
                $variations[$id] = $this->fetchVariations($id, $variable[$id], $l);
            }
        }

        $this->transaction(function () use ($entity, $rows, $resolved, $variations, $sources, $l) {
            if (! $this->dryRun) {
                $this->u->adopt('products', $rows, 'slug');
            }
            $map = $this->saveRows($entity, 'products', $rows, 'wp_id', ['created_at']);
            if (! $this->dryRun) {
                $resolved = array_intersect_key($resolved, $map);
                $counts = ProductChildren::write($resolved, $map, $this->now(), $this->u);
                $this->bump($entity, 'images', $counts['images']);
            }
            foreach ($rows as $row) {
                $id = $row['wp_id'];
                $path = ($row['primary_category_id'] && isset($l['catPath'][$row['primary_category_id']]) ? $l['catPath'][$row['primary_category_id']].'/' : 'product/').$row['slug'];
                if (isset($map[$id]) && ($sources[$id] ?? null) !== null && $sources[$id] !== $path && $sources[$id] !== 'product/'.$row['slug']) {
                    $this->redirect($sources[$id], $path);
                }
                if (isset($variations[$id])) {
                    $this->saveVariations($map[$id] ?? null, $variations[$id]);
                }
            }
        });
    }

    /** The category in the product's permalink, else the deepest of its categories. */
    protected function primaryCategory(?string $sourcePath, array $cats, array $l): ?int
    {
        if (! $cats) {
            return null;
        }
        if ($sourcePath !== null && str_contains($sourcePath, '/')) {
            $catPath = Str::beforeLast($sourcePath, '/');
            while ($catPath !== '') {
                $candidate = $l['pathToCat'][$catPath] ?? null;
                if ($candidate && in_array((int) $candidate, $cats, true)) {
                    return (int) $candidate;
                }
                if (! str_contains($catPath, '/')) {
                    break;
                }
                $catPath = Str::after($catPath, '/'); // a product base: shop/clothing/shirts -> clothing/shirts
            }
        }
        $best = null;
        foreach ($cats as $id) {
            $depth = substr_count((string) ($l['catPath'][$id] ?? ''), '/');
            if ($best === null || $depth > $best[0] || ($depth === $best[0] && $id < $best[1])) {
                $best = [$depth, $id];
            }
        }

        return $best[1] ?? null;
    }

    /** Resolve one product's categories, images, attributes, values and tags to local ids. */
    protected function productChildren(int $id, array $c, array &$l): array
    {
        $p = $c['json'];
        $images = null;
        if ($this->images()) {
            $images = [];
            foreach (array_values(array_filter((array) ($p['images'] ?? []), 'is_array')) as $image) {
                if ($path = $this->image('products', $id, $image)) {
                    $images[] = ['path' => $path, 'alt' => Formatter::decode((string) ($image['alt'] ?? '')) ?: null];
                }
            }
        }
        $attributes = $values = [];
        $local = [];
        foreach ($c['product']->attributes as $key => $a) {
            if (! empty($a['is_taxonomy'])) {
                $slug = substr((string) $key, 3);
                $attributeId = $this->ensureAttribute($slug, (string) (($a['label'] ?? '') ?: Str::headline($slug)), $l);
                foreach ($a['options'] as $option) {
                    if ($valueId = $this->ensureValue($attributeId, (string) $option, $l)) {
                        $values[] = $valueId;
                    }
                }
            } else {
                $local[] = ['name' => (string) $a['name'], 'values' => $a['options']];
                $attributeId = null;
            }
            if ($attributeId) {
                $attributes[] = ['attribute_id' => $attributeId, 'position' => (int) $a['position'], 'is_visible' => (bool) $a['is_visible'], 'is_variation' => (bool) $a['is_variation']];
            }
        }
        if ($local && ! $this->dryRun) {
            $localValues = ProductChildren::localAttributes($local, $l['attributes'], $this->now());
            foreach ($c['product']->attributes as $a) {
                if (empty($a['is_taxonomy']) && ($attributeId = $l['attributes'][Str::slug(Formatter::decode((string) $a['name']))] ?? null)) {
                    $attributes[] = ['attribute_id' => $attributeId, 'position' => (int) $a['position'], 'is_visible' => (bool) $a['is_visible'], 'is_variation' => (bool) $a['is_variation']];
                    foreach ($a['options'] as $option) {
                        if ($valueId = $localValues[$attributeId.'|'.Str::slug((string) $option)] ?? null) {
                            $values[] = $valueId;
                        }
                    }
                }
            }
        }
        // tags: no tag table – a non-filterable "tags" attribute, like the database importer
        $tags = array_values(array_filter((array) ($p['tags'] ?? []), 'is_array'));
        if ($tags) {
            $tagAttribute = $this->ensureAttribute('tags', 'Tags', $l, 90);
            foreach ($tags as $tag) {
                if ($valueId = $this->ensureValue($tagAttribute, Formatter::decode((string) ($tag['name'] ?? '')), $l, urldecode((string) ($tag['slug'] ?? '')), (int) ($tag['id'] ?? 0))) {
                    $values[] = $valueId;
                }
            }
            if ($tagAttribute) {
                $attributes[] = ['attribute_id' => $tagAttribute, 'position' => 90, 'is_visible' => true, 'is_variation' => false];
            }
        }
        $unique = [];
        foreach ($attributes as $a) {
            $unique[$a['attribute_id']] ??= $a;
        }

        return ['categories' => $c['cats'], 'images' => $images, 'attributes' => array_values($unique), 'values' => array_values(array_unique($values)), 'specs' => null];
    }

    /** attributes.id of a slug, created when the attributes step did not run. */
    protected function ensureAttribute(string $slug, string $name, array &$l, int $sortOrder = 50): ?int
    {
        if (isset($l['attributes'][$slug]) || $this->dryRun) {
            return $l['attributes'][$slug] ?? null;
        }

        return $l['attributes'][$slug] = (int) DB::table('attributes')->insertGetId(['slug' => $slug, 'name' => Formatter::decode($name) ?: Str::headline($slug),
            'type' => 'select', 'is_filterable' => false, 'sort_order' => $sortOrder, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
    }

    /** attribute_values.id of a term name (or slug), created when it does not exist yet. */
    protected function ensureValue(?int $attributeId, string $name, array &$l, ?string $slug = null, int $remoteId = 0): ?int
    {
        $name = Formatter::decode($name);
        if (! $attributeId || $name === '') {
            return null;
        }
        $slug = $slug ?: Str::slug($name);
        $found = $l['valueByName'][$attributeId.'|'.mb_strtolower($name)] ?? $l['valueBySlug'][$attributeId.'|'.$slug] ?? null;
        if ($found || $this->dryRun) {
            return $found;
        }
        $id = (int) DB::table('attribute_values')->insertGetId(['attribute_id' => $attributeId, 'value' => $name, 'slug' => $slug, 'sort_order' => 0,
            'wp_id' => $remoteId ?: null, 'created_at' => $this->now(), 'updated_at' => $this->now()] + ($remoteId ? $this->sourceColumn('attribute_values') : []));
        $l['valueByName'][$attributeId.'|'.mb_strtolower($name)] = $l['valueBySlug'][$attributeId.'|'.$slug] = $id;

        return $id;
    }

    /** A variable product's variations (read from the shop, images downloaded), as rows without the product id. */
    protected function fetchVariations(int $remoteId, array $p, array $l): array
    {
        $entity = 'products';
        $termSlug = fn (string $attribute, string $option) => $l['slugByName'][$attribute.'|'.mb_strtolower($option)] ?? Str::slug($option);
        $variations = [];
        try {
            if ($this->store()) {
                foreach (array_values($p['store_variations'] ?? []) as $i => $v) {
                    $values = [];
                    foreach ((array) ($v['attributes'] ?? []) as $a) {
                        $values[(string) ($a['name'] ?? '')] = (string) ($a['value'] ?? '');
                    }
                    $json = $this->client->get('products/'.(int) $v['id'], [], 'wc/store/v1')->json;
                    if (is_array($json)) {
                        $variations[] = StoreApi::variation($json, $values, $p['attributes'] ?? [], $i);
                    }
                }
            } else {
                foreach ($this->client->pages('products/'.$remoteId.'/variations', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc']) as [$page, $items]) {
                    array_push($variations, ...$items);
                }
            }
        } catch (WooApiException $e) {
            $this->issue('error', $entity, $remoteId, 'variations not read: '.$e->getMessage());

            return [];
        }
        $rows = [];
        foreach ($variations as $v) {
            $mapped = ApiMap::variation($v, $termSlug, $this->attributeSlugs ?? []);
            $image = $mapped['image'] ? $this->image($entity, $remoteId.'/'.$mapped['wp_id'], $mapped['image']) : null;
            $row = ProductRows::variationRow(['wp_id' => $mapped['wp_id'], 'product_id' => 0, 'status' => $mapped['status'],
                'menu_order' => $mapped['menu_order'], 'date_gmt' => $mapped['date_gmt'], 'modified_gmt' => $mapped['modified_gmt'],
                'meta' => $mapped['meta'], 'image' => $image], $this->now());
            if ($l['shipping']) {
                $row['shipping_class_id'] = $l['classes'][$mapped['shipping_class_id']] ?? null;
            }
            if (! $this->images()) {
                unset($row['image']); // images not downloaded: keep the variation's current image
            }
            $rows[] = $row;
        }
        $this->bump($entity, 'variations', count($rows));

        return $rows;
    }

    /** Save a product's variations; variations gone from the shop are switched off (never deleted – orders point at them). */
    protected function saveVariations(?int $productId, array $rows): void
    {
        if ($this->dryRun || ! $productId || ! $rows) {
            return;
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['product_id'] = $productId;
        }
        $rows = ProductRows::normaliseVariationOrder($rows);
        $map = $this->u->save('product_variations', $rows, 'wp_id', ['created_at']);
        $this->u->owned('product_variations')->where('product_id', $productId)->whereNotIn('wp_id', array_keys($map) ?: [0])->update(['is_active' => false]);
        Product::query()->find($productId)?->refreshVariablePrice();
    }

    /**
     * Upsert in one go; when the batch hits a database error (e.g. a unique key), save row by row so one bad item
     * becomes an error with its remote id instead of failing the page.
     */
    protected function saveRows(string $entity, string $table, array $rows, string $key = 'wp_id', array $keepOnUpdate = []): array
    {
        if (! $rows) {
            return [];
        }
        try {
            return $this->dryRun ? $this->upsert($entity, $table, $rows, $key, $keepOnUpdate)
                : DB::transaction(fn () => $this->upsert($entity, $table, $rows, $key, $keepOnUpdate));
        } catch (QueryException $e) {
            if (count($rows) === 1) {
                $this->issue('error', $entity, $rows[0][$key] ?? null, 'not saved: '.Str::limit($e->getPrevious()?->getMessage() ?? $e->getMessage(), 300));

                return [];
            }
        }
        $map = [];
        foreach ($rows as $row) {
            try {
                $map += DB::transaction(fn () => $this->upsert($entity, $table, [$row], $key, $keepOnUpdate));
            } catch (QueryException $e) {
                $this->issue('error', $entity, $row[$key] ?? null, 'not saved: '.Str::limit($e->getPrevious()?->getMessage() ?? $e->getMessage(), 300));
            }
        }

        return $map;
    }
}
