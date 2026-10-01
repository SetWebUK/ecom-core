<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Pine\Commerce\Http\Requests\Admin\Catalogue\ProductRequest;
use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\AttributeValue;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\TaxClass;
use Pine\Commerce\Services\Admin\Catalogue\ProductSaver;
use Pine\Commerce\Services\Admin\Catalogue\UrlRedirects;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * Imports ONE row of a product CSV – a product (simple / variable) or a variant (type "variation" with a parent).
 *
 * Every row is first resolved without writing anything: matched to an existing product/variant (by SKU or id, per the
 * session options), every cell parsed and checked, categories / attributes / images looked up. The dry run stops
 * there; the real run then writes the row in its own transaction. A row with any invalid cell is not written at all.
 *
 * Result: ['line', 'kind' => product|variation, 'type', 'sku', 'name', 'action' => create|update|skip|error,
 *          'id' (after a write), 'messages' => [...]]
 */
class RowImporter
{
    /** product ids whose parent price/stock must be refreshed (variants written) */
    public array $variableParents = [];

    /** product ids touched in this batch */
    public array $touched = [];

    /** product ids that became available (back-in-stock alerts) */
    public array $restocked = [];

    protected array $categoryCache = [];

    protected array $attributeCache = [];

    protected array $messages = [];

    protected array $mapping;

    public function __construct(protected ImportSession $session, protected bool $dryRun, protected ImageImporter $images)
    {
        $this->mapping = array_filter((array) $session->state['mapping'], fn ($target) => is_string($target) && $target !== '');
    }

    public function handle(int $line, array $cells): array
    {
        $this->messages = [];
        $this->refs = [];
        $fields = $this->fields($cells);
        $result = ['line' => $line, 'kind' => 'product', 'type' => null, 'sku' => $fields['sku'] ?? '', 'name' => $fields['name'] ?? '', 'action' => 'error', 'id' => null, 'messages' => []];

        try {
            $type = ($fields['type'] ?? '') !== '' ? Cells::type($fields['type']) : null;
            if ($type === null && (($fields['parent_sku'] ?? '') !== '' || ($fields['variation_options'] ?? '') !== '')) {
                $type = 'variation';
            }
            $result['type'] = $type;
            $result = $type === 'variation' ? $this->variation($fields, $result) : $this->product($fields, $type, $result);
        } catch (InvalidArgumentException $e) {
            $result['action'] = 'error';
            $this->messages[] = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $result['action'] = 'error';
            $this->messages[] = 'Could not be saved: '.Str::limit($e->getMessage(), 200);
        }
        $result['messages'] = array_values(array_unique($this->messages));
        if ($this->refs) {
            $result['refs'] = $this->refs;
            $result['ref'] = $this->session->state['progress']['refs'][$this->refs[0]] ?? null;
        }

        return $result;
    }

    // ---------------------------------------------------------------------------------------------- products

    protected function product(array $fields, ?string $type, array $result): array
    {
        $existing = $this->findProduct($fields);
        $pending = is_string($existing) ? $existing : null;
        $product = $existing instanceof Product ? $existing : null;

        if (($product || $pending) && ! $this->session->option('update_existing')) {
            $result['action'] = 'skip';
            $result['id'] = $product?->id;
            $result['name'] = $result['name'] ?: (string) $product?->name;
            $this->messages[] = 'Already in the shop – not updated (updating existing products is switched off).';
            $this->remember($fields, $product?->id ?? $pending);

            return $result;
        }
        if (! $product && ! $pending && ($fields['name'] ?? '') === '') {
            throw new InvalidArgumentException('A new product needs a name');
        }
        if ($product && $type && $product->type !== $type) {
            throw new InvalidArgumentException('This product is '.($product->type === 'variable' ? 'a product with variants' : 'a simple product')
                .' – changing the type isn’t possible in an import (change it in the product editor)');
        }
        $type ??= $product->type ?? 'simple';
        $creating = ! $product && ! $pending;
        $this->assertSkuFree($fields['sku'] ?? '', $product?->id, null);
        $result['type'] = $type;
        $result['action'] = $creating ? 'create' : 'update';
        $result['name'] = ($fields['name'] ?? '') !== '' ? $fields['name'] : (string) $product?->name;
        if ($pending) {
            $this->messages[] = 'Created earlier in this file (line '.substr($pending, 4).').';
        }

        $data = $this->productColumns($fields, $type, $product, $creating);
        $relations = $this->productRelations($fields, $creating || $pending !== null);

        if ($this->dryRun || $pending) {
            $this->remember($fields, $product?->id ?? $pending ?? 'new:'.$result['line']);
            $result['id'] = $product?->id;

            return $result;
        }

        $product ??= new Product(['type' => $type]);
        $before = $product->exists ? ProductSaver::availability($product) : null;
        $oldPath = $product->exists && $product->status === 'published' ? trim((string) parse_url($product->url, PHP_URL_PATH), '/') : null;
        $oldSlug = $product->slug;

        DB::transaction(function () use ($product, $data, $relations) {
            $product->forceFill($data); // $data holds whitelisted columns only (productColumns())
            if ($product->status === 'published' && ! $product->published_at) {
                $product->published_at = now();
            }
            $product->save();
            $this->applyRelations($product, $relations);
        });

        $product->refresh();
        $this->touched[] = $product->id;
        if ($oldPath && $oldSlug !== $product->slug && Features::enabled('redirects', false)) {
            $product->load(['primaryCategory', 'categories']);
            $newPath = trim((string) parse_url($product->url, PHP_URL_PATH), '/');
            if ($newPath !== $oldPath && UrlRedirects::add($oldPath, '/'.$newPath.'/') > 0) {
                $this->messages[] = 'The old web address now redirects to the new one.';
            }
        }
        if ($before !== null && ProductSaver::becameAvailable($before, ProductSaver::availability($product))) {
            $this->restocked[] = $product->id;
        }
        $this->remember($fields, $product->id);
        $result['id'] = $product->id;

        return $result;
    }

