{{-- Products list: status tabs, filters, sortable table with inline quick edit, featured stars, bulk actions and CSV export. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Services\Admin\Catalogue\PriceAdjuster;
    use Pine\Commerce\Services\Admin\Catalogue\ProductFilter;
    use Pine\Commerce\Services\Admin\CatalogueTools;
    use Pine\Commerce\Services\Admin\OrderStatus;
    use Pine\Commerce\Http\Controllers\Admin\ProductController;

    $trashed = $filter->tab === 'trashed';
    $exportQuery = request()->except(['page', 'per_page']);
@endphp

@section('title', 'Products')

@section('content')
    @include('commerce::admin.products.partials.assets')

    <x-admin.page-header title="Products" :subtitle="number_format($counts['all']).' products · '.number_format($counts['active']).' active · '.number_format($counts['outofstock']).' out of stock'">
        <x-slot:actions>
            <x-admin.button :href="route('admin.products.inventory')" icon="clipboard-document-list">Inventory</x-admin.button>
            <x-admin.button :href="route('admin.products.export', $exportQuery)" icon="arrow-down-tray" label="Export these products as CSV">Export</x-admin.button>
            @if (commerce_feature('product_csv', false) && Route::has('admin.products.csv'))
                <x-admin.button :href="route('admin.products.csv', $exportQuery)" icon="arrow-up-tray" label="Import or export the full product CSV">Import / Export</x-admin.button>
            @endif
            <x-admin.button variant="primary" icon="plus" :href="route('admin.products.create')">Add product</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($counts['all'] === 0 && $counts['trashed'] === 0)
        <div class="card">
            <x-admin.empty icon="tag" title="Add your first product" description="Products you add here appear in the shop once they’re published.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.products.create')">Add product</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush class="filters-wrap">
            <x-admin.status-tabs :tabs="$tabs" :current="$filter->tab" />
            <x-admin.filters placeholder="Search by name or SKU" :chips="$filter->chips()" keep="status">
                <x-admin.filter-select name="category" :options="$categoryOptions" placeholder="All categories" label="Category (includes sub-categories)" />
                <x-admin.filter-select name="stock" :options="ProductFilter::STOCK" placeholder="Any stock" label="Stock" />
                <x-admin.filter-select name="type" :options="ProductFilter::TYPES" placeholder="Any type" label="Product type" />
                @if (commerce_feature('product_condition') && ProductFilter::conditionOptions())
                    <x-admin.filter-select name="condition" :options="array_combine(ProductFilter::conditionOptions(), ProductFilter::conditionOptions())" placeholder="Any condition" label="Condition" />
                @endif
                @if (commerce_feature('product_brand') && ProductFilter::brandOptions())
                    <x-admin.filter-select name="brand" :options="array_combine(ProductFilter::brandOptions(), ProductFilter::brandOptions())" placeholder="Any brand" label="Brand" />
                @endif
                <div class="input-group" style="width:auto">
                    <span class="input-group__addon">£</span>
                    <label class="sr-only" for="filter-price_min">Minimum price</label>
                    <input type="text" inputmode="decimal" name="price_min" id="filter-price_min" class="input input--sm input--num" style="width:72px" placeholder="Min" value="{{ $filter->priceMin !== null ? $filter->priceMin : '' }}">
                    <span class="input-group__addon">–</span>
                    <label class="sr-only" for="filter-price_max">Maximum price</label>
                    <input type="text" inputmode="decimal" name="price_max" id="filter-price_max" class="input input--sm input--num" style="width:72px" placeholder="Max" value="{{ $filter->priceMax !== null ? $filter->priceMax : '' }}">
                </div>
            </x-admin.filters>

            @if ($products->isEmpty())
                @if ($trashed)
                    <x-admin.empty icon="trash" title="No deleted products" description="Products you delete appear here so you can restore them." size="sm" />
                @else
                    <x-admin.empty icon="magnifying-glass" title="No products match" description="Try a different search or remove some filters." size="sm">
                        <x-admin.button :href="route('admin.products.index')">Clear filters</x-admin.button>
                    </x-admin.empty>
                @endif
            @else
                <x-admin.table :ids="$products->pluck('id')" selectable :bulk-action="route('admin.products.bulk')" stack class="product-table">
                    <x-slot:bulk>
                        @if ($trashed)
                            <x-admin.button type="submit" name="action" value="restore" size="sm" icon="arrow-uturn-left">Restore</x-admin.button>
                        @else
                            <x-admin.button type="submit" name="action" value="publish" size="sm" icon="eye">Publish</x-admin.button>
                            <x-admin.button type="submit" name="action" value="draft" size="sm" icon="eye-slash">Set as draft</x-admin.button>
                            <x-admin.dropdown label="More actions" size="sm" align="left">
                                <div class="dropdown__label">Stock</div>
                                <x-admin.dropdown-item type="submit" name="action" value="instock" icon="check-circle">Mark in stock</x-admin.dropdown-item>
                                <x-admin.dropdown-item type="submit" name="action" value="outofstock" icon="x-circle">Mark out of stock</x-admin.dropdown-item>
                                <div class="dropdown__sep"></div>
                                <div class="dropdown__label">Prices</div>
                                <x-admin.dropdown-item icon="calculator" x-on:click="close(); $dispatch('open-modal', 'bulk-price')">Change prices…</x-admin.dropdown-item>
                                <x-admin.dropdown-item icon="receipt-percent" x-on:click="close(); $dispatch('open-modal', 'bulk-sale')">Put on sale…</x-admin.dropdown-item>
                                <x-admin.dropdown-item type="submit" name="action" value="end_sale" icon="x-mark">End sale</x-admin.dropdown-item>
                                <div class="dropdown__sep"></div>
                                <div class="dropdown__label">Organise</div>
                                <x-admin.dropdown-item icon="folder-plus" x-on:click="close(); $dispatch('open-modal', 'bulk-category')">Add to / remove from category…</x-admin.dropdown-item>
                                <x-admin.dropdown-item type="submit" name="action" value="feature" icon="star">Mark as featured</x-admin.dropdown-item>
                                <x-admin.dropdown-item type="submit" name="action" value="unfeature" icon="minus-circle">Remove from featured</x-admin.dropdown-item>
                                <div class="dropdown__sep"></div>
                                <a class="dropdown__item" role="menuitem" :href="@js(route('admin.products.export')).concat('?ids=', selected.join(','))"><x-admin.icon name="arrow-down-tray" />Export selected (CSV)</a>
                            </x-admin.dropdown>
                            <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                            data-confirm-title="Delete the selected products?" data-confirm="They disappear from the shop straight away. You can restore them from the Deleted tab; past orders are not affected."
                                            data-confirm-button="Delete products">Delete</x-admin.button>

                            @include('commerce::admin.products.partials.bulk-modals')
                        @endif
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="name" class="col-product">Product</x-admin.th>
                        <x-admin.th>Status</x-admin.th>
                        <x-admin.th sort="stock" first="asc">Stock</x-admin.th>
                        <x-admin.th sort="price" align="right" first="desc">Price</x-admin.th>
                        <x-admin.th class="hidden-mobile">Categories</x-admin.th>
                        <x-admin.th sort="total_sales" align="right" first="desc" class="hidden-mobile">Sales</x-admin.th>
                        <x-admin.th><span class="sr-only">Featured and actions</span></x-admin.th>
                    </x-slot:head>

                    @foreach ($products as $product)
                        @php
                            $image = $product->images->first();
                            $variable = $product->type === 'variable';
                            $row = ProductController::rowData($product);
                            if ($variable) {
                                $active = $product->variations->where('is_active', true);
                                $available = $active->filter(fn ($v) => in_array($v->stock_status, ['instock', 'onbackorder'], true))->count();
                                $prices = $active->map->currentPrice()->filter(fn ($p) => $p !== null);
                            }
                            $categoryNames = $product->categories->pluck('name');
                            $primaryName = $product->primaryCategory?->name ?? $categoryNames->first();
                        @endphp
                        <tr @unless ($variable || $trashed) x-data="quickEdit(@js(['url' => route('admin.products.quick', $product), 'row' => $row, 'popId' => 'qe-'.$product->id]))" @endunless>
                            <x-admin.row-check :id="$product->id" :label="'Select '.$product->name" />
                            <td class="stack-title col-product">
                                <div class="product-cell">
                                    <x-admin.thumb :src="$image?->path" :alt="$image?->alt ?? ''" />
                                    <div class="product-cell__text">
                                        @if ($trashed)
                                            <span class="product-cell__name">{{ $product->name }}</span>
                                        @else
                                            <a href="{{ route('admin.products.edit', $product) }}" class="product-cell__name">{{ $product->name }}</a>
                                        @endif
                                        <div class="product-cell__meta">
                                            @if ($product->sku)<span class="mono">{{ $product->sku }}</span>@endif
                                            @if ($variable)<x-admin.badge size="sm" color="info">{{ $product->variations->count() }} {{ Str::plural('variant', $product->variations->count()) }}</x-admin.badge>@endif
                                            @if ($product->subtitle)<span class="truncate" style="max-width:280px">{{ $product->subtitle }}</span>@endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Status">
                                @if ($trashed)
                                    <x-admin.badge color="danger" dot>Deleted</x-admin.badge>
                                @else
                                    <x-admin.status-badge type="product" :status="$product->status" />
                                @endif
                            </td>
                            <td data-label="Stock" class="nowrap">
                                @if ($variable)
                                    <x-admin.badge :color="$available ? 'success' : 'danger'" dot>{{ $available }} of {{ $active->count() }} in stock</x-admin.badge>
                                @elseif ($trashed)
                                    <x-admin.badge :color="$row['stock_color']" dot>{{ $row['stock_label'] }}</x-admin.badge>
                                @else
                                    <button type="button" class="cell-button" data-qe-toggle @click="toggleEditor()" aria-label="Edit stock of {{ $product->name }}">
                                        <span class="badge" :class="'badge--' + row.stock_color"><span class="badge__dot" aria-hidden="true"></span><span x-text="row.stock_label">{{ $row['stock_label'] }}</span></span>
                                        <x-admin.icon name="pencil" />
                                    </button>
                                @endif
                            </td>
                            <td class="num" data-label="Price">
                                @if ($variable)
                                    @if ($prices->isNotEmpty())
                                        <span class="text-muted text-xs">{{ $prices->min() == $prices->max() ? '' : 'From ' }}</span>{{ money($prices->min()) }}
                                    @else
                                        <span class="text-subtle">—</span>
                                    @endif
                                @elseif ($trashed)
                                    {{ $row['price'] !== null ? money($row['price']) : '—' }}
                                @else
                                    <div class="qe">
                                        <button type="button" class="cell-button" x-ref="trigger" data-qe-toggle @click="toggleEditor()" aria-label="Edit price of {{ $product->name }}" :aria-expanded="open.toString()">
                                            <x-admin.icon name="pencil" />
                                            <span class="price-stack">
                                                <template x-if="row.on_sale"><span class="price-stack__was" x-text="Admin.money(row.regular_price)"></span></template>
                                                <span :class="{ 'price-stack__sale': row.on_sale }" x-text="priceText">
                                                    @if ($row['on_sale'])<span class="price-stack__was">{{ money($product->regular_price) }}</span>@endif{{ $row['price'] !== null ? money($row['price']) : '—' }}
                                                </span>
                                            </span>
                                        </button>
                                        @include('commerce::admin.products.partials.quick-edit', ['product' => $product])
                                    </div>
                                @endif
                            </td>
                            <td class="hidden-mobile col-cats" data-label="Categories">
                                @if ($primaryName)
                                    <span class="truncate" style="display:block;max-width:200px" title="{{ $categoryNames->implode(', ') }}">{{ $primaryName }}@if ($categoryNames->count() > 1)<span class="text-subtle"> +{{ $categoryNames->count() - 1 }}</span>@endif</span>
                                @else
                                    <span class="text-subtle">None</span>
                                @endif
                            </td>
                            <td class="num hidden-mobile" data-label="Sales">{{ number_format($product->total_sales) }}</td>
                            <td class="table__actions" data-label="">
                                <div class="inline-actions">
                                    @if ($trashed)
                                        <x-admin.confirm :action="route('admin.products.restore', $product->id)" method="POST" size="sm" icon="arrow-uturn-left" :danger="false"
                                                         :title="'Restore '.($product->name).'?'" message="It comes back with the status it had before (published products reappear in the shop)." confirm-label="Restore">Restore</x-admin.confirm>
                                    @else
                                        <span x-data="featuredToggle(@js(['url' => route('admin.products.featured', $product), 'featured' => (bool) $product->is_featured]))">
                                            <button type="button" class="star-btn" :class="{ 'is-on': featured }" @click="flip()" :aria-pressed="featured.toString()"
                                                    aria-label="Featured: {{ $product->name }}" :title="featured ? 'Featured – click to remove' : 'Mark as featured'">
                                                <x-admin.icon name="star" variant="mini" />
                                            </button>
                                        </span>
                                        <a href="{{ $product->url }}" target="_blank" rel="noopener" class="btn btn--ghost btn--icon btn--sm" aria-label="View {{ $product->name }} in the shop" title="View in the shop"><x-admin.icon name="arrow-top-right-on-square" /></a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$products" />
            @endif
        </x-admin.card>
    @endif
@endsection
