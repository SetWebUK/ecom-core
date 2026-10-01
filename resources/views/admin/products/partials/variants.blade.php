{{--
    Product editor: Variants card (products with variants only). Options = attribute rows flagged "variation"
    (product_attributes[i][variation]=1). Each variant posts variations[i][id|options(JSON)|sku|regular_price|sale_price|
    stock_quantity|stock_status|image|tax_class|is_active]; ProductSaver::syncVariations() updates/creates/deletes them.
--}}
@php $variantErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'variations'))->flatten()->unique(); @endphp
<template x-if="type === 'variable'">
    <x-admin.card title="Variants" subtitle="Versions of this product customers choose between, e.g. memory size or colour." flush>
        {{-- Options --}}
        <div class="card__section">
            <div class="row row--between mb-2">
                <h3 class="card__section-title" style="margin:0">Options</h3>
                <button type="button" class="btn btn--sm btn--plain" @click="addRow(true)"><x-admin.icon name="plus" /><span>Add option</span></button>
            </div>
            <template x-if="!variantRows.length && !rows.some(r => r.variation)">
                <p class="text-sm text-muted">Add the options customers pick from, e.g. <em>Memory</em> with the values 8GB and 16GB.</p>
            </template>
            <div class="attr-rows">
                <template x-for="row in rows.filter(r => r.variation)" :key="row.uid">
                    @include('commerce::admin.products.partials.attribute-row', ['variant' => true])
                </template>
            </div>
            <div class="row mt-3">
                <button type="button" class="btn btn--primary btn--sm" @click="generate()"><x-admin.icon name="sparkles" /><span>Create variants from options</span></button>
                <span class="text-xs text-muted">Adds every combination that doesn’t exist yet.</span>
            </div>
        </div>

        {{-- Bulk edit --}}
        <div class="variant-bulk" x-show="variations.length > 1">
            <div class="field" style="margin:0">
                <label class="field__label" for="variant-bulk-price">Set every price to</label>
                <div class="input-group">
                    <span class="input-group__addon">£</span>
                    <input id="variant-bulk-price" type="text" inputmode="decimal" class="input input--num" x-model="bulkPrice" placeholder="0.00" @keydown.enter.prevent="applyAll('regular_price')">
                    <button type="button" class="btn btn--sm" @click="applyAll('regular_price')">Apply</button>
                </div>
            </div>
            <div class="field" style="margin:0">
                <label class="field__label" for="variant-bulk-stock">Set every quantity to</label>
                <div class="input-group">
                    <input id="variant-bulk-stock" type="number" step="1" inputmode="numeric" class="input input--num" x-model="bulkStock" placeholder="0" @keydown.enter.prevent="applyAll('stock_quantity')">
                    <button type="button" class="btn btn--sm" @click="applyAll('stock_quantity')">Apply</button>
                </div>
            </div>
        </div>

        {{-- Variant table --}}
        <template x-if="!variations.length">
            <x-admin.empty icon="squares-2x2" title="No variants yet" description="Add options above, then click “Create variants from options”." size="sm" />
        </template>
        <div class="table-wrap table-wrap--sticky-off" x-show="variations.length">
            <table class="variant-table">
                <thead>
                    <tr>
                        <th scope="col">Variant</th>
                        <th scope="col">Image</th>
                        <th scope="col">SKU</th>
                        <th scope="col">Price</th>
                        <th scope="col">Sale price</th>
                        <th scope="col" title="Leave blank to not track a quantity">Stock</th>
                        <th scope="col" title="Leave on “Same as product” unless this variant is taxed differently">Tax class</th>
                        <th scope="col">Active</th>
                        <th scope="col"><span class="sr-only">Remove</span></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(variation, i) in variations" :key="variation.uid">
                        <tr :class="{ 'is-inactive': !variation.is_active }">
                            <td>
                                <span class="variant-name" x-text="optionLabel(variation)"></span>
                                <input type="hidden" :name="'variations[' + i + '][id]'" :value="variation.id || ''">
                                <input type="hidden" :name="'variations[' + i + '][options]'" :value="optionsJson(variation)">
                            </td>
                            <td>
                                <div class="variant-image" @click.outside="imagePop === variation.uid && (imagePop = null)">
                                    <button type="button" class="variant-image__btn" :class="{ 'has-image': variation.image }" @click="imagePop = imagePop === variation.uid ? null : variation.uid"
                                            :aria-label="'Image for ' + optionLabel(variation)" :aria-expanded="(imagePop === variation.uid).toString()">
                                        <template x-if="variation.image_url"><img :src="variation.image_url" alt=""></template>
                                        <template x-if="!variation.image_url"><x-admin.icon name="photo" size="sm" /></template>
                                    </button>
                                    <input type="hidden" :name="'variations[' + i + '][image]'" :value="variation.image || ''">
                                    <div class="variant-image__pop" x-show="imagePop === variation.uid" x-cloak x-transition.opacity.duration.100ms>
                                        <p class="text-xs text-muted mb-2">Choose one of the product’s photos:</p>
                                        <div class="variant-image__grid">
                                            <template x-for="img in (imagePop === variation.uid ? variantImages() : [])" :key="img.path">
                                                <button type="button" :class="{ 'is-selected': variation.image === img.path }" @click="setVariantImage(variation, img)" :aria-label="'Use ' + img.path"><img :src="img.url" alt=""></button>
                                            </template>
                                        </div>
                                        <p class="text-xs text-muted" x-show="imagePop === variation.uid && !variantImages().length">Add photos in the Media section first.</p>
                                        <button type="button" class="btn btn--sm btn--plain mt-2" x-show="variation.image" @click="setVariantImage(variation, null)">No image</button>
                                    </div>
                                </div>
                            </td>
                            <td><input type="text" class="input input--sm input--mono input--sku" :name="'variations[' + i + '][sku]'" x-model="variation.sku" maxlength="100" :aria-label="'SKU of ' + optionLabel(variation)"></td>
                            <td>
                                <div class="input-group"><span class="input-group__addon">£</span>
                                    <input type="text" inputmode="decimal" class="input input--num input--price" :name="'variations[' + i + '][regular_price]'" x-model="variation.regular_price" placeholder="0.00" :aria-label="'Price of ' + optionLabel(variation)">
                                </div>
                            </td>
                            <td>
                                <div class="input-group"><span class="input-group__addon">£</span>
                                    <input type="text" inputmode="decimal" class="input input--num input--price" :name="'variations[' + i + '][sale_price]'" x-model="variation.sale_price" placeholder="—" :aria-label="'Sale price of ' + optionLabel(variation)">
                                </div>
                            </td>
                            <td>
                                <div class="row row--nowrap gap-1">
                                    <input type="number" step="1" inputmode="numeric" class="input input--num input--qty" :name="'variations[' + i + '][stock_quantity]'" x-model="variation.stock_quantity" placeholder="—" :aria-label="'Stock of ' + optionLabel(variation)">
                                    <select class="select select--sm" :name="'variations[' + i + '][stock_status]'" x-model="variation.stock_status" x-show="variation.stock_quantity === '' || variation.stock_quantity === null" :aria-label="'Stock status of ' + optionLabel(variation)">
                                        @foreach (Pine\Commerce\Services\Admin\OrderStatus::STOCK_STATUSES as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </td>
                            <td>
                                <select class="select select--sm" :name="'variations[' + i + '][tax_class]'" x-model="variation.tax_class" :aria-label="'Tax class of ' + optionLabel(variation)">
                                    <option value="">Same as product</option>
                                    @foreach (\Pine\Commerce\Models\TaxClass::options() as $slug => $label)
                                        <option value="{{ $slug }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="hidden" :name="'variations[' + i + '][is_active]'" :value="variation.is_active ? 1 : 0">
                                <label class="switch" :title="variation.is_active ? 'Customers can choose this variant' : 'Hidden from customers'">
                                    <input type="checkbox" role="switch" x-model="variation.is_active" :aria-label="'Active: ' + optionLabel(variation)">
                                    <span class="switch__track" aria-hidden="true"></span>
                                </label>
                            </td>
                            <td class="table__actions">
                                <button type="button" class="btn btn--ghost-danger btn--icon btn--sm" @click="removeVariation(variation)" :aria-label="'Remove ' + optionLabel(variation)" title="Remove variant"><x-admin.icon name="trash" /></button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        @if ($variantErrors->isNotEmpty())
            <div class="card__section">
                @foreach ($variantErrors as $message)
                    <p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>
                @endforeach
            </div>
        @endif
    </x-admin.card>
</template>
