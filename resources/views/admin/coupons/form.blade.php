{{-- Create/edit discount – the reference form page (2-column layout, dirty-form save bar, live summary, pickers). --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\CouponStatus;
    use Pine\Commerce\Services\Admin\LocalTime;

    $editing = $coupon->exists;
    $state = $editing ? CouponStatus::of($coupon) : null;
    $amount = $coupon->amount !== null ? number_format((float) $coupon->amount, 2, '.', '') : '';
    $initial = [
        'code' => (string) old('code', $coupon->code),
        'type' => (string) old('type', $coupon->type ?? 'percent'),
        'amount' => (string) old('amount', $amount),
        'freeShipping' => (bool) old('free_shipping', $coupon->free_shipping),
        'minSpend' => (string) old('minimum_spend', $coupon->minimum_spend !== null ? number_format((float) $coupon->minimum_spend, 2, '.', '') : ''),
        'usageLimit' => (string) old('usage_limit', $coupon->usage_limit),
        'perCustomer' => (string) old('usage_limit_per_user', $coupon->usage_limit_per_user),
        'individual' => (bool) old('individual_use', $coupon->individual_use),
        'startsAt' => (string) old('starts_at', LocalTime::toInput($coupon->starts_at)),
        'expiresAt' => (string) old('expires_at', LocalTime::toInput($coupon->expires_at)),
        'active' => (bool) old('is_active', $coupon->is_active ?? true),
    ];
    $hasExclusions = $coupon->excluded_product_ids || $coupon->excluded_category_ids || $coupon->exclude_sale_items
        || $errors->hasAny(['excluded_product_ids', 'excluded_product_ids.*', 'excluded_category_ids', 'excluded_category_ids.*']);
    $saveLabel = $editing ? 'Save' : 'Create discount';
@endphp

@section('title', $editing ? $coupon->code.' · Discounts' : 'Create discount')

@section('content')
    <x-admin.page-header :title="$editing ? $coupon->code : 'Create discount'" :back="route('admin.coupons.index')" back-label="Back to discounts">
        @if ($state)
            <x-slot:badges><x-admin.badge :color="CouponStatus::color($state)" dot>{{ CouponStatus::label($state) }}</x-admin.badge></x-slot:badges>
            <x-slot:actions>
                <x-admin.button icon="clipboard-document" x-data x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($coupon->code) }}).then(() => Admin.toast('Code copied'))">Copy code</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($state === 'expired')
        <x-admin.callout type="warning" class="mb-4" title="This discount can’t be used any more">
            {{ CouponStatus::isUsedUp($coupon) ? 'It has reached its usage limit.' : 'Its end date has passed.' }} Change the limits or dates below to bring it back.
        </x-admin.callout>
    @endif

    <x-admin.form id="coupon-form" :action="$editing ? route('admin.coupons.update', $coupon) : route('admin.coupons.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <div class="layout" x-data="couponForm(@js($initial))">
            <div class="layout__main">
                <x-admin.card title="Discount code">
                    <div class="stack-fields">
                        <x-admin.field label="Code" for="f-code" error="code" required help="Customers type this in the basket or at checkout. Letters, numbers and dashes – capitals don’t matter.">
                            <div @class(['input-group', 'is-invalid' => $errors->has('code')])>
                                <input type="text" name="code" id="f-code" class="input input--mono" value="{{ $initial['code'] }}" x-model="code" required maxlength="50"
                                       autocomplete="off" spellcheck="false" @if (! $editing) autofocus @endif @error('code') aria-invalid="true" aria-describedby="f-code-error" @enderror>
                                <button type="button" class="btn btn--sm" @click="generate()"><span>Generate</span></button>
                            </div>
                        </x-admin.field>
                        <x-admin.textarea name="description" label="Internal note" optional rows="2" counter="255" :value="$coupon->description" help="Only staff see this – e.g. who the code was made for." />
                    </div>
                </x-admin.card>

                <x-admin.card title="Value">
                    <div class="stack-fields">
                        <x-admin.radio-cards name="type" x-model="type" :value="$initial['type']" :options="[
                            'percent' => ['label' => 'Percentage', 'help' => 'e.g. 10% off the basket', 'icon' => 'receipt-percent'],
                            'fixed_cart' => ['label' => 'Amount off basket', 'help' => 'e.g. £20 off the order', 'icon' => 'banknotes'],
                            'fixed_product' => ['label' => 'Amount off each item', 'help' => 'e.g. £5 off every item', 'icon' => 'tag'],
                        ]" />
                        <x-admin.field label="Discount value" for="f-amount" error="amount" required>
                            <div @class(['input-group', 'is-invalid' => $errors->has('amount')]) style="max-width:240px">
                                <span class="input-group__addon" x-show="type !== 'percent'">£</span>
                                <input type="text" inputmode="decimal" name="amount" id="f-amount" class="input input--num" value="{{ $initial['amount'] }}" x-model="amount" placeholder="0.00" required autocomplete="off">
                                <span class="input-group__addon" x-show="type === 'percent'" x-cloak>%</span>
                            </div>
                        </x-admin.field>
                        <x-admin.toggle name="free_shipping" label="Free shipping" help="Removes the shipping charge too. Use a value of 0 for a free-shipping-only code." :checked="$initial['freeShipping']" x-model="freeShipping" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Applies to" subtitle="Leave both empty to discount everything in the basket.">
                    <div class="stack-fields">
                        <x-admin.product-picker name="product_ids" label="Only these products" :value="$coupon->product_ids ?? []" />
                        <x-admin.category-picker name="category_ids" label="Only products in these categories" :value="$coupon->category_ids ?? []" />
                        <div x-data="{ open: @js((bool) $hasExclusions) }">
                            <button type="button" class="btn btn--plain" @click="open = !open" :aria-expanded="open.toString()">
                                <x-admin.icon name="chevron-right" variant="mini" size="sm" x-bind:style="open ? 'transform:rotate(90deg)' : ''" /><span>Exclusions</span>
                            </button>
                            <div x-show="open" x-collapse x-cloak>
                                <div class="stack-fields mt-4">
                                    <x-admin.product-picker name="excluded_product_ids" label="Never discount these products" :value="$coupon->excluded_product_ids ?? []" />
                                    <x-admin.category-picker name="excluded_category_ids" label="Never discount products in these categories" :value="$coupon->excluded_category_ids ?? []" />
                                    <x-admin.checkbox name="exclude_sale_items" label="Don’t discount items that are already on sale" :checked="$coupon->exclude_sale_items" />
                                </div>
                            </div>
                        </div>
                    </div>
                </x-admin.card>

                <x-admin.card title="Minimum and maximum spend">
                    <div class="form-grid">
                        <x-admin.money name="minimum_spend" label="Minimum basket total" optional :value="$coupon->minimum_spend" x-model="minSpend" help="Blank = no minimum." />
                        <x-admin.money name="maximum_spend" label="Maximum basket total" optional :value="$coupon->maximum_spend" help="Blank = no maximum." />
                    </div>
                </x-admin.card>

                <x-admin.card title="Usage limits">
                    <div class="form-grid">
                        <x-admin.input type="number" min="1" step="1" inputmode="numeric" name="usage_limit" label="Total number of uses" optional :value="$coupon->usage_limit" x-model="usageLimit" help="Blank = unlimited." />
                        <x-admin.input type="number" min="1" step="1" inputmode="numeric" name="usage_limit_per_user" label="Uses per customer" optional :value="$coupon->usage_limit_per_user" x-model="perCustomer" help="Counted by email address." />
                    </div>
                    <div class="mt-4">
                        <x-admin.checkbox name="individual_use" label="Can’t be combined with other discount codes" :checked="$initial['individual']" x-model="individual" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Customer eligibility">
                    <x-admin.textarea name="allowed_emails" label="Only these customers" optional rows="3" :value="implode(', ', $coupon->allowed_emails ?? [])"
                                      help="Billing email addresses separated by commas or new lines. Use *@company.co.uk to allow a whole company. Blank = everyone." />
                </x-admin.card>

                <x-admin.card title="Active dates">
                    <div class="form-grid">
                        <x-admin.datetime name="starts_at" label="Starts" optional :value="$coupon->starts_at" x-model="startsAt" help="UK time. Blank = straight away." />
                        <x-admin.datetime name="expires_at" label="Ends" optional :value="$coupon->expires_at" x-model="expiresAt" help="UK time. Blank = never." />
                    </div>
                </x-admin.card>
            </div>

            <div class="layout__aside layout__aside--sticky">
                <x-admin.card title="Summary">
                    <p class="mono fw-700 text-lg break" x-text="code || 'No code yet'" :class="{ 'text-subtle': !code }">{{ $initial['code'] ?: 'No code yet' }}</p>
                    <ul class="summary-list mt-3">
                        <li x-text="valueText"></li>
                        <li x-show="minSpend" x-text="'Minimum basket total ' + Admin.money(minSpend)"></li>
                        <li x-text="usageText"></li>
                        <li x-show="individual">Can’t be combined with other codes</li>
                        <li x-text="datesText"></li>
                    </ul>
                </x-admin.card>

                <x-admin.card title="Status">
                    <x-admin.toggle name="is_active" label="Active" help="Switch off to stop the code working without deleting it." :checked="$initial['active']" />
                </x-admin.card>

                @if ($editing)
                    <x-admin.card title="Performance">
                        <dl class="kv">
                            <dt>Used</dt>
                            <dd>{{ number_format($coupon->usage_count) }} {{ $coupon->usage_limit ? 'of '.number_format($coupon->usage_limit) : \Illuminate\Support\Str::plural('time', $coupon->usage_count) }}</dd>
                            <dt>Orders</dt>
                            <dd>
                                @if ($ordersCount && Route::has('admin.orders.index'))
                                    <a href="{{ route('admin.orders.index', ['q' => $coupon->code]) }}">{{ number_format($ordersCount) }} {{ \Illuminate\Support\Str::plural('order', $ordersCount) }}</a>
                                @else
                                    {{ number_format($ordersCount) }} {{ \Illuminate\Support\Str::plural('order', $ordersCount) }}
                                @endif
                            </dd>
                            <dt>Created</dt>
                            <dd><x-admin.time :value="$coupon->created_at" format="datetime" /></dd>
                            <dt>Last edited</dt>
                            <dd><x-admin.time :value="$coupon->updated_at" /></dd>
                        </dl>
                        @if ($coupon->usage_limit)
                            <div @class(['progress', 'mt-4', 'progress--danger' => CouponStatus::isUsedUp($coupon)]) role="progressbar" aria-valuemin="0" aria-valuemax="{{ $coupon->usage_limit }}" aria-valuenow="{{ $coupon->usage_count }}" aria-label="Uses">
                                <div class="progress__bar" style="width: {{ min(100, round($coupon->usage_count / max(1, $coupon->usage_limit) * 100)) }}%"></div>
                            </div>
                        @endif
                    </x-admin.card>
                @endif
            </div>
        </div>
    </x-admin.form>

    <div class="form-actions">
        @if ($editing)
            <x-admin.confirm :action="route('admin.coupons.destroy', $coupon)" variant="ghost-danger" icon="trash"
                             :title="'Delete '.($coupon->code).'?'" confirm-label="Delete discount"
                             message="Customers will no longer be able to use this code. Orders that already used it are not affected. This can’t be undone.">Delete discount</x-admin.confirm>
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.coupons.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="coupon-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            // Live summary + code generator for the discount form
            Alpine.data('couponForm', function (initial) {
                // Getters must stay getters, so copy the initial values onto the object rather than Object.assign-ing it.
                var form = {
                    generate: function () {
                        this.code = Admin.randomCode(8);
                        this.$nextTick(function () { Admin.markDirty(document.getElementById('f-code')); });
                    },
                    get valueText() {
                        var amount = parseFloat(this.amount) || 0;
                        var text;
                        if (this.type === 'percent') {
                            text = (amount ? (Math.round(amount * 100) / 100) + '% off' : 'Percentage off') + ' the basket';
                        } else if (this.type === 'fixed_product') {
                            text = (amount ? Admin.money(amount) : 'An amount') + ' off each item';
                        } else {
                            text = (amount ? Admin.money(amount) : 'An amount') + ' off the basket';
                        }
                        if (this.freeShipping) text = amount ? text + ', plus free shipping' : 'Free shipping';
                        return text;
                    },
                    get usageText() {
                        var total = parseInt(this.usageLimit, 10), each = parseInt(this.perCustomer, 10);
                        var parts = [total ? 'Can be used ' + total + ' time' + (total === 1 ? '' : 's') + ' in total' : 'No limit on total uses'];
                        if (each) parts.push(each === 1 ? 'once per customer' : each + ' times per customer');
                        return parts.join(', ');
                    },
                    get datesText() {
                        var fmt = function (d) {
                            return d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                        };
                        var now = new Date();
                        var starts = this.startsAt ? new Date(this.startsAt) : null;
                        var ends = this.expiresAt ? new Date(this.expiresAt) : null;
                        if (starts && isNaN(starts)) starts = null;
                        if (ends && isNaN(ends)) ends = null;
                        if (ends && ends <= now) return 'Ended ' + fmt(ends);
                        if (starts && starts > now) return 'Starts ' + fmt(starts) + (ends ? ', ends ' + fmt(ends) : ', no end date');
                        if (ends) return 'Active now until ' + fmt(ends);
                        return 'Active now, no end date';
                    }
                };
                Object.keys(initial).forEach(function (key) { form[key] = initial[key]; });
                return form;
            });
        });
    </script>
@endpush