    /** SKUs stay unique across products and variants (the row's own product/variant excepted). */
    protected function assertSkuFree(string $sku, ?int $productId, ?int $variationId): void
    {
        if ($sku === '') {
            return;
        }
        $product = Product::query()->where('sku', $sku)->when($productId, fn ($q) => $q->whereKeyNot($productId))->value('name');
        $variant = ProductVariation::query()->where('sku', $sku)->when($variationId, fn ($q) => $q->whereKeyNot($variationId))->exists();
        if ($product !== null || $variant) {
            throw new InvalidArgumentException('SKU “'.$sku.'” is already used by '.($product !== null ? '“'.$product.'”' : 'a variant'));
        }
    }

    /** Existing product for the row: a Product, "new:{line}" (created earlier in this file, dry run) or null. */
    protected function findProduct(array $fields): Product|string|null
    {
        $refs = $this->session->state['progress']['refs'] ?? [];
        $sku = $fields['sku'] ?? '';
        $id = $fields['id'] ?? '';
        if ($this->session->option('match_by') === 'id') {
            if ($id !== '' && ctype_digit($id) && ($product = Product::query()->find((int) $id))) {
                return $product;
            }
        } elseif ($sku !== '') {
            if ($product = Product::query()->where('sku', $sku)->orderBy('id')->first()) {
                return $product;
            }
            if (ProductVariation::query()->where('sku', $sku)->exists()) {
                throw new InvalidArgumentException('SKU “'.$sku.'” belongs to a variant – give the row type “variation” and its parent');
            }
        } elseif ($id !== '' && ctype_digit($id)) {
            // no SKU: the ID column – this shop's ID (a product without a SKU of its own), or in a WooCommerce file the
            // WooCommerce ID the product was imported from
            $product = ($this->session->state['woocommerce'] ?? false)
                ? Product::query()->where('wp_id', (int) $id)->orderBy('id')->first()
                : Product::query()->whereKey((int) $id)->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', ''))->first();
            if ($product) {
                return $product;
            }
        }
        foreach (['sku:'.$sku => $sku, 'id:'.$id => $id] as $key => $value) {
            if ($value !== '' && isset($refs[$key]) && is_string($refs[$key])) {
                return $refs[$key];
            }
        }
        if ($sku !== '' && isset($refs['sku:'.$sku]) && is_int($refs['sku:'.$sku])) {
            return Product::query()->find($refs['sku:'.$sku]);
        }

        return null;
    }

    /** Remember what this row's id / SKU now points at, for variants further down the file. */
    protected function remember(array $fields, int|string|null $target): void
    {
        if ($target === null) {
            return;
        }
        foreach (['id' => 'id:', 'sku' => 'sku:'] as $field => $prefix) {
            if (($fields[$field] ?? '') !== '') {
                $this->session->state['progress']['refs'][$prefix.$fields[$field]] = $target;
                $this->refs[] = $prefix.$fields[$field];
            }
        }
    }

    /** reference keys remembered for the current row (kept in its result, so a crashed batch can be recovered) */
    protected array $refs = [];

