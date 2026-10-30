<?php

namespace Pine\Commerce\Services\Marketing;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Pine\Commerce\Models\CartItem;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\CartLine;

/**
 * What the shop's tracking (partials.tracking) reports about the current page, as WooCommerce's Google Analytics,
 * Pixel Manager and GTM Kit plugins did: the products listed, the product viewed, the basket, the paid order. The
 * controllers set it (one per request, bound "scoped"); partials.tracking turns it into the GA4, Google Ads, Meta and
 * Tag Manager events. Every item carries both IDs the platforms use:
 *   id       ProductIds::product()   – Google Ads remarketing, the Meta Pixel and the shopping feed (the parent product)
 *   item_id  ProductIds::analytics() – Google Analytics (SKU, else "#" + id)
 */
class Ecommerce
{
    /** @var array{page: string} */
    protected array $page = ['page' => 'other'];

    protected ?Order $order = null;

    public function home(): void
    {
        $this->page = ['page' => 'home'];
    }

    public function product(Product $product): void
    {
        $item = static::item($product, null, static::price($product));
        $this->page = ['page' => 'product', 'value' => $item['price'], 'items' => [$item]];
    }

    /** A product list (shop, category, search, theme sections). @param iterable<mixed> $products */
    public function listing(iterable $products, string $name): void
    {
        $models = EloquentCollection::make(is_array($products) ? $products : iterator_to_array($products))
            ->filter(fn ($p) => $p instanceof Product)->values();
        $models->loadMissing('primaryCategory');
        $items = $models->map(fn (Product $p, int $i) => static::item($p, null, static::price($p)) + ['index' => $i, 'item_list_name' => $name])->all();
        $this->page = ['page' => 'list', 'list' => $name, 'items' => $items];
    }

    /** The order-received page of a paid order (sent once per order, in the browser). */
    public function purchase(Order $order): void
    {
        $order->loadMissing(['items.product.primaryCategory', 'items.variation']);
        $items = $order->items->map(fn ($line) => $line->product
            ? static::item($line->product, $line->variation, (float) $line->unit_price, (int) $line->quantity, $line->sku)
            : ['id' => (string) $line->product_id, 'item_id' => trim((string) $line->sku) !== '' ? (string) $line->sku : '#'.$line->product_id,
                'item_name' => (string) $line->name, 'price' => round((float) $line->unit_price, 2), 'quantity' => (int) $line->quantity])->values()->all();

        $this->order = $order;
        $this->page = array_filter([
            'page' => 'purchase', 'transaction_id' => (string) $order->number, 'value' => round((float) $order->total, 2),
            'tax' => round((float) $order->tax_total, 2), 'shipping' => round((float) $order->shipping_total, 2),
            'coupon' => (string) $order->coupon_code, 'currency' => $order->currency ?: null, 'once' => 'store_purchase_'.$order->id,
            'items' => $items,
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /** The current page for partials.tracking: page, items, value, currency … */
    public function page(): array
    {
        return $this->page + ['currency' => static::currency()];
    }

    /** The paid order of the order-received page (for a theme's own tags, e.g. the customer's email). */
    public function order(): ?Order
    {
        return $this->order;
    }

    /** For the add-to-basket JSON (app.js passes it on in the store:added-to-cart event): the line added. */
    public static function added(Cart $cart, CartItem $added, Product $product, ?ProductVariation $variation, int $quantity): array
    {
        $line = collect($cart->totals()['lines'] ?? [])->first(fn (CartLine $l) => $l->item?->id === $added->id);
        $price = $line ? $line->unitPrice : static::price($variation ?? $product);
        $item = static::item($product, $variation, $price, $quantity, $line?->sku());

        return ['currency' => static::currency(), 'value' => round($price * $quantity, 2), 'items' => [$item]];
    }

    /** One item: both IDs, name, category, size, price, quantity. */
    public static function item(Product $product, ?ProductVariation $variation, float $price, int $quantity = 1, ?string $sku = null): array
    {
        $item = [
            'id' => ProductIds::product($product),
            'item_id' => ProductIds::analytics($product, $variation, $sku),
            'item_name' => (string) $product->name,
            'price' => round($price, 2),
            'quantity' => $quantity,
        ];
        if ($category = $product->primaryCategory?->name) {
            $item['item_category'] = (string) $category;
        }
        if ($variation && ($label = trim((string) $variation->label)) !== '') {
            $item['item_variant'] = $label;
        }

        return $item;
    }

    /** The basket for "checkout started" (every cart JSON carries it as tracking_cart). @return array{value: float, items: list<array>} */
    public static function basket(Cart $cart): array
    {
        $totals = $cart->totals();
        $lines = collect($totals['lines'] ?? []);

        return [
            'value' => round((float) ($totals['subtotal_display'] ?? $totals['subtotal'] ?? 0), 2),
            'items' => $lines->map(fn (CartLine $l) => static::item($l->product, $l->variation, $l->unitPrice, $l->quantity, $l->sku()))->values()->all(),
        ];
    }

    protected static function price(Product|ProductVariation $model): float
    {
        return (float) ($model instanceof ProductVariation ? $model->currentPrice() : ($model->price ?? $model->regular_price ?? 0));
    }

    protected static function currency(): string
    {
        return (string) config('commerce.currency.code', 'GBP');
    }
}
