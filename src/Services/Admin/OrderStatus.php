<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\Order;

/** Presentation helpers for order statuses (badge colours, icons, labels) shared across the admin. */
class OrderStatus
{
    public static function options(): array
    {
        return Order::STATUSES;
    }

    public static function label(?string $status): string
    {
        return Order::STATUSES[$status] ?? ucfirst((string) $status);
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            'processing' => 'primary',
            'completed' => 'success',
            'on-hold' => 'warning',
            'pending' => 'gray',
            'cancelled' => 'gray',
            'refunded' => 'danger',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function icon(?string $status): string
    {
        return match ($status) {
            'processing' => 'arrow-path',
            'completed' => 'check-circle',
            'on-hold' => 'pause-circle',
            'pending' => 'clock',
            'cancelled' => 'x-circle',
            'refunded' => 'receipt-refund',
            'failed' => 'exclamation-triangle',
            default => 'question-mark-circle',
        };
    }

    public static function stockColor(?string $status): string
    {
        return match ($status) {
            'instock' => 'success',
            'onbackorder' => 'warning',
            'outofstock' => 'danger',
            default => 'gray',
        };
    }

    public const STOCK_STATUSES = [
        'instock' => 'In stock',
        'outofstock' => 'Out of stock',
        'onbackorder' => 'On backorder',
    ];

    public const PRODUCT_STATUSES = [
        'published' => 'Published',
        'draft' => 'Draft',
        'private' => 'Private',
    ];

    public static function productStatusColor(?string $status): string
    {
        return match ($status) {
            'published' => 'success',
            'draft' => 'gray',
            'private' => 'warning',
            default => 'gray',
        };
    }
}
