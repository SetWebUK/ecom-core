{{--
    Dialogs for the Products list bulk actions that need input (included inside the bulk bar, so `selected` – the ticked
    product ids from bulkTable – is in scope; each dialog is teleported to <body> and posts its own form).
--}}
@php use Pine\Commerce\Services\Admin\Catalogue\PriceAdjuster; @endphp

{{-- Change prices --}}
<template x-teleport="body">
    <x-admin.modal name="bulk-price" title="Change prices" size="lg">
        <form method="POST" action="{{ route('admin.products.bulk') }}" id="bulk-price-form" x-data="bulkPrice(@js(['previewUrl' => route('admin.products.price-preview')]))"
              data-confirm-title="Change these prices?" data-confirm="The new prices show in the shop straight away." data-confirm-button="Change prices" data-confirm-danger="false">
            @csrf
            <input type="hidden" name="action" value="adjust_price">
            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <p class="text-sm text-muted mb-4">Applies to the <strong x-text="selected.length"></strong> selected <span x-text="selected.length === 1 ? 'product' : 'products'"></span>. Products with variants are changed variant by variant.</p>
            <div class="form-grid form-grid--3">
                <div class="field">
                    <label class="field__label" for="bp-field">Which price</label>
                    <select id="bp-field" name="field" class="select" x-model="field" @change="run()">
                        @foreach (PriceAdjuster::FIELDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="bp-mode">Change</label>
                    <select id="bp-mode" name="mode" class="select" x-model="mode" @change="run()">
                        @foreach (PriceAdjuster::MODES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="bp-amount">Amount</label>
                    <div class="input-group">
                        <span class="input-group__addon" x-show="!isPercent">£</span>
                        <input id="bp-amount" name="amount" type="text" inputmode="decimal" class="input input--num" x-model="amount" @input="load()" placeholder="0" required autocomplete="off">
                        <span class="input-group__addon" x-show="isPercent" x-cloak>%</span>
                    </div>
                </div>
            </div>
            <div class="field mt-3" style="max-width:240px">
                <label class="field__label" for="bp-round">Rounding</label>
                <select id="bp-round" name="round" class="select" x-model="round" @change="run()">
                    @foreach (PriceAdjuster::ROUNDING as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>

            <div class="mt-4">
                <div class="row row--between mb-2">
                    <span class="fw-600 text-sm">Preview</span>
                    <span class="text-xs text-muted" x-show="loading"><span class="spinner spinner--sm" style="display:inline-block;vertical-align:-2px"></span> Working it out…</span>
                </div>
                <template x-if="!preview">
                    <p class="text-sm text-muted">Enter an amount to see the new prices before you apply them.</p>
                </template>
                <template x-if="preview">
                    <div>
                        <p class="text-sm mb-2">
                            <strong x-text="preview.changed"></strong> <span x-text="preview.changed === 1 ? 'price will change' : 'prices will change'"></span><template x-if="preview.skipped"><span class="text-warning">, <span x-text="preview.skipped"></span> will be skipped</span></template>.
                        </p>
                        <div class="preview-box">
                            <table class="preview-table">
                                <thead><tr><th>Product</th><th class="num">Now</th><th class="num">New</th></tr></thead>
                                <tbody>
                                    <template x-for="(row, i) in preview.rows" :key="i">
                                        <tr>
                                            <td><span x-text="row.name"></span><template x-if="row.error"><span class="text-xs text-warning" style="display:block" x-text="'Skipped: ' + row.error"></span></template></td>
                                            <td class="num diff-old" x-text="row.old"></td>
                                            <td class="num" :class="row.error ? 'text-subtle' : 'diff-new'" x-text="row.error ? '—' : row.new"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-muted mt-2" x-show="preview.more" x-text="'…and ' + preview.more + ' more.'"></p>
                        <p class="text-xs text-muted mt-2" x-show="field === 'regular_price'">If a sale price ends up at or above the new price, that sale is removed.</p>
                    </div>
                </template>
            </div>
        </form>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button type="submit" form="bulk-price-form" variant="primary">Apply to <span x-text="selected.length"></span> products</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>
</template>

{{-- Put on sale --}}
<template x-teleport="body">
    <x-admin.modal name="bulk-sale" title="Put on sale" size="sm">
        <form method="POST" action="{{ route('admin.products.bulk') }}" id="bulk-sale-form" x-data="{ percent: '' }">
            @csrf
            <input type="hidden" name="action" value="set_sale">
            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <p class="text-sm text-muted mb-4">Sets a sale price on the <strong x-text="selected.length"></strong> selected products (and all their variants), worked out from each regular price.</p>
            <div class="stack-fields">
                <div class="field">
                    <label class="field__label" for="bs-percent">Discount</label>
                    <div class="input-group" style="max-width:160px">
                        <input id="bs-percent" name="percent" type="text" inputmode="decimal" class="input input--num" x-model="percent" placeholder="10" required autocomplete="off">
                        <span class="input-group__addon">% off</span>
                    </div>
                    <p class="field__help" x-show="parseFloat(percent) > 0" x-text="'e.g. £100.00 becomes ' + Admin.money(100 * (1 - (parseFloat(percent) || 0) / 100))"></p>
                </div>
                <x-admin.input type="date" name="sale_ends_at" label="Sale ends" optional help="UK time, end of the day. Leave blank to keep the sale until you end it." />
            </div>
        </form>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button type="submit" form="bulk-sale-form" variant="primary">Put on sale</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>
</template>

{{-- Add to / remove from a category --}}
<template x-teleport="body">
    <x-admin.modal name="bulk-category" title="Categories" size="sm">
        <form method="POST" action="{{ route('admin.products.bulk') }}" id="bulk-category-form" x-data="{ action: 'add_category' }">
            @csrf
            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <div class="stack-fields">
                <x-admin.radio-cards name="action" x-model="action" value="add_category" :options="[
                    'add_category' => ['label' => 'Add to a category', 'icon' => 'folder-plus'],
                    'remove_category' => ['label' => 'Remove from a category', 'icon' => 'folder-minus'],
                ]" />
                <x-admin.select name="category_id" label="Category" :options="Pine\Commerce\Services\Admin\CategoryTree::options()" placeholder="Choose a category…" required />
                <p class="text-xs text-muted" x-show="action === 'remove_category'">Products whose main category is removed switch to another of their categories.</p>
            </div>
        </form>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button type="submit" form="bulk-category-form" variant="primary">Apply to <span x-text="selected.length"></span> products</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>
</template>
