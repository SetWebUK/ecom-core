<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Exports\CsvExport;
use Pine\Commerce\Http\Requests\Admin\Catalogue\ProductRequest;
use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\LocalTime;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The full product CSV: one row per product, followed by one row per variant (type "variation", parent = the
 * product's SKU or "id:123"; a variant's own tax class – "parent" = same as the product – and shipping class). Columns: Columns::exportKeys(). UTF-8 with BOM (Excel), cells that a spreadsheet would
 * run as a formula are prefixed with an apostrophe (CsvExport::cell – the import strips it again).
 *
 *   Exporter::response($query, 'products.csv')      // admin download
 *   (new Exporter)->write($handle, $query)           // CLI / tests
 */
class Exporter
{
    public const CHUNK = 100;

    protected array $keys;

    protected array $categoryPaths = [];

    protected array $attributes = [];

    protected array $valueNames = [];

    protected array $asideIds = [];

    /** shipping class id => slug (core shipping classes) */
    protected array $shippingClasses = [];

    public function __construct(protected bool $variations = true)
    {
        $this->keys = Columns::exportKeys();
    }

    public static function response(Builder $query, string $filename, bool $variations = true): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $variations) {
            $out = fopen('php://output', 'w');
            (new static($variations))->write($out, $query);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<string> */
    public function headers(): array
    {
        return $this->keys;
    }

    /** Write BOM + header + rows to a stream; returns the number of data rows. */
    public function write($handle, Builder $query): int
    {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->keys, ',', '"', '');
        $count = 0;
        foreach ($this->rows($query) as $row) {
            fputcsv($handle, array_map(fn ($value) => CsvExport::cell($value ?? ''), $row), ',', '"', '');
            $count++;
        }

        return $count;
    }

    /** @return \Generator<int, list<string>> rows in header order */
    public function rows(Builder $query): \Generator
    {
        $this->loadLookups();
        $query = $query->with([
            'categories:id', 'images:id,product_id,path,sort_order', 'specs', 'productAttributes',
            'attributeValues:id,attribute_id,value', 'variations',
        ]);

        foreach ($query->lazyById(self::CHUNK, 'products.id', 'id') as $product) {
            yield $this->ordered($this->productRow($product));
            if ($this->variations && $product->type === 'variable') {
                foreach ($product->variations as $variation) {
                    yield $this->ordered($this->variationRow($product, $variation));
                }
            }
        }
    }

    protected function ordered(array $row): array
    {
        return array_map(fn ($key) => isset($row[$key]) ? (string) $row[$key] : '', $this->keys);
    }

    protected function productRow(Product $p): array
    {
        $simple = $p->type !== 'variable';
        $values = $p->attributeValues->groupBy('attribute_id');
        $attributes = [];
        foreach ($p->productAttributes->sortBy('position') as $pa) {
            if (in_array($pa->attribute_id, $this->asideIds, true) || ! isset($this->attributes[$pa->attribute_id])) {
                continue; // Condition / Brand have their own columns while their switches are on
            }
            $names = $values->get($pa->attribute_id, collect())->pluck('value')->all();
            if ($names) {
                $attributes[] = [$this->attributes[$pa->attribute_id]['name'], $names];
            }
        }

        return [
            'id' => $p->id,
            'type' => $p->type ?: 'simple',
            'sku' => $p->sku,
            'name' => $p->name,
            'slug' => $p->slug,
            'status' => $p->status,
            'categories' => Cells::join($p->categories->map(fn ($c) => $this->categoryPaths[$c->id] ?? null)->filter()),
            'primary_category' => $p->primary_category_id ? ($this->categoryPaths[$p->primary_category_id] ?? '') : '',
            'short_description' => $p->short_description,
            'description' => $p->description,
            'regular_price' => $simple ? static::money($p->regular_price) : '',
            'sale_price' => $simple ? static::money($p->sale_price) : '',
            'sale_starts_at' => $simple ? LocalTime::format($p->sale_starts_at, 'Y-m-d H:i') : '',
            'sale_ends_at' => $simple ? LocalTime::format($p->sale_ends_at, 'Y-m-d H:i') : '',
            'cost_price' => static::money($p->cost_price),
            'tax_status' => $p->tax_status,
            'tax_class' => $p->tax_class,
            'manage_stock' => $simple ? ($p->manage_stock ? 'yes' : 'no') : '',
            'stock_quantity' => $simple && $p->manage_stock ? $p->stock_quantity : '',
            'stock_status' => $p->stock_status,
            'backorders' => $simple ? $p->backorders : '',
            'low_stock_threshold' => $p->low_stock_threshold,
            'weight' => static::number($p->weight),
            'length' => static::number($p->length),
            'width' => static::number($p->width),
            'height' => static::number($p->height),
            'shipping_class' => match (Columns::shippingClassMode()) {
                'id' => $p->getAttribute('shipping_class_id') ? ($this->shippingClasses[(int) $p->getAttribute('shipping_class_id')] ?? '') : '',
                'string' => $p->getAttribute('shipping_class'),
                default => '',
            },
            'images' => Cells::join($p->images->map(fn ($i) => media_url($i->path))),
            'attributes' => Cells::joinPairs($attributes),
            'variation_options' => '',
            'specs' => Cells::joinPairs($p->specs->map(fn ($s) => [$s->label, [$s->value]]), false),
            'meta_title' => $p->meta_title,
            'meta_description' => $p->meta_description,
            'featured' => $p->is_featured ? 'yes' : 'no',
            'condition' => $p->condition,
            'brand' => $p->brand,
            'subtitle' => $p->subtitle,
            'gtin' => $p->gtin,
            'mpn' => $p->mpn,
        ];
    }

    protected function variationRow(Product $p, $v): array
    {
        $options = [];
        foreach ((array) $v->options as $attributeSlug => $valueSlug) {
            $attribute = collect($this->attributes)->firstWhere('slug', $attributeSlug);
            $options[] = [$attribute['name'] ?? $attributeSlug, [$this->valueNames[$attributeSlug.'|'.$valueSlug] ?? $valueSlug]];
        }
        $label = implode(', ', array_map(fn ($o) => $o[1][0], $options));

        return [
            'id' => $v->id,
            'type' => 'variation',
            'sku' => $v->sku,
            'name' => $p->name.($label !== '' ? ' – '.$label : ''),
            'status' => $v->is_active ? 'published' : 'draft',
            'parent_sku' => $p->sku ?: 'id:'.$p->id,
            'regular_price' => static::money($v->regular_price),
            'sale_price' => static::money($v->sale_price),
            'manage_stock' => $v->manage_stock ? 'yes' : 'no',
            'stock_quantity' => $v->manage_stock ? $v->stock_quantity : '',
            'stock_status' => $v->stock_status,
            // a variant's own classes; WooCommerce's convention: tax class "parent" / empty shipping class = same as the product
            'tax_class' => ($v->getAttribute('tax_class') ?? '') !== '' ? $v->getAttribute('tax_class') : 'parent',
            'weight' => static::number($v->weight),
            'shipping_class' => Columns::shippingClassMode() === 'id' && $v->getAttribute('shipping_class_id')
                ? ($this->shippingClasses[(int) $v->getAttribute('shipping_class_id')] ?? '') : '',
            'images' => $v->image ? media_url($v->image) : '',
            'variation_options' => Cells::joinPairs($options, false),
        ];
    }

    protected function loadLookups(): void
    {
        $categories = Category::query()->get(['id', 'name', 'parent_id'])->keyBy('id');
        foreach ($categories as $category) {
            $names = [];
            $node = $category;
            for ($depth = 0; $node && $depth < 20; $depth++) {
                array_unshift($names, str_replace('>', '›', $node->name));
                $node = $node->parent_id ? $categories->get($node->parent_id) : null;
            }
            $this->categoryPaths[$category->id] = implode(' > ', $names);
        }
        if (Columns::shippingClassMode() === 'id') {
            $this->shippingClasses = DB::table('shipping_classes')->pluck('slug', 'id')->mapWithKeys(fn ($slug, $id) => [(int) $id => (string) $slug])->all();
        }
        $this->attributes = Attribute::query()->get(['id', 'name', 'slug'])->mapWithKeys(fn ($a) => [$a->id => ['name' => $a->name, 'slug' => $a->slug]])->all();
        $this->valueNames = DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attributes.slug as attribute_slug', 'attribute_values.slug', 'attribute_values.value'])
            ->mapWithKeys(fn ($v) => [$v->attribute_slug.'|'.$v->slug => $v->value])->all();
        $asideSlugs = array_values(array_filter([
            Columns::available('condition') ? (ProductRequest::asideAttributes()['condition'] ?? null) : null,
            Columns::available('brand') ? (ProductRequest::asideAttributes()['brand'] ?? null) : null,
        ]));
        $this->asideIds = collect($this->attributes)->filter(fn ($a) => in_array($a['slug'], $asideSlugs, true))->keys()->map(fn ($id) => (int) $id)->all();
    }

    public static function money(mixed $value): string
    {
        return $value === null || $value === '' ? '' : number_format((float) $value, 2, '.', '');
    }

    public static function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }
}
