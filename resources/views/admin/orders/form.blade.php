{{-- Create a manual / phone order, or edit an order (items only while it is pending, on hold or failed).
     Totals are priced live by the server (admin.orders.quote) with the same maths used when saving. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\Countries;
    use Pine\Commerce\Services\Admin\OrderManager;
    use Pine\Commerce\Services\Admin\PaymentMethods;
    use Illuminate\Support\Str;

    $editing = $order->exists;
    $config = [
        'quoteUrl' => route('admin.orders.quote'),
        'productsUrl' => route('admin.api.sales.products'),
        'customersUrl' => route('admin.api.customers'),
        'customerUrl' => str_replace('987654321', '__ID__', route('admin.api.sales.customer', ['customer' => 987654321])),
        'editableItems' => $editableItems,
        'orderId' => $order->id,
    ];
    $saveLabel = $editing ? 'Save order' : 'Create order';
    $countries = Countries::options();
    $lineErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'lines'))->flatten()->unique()->values();
@endphp

@section('title', $editing ? 'Edit order #'.$order->number : 'Create order')

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header :title="$editing ? 'Edit order #'.$order->number : 'Create order'"
                         :back="$editing ? route('admin.orders.show', $order) : route('admin.orders.index')"
                         :back-label="$editing ? 'Back to order' : 'Back to orders'"
                         :subtitle="$editing ? null : 'For phone, email or in-person sales. Stock and discount codes work exactly like the online checkout.'">
        @if ($editing)
            <x-slot:badges><x-admin.status-badge :status="$order->status" /></x-slot:badges>
        @endif
    </x-admin.page-header>

    @if ($editing && ! $editableItems)
        <x-admin.callout type="info" class="mb-4" title="Items and prices are locked">
            This order is {{ Str::lower(\Pine\Commerce\Services\Admin\OrderStatus::label($order->status)) }}, so only the customer’s details and addresses can be changed.
            To take items off a paid order, use <a href="{{ route('admin.orders.show', $order) }}">Refund</a> on the order page.
        </x-admin.callout>
    @endif

    <x-admin.form id="order-form" :action="$editing ? route('admin.orders.update', $order) : route('admin.orders.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <div class="layout" x-data="orderForm(@js($initial), @js($config))">
            <div class="layout__main">

                {{-- Products ------------------------------------------------------------------ --}}
                <x-admin.card flush>
                    <x-slot:header>
                        <div class="flex-1">
                            <h2 class="card__title">Products</h2>
                            @if ($editableItems)<p class="card__subtitle">Search by name or SKU. Leave the price blank to use the shop price.</p>@endif
                        </div>
                    </x-slot:header>
                    @if ($editableItems)
                        <x-slot:actions>
                            <button type="button" class="btn btn--sm" @click="addCustom()"><x-admin.icon name="plus" /><span>Custom item</span></button>
                        </x-slot:actions>
                        <div class="card__body" style="padding-bottom:var(--s-3)">
                            @if ($lineErrors->isNotEmpty())
                                <x-admin.callout type="danger" class="mb-4">
                                    <ul style="margin:0;padding-left:18px">@foreach ($lineErrors as $message)<li>{{ $message }}</li>@endforeach</ul>
                                </x-admin.callout>
                            @endif
                            <div class="picker" style="position:relative" @click.outside="open = false">
                                <div class="search-input">
                                    <x-admin.icon name="magnifying-glass" />
                                    <label for="f-product-search" class="sr-only">Search products</label>
                                    <input type="text" id="f-product-search" x-ref="productSearch" class="input" placeholder="Search products to add" autocomplete="off"
                                           x-model="q" @keydown="onSearchKey($event)" @focus="if (q.trim()) open = true"
                                           role="combobox" aria-autocomplete="list" aria-controls="product-results" :aria-expanded="open.toString()">
                                </div>
                                <div class="search-results" id="product-results" role="listbox" x-show="open && (results.length || searching || q.trim())" x-cloak>
                                    <template x-if="searching && !results.length"><div class="picker__empty"><span class="spinner spinner--sm" style="margin:0 auto"></span></div></template>
                                    <template x-if="!searching && !results.length && q.trim()"><div class="picker__empty">No products match “<span x-text="q"></span>”</div></template>
                                    <template x-for="(product, i) in results" :key="product.id">
                                        <button type="button" class="picker__option" :class="{ 'is-active': i === active }" role="option" @click="addProduct(product)" @mouseenter="active = i">
                                            <span class="thumb thumb--sm">
                                                <template x-if="product.image"><img :src="product.image" alt="" loading="lazy"></template>
                                                <template x-if="!product.image"><x-admin.icon name="photo" /></template>
                                            </span>
                                            <span class="picker__option-main">
                                                <span class="picker__option-title" x-text="product.name" style="display:block"></span>
                                                <span class="picker__option-sub" x-text="product.sub" style="display:block"></span>
                                            </span>
                                            <template x-if="product.stock_status === 'outofstock'"><span class="badge badge--danger badge--sm">Out of stock</span></template>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <template x-if="!lines.length">
                            <x-admin.empty icon="shopping-bag" title="No products yet" description="Search above to add products, or add a custom item (e.g. a repair or an accessory that isn’t in the shop)." size="sm" />
                        </template>
                        <div class="table-wrap table-wrap--sticky-off" x-show="lines.length" x-cloak>
                            <table class="table table--static-head line-editor">
                                <thead>
                                    <tr>
                                        <th scope="col" style="width:60px"><span class="sr-only">Image</span></th>
                                        <th scope="col">Product</th>
                                        <th scope="col">Price</th>
                                        <th scope="col">Qty</th>
                                        <th scope="col" class="num">Total</th>
                                        <th scope="col"><span class="sr-only">Remove</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(line, i) in lines" :key="line.key">
                                        <tr>
                                            <td class="line-editor__thumb">
                                                <span class="thumb">
                                                    <template x-if="line.image"><img :src="line.image" alt=""></template>
                                                    <template x-if="!line.image"><x-admin.icon name="photo" /></template>
                                                </span>
                                            </td>
                                            <td class="line-editor__product">
                                                <input type="hidden" :name="'lines[' + i + '][product_id]'" :value="line.product_id">
                                                <input type="hidden" :name="'lines[' + i + '][order_item_id]'" :value="line.order_item_id">
                                                <template x-if="line.custom">
                                                    <div class="stack stack--xs">
                                                        <label class="sr-only" :for="'line-name-' + line.key">Item name</label>
                                                        <input type="text" class="input input--sm" data-custom-name :id="'line-name-' + line.key" :name="'lines[' + i + '][name]'" x-model="line.name" @keydown.enter.prevent placeholder="Item name, e.g. Screen repair" maxlength="190" required>
                                                        <label class="sr-only" :for="'line-sku-' + line.key">SKU</label>
                                                        <input type="text" class="input input--sm" :id="'line-sku-' + line.key" :name="'lines[' + i + '][sku]'" x-model="line.sku" placeholder="SKU (optional)" maxlength="100" style="max-width:200px">
                                                    </div>
                                                </template>
                                                <template x-if="!line.custom">
                                                    <div>
                                                        <div class="fw-600" x-text="line.loading ? 'Loading…' : line.name"></div>
                                                        <div class="cell-sub" x-show="line.sku" x-text="'SKU ' + line.sku"></div>
                                                    </div>
                                                </template>
                                                <template x-if="line.is_variable">
                                                    <div class="mt-1">
                                                        <label class="sr-only" :for="'line-variation-' + line.key">Option</label>
                                                        <select class="select select--sm" :id="'line-variation-' + line.key" :name="'lines[' + i + '][variation_id]'" x-model="line.variation_id" @change="variationChanged(line)" style="max-width:320px">
                                                            <option value="">Choose an option…</option>
                                                            <template x-for="v in line.variations" :key="v.id">
                                                                <option :value="String(v.id)" x-text="v.label + (v.price !== null ? ' – ' + money(v.price) : '') + (v.stock !== null ? ' (' + v.stock + ' in stock)' : (v.stock_status === 'outofstock' ? ' (out of stock)' : ''))" :selected="String(v.id) === String(line.variation_id)"></option>
                                                            </template>
                                                        </select>
                                                    </div>
                                                </template>
                                                <template x-if="!line.is_variable"><input type="hidden" :name="'lines[' + i + '][variation_id]'" :value="line.variation_id"></template>
                                                <template x-if="line.options && !line.is_variable && Object.keys(line.options).length">
                                                    <div class="cell-sub" x-text="Object.entries(line.options).map(function (o) { return o[0] + ': ' + o[1]; }).join(' · ')"></div>
                                                </template>
                                                <div class="line-editor__warning" x-show="line.warning" x-cloak><x-admin.icon name="exclamation-triangle" /><span x-text="line.warning"></span></div>
                                            </td>
                                            <td class="line-editor__price">
                                                <label class="sr-only" :for="'line-price-' + line.key">Unit price</label>
                                                <div class="input-group input--price">
                                                    <span class="input-group__addon">£</span>
                                                    <input type="text" inputmode="decimal" class="input input--sm input--num" :id="'line-price-' + line.key" :name="'lines[' + i + '][unit_price]'"
                                                           x-model="line.unit_price" @input="line.total = null; changed()" @keydown.enter.prevent="changed()" autocomplete="off"
                                                           :placeholder="line.list_price !== null && line.list_price !== undefined ? Number(line.list_price).toFixed(2) : '0.00'"
                                                           :title="line.list_price !== null && line.list_price !== undefined ? 'Shop price ' + money(line.list_price) : ''">
                                                </div>
                                            </td>
                                            <td class="line-editor__qty">
                                                <label class="sr-only" :for="'line-qty-' + line.key">Quantity</label>
                                                <input type="number" min="1" max="9999" step="1" inputmode="numeric" class="input input--sm input--qty" :id="'line-qty-' + line.key"
                                                       :name="'lines[' + i + '][quantity]'" x-model="line.quantity" @input="line.total = null; changed()" @keydown.enter.prevent="changed()" required>
                                            </td>
                                            <td class="line-editor__total num fw-600" x-text="money(lineTotal(line))"></td>
                                            <td class="line-editor__remove" style="width:1%">
                                                <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="removeLine(i)" :aria-label="'Remove ' + (line.name || 'item')" title="Remove"><x-admin.icon name="x-mark" /></button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    @else
                        <ul class="line-items mt-2">
                            @foreach ($order->items as $item)
                                <li class="line-item">
                                    <x-admin.thumb :src="null" />
                                    <div>
                                        <div class="line-item__name">{{ $item->name }}</div>
                                        <div class="line-item__meta">@if ($item->sku)<span>SKU {{ $item->sku }}</span>@endif @foreach ((array) $item->options as $k => $v)<span>{{ $k }}: {{ is_scalar($v) ? $v : '' }}</span>@endforeach</div>
                                    </div>
                                    <div class="line-item__price">{{ money($item->unit_price) }} × {{ $item->quantity }}</div>
                                    <div class="line-item__total">{{ money($item->total) }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-admin.card>

                {{-- Discounts, shipping, totals ------------------------------------------------- --}}
                <x-admin.card title="Payment">
                    @if ($editableItems)
                        <div class="form-grid">
                            <x-admin.input name="coupon_code" label="Discount code" optional x-model="f.coupon_code" x-on:change="changed()" x-on:keydown.enter.prevent="changed()"
                                           autocomplete="off" class="input--mono" help="Checked like at the checkout (dates, limits, products)." />
                            <x-admin.money name="manual_discount" label="Extra discount" optional x-model="f.manual_discount" x-on:input="changed()" x-on:keydown.enter.prevent="changed()" help="A fixed amount off the order." />
                            <x-admin.field label="Shipping" for="f-shipping_method" error="shipping_method">
                                <select name="shipping_method" id="f-shipping_method" class="select" x-model="f.shipping_method" @change="f.shipping_cost = ''; changed()">
                                    <option value="">No shipping (collection / digital)</option>
                                    @foreach ($shippingMethods as $method)
                                        <option value="{{ $method->code }}">{{ $method->name }} – {{ (float) $method->cost > 0 ? money($method->cost) : 'Free' }}{{ $method->is_active ? '' : ' (not offered at checkout)' }}</option>
                                    @endforeach
                                </select>
                            </x-admin.field>
                            <x-admin.money name="shipping_cost" label="Shipping cost" optional x-model="f.shipping_cost" x-on:input="changed()" x-on:keydown.enter.prevent="changed()" help="Leave blank to charge the method’s normal price." />
                        </div>
                        <template x-if="quote && quote.coupon_errors && quote.coupon_errors.length">
                            <x-admin.callout type="warning" class="mt-4"><span x-text="quote.coupon_errors.join(' ')"></span></x-admin.callout>
                        </template>
                        <div class="divider"></div>
                        <div class="sum-rows" :class="{ 'is-busy': quoting }" aria-live="polite">
                            <div class="sum-row"><span class="sum-row__label">Subtotal <span class="sum-row__hint" x-text="itemCount + (itemCount === 1 ? ' item' : ' items')"></span></span><span x-text="quote ? money(quote.subtotal) : money(0)"></span></div>
                            <div class="sum-row" x-show="quote && quote.discount > 0" x-cloak>
                                <span class="sum-row__label">Discount <span class="sum-row__hint mono" x-text="quote && quote.coupon_codes.length ? quote.coupon_codes.join(', ') : ''"></span></span>
                                <span x-text="quote ? '−' + money(quote.discount) : ''"></span>
                            </div>
                            <div class="sum-row">
                                <span class="sum-row__label">Shipping <span class="sum-row__hint" x-text="quote && quote.shipping_title ? quote.shipping_title : ''"></span></span>
                                <span x-text="quote ? (quote.shipping > 0 ? money(quote.shipping) : 'Free') : '—'"></span>
                            </div>
                            <template x-if="quote && quote.tax > 0 && quote.tax_lines && quote.tax_lines.length">
                                <div><template x-for="t in quote.tax_lines" :key="t.id"><div class="sum-row"><span x-text="t.label"></span><span x-text="t.formatted"></span></div></template></div>
                            </template>
                            <div class="sum-row" x-show="quote && quote.tax > 0 && !(quote.tax_lines && quote.tax_lines.length)" x-cloak><span>{{ setting('tax.label', 'VAT') }}</span><span x-text="quote ? money(quote.tax) : ''"></span></div>
                            <div class="sum-row sum-row--total"><span>Total</span><span x-text="quote ? money(quote.total) : money(0)"></span></div>
                        </div>
                        @if ($editing && (float) $order->refunded_total > 0)
                            <p class="text-xs text-muted mt-2">{{ money($order->refunded_total) }} has already been refunded on this order.</p>
                        @endif
                    @else
                        <div class="sum-rows">
                            <div class="sum-row"><span>Subtotal</span><span>{{ money($order->subtotal) }}</span></div>
                            @if ((float) $order->discount_total > 0)<div class="sum-row"><span>Discount {{ $order->coupon_code ? '('.$order->coupon_code.')' : '' }}</span><span>−{{ money($order->discount_total) }}</span></div>@endif
                            <div class="sum-row"><span>Shipping <span class="text-muted">{{ $order->shipping_method_title }}</span></span><span>{{ money($order->shipping_total) }}</span></div>
                            @foreach ($order->taxBreakdown() as $taxLine)<div class="sum-row"><span>{{ $taxLine['label'] }}</span><span>{{ money($taxLine['amount']) }}</span></div>@endforeach
                            <div class="sum-row sum-row--total"><span>Total</span><span>{{ money($order->total) }}</span></div>
                        </div>
                    @endif
                </x-admin.card>

                @unless ($editing)
                    <x-admin.card title="Payment status">
                        <div class="stack-fields">
                            <x-admin.radio-cards name="status" x-model="f.status" :value="$initial['status']" columns="190" :options="[
                                'pending' => ['label' => 'Not paid yet', 'help' => 'Pending payment – you can email a payment link.', 'icon' => 'clock'],
                                'processing' => ['label' => 'Paid', 'help' => 'e.g. card over the phone. Ready to send.', 'icon' => 'check-circle'],
                                'on-hold' => ['label' => 'Awaiting transfer', 'help' => 'On hold until a bank transfer arrives.', 'icon' => 'pause-circle'],
                            ]" />
                            <div class="form-grid">
                                <x-admin.select name="payment_method" label="Payment method" optional :options="PaymentMethods::MANUAL_OPTIONS" placeholder="—" x-model="f.payment_method" />
                                <x-admin.input name="transaction_id" label="Payment reference" optional x-model="f.transaction_id" maxlength="100" help="e.g. the card machine or bank reference." />
                            </div>
                            <div x-show="f.status === 'pending'" x-cloak>
                                <x-admin.toggle name="send_invoice" label="Email the customer a link to pay" x-model="sendInvoice" :checked="$initial['send_invoice']"
                                                help="They get the order details with a “Pay for this order” button (card or PayPal)." />
                            </div>
                            <p class="text-xs text-muted" x-show="f.status === 'processing'" x-cloak>The customer gets the usual “order received” email.</p>
                        </div>
                    </x-admin.card>
                @endunless

                <x-admin.card title="Notes">
                    <div class="stack-fields">
                        <x-admin.textarea name="customer_note" label="Note from the customer" optional rows="2" counter="2000" x-model="f.customer_note"
                                          help="Delivery instructions etc. Printed on the packing slip and shown to the customer." />
                        @unless ($editing)
                            <x-admin.textarea name="private_note" label="Private note" optional rows="2" x-model="f.private_note" help="Only staff see this – it’s added to the order’s timeline." />
                        @endunless
                    </div>
                </x-admin.card>
            </div>

            <div class="layout__aside">
                {{-- Customer ------------------------------------------------------------------- --}}
                <x-admin.card title="Customer">
                    <input type="hidden" name="user_id" :value="userId">
                    <template x-if="customer">
                        <div class="customer-chip">
                            <span class="avatar" aria-hidden="true" x-text="(customer.name || '?').trim().split(/\s+/).slice(0, 2).map(function (p) { return p[0]; }).join('').toUpperCase()"></span>
                            <div class="flex-1">
                                <div class="fw-600 truncate" x-text="customer.name"></div>
                                <div class="text-xs text-muted truncate" x-text="customer.email"></div>
                            </div>
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="clearCustomer()" aria-label="Remove customer" title="Remove customer"><x-admin.icon name="x-mark" /></button>
                        </div>
                    </template>
                    <div x-show="!customer" class="picker" style="position:relative" @click.outside="copen = false">
                        <div class="search-input">
                            <x-admin.icon name="magnifying-glass" />
                            <label for="f-customer-search" class="sr-only">Search customers</label>
                            <input type="text" id="f-customer-search" x-ref="customerSearch" class="input" placeholder="Search by name, email or phone" autocomplete="off"
                                   x-model="cq" @keydown="onCustomerKey($event)" role="combobox" aria-autocomplete="list" aria-controls="customer-results" :aria-expanded="copen.toString()">
                        </div>
                        <div class="search-results" id="customer-results" role="listbox" x-show="copen && (cresults.length || csearching || cq.trim())" x-cloak>
                            <template x-if="csearching && !cresults.length"><div class="picker__empty"><span class="spinner spinner--sm" style="margin:0 auto"></span></div></template>
                            <template x-if="!csearching && !cresults.length && cq.trim()"><div class="picker__empty">No customers match – fill in the details below for a guest order.</div></template>
                            <template x-for="(option, i) in cresults" :key="option.id">
                                <button type="button" class="picker__option" :class="{ 'is-active': i === cactive }" role="option" @click="chooseCustomer(option)" @mouseenter="cactive = i">
                                    <span class="picker__option-main">
                                        <span class="picker__option-title" x-text="option.label" style="display:block"></span>
                                        <span class="picker__option-sub" x-text="option.sub" style="display:block"></span>
                                    </span>
                                </button>
                            </template>
                        </div>
                        <p class="text-xs text-muted mt-2">Pick an existing customer to fill in their details, or leave empty for a guest order.</p>
                    </div>
                    @error('user_id')<p class="field__error mt-2"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@enderror
                </x-admin.card>

                <x-admin.card title="Contact">
                    <div class="stack-fields">
                        <x-admin.input name="email" type="email" label="Email" required x-model="f.email" x-on:change="changed()" autocomplete="off" help="Order emails go here." />
                        <x-admin.input name="phone" type="tel" label="Phone" optional x-model="f.phone" autocomplete="off" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Billing address">
                    <div class="stack-fields">
                        <div class="form-grid">
                            <x-admin.input name="billing_first_name" label="First name" required x-model="f.billing_first_name" autocomplete="off" />
                            <x-admin.input name="billing_last_name" label="Last name" required x-model="f.billing_last_name" autocomplete="off" />
                        </div>
                        <x-admin.input name="billing_company" label="Company" optional x-model="f.billing_company" autocomplete="off" />
                        <x-admin.input name="billing_address_1" label="Address" x-model="f.billing_address_1" autocomplete="off" />
                        <x-admin.input name="billing_address_2" label="Apartment, suite, etc." optional x-model="f.billing_address_2" autocomplete="off" />
                        <div class="form-grid">
                            <x-admin.input name="billing_city" label="Town / city" x-model="f.billing_city" autocomplete="off" />
                            <x-admin.input name="billing_postcode" label="Postcode" x-model="f.billing_postcode" autocomplete="off" />
                        </div>
                        <x-admin.input name="billing_county" label="County" optional x-model="f.billing_county" autocomplete="off" />
                        <x-admin.select name="billing_country" label="Country" :options="$countries" x-model="f.billing_country" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Shipping address">
                    <div class="stack-fields">
                        <x-admin.checkbox name="shipping_same_as_billing" label="Same as billing address" x-model="sameAsBilling" :checked="$initial['sameAsBilling']" />
                        <div class="stack-fields" x-show="!sameAsBilling" x-cloak>
                            <div class="form-grid">
                                <x-admin.input name="shipping_first_name" label="First name" x-model="f.shipping_first_name" autocomplete="off" />
                                <x-admin.input name="shipping_last_name" label="Last name" x-model="f.shipping_last_name" autocomplete="off" />
                            </div>
                            <x-admin.input name="shipping_company" label="Company" optional x-model="f.shipping_company" autocomplete="off" />
                            <x-admin.input name="shipping_address_1" label="Address" x-model="f.shipping_address_1" autocomplete="off" />
                            <x-admin.input name="shipping_address_2" label="Apartment, suite, etc." optional x-model="f.shipping_address_2" autocomplete="off" />
                            <div class="form-grid">
                                <x-admin.input name="shipping_city" label="Town / city" x-model="f.shipping_city" autocomplete="off" />
                                <x-admin.input name="shipping_postcode" label="Postcode" x-model="f.shipping_postcode" autocomplete="off" />
                            </div>
                            <x-admin.input name="shipping_county" label="County" optional x-model="f.shipping_county" autocomplete="off" />
                            <x-admin.select name="shipping_country" label="Country" :options="$countries" x-model="f.shipping_country" />
                            <x-admin.input name="shipping_phone" type="tel" label="Delivery phone" optional x-model="f.shipping_phone" autocomplete="off" />
                        </div>
                    </div>
                </x-admin.card>

                @unless ($editing)
                    <x-admin.card title="Options">
                        <div class="stack-fields">
                            <x-admin.toggle name="reduce_stock" label="Reduce stock levels" x-model="reduceStock" :checked="$initial['reduce_stock']"
                                            help="Takes the items out of stock now, like an online order. Cancelling the order puts them back." />
                            <div x-show="customer" x-cloak>
                                <x-admin.checkbox name="save_addresses" label="Save these addresses to the customer’s account" x-model="saveAddresses" :checked="$initial['save_addresses']" />
                            </div>
                        </div>
                    </x-admin.card>
                @endunless
            </div>
        </div>
    </x-admin.form>

    <div class="form-actions">
        <span class="flex-1"></span>
        <x-admin.button :href="$editing ? route('admin.orders.show', $order) : route('admin.orders.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="order-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection
