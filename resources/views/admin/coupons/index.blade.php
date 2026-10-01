{{-- Discounts list – the reference index page (status tabs, filters, sortable columns, bulk actions, pagination, empty states). --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Discounts')

@section('content')
    <x-admin.page-header title="Discounts" subtitle="Codes customers enter in the basket or at checkout.">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.coupons.create')">Create discount</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="receipt-percent" title="Create your first discount code"
                           description="Offer a percentage or fixed amount off, free shipping, or both – for everyone or for chosen customers.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.coupons.create')">Create discount</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" />
            <x-admin.filters placeholder="Search by code or note" :chips="$chips" keep="status">
                <x-admin.filter-select name="type" :options="Pine\Commerce\Models\Coupon::TYPES" placeholder="All types" label="Discount type" />
            </x-admin.filters>

            @if ($coupons->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No discounts match" description="Try a different search or filter." size="sm">
                    <x-admin.button :href="route('admin.coupons.index')">Clear filters</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$coupons->pluck('id')" selectable :bulk-action="route('admin.coupons.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="activate" size="sm" icon="play">Activate</x-admin.button>
                        <x-admin.button type="submit" name="action" value="deactivate" size="sm" icon="pause">Deactivate</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm="Customers will no longer be able to use these codes. Orders that already used them are not affected."
                                        data-confirm-title="Delete the selected discounts?" data-confirm-button="Delete discounts">Delete</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="code">Code</x-admin.th>
                        <x-admin.th sort="amount" first="desc">Discount</x-admin.th>
                        <x-admin.th>Status</x-admin.th>
                        <x-admin.th sort="usage_count" align="right" first="desc">Used</x-admin.th>
                        <x-admin.th sort="expires_at">Ends</x-admin.th>
                        <x-admin.th sort="created_at" first="desc" class="hidden-mobile">Created</x-admin.th>
                    </x-slot:head>
                    @foreach ($coupons as $coupon)
                        @php $state = Pine\Commerce\Services\Admin\CouponStatus::of($coupon); @endphp
                        <tr>
                            <x-admin.row-check :id="$coupon->id" :label="'Select '.$coupon->code" />
                            <td class="stack-title">
                                <a href="{{ route('admin.coupons.edit', $coupon) }}" class="row-link mono">{{ $coupon->code }}</a>
                                @if ($coupon->description)<div class="cell-sub truncate" style="max-width:320px">{{ $coupon->description }}</div>@endif
                            </td>
                            <td data-label="Discount">
                                {{ Pine\Commerce\Services\Admin\CouponStatus::summary($coupon) }}
                                @if ($coupon->minimum_spend)<div class="cell-sub">Min. spend {{ money($coupon->minimum_spend) }}</div>@endif
                            </td>
                            <td data-label="Status"><x-admin.badge :color="Pine\Commerce\Services\Admin\CouponStatus::color($state)" dot>{{ Pine\Commerce\Services\Admin\CouponStatus::label($state) }}</x-admin.badge></td>
                            <td class="num" data-label="Used">{{ number_format($coupon->usage_count) }}<span class="text-subtle"> / {{ $coupon->usage_limit ? number_format($coupon->usage_limit) : '∞' }}</span></td>
                            <td class="nowrap" data-label="Ends">
                                @if ($coupon->expires_at)
                                    <x-admin.time :value="$coupon->expires_at" format="j M Y, H:i" />
                                @else
                                    <span class="text-subtle">No end date</span>
                                @endif
                                @if ($state === 'scheduled')<div class="cell-sub">Starts <x-admin.time :value="$coupon->starts_at" format="j M Y" /></div>@endif
                            </td>
                            <td class="nowrap text-muted hidden-mobile" data-label="Created"><x-admin.time :value="$coupon->created_at" format="date" /></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$coupons" />
            @endif
        </x-admin.card>
    @endif
@endsection