    /** Product table columns from the row. */
    protected function productColumns(array $fields, string $type, ?Product $product, bool $creating): array
    {
        $data = [];
        $errors = [];
        $set = function (string $field, string $column, callable $parse, bool $clearable = true) use ($fields, $creating, &$data, &$errors) {
            if (! array_key_exists($field, $fields)) {
                return;
            }
            $value = $fields[$field];
            if ($value === '') {
                if ($clearable && ! $creating && $this->overwrite()) {
                    $data[$column] = null;
                }

                return;
            }
            try {
                $data[$column] = $parse($value);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        };
        $text = fn (int $limit) => fn (string $v) => Str::limit($v, $limit, '');

        $set('name', 'name', $text(250), false);
        $set('sku', 'sku', $text(100), false);
        $set('status', 'status', [Cells::class, 'status'], false);
        $set('short_description', 'short_description', fn ($v) => $v);
        $set('description', 'description', fn ($v) => $v);
        $set('subtitle', 'subtitle', $text(250));
        $set('meta_title', 'meta_title', $text(250));
        $set('meta_description', 'meta_description', $text(1000));
        $set('featured', 'is_featured', [Cells::class, 'bool'], false);
        $set('cost_price', 'cost_price', fn ($v) => Cells::money($v, 'Cost price'));
        $set('tax_status', 'tax_status', fn ($v) => in_array($s = mb_strtolower(trim($v)), ['taxable', 'shipping', 'none'], true) ? $s
            : throw new InvalidArgumentException('Tax status “'.Cells::short($v).'” should be taxable, shipping or none'), false);
        $set('tax_class', 'tax_class', fn ($v) => in_array($s = Str::slug($v), ['', 'standard', 'parent'], true) ? null : Str::limit($s, 100, ''));
        $set('weight', 'weight', fn ($v) => Cells::decimal($v, 'Weight'));
        foreach (['length' => 'Length', 'width' => 'Width', 'height' => 'Height'] as $field => $label) {
            $set($field, $field, fn ($v) => Cells::decimal($v, $label, 2));
        }
        $set('gtin', 'gtin', $text(100));
        $set('mpn', 'mpn', $text(100));
        if (Columns::shippingClassMode() === 'string') {
            $set('shipping_class', 'shipping_class', fn ($v) => Str::limit(Str::slug($v), 100, ''));
        } elseif (Columns::shippingClassMode() === 'id') {
            $set('shipping_class', 'shipping_class_id', fn ($v) => $this->shippingClass($v));
        }
        if (Columns::available('condition')) {
            $set('condition', 'condition', $text(100));
        }
        if (Columns::available('brand')) {
            $set('brand', 'brand', $text(190));
        }

        if ($type !== 'variable') { // a product with variants takes its price and stock from them
            $set('regular_price', 'regular_price', fn ($v) => Cells::money($v, 'Regular price'));
            $set('sale_price', 'sale_price', fn ($v) => in_array(mb_strtolower($v), ['-', 'none'], true) ? null : Cells::money($v, 'Sale price'));
            $set('sale_starts_at', 'sale_starts_at', fn ($v) => Cells::date($v, 'Sale start'));
            $set('sale_ends_at', 'sale_ends_at', fn ($v) => Cells::date($v, 'Sale end', true));
            $set('manage_stock', 'manage_stock', [Cells::class, 'bool'], false);
            $set('stock_quantity', 'stock_quantity', fn ($v) => Cells::int($v, 'Stock quantity'));
            $set('stock_status', 'stock_status', [Cells::class, 'stockStatus'], false);
            $set('backorders', 'backorders', [Cells::class, 'backorders'], false);
            $set('low_stock_threshold', 'low_stock_threshold', fn ($v) => Cells::int($v, 'Low stock threshold', false));
            if (array_key_exists('stock_quantity', $data) && ! array_key_exists('manage_stock', $data)) {
                $data['manage_stock'] = $data['stock_quantity'] !== null; // a quantity switches on stock tracking
            }
            if (($data['manage_stock'] ?? null) === false) {
                $data['stock_quantity'] = null;
            }
            if (array_key_exists('sale_price', $data) && $data['sale_price'] === null) {
                $data['sale_starts_at'] = null; // no sale price, no sale dates
                $data['sale_ends_at'] = null;
            }
            $regular = $data['regular_price'] ?? $product?->regular_price;
            if (($data['sale_price'] ?? null) !== null && $regular !== null && (float) $data['sale_price'] >= (float) $regular) {
                $errors[] = 'Sale price '.money($data['sale_price']).' must be lower than the regular price '.money($regular);
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        // URL handle: given (made unique) or, for a new product, from the name
        if (($fields['slug'] ?? '') !== '' || $creating) {
            $base = Str::limit(Str::slug(($fields['slug'] ?? '') !== '' ? $fields['slug'] : ($data['name'] ?? 'product')), 180, '') ?: 'product';
            if (! $product || $product->slug !== $base) {
                $data['slug'] = $this->uniqueSlug($base, $product?->id);
            }
        }
        if ($creating) {
            $data['type'] = $type;
            $data['status'] ??= 'published';
            $data['stock_status'] ??= 'instock';
            if ($type !== 'variable' && ! isset($data['manage_stock'])) {
                $data['manage_stock'] = false;
            }
        }

        return $data;
    }

    protected function uniqueSlug(string $base, ?int $ignoreId): string
    {
        $pending = $this->session->state['progress']['slugs'] ?? [];
        $slug = $base;
        for ($i = 2; Product::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()
            || ($this->dryRun && isset($pending[$slug])); $i++) {
            $slug = $base.'-'.$i;
        }
        if ($this->dryRun) {
            $this->session->state['progress']['slugs'][$slug] = true;
        }

        return $slug;
    }

    /**
     * Categories, images, attributes, specs and the Condition/Brand attributes from the row, resolved (no writes).
     * Keys are only present when the row sets them; null = clear.
     */
    protected function productRelations(array $fields, bool $isNew): array
    {
        $relations = [];
        $woo = (bool) ($this->session->state['woocommerce'] ?? false);
        $provided = fn (string $key) => array_key_exists($key, $fields) && ($fields[$key] !== '' || (! $isNew && $this->overwrite()));

        if ($provided('categories') || $provided('primary_category')) {
            $ids = [];
            foreach (Cells::split($fields['categories'] ?? '', $woo) as $path) {
                if ($id = $this->category($path)) {
                    $ids[] = $id;
                }
            }
            $primary = ($fields['primary_category'] ?? '') !== '' ? $this->category($fields['primary_category']) : null;
            if ($primary && ! in_array($primary, $ids, true)) {
                array_unshift($ids, $primary);
            }
            $ids = array_values(array_unique($ids, SORT_REGULAR));
            $unresolved = ($fields['categories'] ?? '') !== '' && ! $ids; // listed categories that don't exist: keep the current ones
            if (array_key_exists('categories', $fields) && ! $unresolved && ($fields['categories'] !== '' || $this->overwrite())) {
                $relations['categories'] = $ids;
            }
            $relations['primary_category'] = $primary ?? ($ids[0] ?? null);
            if (! array_key_exists('categories', $relations) && $primary === null) {
                unset($relations['primary_category']);
            }
        }

        if ($provided('images')) {
            $paths = [];
            foreach (Cells::split($fields['images'], true) as $value) {
                [$path, $warning] = $this->images->resolve($value, $this->dryRun);
                if ($warning) {
                    $this->messages[] = $warning;
                }
                if ($path) {
                    $paths[] = $path;
                }
            }
            if (array_filter($paths, fn ($p) => str_starts_with($p, '(download) '))) {
                $this->messages[] = count(array_filter($paths, fn ($p) => str_starts_with($p, '(download) '))).' image(s) will be downloaded.';
            }
            $relations['images'] = array_values(array_unique($paths));
        }

        $attributes = $this->attributeRows($fields);
        if ($attributes !== null && ($attributes !== [] || (! $isNew && $this->overwrite()))) {
            $relations['attributes'] = $attributes;
        }

        if ($provided('specs')) {
            $relations['specs'] = array_map(fn ($pair) => ['label' => Str::limit($pair[0], 250, ''), 'value' => (string) ($pair[1][0] ?? '')],
                array_values(array_filter(Cells::pairs($fields['specs'], false), fn ($pair) => ($pair[1][0] ?? '') !== '')));
        }

        // Condition / Brand columns also pick (or add) the value of their attribute, like the product editor's side panel
        foreach (ProductRequest::asideAttributes() as $role => $slug) {
            if (($fields[$role] ?? '') !== '' && ($attributeId = Attribute::query()->where('slug', $slug)->value('id'))) {
                $text = Str::limit(trim($fields[$role]), 190, '');
                $valueId = AttributeValue::query()->where('attribute_id', $attributeId)->whereRaw('LOWER(value) = ?', [mb_strtolower($text)])->value('id');
                $relations['aside'][$role] = [(int) $attributeId, $valueId ? (int) $valueId : 'new:'.$text];
            }
        }

        return $relations;
    }

    /**
     * Attribute rows of the product: [['attribute' => id|"new", 'values' => [id|"new"...], 'visible' => ?bool], ...],
     * [] = the cell is empty, null = no attribute column in this file.
     */
    protected function attributeRows(array $fields): ?array
    {
        $groups = [];
        $mapped = false;
        if (array_key_exists('attributes', $fields)) {
            $mapped = true;
            foreach (Cells::pairs($fields['attributes']) as [$name, $values]) {
                $groups[] = [$name, $values, null];
            }
        }
        foreach ($fields['_woo_attributes'] ?? [] as $group) {
            $mapped = true;
            if (($group['name'] ?? '') !== '' && ($group['values'] ?? '') !== '') {
                $visible = ($group['visible'] ?? '') !== '' ? Cells::bool($group['visible']) : null;
                $groups[] = [$group['name'], Cells::split($group['values'], true), $visible];
            }
        }
        if (! $mapped) {
            return null;
        }

        $rows = [];
        foreach ($groups as [$name, $values, $visible]) {
            $attribute = $this->attribute($name);
            if ($attribute === null) {
                continue;
            }
            $ids = [];
            foreach ($values as $value) {
                if ($id = $this->attributeValue($attribute, $value)) {
                    $ids[] = $id;
                }
            }
            if ($ids) {
                $rows[] = ['attribute' => $attribute, 'values' => $ids, 'visible' => $visible];
            }
        }

        return $rows;
    }

    protected function applyRelations(Product $product, array $relations): void
    {
        if (array_key_exists('categories', $relations)) {
            $product->categories()->sync($relations['categories']);
        }
        if (array_key_exists('primary_category', $relations)) {
            $primary = $relations['primary_category'];
            $categories = array_key_exists('categories', $relations) ? $relations['categories'] : $product->categories()->pluck('categories.id')->all();
            if ($primary && ! in_array($primary, $categories)) {
                $product->categories()->syncWithoutDetaching([$primary]);
            }
            $product->forceFill(['primary_category_id' => $primary])->saveQuietly();
        }
        if (array_key_exists('images', $relations)) {
            $alts = $product->images()->pluck('alt', 'path');
            ProductSaver::syncImages($product, array_map(fn ($path) => ['path' => $path, 'alt' => $alts[$path] ?? null], $relations['images']));
        }
        if (array_key_exists('specs', $relations)) {
            $current = $product->specs()->get()->keyBy(fn ($s) => mb_strtolower($s->label));
            ProductSaver::syncSpecs($product, array_map(fn ($spec) => $spec + [
                'key' => $current->get(mb_strtolower($spec['label']))?->key,
                'description' => $current->get(mb_strtolower($spec['label']))?->description,
            ], $relations['specs']));
        }
        if (array_key_exists('attributes', $relations) || isset($relations['aside'])) {
            $this->syncAttributes($product, $relations['attributes'] ?? null, $relations['aside'] ?? []);
        }
    }

    /** Listed attributes replace the product's other (non-variant, non-Condition/Brand) attributes. */
    protected function syncAttributes(Product $product, ?array $rows, array $aside): void
    {
        $existing = $product->productAttributes()->get()->keyBy('attribute_id');
        $asideIds = Attribute::query()->whereIn('slug', array_values(ProductRequest::asideAttributes()) ?: [''])->pluck('id')->all();
        $pivot = $product->attributeValues()->get(['attribute_values.id', 'attribute_values.attribute_id']);
        $keepValues = [];
        $listed = [];

        if ($rows !== null) {
            $ids = array_map(fn ($row) => $this->materialiseAttribute($row['attribute']), $rows);
            // same attributes in their stored order: keep the stored positions (imported products share positions
            // with Condition/Brand), so importing an unchanged file never reorders the specification table
            $stored = $existing->toBase()->only($ids)->sortBy([['position', 'asc'], ['id', 'asc']])->keys()->map(fn ($id) => (int) $id)->all();
            $keepPositions = $stored === $ids;
            $position = 0;
            foreach ($rows as $i => $row) {
                $attributeId = $ids[$i];
                $listed[] = $attributeId;
                $current = $existing->get($attributeId);
                $rowPosition = $position++;
                $product->productAttributes()->updateOrCreate(['attribute_id' => $attributeId], [
                    'position' => $keepPositions ? (int) $current->position : $rowPosition,
                    'is_visible' => $row['visible'] ?? $current?->is_visible ?? true,
                    'is_variation' => $current?->is_variation ?? false,
                ]);
                foreach ($row['values'] as $value) {
                    $keepValues[] = $this->materialiseValue($attributeId, $value);
                }
            }
            // attributes not listed: keep variant attributes and Condition/Brand, drop the rest
            $drop = $existing->filter(fn ($pa) => ! in_array($pa->attribute_id, $listed) && ! $pa->is_variation && ! in_array($pa->attribute_id, $asideIds))->keys()->all();
            if ($drop) {
                $product->productAttributes()->whereIn('attribute_id', $drop)->delete();
            }
            foreach ($pivot as $value) {
                if (! in_array($value->attribute_id, $listed) && ! in_array($value->attribute_id, $drop)) {
                    $keepValues[] = $value->id;
                }
            }
        } else {
            $keepValues = $pivot->pluck('id')->all();
        }

        foreach ($aside as [$attributeId, $valueRef]) {
            $valueId = $this->materialiseValue($attributeId, $valueRef);
            // one value per Condition/Brand attribute: drop its other values
            $others = AttributeValue::query()->where('attribute_id', $attributeId)->whereIn('id', $keepValues ?: [0])->pluck('id')->map(fn ($id) => (int) $id)->all();
            $keepValues = array_values(array_diff(array_map('intval', $keepValues), $others));
            $keepValues[] = $valueId;
            $current = $existing->get($attributeId);
            $product->productAttributes()->updateOrCreate(['attribute_id' => $attributeId], [
                'position' => $current?->position ?? (int) $product->productAttributes()->max('position') + 1,
                'is_visible' => $current?->is_visible ?? true,
                'is_variation' => $current?->is_variation ?? false,
            ]);
        }

        $product->attributeValues()->sync(array_values(array_unique(array_map('intval', $keepValues))));
    }

    // ---------------------------------------------------------------------------------------------- variants

    protected function variation(array $fields, array $result): array
    {
        $result['kind'] = 'variation';
        $result['type'] = 'variation';
        $parent = $this->findParent($fields['parent_sku'] ?? '');
        $parentModel = $parent instanceof Product ? $parent : null;
        if ($parentModel && $parentModel->type !== 'variable') {
            throw new InvalidArgumentException('The parent “'.$parentModel->name.'” isn’t a product with variants (type variable)');
        }

        $options = $this->variationOptions($fields); // attribute slug|"new:Name" => [attributeRef, valueRef, label]
        $label = implode(', ', array_map(fn ($o) => $o[2], $options));
        $result['name'] = trim(($parentModel?->name ?? 'New product').($label !== '' ? ' – '.$label : ''));

        $variation = null;
        $sku = $fields['sku'] ?? '';
        if ($this->session->option('match_by') === 'id' && ($fields['id'] ?? '') !== '' && ctype_digit($fields['id'])) {
            $variation = ProductVariation::query()->find((int) $fields['id']);
        } elseif ($sku !== '') {
            $variation = ProductVariation::query()->where('sku', $sku)->orderBy('id')->first();
            if (! $variation && Product::query()->where('sku', $sku)->exists()) {
                throw new InvalidArgumentException('SKU “'.$sku.'” belongs to a product, not a variant');
            }
        }
        if ($variation && $parentModel && $variation->product_id !== $parentModel->id) {
            throw new InvalidArgumentException('SKU “'.$sku.'” is a variant of another product (#'.$variation->product_id.')');
        }
        if (! $variation && ! $parentModel && ! $parent) {
            throw new InvalidArgumentException(($fields['parent_sku'] ?? '') === ''
                ? 'A variant needs its parent product (Parent column: the parent’s SKU or id:123)'
                : 'Parent “'.$fields['parent_sku'].'” not found – put the parent product’s row above its variants');
        }
        $parentModel ??= $variation?->product;
        if (! $variation && $parentModel && $options && ! $this->hasPendingOption($options)) {
            $wanted = $this->optionSlugs($options);
            $variation = $parentModel->variations()->get()->first(fn ($v) => $this->sameOptions((array) $v->options, $wanted));
        }

        if ($variation && ! $this->session->option('update_existing')) {
            $result['action'] = 'skip';
            $result['id'] = $variation->id;
            $this->messages[] = 'Already in the shop – not updated (updating existing products is switched off).';

            return $result;
        }
        if (! $variation && ! $options) {
            throw new InvalidArgumentException('A new variant needs its options (e.g. “Memory: 16GB | Colour: Silver”)');
        }
        $result['action'] = $variation ? 'update' : 'create';
        $this->assertSkuFree($sku, null, $variation?->id);
        $data = $this->variationColumns($fields, $variation);

        $image = null;
        if (($fields['images'] ?? '') !== '') {
            [$image, $warning] = $this->images->resolve(Cells::split($fields['images'], true)[0] ?? '', $this->dryRun);
            if ($warning) {
                $this->messages[] = $warning;
            }
            if ($image && str_starts_with($image, '(download) ')) {
                $this->messages[] = '1 image(s) will be downloaded.';
            }
        } elseif (array_key_exists('images', $fields) && $variation && $this->overwrite()) {
            $data['image'] = null;
        }

        if ($this->dryRun || ! $parentModel) {
            $result['id'] = $variation?->id;

            return $result;
        }

        $before = ProductSaver::availability($parentModel);
        DB::transaction(function () use (&$variation, $parentModel, $data, $options, $image) {
            $variation ??= new ProductVariation(['product_id' => $parentModel->id, 'is_active' => true,
                'sort_order' => (int) $parentModel->variations()->max('sort_order') + 1]);
            $variation->product_id = $parentModel->id;
            $slugs = [];
            foreach ($options as [$attributeRef, $valueRef]) {
                $attributeId = $this->materialiseAttribute($attributeRef);
                $valueId = $this->materialiseValue($attributeId, $valueRef);
                $slugs[Attribute::query()->whereKey($attributeId)->value('slug')] = AttributeValue::query()->whereKey($valueId)->value('slug');
                // the parent uses this attribute for its variants and offers the value
                $current = $parentModel->productAttributes()->where('attribute_id', $attributeId)->first();
                $parentModel->productAttributes()->updateOrCreate(['attribute_id' => $attributeId], [
                    'position' => $current?->position ?? (int) $parentModel->productAttributes()->max('position') + 1,
                    'is_visible' => $current?->is_visible ?? true,
                    'is_variation' => true,
                ]);
                $parentModel->attributeValues()->syncWithoutDetaching([$valueId]);
            }
            if ($slugs) {
                $variation->options = $slugs;
            }
            $variation->forceFill($data); // whitelisted columns only (variationColumns())
            if ($image) {
                $variation->image = $image;
            }
            $variation->options ??= [];
            $variation->saveQuietly();
        });

        $this->variableParents[$parentModel->id] = true;
        $this->touched[] = $parentModel->id;
        $this->restockCheck[$parentModel->id] ??= $before;
        $result['id'] = $variation->id;

        return $result;
    }

    /** availability of parents before their first variant of this batch was written */
    public array $restockCheck = [];

    protected function variationColumns(array $fields, ?ProductVariation $variation): array
    {
        $data = [];
        $errors = [];
        $creating = $variation === null;
        $set = function (string $field, string $column, callable $parse, bool $clearable = true) use ($fields, $creating, &$data, &$errors) {
            if (! array_key_exists($field, $fields)) {
                return;
            }
            if ($fields[$field] === '') {
                if ($clearable && ! $creating && $this->overwrite()) {
                    $data[$column] = null;
                }

                return;
            }
            try {
                $data[$column] = $parse($fields[$field]);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        };
        $set('sku', 'sku', fn ($v) => Str::limit($v, 100, ''), false);
        $set('regular_price', 'regular_price', fn ($v) => Cells::money($v, 'Regular price'));
        $set('sale_price', 'sale_price', fn ($v) => in_array(mb_strtolower($v), ['-', 'none'], true) ? null : Cells::money($v, 'Sale price'));
        $set('manage_stock', 'manage_stock', [Cells::class, 'bool'], false);
        $set('stock_quantity', 'stock_quantity', fn ($v) => Cells::int($v, 'Stock quantity'));
        $set('stock_status', 'stock_status', [Cells::class, 'stockStatus'], false);
        $set('weight', 'weight', fn ($v) => Cells::decimal($v, 'Weight'));
        $set('status', 'is_active', fn ($v) => Cells::status($v) === 'published', false);
        // the variant's own classes (null = same as the product): "parent" / "same as product" → null, "standard" is kept
        // (a variant can be standard-rated under a reduced-rate product)
        $set('tax_class', 'tax_class', fn ($v) => static::sameAsParent($v) ? null : Str::limit(TaxClass::normalise($v), 100, ''));
        if (Columns::shippingClassMode() === 'id') {
            $set('shipping_class', 'shipping_class_id', fn ($v) => static::sameAsParent($v) ? null : $this->shippingClass($v));
        }
        if (array_key_exists('stock_quantity', $data) && ! array_key_exists('manage_stock', $data)) {
            $data['manage_stock'] = $data['stock_quantity'] !== null;
        }
        if (($data['manage_stock'] ?? null) === false) {
            $data['stock_quantity'] = null;
        }
        $regular = $data['regular_price'] ?? $variation?->regular_price;
        if (($data['sale_price'] ?? null) !== null && $regular !== null && (float) $data['sale_price'] >= (float) $regular) {
            $errors[] = 'Sale price '.money($data['sale_price']).' must be lower than the regular price '.money($regular);
        }
        if ($errors) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
        if ($creating) {
            $data['manage_stock'] ??= false;
            $data['stock_status'] ??= 'instock';
        }

        return $data;
    }

    /** A variant cell meaning "same as the product" (WooCommerce writes "parent"). */
    protected static function sameAsParent(string $value): bool
    {
        return in_array(Str::slug($value), ['parent', 'same-as-parent', 'same-as-product', 'inherit'], true);
    }

    /** Parent reference "id:123" or a SKU: a Product, "new:{line}" (dry run) or null. */
    protected function findParent(string $reference): Product|string|null
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        $refs = $this->session->state['progress']['refs'] ?? [];
        if (preg_match('/^id:\s*(\d+)$/i', $reference, $m)) {
            $target = $refs['id:'.$m[1]] ?? null;
            if ($target === null) {
                if ($this->session->option('match_by') === 'id' || ! ($this->session->state['woocommerce'] ?? false)) {
                    $target = Product::query()->find((int) $m[1]);
                }
                $target ??= ($this->session->state['woocommerce'] ?? false) ? Product::query()->where('wp_id', (int) $m[1])->first() : null;
            }
        } else {
            $target = $refs['sku:'.$reference] ?? Product::query()->where('sku', $reference)->orderBy('id')->first();
        }

        return is_int($target) ? Product::query()->find($target) : $target;
    }

    /** @return list<array{0:int|string, 1:int|string, 2:string}> [attribute ref, value ref, "Name: value"] */
    protected function variationOptions(array $fields): array
    {
        $pairs = [];
        if (($fields['variation_options'] ?? '') !== '') {
            foreach (Cells::pairs($fields['variation_options'], false) as [$name, $values]) {
                $pairs[] = [$name, $values[0] ?? ''];
            }
        } else {
            foreach ($fields['_woo_attributes'] ?? [] as $group) {
                if (($group['name'] ?? '') !== '' && ($group['values'] ?? '') !== '') {
                    $pairs[] = [$group['name'], Cells::split($group['values'], true)[0] ?? ''];
                }
            }
        }
        $options = [];
        foreach ($pairs as [$name, $value]) {
            if ($value === '') {
                continue;
            }
            $attribute = $this->attribute($name);
            $valueRef = $attribute !== null ? $this->attributeValue($attribute, $value) : null;
            if ($attribute === null || $valueRef === null) {
                throw new InvalidArgumentException('Variant option “'.$name.': '.$value.'” doesn’t exist (switch on “create missing attributes” to add it)');
            }
            $options[] = [$attribute, $valueRef, $value];
        }

        return $options;
    }

    protected function hasPendingOption(array $options): bool
    {
        foreach ($options as [$attribute, $value]) {
            if (! is_int($attribute) || ! is_int($value)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,string> attribute slug => value slug */
    protected function optionSlugs(array $options): array
    {
        $slugs = [];
        foreach ($options as [$attributeId, $valueId]) {
            $slugs[(string) Attribute::query()->whereKey($attributeId)->value('slug')] = (string) AttributeValue::query()->whereKey($valueId)->value('slug');
        }

        return $slugs;
    }

    protected function sameOptions(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return array_map('strval', $a) === array_map('strval', $b);
    }

    // ---------------------------------------------------------------------------------------------- lookups

    /** Category id for "Parent > Child" (created when missing and allowed; "new:…" in a dry run), or null. */
    protected function category(string $path): int|string|null
    {
        $names = array_values(array_filter(array_map(fn ($n) => Cells::unescape($n), preg_split('/\s*>\s*/', trim($path)) ?: []), fn ($n) => $n !== ''));
        if (! $names) {
            return null;
        }
        $key = mb_strtolower(implode(' > ', $names));
        if (array_key_exists($key, $this->categoryCache)) {
            return $this->categoryCache[$key];
        }
        $parentId = null;
        $pendingFrom = null;
        foreach ($names as $i => $name) {
            $found = $pendingFrom === null ? Category::query()->where('parent_id', $parentId)
                ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->orWhere('slug', Str::slug($name)))
                ->orderByRaw('LOWER(name) = ? DESC', [mb_strtolower($name)])->orderBy('id')->first() : null;
            if ($found) {
                $parentId = $found->id;

                continue;
            }
            if (! $this->session->option('create_missing')) {
                $this->messages[] = 'Category “'.implode(' > ', $names).'” doesn’t exist – skipped (switch on “create missing categories”).';

                return $this->categoryCache[$key] = null;
            }
            if ($this->dryRun) {
                $this->messages[] = 'New category: '.implode(' > ', array_slice($names, 0, $i + 1));
                $pendingFrom ??= $i;

                continue;
            }
            $parentId = $this->createCategory($name, $parentId)->id;
        }

        return $this->categoryCache[$key] = $pendingFrom === null ? $parentId : 'new:'.$key;
    }

    protected function createCategory(string $name, ?int $parentId): Category
    {
        $parentPath = $parentId ? (string) Category::query()->whereKey($parentId)->value('path') : '';
        $base = Str::limit(Str::slug($name), 180, '') ?: 'category';
        $slug = $base;
        for ($i = 2; Category::query()->where('path', ltrim($parentPath.'/'.$slug, '/'))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return Category::query()->create([
            'name' => Str::limit($name, 190, ''),
            'slug' => $slug,
            'parent_id' => $parentId,
            'is_visible' => true,
            'sort_order' => (int) Category::query()->where('parent_id', $parentId)->max('sort_order') + 1,
        ]);
    }

    /**
     * Shipping class id by slug or name (core shipping classes). Missing: created when allowed (a dry run only says
     * so), otherwise the row keeps its current class with a warning.
     */
    protected function shippingClass(string $value): ?int
    {
        $value = Str::limit(trim($value), 120, '');
        $slug = Str::limit(Str::slug($value), 100, '');
        $id = DB::table('shipping_classes')->where('slug', $slug)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($value)])->orderBy('id')->value('id');
        if ($id) {
            return (int) $id;
        }
        if (! $this->session->option('create_missing') || $slug === '') {
            throw new InvalidArgumentException('Shipping class “'.Cells::short($value).'” doesn’t exist (add it under Settings › Shipping, or switch on “create missing”)');
        }
        $this->messages[] = 'New shipping class: '.$value;
        if ($this->dryRun) {
            return null;
        }

        return (int) DB::table('shipping_classes')->insertGetId(['name' => $value, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Attribute id by name or slug, "new:Name" when it would be created (dry run / applied later), or null. */
    protected function attribute(string $name): int|string|null
    {
        $name = trim(preg_replace('/^pa_/', '', $name));
        $key = mb_strtolower($name);
        if (array_key_exists($key, $this->attributeCache)) {
            return $this->attributeCache[$key];
        }
        $attribute = Attribute::query()->whereRaw('LOWER(name) = ?', [$key])->orWhere('slug', Str::slug($name))->orderBy('id')->first();
        if ($attribute) {
            return $this->attributeCache[$key] = $attribute->id;
        }
        if (! $this->session->option('create_missing')) {
            $this->messages[] = 'Attribute “'.$name.'” doesn’t exist – skipped (switch on “create missing attributes”).';

            return $this->attributeCache[$key] = null;
        }
        $this->messages[] = 'New attribute: '.$name;

        return $this->attributeCache[$key] = 'new:'.Str::limit($name, 190, '');
    }

    /** Value id of an attribute (id or "new:Name" ref), "new:Value" when it would be created, or null. */
    protected function attributeValue(int|string $attribute, string $value): int|string|null
    {
        $value = Str::limit(trim($value), 190, '');
        if (is_int($attribute)) {
            $found = AttributeValue::query()->where('attribute_id', $attribute)
                ->where(fn ($q) => $q->whereRaw('LOWER(value) = ?', [mb_strtolower($value)])->orWhere('slug', Str::slug($value)))
                ->orderByRaw('LOWER(value) = ? DESC', [mb_strtolower($value)])->orderBy('id')->value('id');
            if ($found) {
                return (int) $found;
            }
        }
        if (! $this->session->option('create_missing')) {
            $this->messages[] = 'Attribute value “'.$value.'” doesn’t exist – skipped (switch on “create missing attributes”).';

            return null;
        }
        if (is_int($attribute)) {
            $this->messages[] = 'New attribute value: '.(Attribute::query()->whereKey($attribute)->value('name') ?? '').': '.$value;
        }

        return 'new:'.$value;
    }

    /** Create a "new:Name" attribute now (real run); ids pass through. */
    protected function materialiseAttribute(int|string $ref): int
    {
        if (is_int($ref) || ctype_digit((string) $ref)) {
            return (int) $ref;
        }
        $name = substr($ref, 4);
        $existing = Attribute::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $base = Str::limit(Str::slug($name), 180, '') ?: 'attribute';
        $slug = $base;
        for ($i = 2; Attribute::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }
        $id = Attribute::query()->create(['name' => $name, 'slug' => $slug, 'sort_order' => (int) Attribute::query()->max('sort_order') + 1])->id;
        $this->attributeCache[mb_strtolower($name)] = $id;

        return $id;
    }

    protected function materialiseValue(int $attributeId, int|string $ref): int
    {
        if (is_int($ref) || ctype_digit((string) $ref)) {
            return (int) $ref;
        }

        return ProductSaver::findOrCreateValue(Attribute::query()->findOrFail($attributeId), substr($ref, 4))->id;
    }

    // ---------------------------------------------------------------------------------------------- cells

    /**
     * Mapped cells of the row: [target key => trimmed value]; WooCommerce attribute groups under '_woo_attributes'.
     */
    public function fields(array $cells): array
    {
        $fields = [];
        $woo = [];
        foreach ($this->mapping as $index => $target) {
            $value = trim((string) ($cells[(int) $index] ?? ''));
            if (preg_match('/^\'[=+\-@\t\r]/', $value)) {
                $value = substr($value, 1); // formula guard added by the export (CsvExport::cell)
            }
            if (preg_match('/^woo_attr_(name|values|visible):(\d+)$/', $target, $m)) {
                $woo[(int) $m[2]][$m[1]] = $value;

                continue;
            }
            $fields[$target] = $value;
        }
        if ($woo) {
            ksort($woo);
            $fields['_woo_attributes'] = array_values($woo);
        }

        return $fields;
    }

    protected function overwrite(): bool
    {
        return $this->session->option('empty_cells') === 'overwrite';
    }
}
