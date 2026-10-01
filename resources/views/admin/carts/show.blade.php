{{-- One abandoned basket: products, customer, reminder timeline (AbandonedCartController@show). --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;
    $stopped = (bool) $cart->recovery_stopped_at;
@endphp

@section('title', 'Basket #'.$cart->id)

@section('content')
    <x-admin.page-header :title="'Basket #'.$cart->id" :back="route('admin.carts.index')" back-label="Abandoned checkouts"
                         :subtitle="'Started '.$cart->created_at->diffForHumans().' · last activity '.$cart->updated_at->diffForHumans()">
        <x-slot:badges>
            @if ($cart->recovered_at)<x-admin.badge color="success">Recovered</x-admin.badge>
            @elseif ($cart->converted_at)<x-admin.badge color="success">Checked out</x-admin.badge>
            @elseif ($stopped)<x-admin.badge>Reminders stopped</x-admin.badge>
            @else<x-admin.badge color="attention">Abandoned</x-admin.badge>@endif
        </x-slot:badges>
        @if (! $stopped && ! $cart->converted_at && $email)
            <x-slot:actions>
                <x-admin.confirm :action="route('admin.carts.stop', $cart->id)" method="POST" icon="no-symbol" :danger="false"
                                 title="Stop reminders for this basket?" message="No more reminder emails will be sent about this basket." confirm-label="Stop reminders">Stop reminders</x-admin.confirm>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="layout">
        <div class="layout__main">
            <x-admin.card title="Products" flush>
                <x-admin.table>
                    <x-slot:head>
                        <x-admin.th>Product</x-admin.th>
                        <x-admin.th align="right">Qty</x-admin.th>
                        <x-admin.th align="right">Price today</x-admin.th>
                    </x-slot:head>
                    @forelse ($cart->items as $item)
                        @php($product = $item->product)
                        <tr>
                            <td>
                                <div class="row gap-2 row--nowrap">
                                    <x-admin.thumb :src="$item->variation?->image ?: $product?->images->first()?->path" size="sm" />
                                    <span>{{ $product?->name ?? 'Product no longer available' }}@if ($product?->trashed()) <x-admin.badge size="sm">Deleted</x-admin.badge>@endif</span>
                                </div>
                            </td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ money((float) ($item->variation ? $item->variation->currentPrice() : $product?->currentPrice())) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">The basket is empty.</td></tr>
                    @endforelse
                </x-admin.table>
                <div class="row row--between" style="padding:12px 16px">
                    <span class="text-muted">@if ($cart->coupon_code)Discount code: <span class="mono">{{ $cart->coupon_code }}</span>@endif</span>
                    <span class="fw-600">{{ money($cart->value) }}</span>
                </div>
            </x-admin.card>

            <x-admin.card title="Timeline" subtitle="Reminder emails and returns to the basket. No tracking pixels – opens are not recorded.">
                <x-admin.timeline>
                    @foreach ($timeline as $event)
                        <x-admin.timeline-item :icon="$event['icon']" :color="$event['color']" :time="$event['time']">{{ $event['text'] }}</x-admin.timeline-item>
                    @endforeach
                </x-admin.timeline>
            </x-admin.card>
        </div>
        <div class="layout__aside">
            <x-admin.card title="Customer">
                @if ($cart->user)
                    <p class="fw-600"><a href="{{ route('admin.customers.show', $cart->user) }}">{{ $cart->user->full_name ?: $cart->user->email }}</a></p>
                @endif
                @if ($email)
                    <p class="text-sm">{{ $email }}</p>
                    <ul class="text-sm text-muted mt-2" style="margin:8px 0 0;padding-left:18px">
                        <li>{{ $consent ? 'Agreed to marketing emails' : 'No marketing consent on file' }}</li>
                        @if ($unsubscribed)<li>Unsubscribed from basket reminders</li>@endif
                    </ul>
                @else
                    <p class="text-muted">No email address – the shopper didn’t reach the checkout email step.</p>
                @endif
            </x-admin.card>
            <x-admin.card title="Reminders">
                <p class="text-sm">
                    @if (! $remindersOn)
                        Reminder emails are switched off.
                    @elseif ($stopped)
                        Stopped: {{ AbandonedCartRecovery::STOP_REASONS[$cart->recovery_stop_reason] ?? $cart->recovery_stop_reason }}.
                    @elseif ($cart->converted_at)
                        This basket became an order.
                    @else
                        {{ $cart->recoveryEmails->count() }} of {{ count(AbandonedCartRecovery::steps()) }} reminders sent.
                    @endif
                </p>
                <x-admin.button class="mt-3" size="sm" icon="cog-6-tooth" :href="route('admin.settings.edit', 'abandoned_carts')">Reminder settings</x-admin.button>
            </x-admin.card>
        </div>
    </div>
@endsection
