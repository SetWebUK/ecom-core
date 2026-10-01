<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Deep-copies a product (as a draft) including images, categories, attributes, specs, variations and links. */
class ProductDuplicator
{
    public static function duplicate(Product $product): Product
    {
        // Reload so computed columns selected by list queries (e.g. main_image) are not copied
        $product = Product::withTrashed()->findOrFail($product->getKey());

        return DB::transaction(function () use ($product) {
            $product->load(['images', 'categories', 'productAttributes', 'attributeValues', 'specs', 'variations', 'related']);

            $copy = $product->replicate([
                'slug', 'sku', 'total_sales', 'average_rating', 'review_count', 'wp_id', 'published_at', 'price', 'deleted_at',
            ]);
            $copy->name = Str::limit($product->name.' (copy)', 250, '');
            $copy->slug = static::uniqueSlug($product->slug.'-copy');
            $copy->sku = $product->sku ? static::uniqueSku($product->sku.'-COPY') : null;
            $copy->status = 'draft';
            $copy->is_featured = false;
            $copy->total_sales = 0;
            $copy->average_rating = 0;
            $copy->review_count = 0;
            $copy->save();

            foreach ($product->images as $image) {
                $copy->images()->create($image->only(['path', 'alt', 'sort_order']));
            }
            $copy->categories()->sync($product->categories->pluck('id'));
            foreach ($product->productAttributes as $pa) {
                $copy->productAttributes()->create($pa->only(['attribute_id', 'position', 'is_visible', 'is_variation']));
            }
            $copy->attributeValues()->sync($product->attributeValues->pluck('id'));
            foreach ($product->specs as $spec) {
                $copy->specs()->create(array_intersect_key($spec->getAttributes(), array_flip(['key', 'label', 'value', 'description', 'sort_order'])));
            }
            $links = $product->related->map(fn (Product $related) => ['related_id' => $related->id, 'type' => $related->pivot->type])->unique(fn ($l) => $l['related_id'].'|'.$l['type']);
            foreach ($links as $link) {
                $copy->related()->attach($link['related_id'], ['type' => $link['type']]);
            }
            foreach ($product->variations as $variation) {
                $v = $variation->replicate(['wp_id']);
                $v->product_id = $copy->id;
                $v->sku = $variation->sku ? static::uniqueSku($variation->sku.'-COPY') : null;
                $v->saveQuietly();
            }
            $copy->refreshVariablePrice();

            return $copy->refresh();
        });
    }

    public static function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($base), 180, '') ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;
        while (Product::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** A SKU not used by any product or variation ("ABC-COPY", "ABC-COPY-2", …). */
    public static function uniqueSku(string $base): string
    {
        $sku = $base;
        $i = 2;
        while (Product::withTrashed()->where('sku', $sku)->exists() || ProductVariation::query()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$i++;
        }

        return $sku;
    }
}
