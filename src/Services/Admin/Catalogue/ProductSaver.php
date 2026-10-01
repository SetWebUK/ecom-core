<?php

namespace Pine\Commerce\Services\Admin\Catalogue;

use Pine\Commerce\Http\Requests\Admin\Catalogue\ProductRequest;
use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\AttributeValue;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the product editor's data in one transaction: product columns, categories, images, specifications,
 * attributes (incl. Condition/Brand from the side panel), variants and related products. Afterwards it clears the
 * storefront caches, adds a redirect when the URL handle changed and emails waiting back-in-stock subscribers.
 */
class ProductSaver
{
    /** @return array{product: Product, alerts: array, redirected: bool} */
    public static function save(Product $product, ProductRequest $request): array
    {
        $isNew = ! $product->exists;
        $oldPath = $isNew ? null : trim((string) parse_url($product->url, PHP_URL_PATH), '/');
        $oldSlug = $product->slug;
        $before = $isNew ? [] : static::availability($product);

        DB::transaction(function () use ($product, $request) {
            $product->fill($request->productData());
            $categoryIds = $request->categoryIds();
            $product->primary_category_id = $request->primaryCategoryId();
            // an imported breadcrumb category (differs from the URL's) gives way once staff pick the main category
            if ($product->isDirty('primary_category_id') && ! empty($product->breadcrumb_category_id)) {
                $product->breadcrumb_category_id = null;
            }
            $product->save();

            $product->categories()->sync($categoryIds);
            static::syncImages($product, $request->images());
            static::syncSpecs($product, $request->specs());
            static::syncAttributes($product, $request->attributeRows(), $request->conditionValue(), $request->brandValue());
            if ($product->type === 'variable') {
                static::syncVariations($product, $request->variations());
                static::refreshVariable($product);
            }
            static::syncRelated($product, $request->related());
        });

        $product->refresh()->load(['primaryCategory', 'categories']);
        CatalogueTools::flushStorefrontCaches();

        $redirected = false;
        if (! $isNew && $oldSlug !== $product->slug && $request->wantsRedirect() && $oldPath) {
            $newPath = trim((string) parse_url($product->url, PHP_URL_PATH), '/');
            $redirected = $oldPath !== $newPath && UrlRedirects::add($oldPath, '/'.$newPath.'/') > 0;
        }

        $alerts = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
        if (! $isNew && static::becameAvailable($before, static::availability($product))) {
            $alerts = CatalogueTools::notifyBackInStock($product);
        }

        return ['product' => $product, 'alerts' => $alerts, 'redirected' => $redirected];
    }

    // Relations ----------------------------------------------------------------------------------------

    /** @param list<array{path:string, alt:?string}> $images */
    public static function syncImages(Product $product, array $images): void
    {
        $existing = $product->images()->get()->keyBy('path');
        $keep = [];
        foreach ($images as $i => $image) {
            $row = $existing->get($image['path']);
            if ($row) {
                if ((int) $row->sort_order !== $i || $row->alt !== $image['alt']) {
                    $row->forceFill(['sort_order' => $i, 'alt' => $image['alt']])->save();
                }
                $keep[] = $row->id;
            } else {
                $keep[] = $product->images()->create(['path' => $image['path'], 'alt' => $image['alt'], 'sort_order' => $i])->id;
            }
        }
        $product->images()->whereNotIn('id', $keep ?: [0])->delete();
    }

    public static function syncSpecs(Product $product, array $specs): void
    {
        $product->specs()->delete();
        foreach ($specs as $i => $spec) {
            $product->specs()->create([
                'key' => $spec['key'],
                'label' => Str::limit($spec['label'], 250, ''),
                'value' => $spec['value'],
                'description' => $spec['description'],
                'sort_order' => $i,
            ]);
        }
    }

