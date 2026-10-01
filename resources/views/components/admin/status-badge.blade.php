{{--
    Status badges with the shared colours from Pine\Commerce\Services\Admin\OrderStatus.
    <x-admin.status-badge :status="$order->status" />                       order status (default)
    <x-admin.status-badge type="stock" :status="$product->stock_status" />   instock | outofstock | onbackorder
    <x-admin.status-badge type="product" :status="$product->status" />       published | draft | private
--}}
@props(['status', 'type' => 'order', 'size' => null])
@php
    [$label, $color] = match ($type) {
        'stock' => [\Pine\Commerce\Services\Admin\OrderStatus::STOCK_STATUSES[$status] ?? ucfirst((string) $status), \Pine\Commerce\Services\Admin\OrderStatus::stockColor($status)],
        'product' => [\Pine\Commerce\Services\Admin\OrderStatus::PRODUCT_STATUSES[$status] ?? ucfirst((string) $status), \Pine\Commerce\Services\Admin\OrderStatus::productStatusColor($status)],
        default => [\Pine\Commerce\Services\Admin\OrderStatus::label($status), \Pine\Commerce\Services\Admin\OrderStatus::color($status)],
    };
@endphp
<x-admin.badge :color="$color" :size="$size" dot {{ $attributes }}>{{ $label }}</x-admin.badge>
