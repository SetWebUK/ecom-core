{{--
    One editable inventory row (product without variants, or one variant). Inputs save on change via PATCH JSON:
    products.quick for products, variations.update for variants (x-data="stockCell(...)").
    Expects: $item (Product|ProductVariation), $url, $variant (bool), $title, $image, $editUrl, $backorders
--}}
@php
    $stock = Pine\Commerce\Services\Admin\CatalogueTools::stock($item);
    $fmt = fn ($v) => $v !== null ? number_format((float) $v, 2, '.', '') : '';
    $managed = (bool) $item->manage_stock && $item->stock_quantity !== null;
    $row = [
        'regular_price' => $fmt($item->regular_price),
        'sale_price' => $fmt($item->sale_price),
        'manage_stock' => $managed,
        'stock_quantity' => $managed ? (int) $item->stock_quantity : null,
        'stock_status' => $item->stock_status ?: 'instock',
        'stock_label' => $stock['label'],
        'stock_color' => $stock['color'],
    ];
    $key = ($variant ? 'v' : 'p').$item->id;
@endphp
<tr @class(['inv-row--variant' => $variant]) x-data="stockCell(@js(['url' => $url, 'row' => $row]))">
    <td class="stack-title">
        <div class="product-cell">
            @if (! $variant)<x-admin.thumb :src="$image" />@elseif ($image)<x-admin.thumb :src="$image" size="sm" />@endif
            <div class="product-cell__text">
                @if ($variant)
                    <span class="fw-600">{{ $title }}</span>
                    @unless ($item->is_active)<x-admin.badge size="sm">Inactive</x-admin.badge>@endunless
                @else
                    <a class="product-cell__name" href="{{ $editUrl }}">{{ $title }}</a>
                    @if ($item->status !== 'published')<div class="product-cell__meta"><x-admin.status-badge type="product" :status="$item->status" size="sm" /></div>@endif
                @endif
            </div>
        </div>
    </td>
    <td data-label="SKU" class="mono text-sm">{{ $item->sku ?: '—' }}</td>
    <td data-label="Status" class="nowrap">
        <template x-if="row.manage_stock">
            <span class="badge" :class="'badge--' + row.stock_color"><span class="badge__dot" aria-hidden="true"></span><span x-text="row.stock_label">{{ $stock['label'] }}</span></span>
        </template>
        <template x-if="!row.manage_stock">
            <select class="select select--sm" x-model="status" @change="saveStatus()" aria-label="Stock status of {{ $title }}">
                @foreach (Pine\Commerce\Services\Admin\OrderStatus::STOCK_STATUSES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </template>
    </td>
    <td data-label="Quantity">
        <div class="inv-qty">
            <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="step(-1)" aria-label="One less {{ $title }}" title="One less"><x-admin.icon name="minus" size="sm" /></button>
            <input type="number" step="1" inputmode="numeric" class="input input--sm input--num inv-input" x-model="qty" @change="saveQty()" @keydown.enter.prevent="$event.target.blur()"
                   placeholder="—" title="Leave empty to not track a quantity" aria-label="Quantity of {{ $title }}" value="{{ $managed ? (int) $item->stock_quantity : '' }}"
                   :class="{ 'is-invalid': state === 'error' }">
            <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="step(1)" aria-label="One more {{ $title }}" title="One more"><x-admin.icon name="plus" size="sm" /></button>
        </div>
    </td>
    <td data-label="Price" class="num">
        <div class="input-group" style="display:inline-flex;width:auto"><span class="input-group__addon">£</span>
            <input type="text" inputmode="decimal" class="input input--sm input--num inv-input input--price" x-model="regular" @change="savePrice('regular_price')" @keydown.enter.prevent="$event.target.blur()"
                   placeholder="0.00" aria-label="Price of {{ $title }}" value="{{ $row['regular_price'] }}">
        </div>
    </td>
    <td data-label="Sale price" class="num">
        <div class="input-group" style="display:inline-flex;width:auto"><span class="input-group__addon">£</span>
            <input type="text" inputmode="decimal" class="input input--sm input--num inv-input input--price" x-model="sale" @change="savePrice('sale_price')" @keydown.enter.prevent="$event.target.blur()"
                   placeholder="—" aria-label="Sale price of {{ $title }}" value="{{ $row['sale_price'] }}">
        </div>
    </td>
    <td class="table__actions">
        <span class="save-state" :class="'save-state--' + state" aria-live="polite">
            <template x-if="state === 'saving'"><span class="spinner spinner--sm" role="status" aria-label="Saving"></span></template>
            <template x-if="state === 'saved'"><span title="Saved"><x-admin.icon name="check-circle" label="Saved" /></span></template>
            <template x-if="state === 'error'"><span title="Not saved"><x-admin.icon name="exclamation-circle" label="Not saved" /></span></template>
        </span>
    </td>
</tr>