    /**
     * product_attributes rows + attribute_value_product pivot. Condition and Brand ("make") come from the side panel:
     * the chosen text is matched to (or creates) a value of that attribute, and mirrored into products.condition/brand.
     */
    public static function syncAttributes(Product $product, array $rows, ?string $condition, ?string $brand): void
    {
        $existing = $product->productAttributes()->get()->keyBy('attribute_id');
        $valueIds = [];
        $keepAttributes = [];
        $position = 0;

        // Rows still in their stored order keep their stored positions (imported products share positions with
        // Condition/Brand), so saving the editor without reordering attributes doesn't renumber anything.
        $postedIds = array_map('intval', array_column($rows, 'attribute_id'));
        $storedIds = $existing->toBase()->only($postedIds)->sortBy([['position', 'asc'], ['id', 'asc']])->keys()->map(fn ($id) => (int) $id)->all();
        $keepPositions = $postedIds === $storedIds;

        foreach ($rows as $row) {
            $keepAttributes[] = $row['attribute_id'];
            $rowPosition = $position++;
            $product->productAttributes()->updateOrCreate(
                ['attribute_id' => $row['attribute_id']],
                ['position' => $keepPositions ? (int) $existing[$row['attribute_id']]->position : $rowPosition, 'is_visible' => $row['visible'], 'is_variation' => $row['variation']]
            );
            array_push($valueIds, ...$row['values']);
        }

        $slugs = ProductRequest::asideAttributes();
        $aside = Attribute::query()->whereIn('slug', array_values($slugs) ?: [''])->get()->keyBy('slug');
        foreach (['condition' => $condition, 'brand' => $brand] as $role => $text) {
            $attribute = isset($slugs[$role]) ? $aside->get($slugs[$role]) : null;
            if (! $attribute || $text === null) {
                continue;
            }
            $value = static::findOrCreateValue($attribute, $text);
            $valueIds[] = $value->id;
            $keepAttributes[] = $attribute->id;
            $row = $existing->get($attribute->id);
            $product->productAttributes()->updateOrCreate(
                ['attribute_id' => $attribute->id],
                ['position' => $row?->position ?? $position++, 'is_visible' => $row?->is_visible ?? true, 'is_variation' => $row?->is_variation ?? false]
            );
        }

        $product->productAttributes()->whereNotIn('attribute_id', $keepAttributes ?: [0])->delete();
        $product->attributeValues()->sync(array_values(array_unique($valueIds)));

        // Mirror into the columns the product cards read (same rules as the WordPress import)
        $columns = [
            'condition' => $condition !== null ? Str::limit(str_replace((array) config('commerce.catalog.condition_label_strip', []), '', $condition), 100, '') : null,
            'brand' => $brand ?? static::brandFromTitle($product->name),
        ];
        if ($product->condition !== $columns['condition'] || $product->brand !== $columns['brand']) {
            $product->forceFill($columns)->saveQuietly();
        }
    }

    public static function findOrCreateValue(Attribute $attribute, string $text): AttributeValue
    {
        $text = Str::limit(trim($text), 190, '');
        $value = $attribute->values()->whereRaw('LOWER(value) = ?', [mb_strtolower($text)])->first();
        if ($value) {
            return $value;
        }

        return $attribute->values()->create([
            'value' => $text,
            'slug' => static::uniqueValueSlug($attribute, $text),
            'sort_order' => (int) $attribute->values()->max('sort_order') + 1,
        ]);
    }

