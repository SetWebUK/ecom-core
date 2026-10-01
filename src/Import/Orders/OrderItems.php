<?php

namespace Pine\Commerce\Import\Orders;

use Pine\Commerce\Import\Data\WcOrderItem;
use Pine\Commerce\Import\Source\WordPressSource;

/** woocommerce_order_items + itemmeta loader shared by both order storages (items live there in HPOS and posts mode). */
final class OrderItems
{
    /** @return array<int,list<WcOrderItem>> order id => items (ascending item id) */
    public static function load(WordPressSource $wp, array $orderIds): array
    {
        $items = [];
        foreach (array_chunk(array_values(array_unique($orderIds)), 1000) as $chunk) {
            foreach ($wp->table('woocommerce_order_items')->whereIn('order_id', $chunk)->orderBy('order_item_id')->get() as $item) {
                $items[(int) $item->order_item_id] = $item;
            }
        }
        $meta = [];
        foreach (array_chunk(array_keys($items), 2000) as $chunk) {
            foreach ($wp->table('woocommerce_order_itemmeta')->whereIn('order_item_id', $chunk)->orderBy('meta_id')->get() as $row) {
                $meta[(int) $row->order_item_id][$row->meta_key] ??= $row->meta_value;
            }
        }
        $out = [];
        foreach ($items as $id => $item) {
            $out[(int) $item->order_id][] = self::item($item, $meta[$id] ?? []);
        }

        return $out;
    }

    public static function item(object $item, array $m): WcOrderItem
    {
        $type = (string) $item->order_item_type;
        [$total, $tax, $subtotal] = match ($type) {
            'shipping' => [(float) ($m['cost'] ?? 0), (float) ($m['total_tax'] ?? 0), (float) ($m['cost'] ?? 0)],
            'coupon' => [(float) ($m['discount_amount'] ?? 0), (float) ($m['discount_amount_tax'] ?? 0), (float) ($m['discount_amount'] ?? 0)],
            'tax' => [(float) ($m['tax_amount'] ?? 0) + (float) ($m['shipping_tax_amount'] ?? 0), 0.0, 0.0],
            default => [(float) ($m['_line_total'] ?? 0), (float) ($m['_line_tax'] ?? 0), (float) ($m['_line_subtotal'] ?? $m['_line_total'] ?? 0)],
        };

        return new WcOrderItem((int) $item->order_item_id, $type, (string) $item->order_item_name,
            (int) ($m['_product_id'] ?? 0), (int) ($m['_variation_id'] ?? 0), (int) ($m['_qty'] ?? 0),
            $subtotal, $total, $tax, $m);
    }
}
