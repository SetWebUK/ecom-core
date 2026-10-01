{{--
    Quick edit popover for one row of the Products list (inside x-data="quickEdit(...)"). Teleported to <body> and
    positioned (fixed) next to the price button, so it is never clipped by the table's horizontal scroll.
--}}
@php $popId = 'qe-'.$product->id; @endphp
<template x-teleport="body">
    <div class="qe__pop" id="{{ $popId }}" x-show="open" x-cloak x-transition.opacity.duration.100ms role="dialog" aria-label="Quick edit: {{ $product->name }}"
         :style="'position:fixed;top:' + pos.top + 'px;left:' + pos.left + 'px'"
         @scroll.window.passive="open && place()" @resize.window="open && place()"
         @click.outside="outside($event)" @keydown.escape.window="open && close(true)">
        <form @submit.prevent="save()" data-no-loading novalidate>
            <p class="fw-600 truncate mb-2" title="{{ $product->name }}">{{ $product->name }}</p>
            <div class="form-grid">
                <div class="field" :class="{ 'is-invalid': errors.regular_price }">
                    <label class="field__label" for="{{ $popId }}-first">Price</label>
                    <div class="input-group"><span class="input-group__addon">£</span>
                        <input id="{{ $popId }}-first" type="text" inputmode="decimal" class="input input--num" x-model="form.regular_price" placeholder="0.00" autocomplete="off">
                    </div>
                    <template x-if="errors.regular_price"><p class="field__error" x-text="errors.regular_price[0]"></p></template>
                </div>
                <div class="field" :class="{ 'is-invalid': errors.sale_price }">
                    <label class="field__label" for="{{ $popId }}-sale">Sale price</label>
                    <div class="input-group"><span class="input-group__addon">£</span>
                        <input id="{{ $popId }}-sale" type="text" inputmode="decimal" class="input input--num" x-model="form.sale_price" placeholder="None" autocomplete="off">
                    </div>
                    <template x-if="errors.sale_price"><p class="field__error" x-text="errors.sale_price[0]"></p></template>
                </div>
            </div>
            <div class="mt-3">
                <label class="check" for="{{ $popId }}-track">
                    <input type="checkbox" class="checkbox" id="{{ $popId }}-track" x-model="form.manage_stock">
                    <span class="check__text"><span class="check__label">Track quantity</span></span>
                </label>
            </div>
            <div class="field mt-2" x-show="form.manage_stock" :class="{ 'is-invalid': errors.stock_quantity }">
                <label class="field__label" for="{{ $popId }}-qty">Quantity in stock</label>
                <input id="{{ $popId }}-qty" type="number" step="1" inputmode="numeric" class="input input--num" x-model="form.stock_quantity" style="max-width:140px">
                <template x-if="errors.stock_quantity"><p class="field__error" x-text="errors.stock_quantity[0]"></p></template>
            </div>
            <div class="field mt-2" x-show="!form.manage_stock">
                <label class="field__label" for="{{ $popId }}-status">Stock status</label>
                <select id="{{ $popId }}-status" class="select" x-model="form.stock_status">
                    @foreach (Pine\Commerce\Services\Admin\OrderStatus::STOCK_STATUSES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <p class="qe__hint">Leave the sale price empty to end a sale. Customers waiting for this product are emailed when it comes back in stock.</p>
            <div class="qe__foot">
                <button type="button" class="btn btn--sm" @click="open = false">Cancel</button>
                <button type="submit" class="btn btn--sm btn--primary" :class="{ 'is-loading': saving }" :disabled="saving"><span>Save</span></button>
            </div>
        </form>
    </div>
</template>
