<?php

namespace Pine\Commerce\Services\Admin\Catalogue;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk price changes from the Products list: adjust regular or sale prices by a percentage or an amount (or set an
 * exact amount), put products on sale by a percentage, or end their sale. Products with variants are changed per
 * variant. preview() returns what would change without saving; apply() saves through the models so the `price`
 * column stays right.
 *
 * $params: field (regular_price|sale_price), mode (increase_percent|decrease_percent|increase_amount|decrease_amount|set),
 *          amount (float), round (none|99|whole)
 */
class PriceAdjuster
{
    public const FIELDS = ['regular_price' => 'Price', 'sale_price' => 'Sale price'];

    public const MODES = [
        'increase_percent' => 'Increase by %',
        'decrease_percent' => 'Decrease by %',
        'increase_amount' => 'Increase by £',
        'decrease_amount' => 'Decrease by £',
        'set' => 'Set to £',
    ];

    public const ROUNDING = ['none' => 'Don’t round', '99' => 'End in .99', 'whole' => 'Whole pounds'];

    /** Display names of variants ("Product – 16gb"), kept outside the models so they are never saved. */
    protected static ?\WeakMap $names = null;

    /**
     * @param  list<int>  $ids
     * @return array{rows: list<array>, changed:int, skipped:int}
     */
    public static function preview(array $ids, array $params, int $limit = 100): array
    {
        $rows = [];
        $changed = 0;
        $skipped = 0;
        foreach (static::items($ids) as $item) {
            $result = static::compute($item, $params);
            if ($result === null) {
                continue;
            }
            $result['error'] ? $skipped++ : $changed++;
            if (count($rows) < $limit) {
                $rows[] = $result;
            }
        }

        return ['rows' => $rows, 'changed' => $changed, 'skipped' => $skipped];
    }

    /** @return array{changed:int, skipped:int} */
    public static function apply(array $ids, array $params): array
    {
        $changed = 0;
        $skipped = 0;
        $parents = [];
        DB::transaction(function () use ($ids, $params, &$changed, &$skipped, &$parents) {
            foreach (static::items($ids) as $item) {
                $result = static::compute($item, $params);
                if ($result === null) {
                    continue;
                }
                if ($result['error']) {
                    $skipped++;

                    continue;
                }
                $item->{$params['field']} = $result['new'];
                if ($params['field'] === 'regular_price' && $item->sale_price !== null && (float) $item->sale_price >= (float) $result['new']) {
                    $item->sale_price = null; // a sale above the new price would show as a price rise
                }
                if ($item instanceof ProductVariation) {
                    $item->saveQuietly();
                    $parents[$item->product_id] = true;
                } else {
                    $item->save();
                }
                $changed++;
            }
            foreach (Product::query()->whereIn('id', array_keys($parents))->get() as $product) {
                ProductSaver::refreshVariable($product);
            }
        });

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    /** Put products on sale at $percent off their regular price (optionally ending at $endsAt), or end the sale ($percent = null). */
    public static function setSale(array $ids, ?float $percent, $startsAt = null, $endsAt = null): int
    {
        $count = 0;
        $parents = [];
        DB::transaction(function () use ($ids, $percent, $startsAt, $endsAt, &$count, &$parents) {
            foreach (static::items($ids) as $item) {
                if ($item->regular_price === null || (float) $item->regular_price <= 0) {
                    continue;
                }
                $sale = $percent === null ? null : round((float) $item->regular_price * (1 - $percent / 100), 2);
                if ($sale !== null && $sale >= (float) $item->regular_price) {
                    continue;
                }
                $item->sale_price = $sale;
                if ($item instanceof Product) {
                    $item->sale_starts_at = $percent === null ? null : $startsAt;
                    $item->sale_ends_at = $percent === null ? null : $endsAt;
                    $item->save();
                } else {
                    $item->saveQuietly();
                    $parents[$item->product_id] = true;
                }
                $count++;
            }
            foreach (Product::query()->whereIn('id', array_keys($parents))->get() as $product) {
                ProductSaver::refreshVariable($product);
            }
        });

        return $count;
    }

    /** Simple products and the variants of products with variants. @return Collection<int, Product|ProductVariation> */
    protected static function items(array $ids): Collection
    {
        static::$names ??= new \WeakMap;
        $products = Product::query()->whereIn('id', $ids)->with('variations')->orderBy('name')
            ->get(['id', 'name', 'type', 'sku', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'price', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders']);
        $items = collect();
        foreach ($products as $product) {
            if ($product->type === 'variable') {
                foreach ($product->variations as $variation) {
                    static::$names[$variation] = $product->name.' – '.static::optionText($variation);
                    $items->push($variation);
                }
            } else {
                $items->push($product);
            }
        }

        return $items;
    }

    protected static function optionText(ProductVariation $variation): string
    {
        return collect((array) $variation->options)->map(fn ($v) => str_replace('-', ' ', (string) $v))->implode(', ') ?: 'Variant '.$variation->id;
    }

    /** @return array{name:string, old:?float, new:?float, error:?string}|null null = nothing to change */
    public static function compute(Product|ProductVariation $item, array $params): ?array
    {
        $field = $params['field'];
        $amount = (float) $params['amount'];
        $old = $item->{$field} !== null ? (float) $item->{$field} : null;
        $name = $item instanceof ProductVariation ? (string) (static::$names[$item] ?? 'Variant '.$item->id) : $item->name;

        if ($old === null && $params['mode'] !== 'set') {
            return $field === 'sale_price' ? null : ['name' => $name, 'old' => null, 'new' => null, 'error' => 'No price yet'];
        }

        $new = match ($params['mode']) {
            'increase_percent' => $old * (1 + $amount / 100),
            'decrease_percent' => $old * (1 - $amount / 100),
            'increase_amount' => $old + $amount,
            'decrease_amount' => $old - $amount,
            'set' => $amount,
        };
        $new = static::round($new, $params['round'] ?? 'none');

        $error = null;
        if ($new < 0) {
            $error = 'Would go below £0';
        } elseif ($field === 'sale_price' && $item->regular_price !== null && $new >= (float) $item->regular_price) {
            $error = 'Sale price would be ≥ price';
        }

        return ['name' => $name, 'sku' => $item->sku, 'old' => $old, 'new' => $new, 'error' => $error];
    }

    public static function round(float $value, string $mode): float
    {
        return match ($mode) {
            '99' => $value < 1 ? round($value, 2) : floor($value) + 0.99,
            'whole' => round($value),
            default => round($value, 2),
        };
    }
}
