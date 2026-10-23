<?php

namespace Pine\Commerce\Scheduling\Tasks;

use Illuminate\Support\Facades\Cache;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Scheduling\Task;

/**
 * Scheduled sales: products.price (used for price sorting, the price filter, admin lists and the feed) is only
 * recalculated when a product is saved, so a sale that starts or ends on its date would leave it stale. This task
 * rewrites price for every product with sale dates whose stored price no longer matches Product::currentPrice(), and
 * for every product with variants whose variants have sale dates (the lowest current variant price; the storefront's
 * displayed price is always computed live). updated_at is left alone (nobody edited the product).
 */
class RefreshSalePrices extends Task
{
    public function handle(): string
    {
        $changed = 0;
        Product::query()
            ->where('type', '!=', 'variable')
            ->where(fn ($q) => $q->whereNotNull('sale_starts_at')->orWhereNotNull('sale_ends_at'))
            ->select(['id', 'type', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'price'])
            ->chunkById(200, function ($products) use (&$changed) {
                foreach ($products as $product) {
                    $current = $product->currentPrice();
                    $stored = $product->price === null ? null : round((float) $product->price, 2);
                    if (($current === null ? null : round($current, 2)) !== $stored) {
                        Product::query()->whereKey($product->id)->toBase()->update(['price' => $current]);
                        $changed++;
                    }
                }
            });
        // products with variants: their price is the lowest current variant price
        Product::query()
            ->where('type', 'variable')
            ->whereHas('variations', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('sale_starts_at')->orWhereNotNull('sale_ends_at')))
            ->select(['id', 'type', 'price'])
            ->chunkById(200, function ($products) use (&$changed) {
                foreach ($products as $product) {
                    $current = $product->variations()->where('is_active', true)->get()->map->currentPrice()->filter(fn ($p) => $p !== null)->min();
                    $stored = $product->price === null ? null : round((float) $product->price, 2);
                    if (($current === null ? null : round((float) $current, 2)) !== $stored) {
                        Product::query()->whereKey($product->id)->toBase()->update(['price' => $current]);
                        $changed++;
                    }
                }
            });
        if ($changed) {
            Cache::forget('feeds.google-shopping');
        }

        return $changed.' '.str('price')->plural($changed).' updated';
    }
}
