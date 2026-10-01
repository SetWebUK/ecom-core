<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Mail\BackInStock;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\StockNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Catalogue maintenance helpers used by the back office: review stats, stock labels, back-in-stock emails and
 * clearing the storefront caches that depend on product data.
 */
class CatalogueTools
{
    /** Storefront cache keys built from product data (see Pine\Commerce\Services\Catalog\Facets, FeedController, SitemapController). */
    public const CACHE_KEYS = ['catalog.facet-options', 'feeds.google-shopping', 'sitemap.xml'];

    /** Recalculate a product's average rating and review count from approved reviews. */
    public static function refreshReviewStats(?Product $product): void
    {
        if (! $product) {
            return;
        }
        $stats = $product->reviews()->where('is_approved', true)->selectRaw('COUNT(*) as c, AVG(rating) as a')->first();
        $product->forceFill([
            'review_count' => (int) ($stats?->c ?? 0),
            'average_rating' => round((float) ($stats?->a ?? 0), 2),
        ])->saveQuietly();
    }

    /** Forget cached storefront data after catalogue edits (facet options, Google feed, sitemap). */
    public static function flushStorefrontCaches(): void
    {
        foreach (self::CACHE_KEYS as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // cache store unavailable – the entries expire on their own
            }
        }
        if (class_exists(\Pine\Commerce\Services\Catalog\Categories::class)) {
            \Pine\Commerce\Services\Catalog\Categories::flush();
        }
        CategoryTree::flush();
    }

    /** Store-wide low stock threshold (Settings › Inventory), default 2. */
    public static function lowStockThreshold(): int
    {
        return max(0, (int) setting('inventory.low_stock_threshold', 2));
    }

    /**
     * Friendly stock text + badge colour for a product or variation row.
     *
     * @return array{label:string, color:string, quantity:?int, low:bool}
     */
    public static function stock(Product|ProductVariation $item): array
    {
        $managed = (bool) $item->manage_stock && $item->stock_quantity !== null;
        $qty = $managed ? (int) $item->stock_quantity : null;
        $threshold = $item instanceof Product && $item->low_stock_threshold !== null ? (int) $item->low_stock_threshold : static::lowStockThreshold();
        $low = $managed && $qty > 0 && $qty <= $threshold;

        [$label, $color] = match (true) {
            $item->stock_status === 'outofstock' => [$managed ? 'Out of stock' : 'Out of stock', 'danger'],
            $item->stock_status === 'onbackorder' => [$managed ? 'Backorder ('.$qty.')' : 'On backorder', 'warning'],
            $managed && $low => [$qty.' in stock', 'attention'],
            $managed => [$qty.' in stock', 'success'],
            default => ['In stock', 'success'],
        };

        return ['label' => $label, 'color' => $color, 'quantity' => $qty, 'low' => $low];
    }

    public static function isAvailable(StockNotification $alert): bool
    {
        $product = $alert->product;
        if (! $product || $product->status !== 'published' || $product->trashed()) {
            return false;
        }
        if ($alert->product_variation_id) {
            $variation = $alert->variation;

            return $variation && $variation->product_id === $product->id && $variation->is_active && $variation->isInStock();
        }

        return $product->isInStock();
    }

    /** Email one back-in-stock alert and mark it as notified. */
    public static function sendStockAlert(StockNotification $alert): bool
    {
        try {
            $alert->loadMissing(['product.images', 'product.primaryCategory', 'product.categories', 'variation']);
            Mail::to($alert->email)->send(new BackInStock($alert));
            $alert->forceFill(['notified_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Back-in-stock email failed for alert '.$alert->id.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * Send every waiting back-in-stock alert for these products whose product/variation can be bought again.
     *
     * @param  iterable<Product>|Product  $products
     * @return array{sent:int, failed:int, waiting:int} waiting = alerts left because the item is still unavailable
     */
    public static function notifyBackInStock(iterable|Product $products): array
    {
        $ids = collect($products instanceof Product ? [$products] : $products)
            ->map(fn ($p) => $p instanceof Product ? $p->id : (int) $p)->filter()->unique()->values();
        $result = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
        if ($ids->isEmpty() || ! Features::enabled('stock_alerts', false)) { // no back-in-stock emails while the feature is off
            return $result;
        }

        StockNotification::query()
            ->whereIn('product_id', $ids)
            ->whereNull('notified_at')
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'primaryCategory', 'categories']), 'variation'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $alerts) use (&$result) {
                foreach ($alerts as $alert) {
                    if (! static::isAvailable($alert)) {
                        $result['waiting']++;

                        continue;
                    }
                    static::sendStockAlert($alert) ? $result['sent']++ : $result['failed']++;
                }
            });

        return $result;
    }

    /** "2 back-in-stock emails sent." or null when nothing was sent. */
    public static function alertSummary(array $result): ?string
    {
        if (($result['sent'] ?? 0) === 0 && ($result['failed'] ?? 0) === 0) {
            return null;
        }
        $text = $result['sent'].' back-in-stock '.str('email')->plural($result['sent']).' sent';
        if ($result['failed'] ?? 0) {
            $text .= ', '.$result['failed'].' failed (see the log)';
        }

        return $text.'.';
    }
}
