<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Models\Product;

/** product_variation posts -> product_variations; variable products then get price = cheapest active variation. */
class VariationsStep extends AbstractStep
{
    public function key(): string
    {
        return 'catalog.variations';
    }

    public function section(): string
    {
        return 'catalog';
    }

    public function after(): array
    {
        return ['catalog.products'];
    }

    protected function clear(): void
    {
        DB::table('product_variations')->whereNotNull('wp_id')->delete();
    }

    protected function import(): void
    {
        $productIds = $this->ctx->map('products');
        $posts = $this->wp->posts('product_variation');
        $rows = [];
        foreach ($posts->chunk(1000) as $chunk) {
            $meta = $this->wp->postMeta($chunk->pluck('ID')->all());
            foreach ($chunk as $post) {
                $pid = $productIds[$post->post_parent] ?? null;
                if (! $pid) {
                    continue;
                }
                $m = $meta[$post->ID] ?? [];
                $options = [];
                foreach ($m as $key => $value) {
                    if (str_starts_with($key, 'attribute_')) {
                        $slug = str_starts_with($key, 'attribute_pa_') ? substr($key, 13) : Str::slug(substr($key, 10));
                        $options[$slug] = $value;
                    }
                }
                $manage = WordPressSource::yes($m['_manage_stock'] ?? 'no');
                $stock = isset($m['_stock']) && is_numeric($m['_stock']) ? (int) $m['_stock'] : null;
                $rows[] = [
                    'wp_id' => $post->ID,
                    'product_id' => $pid,
                    'sku' => trim((string) ($m['_sku'] ?? '')) ?: null,
                    'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'regular_price' => WordPressSource::decimal($m['_regular_price'] ?? null),
                    'sale_price' => WordPressSource::decimal($m['_sale_price'] ?? null),
                    'manage_stock' => $manage,
                    'stock_quantity' => $manage ? $stock : null,
                    'stock_status' => $manage && $stock !== null ? ($stock > 0 ? 'instock' : 'outofstock') : ($m['_stock_status'] ?? 'instock'),
                    'image' => $this->ctx->attachmentPath($m['_thumbnail_id'] ?? 0),
                    'weight' => is_numeric($m['_weight'] ?? null) && (float) $m['_weight'] > 0 ? (float) $m['_weight'] : null,
                    'is_active' => $post->post_status === 'publish',
                    'sort_order' => (int) $post->menu_order,
                    'created_at' => WordPressSource::gmt($post->post_date_gmt) ?? $this->now(),
                    'updated_at' => WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
                ];
            }
        }
        // WooCommerce lists a product's variations by menu_order, then post ID - and menu_order is often 0 for all of
        // them. Store that effective order as 0, 1, 2 … per product, so the admin shows (and saves) the same order.
        $rows = static::normaliseSortOrder($rows);
        $this->ctx->save('product_variations', $rows, 'wp_id', ['created_at']);

        // Variable products: price = cheapest active variation (model logic)
        foreach (array_chunk(array_values(array_unique(array_column($rows, 'product_id'))), 500) as $chunk) {
            Product::whereIn('id', $chunk)->get()->each->refreshVariablePrice();
        }

        $this->ctx->count('Variations', $posts->count(), DB::table('product_variations')->whereNotNull('wp_id')->count());
    }

    /**
     * sort_order = position within the product (by WordPress menu_order, then post ID), starting at 0.
     *
     * @param  list<array{wp_id:int, product_id:int, sort_order:int}>  $rows
     * @return list<array>
     */
    public static function normaliseSortOrder(array $rows): array
    {
        $byProduct = [];
        foreach ($rows as $i => $row) {
            $byProduct[$row['product_id']][] = $i;
        }
        foreach ($byProduct as $indexes) {
            usort($indexes, fn ($a, $b) => [(int) $rows[$a]['sort_order'], (int) $rows[$a]['wp_id']] <=> [(int) $rows[$b]['sort_order'], (int) $rows[$b]['wp_id']]);
            foreach ($indexes as $position => $i) {
                $rows[$i]['sort_order'] = $position;
            }
        }

        return $rows;
    }
}
