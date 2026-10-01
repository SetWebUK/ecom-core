{{-- Abandoned checkouts: baskets idle for over an hour that never became orders. Email the customer from here. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Http\Controllers\Admin\AbandonedCartController;
    use Illuminate\Support\Str;

    $store = setting('store.name', config('app.name'));
    $phone = setting('store.phone');
@endphp

@section('title', 'Abandoned checkouts')

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header title="Abandoned checkouts" :back="route('admin.orders.index')" back-label="Back to orders"
                         subtitle="Baskets left for more than an hour without placing an order. Values use today’s prices.">
        <x-slot:actions>
            <x-admin.button icon="cog-6-tooth" :href="route('admin.settings.edit', 'abandoned_carts')">Reminder emails{{ $remindersOn ? '' : ' (off)' }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($remindersOn || $recovery['emails'] > 0 || $recovery['recovered'] > 0)
        <div class="grid-3 mb-4" data-recovery-stats>
            <x-admin.stat label="Reminders sent (30 days)" icon="envelope" :value="number_format($recovery['emails'])" :hint="number_format($recovery['clicks']).' returned to their basket'" />
            <x-admin.stat label="Recovered baskets (30 days)" icon="arrow-uturn-left" :value="number_format($recovery['recovered'])" hint="Ordered after a reminder" />
            <x-admin.stat label="Recovered revenue (30 days)" icon="banknotes" :value="money($recovery['revenue'])" hint="Paid orders" />
        </div>
    @endif

    @if ((int) ($summary->carts ?? 0) === 0 && ! $contact)
        <div class="card">
            <x-admin.empty icon="shopping-cart" title="No abandoned checkouts" description="When a shopper adds products to their basket but doesn’t finish checking out, the basket appears here after an hour." />
        </div>
    @else
        <div class="grid-3 mb-4">
            <x-admin.stat label="Abandoned baskets" icon="shopping-cart" :value="number_format((int) $summary->carts)" />
            <x-admin.stat label="With an email address" icon="envelope" :value="number_format((int) $summary->reachable)" hint="You can contact these shoppers" />
            <x-admin.stat label="On this page" icon="banknotes" :value="money($carts->sum('value'))" hint="Basket value at today’s prices" />
        </div>

        <x-admin.card flush>
            <x-admin.filters :search="false" :chips="$chips">
                <x-admin.filter-select name="contact" :options="AbandonedCartController::CONTACT" placeholder="All baskets" label="Contact details" />
            </x-admin.filters>

            @if ($carts->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No baskets match" size="sm">
                    <x-admin.button :href="route('admin.carts.index')">Show all</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table stack>
                    <x-slot:head>
                        <x-admin.th>Customer</x-admin.th>
                        <x-admin.th>Basket</x-admin.th>
                        <x-admin.th align="right">Value</x-admin.th>
                        <x-admin.th>Reminders</x-admin.th>
                        <x-admin.th sort="updated_at" first="desc">Last activity</x-admin.th>
                        <x-admin.th class="table__actions"><span class="sr-only">Actions</span></x-admin.th>
                    </x-slot:head>
                    @foreach ($carts as $cart)
                        @php
                            $email = $cart->contact_email;
                            $name = $cart->user?->first_name ?: null;
                            $lines = $cart->items->map(function ($item) {
                                $product = $item->product;
                                return [
                                    'name' => $product?->name ?? 'Product no longer available',
                                    'qty' => (int) $item->quantity,
                                    'image' => $item->variation?->image ?: $product?->images->first()?->path,
                                    'url' => $product && ! $product->trashed() ? $product->url : null,
                                ];
                            });
                            $body = 'Hi'.($name ? ' '.$name : '').",\n\nWe noticed you left some items in your basket at {$store}:\n\n"
                                .$lines->map(fn ($l) => '• '.$l['qty'].' × '.$l['name'].($l['url'] ? "\n  ".$l['url'] : ''))->implode("\n")
                                ."\n\nThey’re still available if you’d like to complete your order. If you have any questions, just reply to this email"
                                .($phone ? ' or call us on '.$phone : '').".\n\nThanks,\n{$store}";
                            $mailto = $email ? 'mailto:'.rawurlencode($email).'?subject='.rawurlencode('You left something in your basket at '.$store).'&body='.rawurlencode($body) : null;
                        @endphp
                        <tr>
                            <td class="stack-title" style="max-width:260px">
                                @if ($email)
                                    <div class="truncate fw-600">{{ $cart->user?->full_name ?: $email }}</div>
                                    @if ($cart->user)
                                        <div class="cell-sub truncate"><a href="{{ route('admin.customers.show', $cart->user) }}">{{ $email }}</a></div>
                                    @elseif ($cart->user?->full_name)
                                        <div class="cell-sub truncate">{{ $email }}</div>
                                    @else
                                        <div class="cell-sub">Email captured at checkout</div>
                                    @endif
                                @else
                                    <div class="text-muted">Unknown shopper</div>
                                    <div class="cell-sub">Didn’t reach the checkout email step</div>
                                @endif
                            </td>
                            <td data-label="Basket">
                                <div class="cart-items">
                                    @foreach ($lines->take(3) as $line)
                                        <x-admin.thumb :src="$line['image']" :alt="$line['name']" size="sm" :title="$line['qty'].' × '.$line['name']" />
                                    @endforeach
                                    <span class="text-sm" style="min-width:0">
                                        <span class="truncate" style="display:block;max-width:320px">{{ $lines->first()['qty'] }} × {{ $lines->first()['name'] }}</span>
                                        @if ($lines->count() > 1)<span class="cart-items__more">+ {{ $lines->count() - 1 }} more {{ Str::plural('product', $lines->count() - 1) }} ({{ $lines->sum('qty') }} items)</span>@endif
                                    </span>
                                </div>
                                @if ($cart->coupon_code)<div class="cell-sub">Discount code: <span class="mono">{{ $cart->coupon_code }}</span></div>@endif
                            </td>
                            <td class="num fw-600" data-label="Value">{{ money($cart->value) }}</td>
                            <td class="nowrap" data-label="Reminders">
                                @php($sent = $cart->recoveryEmails->count())
                                @if ($cart->recovery_stopped_at)
                                    <x-admin.badge size="sm">Stopped</x-admin.badge>
                                @elseif ($sent)
                                    <x-admin.badge size="sm" color="info">{{ $sent }} sent</x-admin.badge>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                                @if ($cart->recoveryEmails->whereNotNull('clicked_at')->isNotEmpty())<div class="cell-sub">Clicked</div>@endif
                            </td>
                            <td class="nowrap" data-label="Last activity"><x-admin.time :value="$cart->updated_at" format="relative" /></td>
                            <td class="table__actions">
                                @if ($mailto)
                                    <span class="row gap-1" style="flex-wrap:nowrap;justify-content:flex-end">
                                        <x-admin.button size="sm" icon="envelope" :href="$mailto">Email</x-admin.button>
                                        <x-admin.button size="sm" variant="ghost" icon="eye" label="View basket" :href="route('admin.carts.show', $cart->id)" />
                                        <x-admin.button size="sm" variant="ghost" icon="clipboard-document" label="Copy email address" x-data x-on:click="Sales.copy({{ \Illuminate\Support\Js::from($email) }}, 'Email address')" />
                                    </span>
                                @else
                                    <x-admin.button size="sm" variant="ghost" icon="eye" label="View basket" :href="route('admin.carts.show', $cart->id)" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$carts" />
            @endif
        </x-admin.card>
        <p class="text-xs text-muted mt-3">“Email” opens your email program with a ready-to-send reminder listing the products, with links to each one. Only contact shoppers who are happy to hear from you.</p>
    @endif
@endsection