    public static function uniqueValueSlug(Attribute $attribute, string $text, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($text), 180, '') ?: 'value';
        $slug = $base;
        for ($i = 2; $attribute->values()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    protected static function brandFromTitle(string $name): ?string
    {
        return commerce_presenter()::brandFromTitle($name); // commerce.catalog.known_brands (or a client presenter's rule)
    }

    /**
     * Update/create the posted variants and delete the ones that were removed.
     *
     * The rows' "sort_order" is their position in the form. It is only written when the user actually changed the
     * order (moved a variant, or added one above existing ones): otherwise existing variants keep their stored
     * sort_order (imported values, ties included) and new ones are appended after them.
     */
    public static function syncVariations(Product $product, array $rows): void
    {
        $existing = $product->variations()->get()->keyBy('id');
        $rows = static::keepVariationOrder($existing, array_values($rows));
        $keep = [];
        foreach ($rows as $row) {
            $data = collect($row)->except('id')->all();
            $variation = $row['id'] ? $existing->get($row['id']) : null;
            if ($variation) {
                $variation->fill($data);
                if ($variation->isDirty()) {
                    $variation->saveQuietly(); // parent price is refreshed once below
                }
            } else {
                $variation = new ProductVariation($data);
                $variation->product_id = $product->id;
                $variation->saveQuietly();
            }
            $keep[] = $variation->id;
        }
        $product->variations()->whereNotIn('id', $keep ?: [0])->delete();
    }

    /**
     * Drop "sort_order" from the rows of existing variants when the posted order is the stored one, and number new
     * variants after the last stored position; leave the posted positions alone when the user reordered.
     *
     * @param  \Illuminate\Support\Collection<int, ProductVariation>  $existing  keyed by id
     */
    protected static function keepVariationOrder($existing, array $rows): array
    {
        $stored = $existing->sortBy([['sort_order', 'asc'], ['id', 'asc']])->keys()->map(fn ($id) => (int) $id)->all();
        $posted = [];
        $newBeforeExisting = false;
        $seenNew = false;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id && $existing->has($id)) {
                $posted[] = $id;
                $newBeforeExisting = $newBeforeExisting || $seenNew;
            } else {
                $seenNew = true;
            }
        }
        $storedKept = array_values(array_intersect($stored, $posted));
        if ($newBeforeExisting || $posted !== $storedKept) {
            return $rows; // reordered: positions as posted
        }
        $next = $existing->isEmpty() ? 0 : (int) $existing->max('sort_order') + 1;
        foreach ($rows as $i => $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id && $existing->has($id)) {
                unset($rows[$i]['sort_order']);
            } elseif (array_key_exists('sort_order', $row)) {
                $rows[$i]['sort_order'] = $next++;
            }
        }

        return $rows;
    }

    /** Parent price/regular price/stock status of a product with variants, from its active variants. */
    public static function refreshVariable(Product $product): void
    {
        if ($product->type !== 'variable') {
            return;
        }
        $variations = $product->variations()->where('is_active', true)->get();
        $prices = $variations->map->currentPrice()->filter(fn ($p) => $p !== null);
        $regular = $variations->pluck('regular_price')->filter(fn ($p) => $p !== null)->map(fn ($p) => (float) $p);
        $status = match (true) {
            $variations->contains('stock_status', 'instock') => 'instock',
            $variations->contains('stock_status', 'onbackorder') => 'onbackorder',
            default => 'outofstock',
        };

        $product->forceFill([
            'price' => $prices->isNotEmpty() ? $prices->min() : null,
            'regular_price' => $regular->isNotEmpty() ? $regular->min() : null,
            'sale_price' => null,
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => $status,
        ])->saveQuietly();
    }

    public static function syncRelated(Product $product, array $related): void
    {
        DB::table('related_products')->where('product_id', $product->id)->delete();
        $rows = [];
        foreach ($related as $type => $ids) {
            foreach ($ids as $id) {
                $rows[] = ['product_id' => $product->id, 'related_id' => $id, 'type' => $type];
            }
        }
        if ($rows) {
            DB::table('related_products')->insert($rows);
        }
    }

    /**
     * Inline edit of one product or variant (prices / stock) from the Products list or the Inventory page.
     *
     * @return array{alerts: array}
     */
    public static function quickUpdate(Product|ProductVariation $item, array $changes): array
    {
        $product = $item instanceof Product ? $item : $item->product;
        $before = static::availability($product);

        DB::transaction(function () use ($item, $changes) {
            foreach ($changes as $field => $value) {
                $item->{$field} = $value;
            }
            if (array_key_exists('manage_stock', $changes) && ! $item->manage_stock) {
                $item->stock_quantity = null;
            }
            if ($item->manage_stock && $item->stock_quantity !== null) {
                $backorders = $item instanceof Product ? $item->backorders : 'no';
                $item->stock_status = $item->stock_quantity > 0 ? 'instock' : ($backorders === 'no' ? 'outofstock' : 'onbackorder');
            }
            if ($item instanceof Product && $item->sale_price === null) {
                $item->sale_starts_at = null;
                $item->sale_ends_at = null;
            }
            if ($item instanceof Product) {
                $item->save();
            } else {
                $item->saveQuietly();
                static::refreshVariable($item->product()->first());
            }
        });

        CatalogueTools::flushStorefrontCaches();
        $product->refresh();
        $alerts = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
        if (static::becameAvailable($before, static::availability($product))) {
            $alerts = CatalogueTools::notifyBackInStock($product);
        }

        return ['alerts' => $alerts];
    }

    // Back-in-stock ------------------------------------------------------------------------------------

    /** @return array{product:bool, variations:array<int,bool>} */
    public static function availability(Product $product): array
    {
        return [
            'product' => $product->isInStock() && $product->status === 'published',
            'variations' => $product->type === 'variable'
                ? $product->variations()->get(['id', 'stock_status', 'is_active'])->mapWithKeys(fn ($v) => [$v->id => $v->is_active && $v->isInStock()])->all()
                : [],
        ];
    }

    public static function becameAvailable(array $before, array $after): bool
    {
        if (($after['product'] ?? false) && ! ($before['product'] ?? false)) {
            return true;
        }
        foreach ($after['variations'] ?? [] as $id => $available) {
            if ($available && ! ($before['variations'][$id] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
