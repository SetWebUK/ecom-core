{{-- Create/edit a delivery option: zone, type (flat rate / free / weight or price table / local pickup) and its settings. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Models\ShippingMethod;
    use Pine\Commerce\Services\Admin\Countries;
    $editing = $method->exists;
    $saveLabel = $editing ? 'Save' : 'Add delivery option';
    $type = old('type', $method->typeKey());
    $formula = (string) ($method->setting('cost') ?? '');
    $bands = old('rates', (array) $method->setting('rates', []));
    $classCosts = old('class_costs', (array) $method->setting('class_costs', []));
    $types = [
        'flat_rate' => ['label' => 'Flat rate', 'help' => 'A set price per order, per item or per shipping class.', 'icon' => 'truck'],
        'free_shipping' => ['label' => 'Free shipping', 'help' => 'Free, optionally over an amount or with a coupon.', 'icon' => 'gift'],
        'weight_table' => ['label' => 'By weight', 'help' => 'Price bands by the basket’s total weight.', 'icon' => 'scale'],
        'price_table' => ['label' => 'By basket value', 'help' => 'Price bands by the basket’s value.', 'icon' => 'banknotes'],
        'local_pickup' => ['label' => 'Local pickup', 'help' => 'Customers collect – tax at your shop’s address.', 'icon' => 'building-storefront'],
    ];
@endphp

@section('title', $editing ? $method->name.' · Shipping' : 'Add delivery option')

@section('content')
    <x-admin.page-header :title="$editing ? $method->name : 'Add delivery option'" :back="$method->shipping_zone_id ? route('admin.shipping.zones.edit', $method->shipping_zone_id) : route('admin.shipping.index')" back-label="Back to shipping" />

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'shipping'])
        <div>
            <x-admin.form id="shipping-form" :action="$editing ? route('admin.shipping.update', $method) : route('admin.shipping.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
                <div class="stack" x-data="{ type: @js($type), calculation: @js(old('calculation', $method->setting('calculation', 'order'))), requires: @js(old('requires', (string) $method->setting('requires', ''))),
                                    bands: @js(array_values(array_map(fn ($b) => ['min' => (string) ($b['min'] ?? ''), 'max' => (string) ($b['max'] ?? ''), 'cost' => (string) ($b['cost'] ?? '')], $bands))) }">
                    <x-admin.card title="What customers see">
                        <div class="stack-fields">
                            <x-admin.input name="name" label="Name" :value="$method->name" required maxlength="120" :autofocus="! $editing" help="e.g. Free UK next-day delivery" />
                            <x-admin.textarea name="description" label="Description" :value="$method->description" rows="2" optional counter="1000" help="Shown under the name at checkout." />
                        </div>
                    </x-admin.card>

                    <x-admin.card title="Type">
                        <x-admin.radio-cards name="type" :value="$type" :options="$types" columns="180" x-model="type" />
                    </x-admin.card>

                    <x-admin.card title="Price">
                        <div class="stack-fields">
                            {{-- flat rate / local pickup --}}
                            <div class="form-grid" x-show="type === 'flat_rate' || type === 'local_pickup'">
                                <x-admin.money name="cost" label="Price" :value="$formula !== '' ? null : $method->cost" help="0 = free." />
                                <div x-show="type === 'flat_rate'">
                                    <x-admin.select name="calculation" label="Charge" x-model="calculation" :value="old('calculation', $method->setting('calculation', 'order'))"
                                                    :options="['order' => 'Once per order', 'item' => 'Per item in the basket', 'class' => 'Per order + per shipping class']" />
                                </div>
                            </div>
                            <div x-show="type === 'flat_rate'">
                                <x-admin.input name="cost_formula" label="Or a formula" :value="old('cost_formula', $formula)" optional class="mono" placeholder="5 + 1.50 * [qty]" error="cost_formula"
                                               help='Instead of the price: numbers with + − * / and [qty] (items), [cost] (basket value) or [fee percent="5" min_fee="3"].' />
                            </div>
                            <div class="stack-fields" x-show="type === 'flat_rate' && calculation === 'class'" x-cloak>
                                @if ($classes)
                                    <div class="form-grid">
                                        @foreach ($classes as $id => $name)
                                            <x-admin.input :name="'class_costs['.$id.']'" :label="'“'.$name.'” class costs'" :value="$classCosts[$id] ?? ''" optional class="mono" :error="'class_costs.'.$id" placeholder="e.g. 10 or 2 * [qty]" />
                                        @endforeach
                                        <x-admin.input name="no_class_cost" label="Products without a class cost" :value="old('no_class_cost', $method->setting('no_class_cost'))" optional class="mono" />
                                    </div>
                                    <x-admin.select name="class_mode" label="With several classes in the basket" :value="old('class_mode', $method->setting('class_mode', 'sum'))"
                                                    :options="['sum' => 'Add up each class’s cost', 'max' => 'Charge only the most expensive class']" />
                                @else
                                    <x-admin.callout type="info">Add shipping classes on the <a href="{{ route('admin.shipping.index') }}#shipping-classes">Shipping</a> page first.</x-admin.callout>
                                @endif
                            </div>

                            {{-- free shipping --}}
                            <div class="stack-fields" x-show="type === 'free_shipping'" x-cloak>
                                <x-admin.select name="requires" label="Free shipping needs" x-model="requires" :value="old('requires', (string) $method->setting('requires', ''))" :options="[
                                    '' => 'Nothing – always free',
                                    'min_amount' => 'A minimum order amount',
                                    'coupon' => 'A free-shipping coupon',
                                    'either' => 'A minimum order amount OR a coupon',
                                    'both' => 'A minimum order amount AND a coupon',
                                ]" />
                                <x-admin.toggle name="ignore_discounts" label="Check the minimum before discounts" :checked="(bool) old('ignore_discounts', $method->setting('ignore_discounts'))" />
                            </div>

                            {{-- weight / price bands --}}
                            <div class="stack-fields" x-show="type === 'weight_table' || type === 'price_table'" x-cloak>
                                <p class="text-sm text-muted" x-text="type === 'weight_table' ? 'Bands by total weight (kg, from the product weights). A basket outside every band gets no option.' : 'Bands by the basket’s value after discounts. A basket outside every band gets no option.'"></p>
                                <table class="table table--compact">
                                    <thead><tr><th scope="col" x-text="type === 'weight_table' ? 'From (kg)' : 'From (£)'"></th><th scope="col" x-text="type === 'weight_table' ? 'Up to (kg)' : 'Up to (£)'"></th><th scope="col">Price (£)</th><th scope="col"><span class="sr-only">Remove</span></th></tr></thead>
                                    <tbody>
                                        <template x-for="(band, i) in bands" :key="i">
                                            <tr>
                                                <td><input class="input input--sm" type="number" step="any" min="0" :name="'rates[' + i + '][min]'" x-model="band.min" aria-label="From"></td>
                                                <td><input class="input input--sm" type="number" step="any" min="0" :name="'rates[' + i + '][max]'" x-model="band.max" placeholder="no limit" aria-label="Up to"></td>
                                                <td><input class="input input--sm" type="number" step="0.01" min="0" :name="'rates[' + i + '][cost]'" x-model="band.cost" required aria-label="Price"></td>
                                                <td><button type="button" class="btn btn--ghost-danger btn--icon btn--sm" @click="bands.splice(i, 1)" aria-label="Remove band"><x-admin.icon name="trash" /></button></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <div><x-admin.button size="sm" icon="plus" x-on:click="bands.push({ min: bands.length ? (bands[bands.length - 1].max || '') : '0', max: '', cost: '' })">Add band</x-admin.button></div>
                                @error('rates')<p class="field__error">{{ $message }}</p>@enderror
                                @if ($errors->has('rates.*'))<p class="field__error">{{ collect($errors->get('rates.*'))->flatten()->unique()->implode(' ') }}</p>@endif
                            </div>

                            <div class="form-grid">
                                <x-admin.money name="min_order_amount" :label="'Minimum order'" :value="$method->min_order_amount" optional
                                               help="Free shipping: the amount it needs. Other types: hidden below this basket value. Blank = no minimum." />
                                <x-admin.select name="tax_status" label="Tax" :value="old('tax_status', $method->tax_status ?? 'taxable')" :options="['taxable' => 'Taxable', 'none' => 'Not taxable']"
                                                help="Taxable options are taxed as Settings › Tax says." />
                            </div>
                        </div>
                    </x-admin.card>

                    <x-admin.card title="Availability">
                        <div class="stack-fields">
                            <x-admin.select name="shipping_zone_id" label="Shipping zone" :options="$zones" :value="old('shipping_zone_id', $method->shipping_zone_id)" placeholder="No zone (use the country list below)"
                                            help="Offered when the delivery address falls in this zone." />
                            <x-admin.select name="countries[]" label="Only these countries" :options="Countries::options()" :value="$method->countries ?? []" multiple size="5" optional
                                            help="Extra limit on top of the zone. None selected = every country of the zone." error="countries" />
                            <x-admin.toggle name="is_active" label="Offer this option at checkout" :checked="$method->is_active ?? true" />
                            <x-admin.input name="code" label="Code" :value="$method->code" class="mono" maxlength="60" x-data="slugField('#f-name')"
                                           :help="$editing && $orders ? 'Saved on '.$orders.' orders – changing it only affects new orders.' : 'Internal reference. Leave blank to make one from the name.'" />
                        </div>
                    </x-admin.card>
                </div>
            </x-admin.form>
            <div class="form-actions">
                @if ($editing)
                    <x-admin.confirm :action="route('admin.shipping.destroy', $method)" variant="ghost-danger" icon="trash" :title="'Delete “'.($method->name).'”?'" confirm-label="Delete delivery option"
                                     :message="$orders ? 'Past orders keep their delivery details. Customers won’t be able to choose it any more. Switch it off instead if you might need it again.' : 'Customers won’t be able to choose it any more.'">Delete</x-admin.confirm>
                @endif
                <span class="flex-1"></span>
                <x-admin.button :href="$method->shipping_zone_id ? route('admin.shipping.zones.edit', $method->shipping_zone_id) : route('admin.shipping.index')">Cancel</x-admin.button>
                <x-admin.button type="submit" form="shipping-form" variant="primary">{{ $saveLabel }}</x-admin.button>
            </div>
        </div>
    </div>
@endsection
