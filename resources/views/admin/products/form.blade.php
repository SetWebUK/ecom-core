{{-- Product editor – one long page (main column + side panel) with a sticky "Unsaved changes" bar. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\LocalTime;
    use Pine\Commerce\Services\Admin\OrderStatus;

    $editing = $product->exists;
    $money = fn ($v) => $v !== null && $v !== '' ? number_format((float) $v, 2, '.', '') : '';

    // Attribute rows / variants / specs: old input after a failed save, else the saved data
    $oldRows = old('product_attributes');
    $rows = is_array($oldRows)
        ? collect($oldRows)->filter(fn ($r) => is_array($r) && ! empty($r['attribute_id']))->map(fn ($r) => [
            'attribute_id' => (int) $r['attribute_id'],
            'values' => array_values(array_map('intval', array_filter((array) ($r['values'] ?? []), 'is_numeric'))),
            'visible' => filter_var($r['visible'] ?? true, FILTER_VALIDATE_BOOL),
            'variation' => filter_var($r['variation'] ?? false, FILTER_VALIDATE_BOOL),
        ])->values()->all()
        : $attributeRows;
    $oldVariations = old('variations');
    $variantData = is_array($oldVariations)
        ? collect($oldVariations)->filter(fn ($v) => is_array($v))->map(fn ($v) => [
            'id' => ! empty($v['id']) ? (int) $v['id'] : null,
            'options' => (object) ((array) json_decode((string) ($v['options'] ?? ''), true)),
            'sku' => (string) ($v['sku'] ?? ''),
            'regular_price' => (string) ($v['regular_price'] ?? ''),
            'sale_price' => (string) ($v['sale_price'] ?? ''),
            'stock_quantity' => (string) ($v['stock_quantity'] ?? ''),
            'stock_status' => (string) ($v['stock_status'] ?? 'instock'),
            'image' => (string) ($v['image'] ?? ''),
            'image_url' => ! empty($v['image']) ? media_url($v['image']) : null,
            'tax_class' => (string) ($v['tax_class'] ?? ''),
            'is_active' => filter_var($v['is_active'] ?? true, FILTER_VALIDATE_BOOL),
        ])->values()->all()
        : $variations;
    $oldSpecs = old('specs');
    $specData = is_array($oldSpecs)
        ? collect($oldSpecs)->filter(fn ($v) => is_array($v))->map(fn ($s) => ['key' => (string) ($s['key'] ?? ''), 'label' => (string) ($s['label'] ?? ''), 'value' => (string) ($s['value'] ?? ''), 'description' => (string) ($s['description'] ?? '')])->values()->all()
        : $product->specs->map(fn ($s) => ['key' => (string) $s->key, 'label' => (string) $s->label, 'value' => (string) $s->value, 'description' => (string) $s->description])->values()->all();

    $categoryIds = collect(old('category_ids', $product->categories->pluck('id')->all()))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->values()->all();

    $config = [
        'type' => old('type', $product->type ?: 'simple'),
        'name' => (string) old('name', $product->name),
        'slug' => (string) old('slug', $product->slug),
        'slugAuto' => ! $editing && ! old('slug'),
        'originalSlug' => $editing ? $product->slug : '',
        'siteUrl' => rtrim(url('/'), '/'),
        'categoryPaths' => $categories->pluck('path', 'id'),
        'categoryNames' => $categories->pluck('name', 'id'),
        'categoryIds' => $categoryIds,
        'primaryId' => old('primary_category_id', $product->primary_category_id),
        'regular' => (string) old('regular_price', $money($product->regular_price)),
        'sale' => (string) old('sale_price', $money($product->sale_price)),
        'cost' => (string) old('cost_price', $money($product->cost_price)),
        'schedule' => (bool) old('schedule_sale', $product->sale_starts_at || $product->sale_ends_at),
        'manageStock' => (bool) old('manage_stock', $product->manage_stock),
        'attributes' => $attributes,
        'rows' => $rows,
        'variations' => $variantData,
        'specs' => $specData,
        'valueUrl' => route('admin.attributes.values.store', ['attribute' => '__ID__']),
        'attributeUrl' => route('admin.attributes.store'),
        'productSku' => (string) $product->sku,
    ];
    $saveLabel = $editing ? 'Save' : 'Save product';
    $storeUrl = $editing ? $product->url : null;
@endphp

@section('title', $editing ? $product->name.' · Products' : 'Add product')

@section('content')
    @include('commerce::admin.products.partials.assets', ['sortable' => true])

    <x-admin.page-header :title="$editing ? $product->name : 'Add product'" :back="route('admin.products.index')" back-label="Back to products">
        @if ($editing)
            <x-slot:badges>
                <x-admin.status-badge type="product" :status="$product->status" />
                @if ($product->type === 'variable')<x-admin.badge color="info">{{ $product->variations->count() }} {{ Str::plural('variant', $product->variations->count()) }}</x-admin.badge>@endif
            </x-slot:badges>
            <x-slot:actions>
                <x-admin.button :href="$storeUrl" icon="arrow-top-right-on-square" target="_blank" rel="noopener">
                    <span class="view-store-label">{{ $product->status === 'published' ? 'View in shop' : 'Preview' }}</span>
                </x-admin.button>
                <x-admin.dropdown label="More actions">
                    <x-admin.confirm as="menu-item" :action="route('admin.products.duplicate', $product)" method="POST" icon="document-duplicate" :danger="false"
                                     title="Duplicate this product?" message="You’ll get a draft copy with the same photos, details, categories and variants to edit." confirm-label="Duplicate">Duplicate</x-admin.confirm>
                    <x-admin.dropdown-item :href="route('admin.products.create')" icon="plus">Add another product</x-admin.dropdown-item>
                    <div class="dropdown__sep"></div>
                    <x-admin.confirm as="menu-item" :action="route('admin.products.destroy', $product)" icon="trash"
                                     :title="'Delete '.($product->name).'?'" message="It disappears from the shop straight away. You can restore it from the Deleted tab of the products list; past orders are not affected." confirm-label="Delete product">Delete product</x-admin.confirm>
                </x-admin.dropdown>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($editing && $waitingAlerts > 0)
        <x-admin.callout type="info" class="mb-4" icon="bell-alert" :title="($waitingAlerts).' '.(Str::plural('customer', $waitingAlerts)).' waiting for this to come back in stock'">
            They’re emailed automatically when you save it as in stock. @if (commerce_feature('stock_alerts', false))<a href="{{ route('admin.stock-alerts.index', ['q' => $product->sku ?: $product->name]) }}">See stock alerts</a>@endif
        </x-admin.callout>
    @endif

    <x-admin.form id="product-form" :action="$editing ? route('admin.products.update', $product) : route('admin.products.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <div class="layout" x-data="productForm(@js($config))">
            <div class="layout__main">
                @include('commerce::admin.products.partials.form-main')
            </div>
            <div class="layout__aside">
                @include('commerce::admin.products.partials.form-aside')
            </div>
        </div>
    </x-admin.form>

    <div class="form-actions">
        @if ($editing)
            <x-admin.confirm :action="route('admin.products.destroy', $product)" variant="ghost-danger" icon="trash"
                             :title="'Delete '.($product->name).'?'" confirm-label="Delete product"
                             message="It disappears from the shop straight away. You can restore it from the Deleted tab of the products list; past orders are not affected.">Delete product</x-admin.confirm>
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.products.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="product-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection
