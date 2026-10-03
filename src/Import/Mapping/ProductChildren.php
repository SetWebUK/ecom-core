<?php

namespace Pine\Commerce\Import\Mapping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Support\Upserter;

/**
 * Rows that hang off imported products – category links, images, attributes, attribute values, spec rows and
 * related products – rebuilt for each product on every run (so re-imports never duplicate them). Shared by the
 * database importer and the WooCommerce REST API importer; each resolves its own ids first.
 */
final class ProductChildren
{
    /**
     * Replace the children of the given products.
     *
     * @param  array<int|string, array{categories:list<int>, images:?list<array{path:string, alt:?string, exists?:bool}>,
     *               attributes:list<array{attribute_id:int, position:int, is_visible:bool, is_variation:bool}>,
     *               values:list<int>, specs:?list<array{key:?string, label:string, value:?string, description:?string}>}>  $children
     *               remote id => resolved children (images / specs null = keep the product's current ones)
     * @param  array<int|string,int>  $productIds  remote id => products.id
     * @return array{categories:int, images:int, missing_images:int, values:int, specs:int}
     */
    public static function write(array $children, array $productIds, string $now, Upserter $upserter): array
    {
        $ids = array_values(array_filter(array_map(fn ($k) => $productIds[$k] ?? null, array_keys($children))));
        foreach (['category_product', 'product_attributes', 'attribute_value_product'] as $table) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table($table)->whereIn('product_id', $chunk)->delete();
            }
        }
        // images / spec rows: null = leave this product's rows as they are (images not downloaded, no spec source)
        foreach (['images' => 'product_images', 'specs' => 'product_specs'] as $key => $table) {
            $owners = array_values(array_filter(array_map(fn ($k) => ($children[$k][$key] ?? null) !== null ? ($productIds[$k] ?? null) : null, array_keys($children))));
            foreach (array_chunk($owners, 500) as $chunk) {
                DB::table($table)->whereIn('product_id', $chunk)->delete();
            }
        }

        $cats = $images = $attrs = $values = $specs = [];
        $missing = 0;
        foreach ($children as $remoteId => $c) {
            $pid = $productIds[$remoteId] ?? null;
            if (! $pid) {
                continue;
            }
            foreach (array_unique($c['categories']) as $categoryId) {
                $cats[$pid.'|'.$categoryId] = ['category_id' => $categoryId, 'product_id' => $pid];
            }
            foreach (array_values($c['images'] ?? []) as $pos => $image) {
                if (! ($image['exists'] ?? true)) {
                    $missing++;
                }
                $images[] = ['product_id' => $pid, 'path' => $image['path'], 'alt' => $image['alt'] ?? null, 'sort_order' => $pos,
                    'created_at' => $now, 'updated_at' => $now];
            }
            foreach ($c['attributes'] as $a) {
                $attrs[$pid.'|'.$a['attribute_id']] = ['product_id' => $pid] + $a;
            }
            foreach (array_unique($c['values']) as $valueId) {
                $values[$pid.'|'.$valueId] = ['attribute_value_id' => $valueId, 'product_id' => $pid];
            }
            foreach ($c['specs'] ?? [] as $i => $s) {
                $specs[] = ['product_id' => $pid, 'key' => $s['key'], 'label' => $s['label'], 'value' => $s['value'], 'description' => $s['description'], 'sort_order' => $i];
            }
        }

        $upserter->insert('category_product', array_values($cats));
        $upserter->insert('product_images', $images);
        $upserter->insert('product_attributes', array_values($attrs));
        $upserter->insert('attribute_value_product', array_values($values));
        $upserter->insert('product_specs', $specs);

        return ['categories' => count($cats), 'images' => count($images), 'missing_images' => $missing, 'values' => count($values), 'specs' => count($specs)];
    }

    /**
     * Replace the related products (up-sells, cross-sells, grouped children) of the given products.
     *
     * @param  list<array{0:int|string, 1:int|string, 2:string}>  $related  [remote product id, remote related id, type]
     * @param  array<int|string,int>  $productIds  remote id => products.id (every product the related ids may point to)
     * @param  list<int>  $owners  products.id whose related rows are rebuilt
     */
    public static function related(array $related, array $productIds, array $owners, Upserter $upserter): int
    {
        foreach (array_chunk($owners, 500) as $chunk) {
            DB::table('related_products')->whereIn('product_id', $chunk)->delete();
        }
        $rows = [];
        foreach ($related as [$remoteId, $relatedRemote, $type]) {
            $pid = $productIds[$remoteId] ?? null;
            $rid = $productIds[$relatedRemote] ?? null;
            if ($pid && $rid && $rid !== $pid) {
                $rows[$pid.'|'.$rid.'|'.$type] = ['product_id' => $pid, 'related_id' => $rid, 'type' => $type];
            }
        }
        $upserter->insert('related_products', array_values($rows));

        return count($rows);
    }

    /**
     * Product-level (non-taxonomy) attributes become global attributes + values, created on first sight.
     *
     * @param  iterable<array{name:string, values:list<string>}>  $local
     * @param  array<string,int>  $attributeIds  slug => attributes.id (extended in place)
     * @return array<string,int>  "attributeId|valueSlug" => attribute_values.id
     */
    public static function localAttributes(iterable $local, array &$attributeIds, string $now): array
    {
        $values = [];
        foreach ($local as $attr) {
            $name = Formatter::decode($attr['name']);
            $slug = Str::slug($name);
            if ($slug === '') {
                continue;
            }
            if (! isset($attributeIds[$slug])) {
                $attributeIds[$slug] = DB::table('attributes')->insertGetId([
                    'name' => $name, 'slug' => $slug, 'type' => 'select', 'is_filterable' => false,
                    'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach (array_values(array_filter(array_map('trim', $attr['values']), fn ($v) => $v !== '')) as $i => $v) {
                $values[$attributeIds[$slug].'|'.Str::slug($v)] = ['attribute_id' => $attributeIds[$slug], 'value' => $v, 'slug' => Str::slug($v), 'sort_order' => $i, 'wp_id' => null];
            }
        }

        return $values ? self::saveValues(array_values($values), $now) : [];
    }

    /** Upsert attribute values on (attribute_id, slug). Returns ["attrId|slug" => id]. */
    public static function saveValues(array $values, string $now): array
    {
        $existing = [];
        foreach (DB::table('attribute_values')->get(['id', 'attribute_id', 'slug']) as $v) {
            $existing[$v->attribute_id.'|'.$v->slug] = $v->id;
        }
        foreach ($values as $v) {
            $key = $v['attribute_id'].'|'.$v['slug'];
            $data = $v + ['updated_at' => $now];
            if (isset($existing[$key])) {
                DB::table('attribute_values')->where('id', $existing[$key])->update($data);
            } else {
                $existing[$key] = DB::table('attribute_values')->insertGetId($data + ['created_at' => $now]);
            }
        }

        return $existing;
    }
}
