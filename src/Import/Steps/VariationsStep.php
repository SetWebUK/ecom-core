<?php

namespace Pine\Commerce\Import\Steps;

use Pine\Commerce\Import\Mapping\ProductRows;
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
        $this->ctx->owned('product_variations')->delete();
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
                $rows[] = ProductRows::variationRow([
                    'wp_id' => (int) $post->ID, 'product_id' => $pid, 'status' => (string) $post->post_status, 'menu_order' => (int) $post->menu_order,
                    'date_gmt' => $post->post_date_gmt, 'modified_gmt' => $post->post_modified_gmt, 'meta' => $meta[$post->ID] ?? [],
                    'image' => $this->ctx->attachmentPath(($meta[$post->ID] ?? [])['_thumbnail_id'] ?? 0),
                ], $this->now());
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

        $this->ctx->count('Variations', $posts->count(), $this->ctx->owned('product_variations')->count());
    }

    /**
     * sort_order = position within the product (by WordPress menu_order, then post ID), starting at 0.
     *
     * @param  list<array{wp_id:int, product_id:int, sort_order:int}>  $rows
     * @return list<array>
     */
    public static function normaliseSortOrder(array $rows): array
    {
        return ProductRows::normaliseVariationOrder($rows);
    }
}
