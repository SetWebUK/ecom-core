{{-- Inventory: every product and variant with inline stock + price editing, CSV export and import (with a preview). --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Services\Admin\CatalogueTools;
    use Pine\Commerce\Services\Admin\OrderStatus;
    use Pine\Commerce\Http\Controllers\Admin\InventoryController;
    $exportQuery = request()->only(['q', 'stock', 'category']);
@endphp

@section('title', 'Inventory')

@section('content')
    @include('commerce::admin.products.partials.assets')

    <x-admin.page-header title="Inventory" :back="route('admin.products.index')" back-label="Back to products"
                         subtitle="Change stock and prices right here – each change saves as soon as you leave the box.">
        <x-slot:actions>
            <x-admin.button :href="route('admin.products.inventory.export', $exportQuery)" icon="arrow-down-tray">Export CSV</x-admin.button>
            <x-admin.button variant="primary" icon="arrow-up-tray" x-data x-on:click="$dispatch('open-modal', 'inventory-import')">Import CSV</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card flush class="filters-wrap">
        <x-admin.filters placeholder="Search by product name or SKU" :chips="$chips">
            <x-admin.filter-select name="stock" :options="InventoryController::STOCK" placeholder="Any stock" label="Stock" />
            <x-admin.filter-select name="category" :options="$categoryOptions" placeholder="All categories" label="Category (includes sub-categories)" />
        </x-admin.filters>

        @if ($products->isEmpty())
            <x-admin.empty icon="magnifying-glass" :title="$isFiltered ? 'Nothing matches' : 'No products yet'" :description="$isFiltered ? 'Try a different search or filter.' : 'Add products to track their stock here.'" size="sm">
                @if ($isFiltered)<x-admin.button :href="route('admin.products.inventory')">Clear filters</x-admin.button>@endif
            </x-admin.empty>
        @else
            <x-admin.table stack wide>
                <x-slot:head>
                    <x-admin.th sort="name">Product</x-admin.th>
                    <x-admin.th sort="sku">SKU</x-admin.th>
                    <x-admin.th>Status</x-admin.th>
                    <x-admin.th sort="stock_quantity" first="asc">Quantity</x-admin.th>
                    <x-admin.th sort="price" align="right">Price</x-admin.th>
                    <x-admin.th align="right">Sale price</x-admin.th>
                    <x-admin.th><span class="sr-only">Saved</span></x-admin.th>
                </x-slot:head>
                @foreach ($products as $product)
                    @php $image = $product->images->first(); @endphp
                    @if ($product->type === 'variable')
                        <tr>
                            <td class="stack-title" colspan="7">
                                <div class="product-cell">
                                    <x-admin.thumb :src="$image?->path" />
                                    <div class="product-cell__text">
                                        <a class="product-cell__name" href="{{ route('admin.products.edit', $product) }}">{{ $product->name }}</a>
                                        <div class="product-cell__meta">{{ $product->variations->count() }} {{ Str::plural('variant', $product->variations->count()) }}@if ($product->status !== 'published') · <x-admin.status-badge type="product" :status="$product->status" size="sm" />@endif</div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @foreach ($product->variations as $variation)
                            @include('commerce::admin.inventory.partials.row', [
                                'item' => $variation, 'url' => route('admin.variations.update', $variation), 'variant' => true,
                                'title' => $variantLabels[$variation->id] ?? 'Variant', 'image' => $variation->image,
                                'editUrl' => route('admin.products.edit', $product), 'backorders' => 'no',
                            ])
                        @endforeach
                    @else
                        @include('commerce::admin.inventory.partials.row', [
                            'item' => $product, 'url' => route('admin.products.quick', $product), 'variant' => false,
                            'title' => $product->name, 'image' => $image?->path, 'editUrl' => route('admin.products.edit', $product), 'backorders' => $product->backorders,
                        ])
                    @endif
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$products" />
        @endif
    </x-admin.card>
    <p class="text-xs text-muted mt-3">Leave the quantity empty to stop tracking it and set the status by hand. Low stock is highlighted at {{ $threshold }} or fewer (per-product thresholds override this). Customers waiting for an item are emailed when it comes back in stock.</p>

    <x-admin.modal name="inventory-import" title="Import stock and prices" :open="$errors->has('file')">
        <form method="POST" action="{{ route('admin.products.inventory.import') }}" enctype="multipart/form-data" id="import-form">
            @csrf
            <div class="stack-fields">
                <p class="text-sm">Upload a CSV with a header row. Rows are matched to products and variants by <strong>SKU</strong>. You’ll see exactly what will change before anything is saved.</p>
                <table class="preview-table">
                    <thead><tr><th>Column</th><th>What it does</th></tr></thead>
                    <tbody>
                        <tr><td class="mono">sku</td><td>Required – which product or variant.</td></tr>
                        <tr><td class="mono">stock_quantity</td><td>New quantity (turns on stock tracking).</td></tr>
                        <tr><td class="mono">regular_price</td><td>New price.</td></tr>
                        <tr><td class="mono">sale_price</td><td>New sale price; <span class="mono">-</span> removes the sale.</td></tr>
                    </tbody>
                </table>
                <p class="text-xs text-muted">Empty cells leave that value as it is. Tip: <a href="{{ route('admin.products.inventory.export') }}">export the current inventory</a>, edit it in Excel, then save it as “CSV UTF-8” and import it here.</p>
                <x-admin.field label="CSV file" for="f-file" error="file" required>
                    <input type="file" name="file" id="f-file" class="input" accept=".csv,text/csv" required>
                </x-admin.field>
            </div>
        </form>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button type="submit" form="import-form" variant="primary" icon="eye">Preview changes</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>
@endsection
