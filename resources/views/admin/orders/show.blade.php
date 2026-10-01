{{-- Order page (Shopify-style): header with status + actions, items / fulfilment / payment / timeline on the left,
     customer, addresses, payment details and attribution on the right. Modals: status, refund, email, addresses. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\Countries;
    use Pine\Commerce\Services\Admin\LocalTime;
    use Pine\Commerce\Services\Admin\NoteFormatter;
    use Pine\Commerce\Services\Admin\OrderStatus;
    use Pine\Commerce\Services\Admin\PaymentMethods;
    use Illuminate\Support\Str;

    $status = $order->status;
    $itemCount = (int) $order->items->sum('quantity');
    $refunded = (float) $order->refunded_total;
    $net = round((float) $order->total - $refunded, 2);
    $isPaid = in_array($status, ['processing', 'completed', 'refunded'], true) || ($order->paid_at && ! in_array($status, ['pending', 'failed', 'cancelled'], true));
    $paymentLabel = PaymentMethods::label($order->payment_method, $order->payment_method_title);
    $productEdit = fn ($id) => $id && Route::has('admin.products.edit') ? route('admin.products.edit', $id) : null;

    $addressText = function (string $type) use ($order): string {
        $lines = [];
        $name = trim($order->{$type.'_first_name'}.' '.$order->{$type.'_last_name'});
        foreach ([$name, $order->{$type.'_company'}, $order->{$type.'_address_1'}, $order->{$type.'_address_2'}, $order->{$type.'_city'}, $order->{$type.'_county'}, $order->{$type.'_postcode'}] as $line) {
            if (trim((string) $line) !== '') {
                $lines[] = trim($line);
            }
        }
        $country = $order->{$type.'_country'};
        if ($country && $country !== 'GB') {
            $lines[] = Countries::name($country);
        } elseif ($lines) {
            $lines[] = 'United Kingdom';
        }

        return implode("\n", $lines);
    };
    $shippingText = $addressText('shipping');
    $billingText = $addressText('billing');
    $sameAddress = $shippingText === $billingText || $shippingText === '';

    $paymentBadge = match (true) {
        $status === 'refunded' || ($refunded > 0 && $net <= 0) => ['Refunded', 'danger'],
        $refunded > 0 => ['Partially refunded', 'warning'],
        $isPaid => ['Paid', 'success'],
        $status === 'failed' => ['Payment failed', 'danger'],
        $status === 'cancelled' => ['Cancelled', 'gray'],
        default => ['Awaiting payment', 'attention'],
    };

    // Timeline grouped by UK day
    $noteDays = $order->notes->groupBy(fn ($note) => LocalTime::format($note->created_at, 'Y-m-d'));
    $today = LocalTime::now()->toDateString();
    $yesterday = LocalTime::now()->subDay()->toDateString();

    $addressErrors = $errors->getBag('address');
    $openAddress = $addressErrors->any() ? old('address_type') : null;
@endphp

@section('title', 'Order #'.$order->number)

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header :title="'#'.$order->number" :back="route('admin.orders.index')" back-label="Back to orders">
        <x-slot:badges>
            <x-admin.status-badge :status="$status" />
            @if ($paymentBadge[0] !== OrderStatus::label($status))
                <x-admin.badge :color="$paymentBadge[1]">{{ $paymentBadge[0] }}</x-admin.badge>
            @endif
            @if ($order->created_via === 'admin')
                <x-admin.badge color="outline">Created by staff</x-admin.badge>
            @endif
        </x-slot:badges>
        <x-slot:meta>
            <x-admin.time :value="$order->created_at" format="l j F Y \a\t H:i" />
            @if ($order->created_via === 'checkout') · Online store @endif
        </x-slot:meta>
        <x-slot:actions>
            <span class="pager-btns">
                <x-admin.button icon="chevron-up" label="Newer order" :href="$newerId ? route('admin.orders.show', $newerId) : null" :disabled="! $newerId" />
                <x-admin.button icon="chevron-down" label="Older order" :href="$olderId ? route('admin.orders.show', $olderId) : null" :disabled="! $olderId" />
            </span>
            <x-admin.dropdown label="Print" icon="printer">
                <x-admin.dropdown-item :href="route('admin.print', ['document' => 'packing-slip', 'orders' => $order->id])" icon="clipboard-document-list" target="_blank">Packing slip</x-admin.dropdown-item>
                <x-admin.dropdown-item :href="route('admin.print', ['document' => 'invoice', 'orders' => $order->id])" icon="document-text" target="_blank">Invoice</x-admin.dropdown-item>
                <div class="dropdown__sep"></div>
                <x-admin.dropdown-item :href="route('admin.orders.pdf', ['order' => $order->id, 'document' => 'invoice'])" icon="document-arrow-down">Invoice PDF</x-admin.dropdown-item>
                <x-admin.dropdown-item :href="route('admin.orders.pdf', ['order' => $order->id, 'document' => 'packing-slip'])" icon="document-arrow-down">Packing slip PDF</x-admin.dropdown-item>
                <x-admin.confirm as="menu-item" :action="route('admin.orders.invoice.regenerate', $order)" method="POST" icon="arrow-path" :danger="false"
                                 title="Regenerate the invoice?" confirm-label="Regenerate"
                                 message="The PDF is rebuilt from the current order details and invoice settings. An invoice number that was already issued never changes; a paid order without one gets the next number.">
                    Regenerate invoice
                </x-admin.confirm>
            </x-admin.dropdown>
            <x-admin.dropdown label="More actions">
                <x-admin.dropdown-item :href="route('admin.orders.edit', $order)" icon="pencil-square">{{ $editable ? 'Edit order' : 'Edit details' }}</x-admin.dropdown-item>
                <x-admin.dropdown-item icon="arrow-path" x-on:click="$dispatch('open-modal', 'order-status')">Change status</x-admin.dropdown-item>
                @if ($canRefund)
                    <x-admin.dropdown-item icon="receipt-refund" x-on:click="$dispatch('open-modal', 'refund')">Refund</x-admin.dropdown-item>
                @endif
                <x-admin.dropdown-item icon="envelope" x-on:click="$dispatch('open-modal', 'order-email')">Send email</x-admin.dropdown-item>
                @if ($payUrl)
                    <x-admin.dropdown-item icon="link" x-on:click="Sales.copy({{ \Illuminate\Support\Js::from($payUrl) }}, 'Payment link')">Copy payment link</x-admin.dropdown-item>
                @endif
                <x-admin.dropdown-item :href="route('admin.orders.create', array_filter(['customer' => $customer?->id]))" icon="document-duplicate">New order for this customer</x-admin.dropdown-item>
                @if (! in_array($status, ['cancelled', 'refunded', 'completed'], true))
                    <div class="dropdown__sep"></div>
                    <x-admin.confirm as="menu-item" :action="route('admin.orders.status', $order)" method="PUT" icon="x-circle"
                                     :title="'Cancel order #'.$order->number.'?'" confirm-label="Cancel order"
                                     :message="$isPaid ? 'Cancelling doesn’t refund the customer – refund them first if they have paid. Stock held by the order is put back.' : 'Stock held by the order is put back. The customer can’t pay for it any more.'">
                        <x-slot:fields><input type="hidden" name="status" value="cancelled"></x-slot:fields>
                        Cancel order
                    </x-admin.confirm>
                @endif
                @if ($deletable)
                    <x-admin.confirm as="menu-item" :action="route('admin.orders.destroy', $order)" icon="trash"
                                     :title="'Delete order #'.$order->number.'?'" confirm-label="Delete order"
                                     message="Unpaid orders only. It disappears from the orders list and reports; stock it held is put back.">Delete order</x-admin.confirm>
                @endif
            </x-admin.dropdown>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- What needs doing next ------------------------------------------------------------------ --}}
    @if ($status === 'pending')
        <x-admin.callout type="warning" class="mb-4" title="Awaiting payment" icon="clock">
            The customer hasn’t paid yet.
            @if ($payUrl) You can email them a link to pay, or mark the order as paid once the money has arrived. @endif
            <div class="row mt-2">
                @if ($payUrl)
                    <form method="POST" action="{{ route('admin.orders.email', $order) }}" style="display:contents">
                        @csrf
                        <input type="hidden" name="email" value="{{ \Pine\Commerce\Mail\Admin\CustomerInvoice::class }}">
                        <x-admin.button type="submit" size="sm" icon="envelope">Email payment link</x-admin.button>
                    </form>
                    <x-admin.button size="sm" icon="link" x-data x-on:click="Sales.copy({{ \Illuminate\Support\Js::from($payUrl) }}, 'Payment link')">Copy payment link</x-admin.button>
                @endif
                <x-admin.confirm :action="route('admin.orders.status', $order)" method="PUT" size="sm" icon="banknotes" :danger="false"
                                 title="Mark as paid?" confirm-label="Mark as paid" message="The order moves to Processing and the customer gets their “order received” email.">
                    <x-slot:fields><input type="hidden" name="status" value="processing"><input type="hidden" name="status_note" value="Payment received."></x-slot:fields>
                    Mark as paid
                </x-admin.confirm>
            </div>
        </x-admin.callout>
    @elseif ($status === 'on-hold')
        <x-admin.callout type="warning" class="mb-4" title="On hold" icon="pause-circle">
            Usually waiting for a bank transfer ({{ $paymentLabel }}). Check the money has arrived, then mark the order as paid to fulfil it.
            <div class="row mt-2">
                <x-admin.confirm :action="route('admin.orders.status', $order)" method="PUT" size="sm" icon="banknotes" :danger="false"
                                 title="Payment received?" confirm-label="Mark as paid" message="The order moves to Processing (ready to send) and the customer gets their “order received” email.">
                    <x-slot:fields><input type="hidden" name="status" value="processing"><input type="hidden" name="status_note" value="Payment received."></x-slot:fields>
                    Payment received – mark as paid
                </x-admin.confirm>
            </div>
        </x-admin.callout>
    @elseif ($status === 'failed')
        <x-admin.callout type="danger" class="mb-4" title="Payment failed">The customer’s payment didn’t go through. They can try again from the checkout, or you can send them a payment link.</x-admin.callout>
    @elseif ($status === 'cancelled')
        <x-admin.callout type="neutral" class="mb-4" icon="x-circle">This order was cancelled{{ $isPaid ? '' : ' before it was paid' }}.</x-admin.callout>
    @endif

    <div class="layout">
        <div class="layout__main">
            {{-- Fulfilment ------------------------------------------------------------------------ --}}
            @if ($status === 'processing')
                <x-admin.card>
                    <x-slot:header>
                        <div class="flex-1 row">
                            <h2 class="card__title">Ready to send</h2>
                            <x-admin.badge color="attention" dot>Unfulfilled</x-admin.badge>
                        </div>
                    </x-slot:header>
                    <form method="POST" action="{{ route('admin.orders.fulfil', $order) }}" class="stack-fields">
                        @csrf
                        <p class="text-sm text-muted">{{ $itemCount }} {{ Str::plural('item', $itemCount) }} to {{ $order->shipping_name ?: $order->billing_name }}{{ $order->shipping_method_title ? ' · '.$order->shipping_method_title : '' }}.</p>
                        <div class="form-grid">
                            <x-admin.input name="tracking_carrier" label="Carrier" optional :value="$order->tracking_carrier ?? 'DPD'" list="carrier-options" autocomplete="off" />
                            <x-admin.input name="tracking_number" label="Tracking number" optional :value="$order->tracking_number" autocomplete="off" />
                        </div>
                        <datalist id="carrier-options">@foreach ($carriers as $carrier)<option value="{{ $carrier }}">@endforeach</datalist>
                        <div class="row row--between">
                            <p class="text-xs text-muted flex-1">The customer is emailed that their order is on its way{{ ' (with the tracking number, if you add one)' }}.</p>
                            <x-admin.button type="submit" variant="primary" icon="truck">Mark as completed</x-admin.button>
                        </div>
                    </form>
                </x-admin.card>
            @elseif ($status === 'completed')
                <x-admin.card>
                    <x-slot:header>
                        <div class="flex-1 row">
                            <h2 class="card__title">Fulfilled</h2>
                            <x-admin.badge color="success" dot>Completed</x-admin.badge>
                        </div>
                    </x-slot:header>
                    <div x-data="{ edit: {{ $errors->hasAny(['tracking_carrier', 'tracking_number']) ? 'true' : 'false' }} }">
                        <div class="row row--between" x-show="!edit">
                            <div class="text-sm">
                                @if ($order->completed_at)<div>Completed <x-admin.time :value="$order->completed_at" format="datetime" /></div>@endif
                                @if ($order->tracking_number)
                                    <div class="mt-1">Tracking: <strong>{{ $order->tracking_carrier }}</strong> <span class="mono">{{ $order->tracking_number }}</span>
                                        <button type="button" class="btn btn--plain" @click="Sales.copy({{ \Illuminate\Support\Js::from($order->tracking_number) }}, 'Tracking number')">Copy</button>
                                    </div>
                                @else
                                    <div class="text-muted mt-1">No tracking number.</div>
                                @endif
                            </div>
                            <button type="button" class="btn btn--sm" @click="edit = true"><span>{{ $order->tracking_number ? 'Edit tracking' : 'Add tracking' }}</span></button>
                        </div>
                        <form method="POST" action="{{ route('admin.orders.fulfil', $order) }}" class="stack-fields" x-show="edit" x-cloak>
                            @csrf
                            <div class="form-grid">
                                <x-admin.input name="tracking_carrier" label="Carrier" optional :value="$order->tracking_carrier ?? 'DPD'" list="carrier-options" autocomplete="off" />
                                <x-admin.input name="tracking_number" label="Tracking number" optional :value="$order->tracking_number" autocomplete="off" />
                            </div>
                            <datalist id="carrier-options">@foreach ($carriers as $carrier)<option value="{{ $carrier }}">@endforeach</datalist>
                            <p class="text-xs text-muted">Saving doesn’t email the customer again – use “Send email” for that.</p>
                            <div class="row row--end">
                                <button type="button" class="btn" @click="edit = false"><span>Cancel</span></button>
                                <x-admin.button type="submit" variant="primary">Save tracking</x-admin.button>
                            </div>
                        </form>
                    </div>
                </x-admin.card>
            @endif

            {{-- Items ----------------------------------------------------------------------------- --}}
            <x-admin.card flush>
                <x-slot:header>
                    <div class="flex-1">
                        <h2 class="card__title">Items <span class="text-muted fw-500">({{ $itemCount }})</span></h2>
                    </div>
                </x-slot:header>
                @if ($order->items->isEmpty())
                    <x-admin.empty icon="cube" title="No items on this order" size="sm" />
                @else
                    <ul class="line-items mt-2">
                        @foreach ($order->items as $item)
                            @php
                                $image = $item->variation?->image ?: $item->product?->images->first()?->path;
                                $editUrl = $item->product && ! $item->product->trashed() ? $productEdit($item->product_id) : null;
                                $fullyRefunded = $item->refunded_quantity >= $item->quantity && $item->quantity > 0;
                                $discounted = (float) $item->subtotal - (float) $item->total > 0.004;
                            @endphp
                            <li @class(['line-item', 'is-refunded' => $fullyRefunded])>
                                <x-admin.thumb :src="$image" :alt="$item->name" />
                                <div class="min-w-0">
                                    @if ($editUrl)
                                        <a href="{{ $editUrl }}" class="line-item__name link-subtle">{{ $item->name }}</a>
                                    @else
                                        <span class="line-item__name">{{ $item->name }}</span>
                                    @endif
                                    <div class="line-item__meta">
                                        @if ($item->sku)<span>SKU: <span class="mono">{{ $item->sku }}</span></span>@endif
                                        @foreach ((array) $item->options as $label => $value)
                                            <span>{{ $label }}: {{ is_scalar($value) ? $value : json_encode($value) }}</span>
                                        @endforeach
                                        @if ($item->product?->trashed())<span class="text-warning">Product deleted</span>@endif
                                    </div>
                                    @if ($item->refunded_quantity > 0)
                                        <x-admin.badge color="danger" size="sm" class="mt-1">{{ $item->refunded_quantity }} refunded</x-admin.badge>
                                    @endif
                                </div>
                                <div class="line-item__price">{{ money($item->unit_price) }} × {{ $item->quantity }}</div>
                                <div class="line-item__total">
                                    @if ($discounted)<span class="money-was">{{ money($item->subtotal) }}</span>@endif
                                    @if ($order->pricesIncludeTax() && (float) $item->tax > 0)
                                        {{ money($item->displayTotal(true)) }}<span class="cell-sub">incl. {{ money($item->tax) }} tax</span>
                                    @else
                                        {{ money($item->total) }}
                                        @if ((float) $item->tax > 0)<span class="cell-sub">+ {{ money($item->tax) }} tax</span>@endif
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>

            {{-- Payment --------------------------------------------------------------------------- --}}
            <x-admin.card>
                <x-slot:header>
                    <div class="flex-1 row">
                        <h2 class="card__title">Payment</h2>
                        <x-admin.badge :color="$paymentBadge[1]" dot>{{ $paymentBadge[0] }}</x-admin.badge>
                    </div>
                </x-slot:header>
                <div class="sum-rows">
                    <div class="sum-row"><span class="sum-row__label">Subtotal <span class="sum-row__hint">{{ $itemCount }} {{ Str::plural('item', $itemCount) }}</span></span><span>{{ money($order->subtotal) }}</span></div>
                    @if ((float) $order->discount_total > 0)
                        <div class="sum-row"><span class="sum-row__label">Discount @if ($order->coupon_code)<span class="sum-row__hint mono">{{ str_replace(',', ', ', $order->coupon_code) }}</span>@endif</span><span>−{{ money($order->discount_total) }}</span></div>
                    @endif
                    <div class="sum-row"><span class="sum-row__label">Shipping <span class="sum-row__hint">{{ $order->shipping_method_title ?: '—' }}</span></span><span>{{ (float) $order->shipping_total > 0 ? money($order->shipping_total) : 'Free' }}</span></div>
                    @foreach ($order->taxBreakdown() as $taxLine)
                        <div class="sum-row"><span class="sum-row__label">{{ $taxLine['label'] }}@if ((float) $order->shipping_tax > 0 && $loop->first && count($order->taxBreakdown()) === 1) <span class="sum-row__hint">incl. {{ money($order->shipping_tax) }} on shipping</span>@endif</span><span>{{ money($taxLine['amount']) }}</span></div>
                    @endforeach
                    <div class="sum-row sum-row--total"><span>Total</span><span>{{ money($order->total) }}</span></div>
                    @if ($isPaid)
                        <div class="sum-row sum-row--muted"><span class="sum-row__label">Paid by customer <span class="sum-row__hint">{{ $paymentLabel }}</span></span><span>{{ money($order->total) }}</span></div>
                    @endif
                    @if ($refunded > 0)
                        <div class="sum-row sum-row--danger"><span>Refunded</span><span>−{{ money($refunded) }}</span></div>
                        <div class="sum-row sum-row--total"><span>Net payment</span><span>{{ money($net) }}</span></div>
                    @endif
                </div>

                @if ($order->refunds->isNotEmpty())
                    <div class="divider"></div>
                    <h3 class="card__section-title">Refunds</h3>
                    <ul class="stack stack--sm" style="list-style:none;margin:0;padding:0">
                        @foreach ($order->refunds as $refund)
                            <li class="text-sm">
                                <div class="row row--between">
                                    <strong>{{ money($refund->amount) }}</strong>
                                    <span class="text-muted text-xs"><x-admin.time :value="$refund->created_at" />{{ $refund->user ? ' · '.$refund->user->full_name : '' }}</span>
                                </div>
                                @if ($refund->reason)<div class="text-muted">{{ $refund->reason }}</div>@endif
                                @php $refundItems = collect((array) $refund->items)->filter(fn ($i) => ($i['quantity'] ?? 0) > 0 || ($i['type'] ?? null) === 'shipping'); @endphp
                                @if ($refundItems->isNotEmpty())
                                    <div class="text-xs text-muted">{{ $refundItems->map(fn ($i) => ($i['type'] ?? null) === 'shipping' ? 'Shipping '.money($i['amount'] ?? 0) : ($i['quantity'] ?? 0).' × '.($i['name'] ?? 'item'))->implode(', ') }}{{ $refund->restock ? ' · returned to stock' : '' }}</div>
                                @endif
                                <div class="text-xs text-subtle">{{ $refund->gateway_refund_id ? 'Refunded through '.$paymentLabel.' (ref '.$refund->gateway_refund_id.')' : 'Recorded manually' }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canRefund || $payUrl)
                    <x-slot:footer>
                        @if ($payUrl)
                            <x-admin.button size="sm" icon="link" x-data x-on:click="Sales.copy({{ \Illuminate\Support\Js::from($payUrl) }}, 'Payment link')">Copy payment link</x-admin.button>
                        @endif
                        @if ($canRefund)
                            <x-admin.button icon="receipt-refund" x-data x-on:click="$dispatch('open-modal', 'refund')">Refund</x-admin.button>
                        @endif
                    </x-slot:footer>
                @endif
            </x-admin.card>

            {{-- Timeline -------------------------------------------------------------------------- --}}
            <x-admin.card title="Timeline" subtitle="Order history and notes, newest first. Private notes are only seen by staff.">
                <form method="POST" action="{{ route('admin.orders.notes.store', $order) }}" x-data="{ type: @js(old('type', 'private')), text: @js(old('note', '')) }" class="mb-4">
                    @csrf
                    <div class="composer" :class="{ 'is-customer': type === 'customer' }">
                        <label for="f-note" class="sr-only">Note</label>
                        <textarea name="note" id="f-note" class="textarea" rows="2" maxlength="5000" x-model="text"
                                  :placeholder="type === 'customer' ? @js('Write a note to the customer – it will be emailed to '.$order->email) : 'Add a private note for your team…'"
                                  @keydown.ctrl.enter="$el.form.requestSubmit()" @keydown.meta.enter="$el.form.requestSubmit()"></textarea>
                        @if ($errors->note->has('note'))<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $errors->note->first('note') }}</span></p>@endif
                        <div class="composer__bar">
                            <div class="segmented" role="radiogroup" aria-label="Note type">
                                <label class="segmented__item" :class="{ 'is-active': type === 'private' }"><input type="radio" name="type" value="private" x-model="type" class="sr-only"> <x-admin.icon name="lock-closed" size="xs" />&nbsp;Private note</label>
                                <label class="segmented__item" :class="{ 'is-active': type === 'customer' }"><input type="radio" name="type" value="customer" x-model="type" class="sr-only"> <x-admin.icon name="envelope" size="xs" />&nbsp;Note to customer</label>
                            </div>
                            <button type="submit" class="btn btn--sm" :class="{ 'btn--primary': text.trim() !== '' }" :disabled="text.trim() === ''">
                                <span x-text="type === 'customer' ? 'Send to customer' : 'Add note'">Add note</span>
                            </button>
                        </div>
                    </div>
                </form>

                @if ($order->notes->isEmpty())
                    <p class="text-sm text-muted">No history yet.</p>
                @else
                    <x-admin.timeline>
                        @foreach ($noteDays as $day => $notes)
                            <li class="timeline__day">{{ $day === $today ? 'Today' : ($day === $yesterday ? 'Yesterday' : \Illuminate\Support\Carbon::parse($day)->format('j F Y')) }}</li>
                            @foreach ($notes as $note)
                                @php
                                    $icon = match (true) {
                                        $note->is_customer_note => 'envelope',
                                        ! $note->is_system => 'chat-bubble-left',
                                        (bool) preg_match('/refund/i', $note->note) => 'receipt-refund',
                                        (bool) preg_match('/payment|paid|transaction/i', $note->note) => 'credit-card',
                                        (bool) preg_match('/email/i', $note->note) => 'envelope',
                                        (bool) preg_match('/stock/i', $note->note) => 'cube',
                                        (bool) preg_match('/status changed/i', $note->note) => 'arrow-path',
                                        default => null,
                                    };
                                    $color = $note->is_customer_note ? 'primary' : (preg_match('/to Completed/i', $note->note) ? 'success' : (preg_match('/refund|failed|cancelled/i', $note->note) && $note->is_system ? 'danger' : null));
                                @endphp
                                <x-admin.timeline-item :icon="$icon" :color="$color" :time="$note->created_at" :author="$note->is_system ? null : ($note->user?->full_name ?? 'Staff')"
                                                       :bubble="! $note->is_system" :customer="$note->is_customer_note">
                                    {!! NoteFormatter::html($note->note) !!}
                                    <x-slot:meta>
                                        @if ($note->is_customer_note)
                                            <x-admin.badge color="info" size="sm" icon="envelope">Sent to customer</x-admin.badge>
                                        @elseif (! $note->is_system)
                                            <x-admin.badge color="gray" size="sm" icon="lock-closed">Private</x-admin.badge>
                                        @endif
                                        @unless ($note->is_system)
                                            <x-admin.confirm :action="route('admin.orders.notes.destroy', [$order, $note])" variant="plain" size="sm"
                                                             title="Delete this note?" message="It will be removed from the timeline. Emails already sent can’t be recalled." confirm-label="Delete note"
                                                             class="text-xs">Delete</x-admin.confirm>
                                        @endunless
                                    </x-slot:meta>
                                </x-admin.timeline-item>
                            @endforeach
                        @endforeach
                    </x-admin.timeline>
                @endif
            </x-admin.card>
        </div>

        <div class="layout__aside">
            @if ($order->customer_note)
                <x-admin.card title="Note from the customer">
                    <p class="text-sm" style="white-space:pre-line">{{ $order->customer_note }}</p>
                </x-admin.card>
            @endif

            <x-admin.card title="Customer">
                <div class="aside-section">
                    @if ($customer)
                        <a href="{{ route('admin.customers.show', $customer) }}" class="fw-600">{{ $customer->full_name ?: $order->billing_name ?: $order->email }}</a>
                        @unless ($order->user_id)<div class="text-xs text-muted">Checked out as a guest</div>@endunless
                    @else
                        <span class="fw-600">{{ $order->billing_name ?: $order->email }}</span>
                        <div class="text-xs text-muted">Guest checkout – no account</div>
                    @endif
                    <div class="text-sm text-muted mt-1">
                        @if ($customerOrders > 1)
                            <a href="{{ $customer ? route('admin.orders.index', ['customer' => $customer->id, 'status' => 'all']) : route('admin.orders.index', ['q' => $order->email, 'status' => 'all']) }}">{{ $customerOrders }} orders</a>
                        @else
                            First order
                        @endif
                    </div>
                </div>
                <div class="aside-section">
                    <div class="aside-section__title">Contact information</div>
                    <div class="stack stack--xs">
                        <div class="contact-line">
                            <x-admin.icon name="envelope" />
                            <a href="mailto:{{ $order->email }}">{{ $order->email }}</a>
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="Sales.copy({{ \Illuminate\Support\Js::from($order->email) }}, 'Email address')" aria-label="Copy email address" title="Copy email address"><x-admin.icon name="clipboard-document" /></button>
                        </div>
                        @if ($order->phone)
                            <div class="contact-line"><x-admin.icon name="phone" /><a href="tel:{{ preg_replace('/[^0-9+]/', '', $order->phone) }}">{{ $order->phone }}</a></div>
                        @else
                            <div class="contact-line text-muted"><x-admin.icon name="phone" />No phone number</div>
                        @endif
                    </div>
                </div>
                <div class="aside-section">
                    <div class="aside-section__title">
                        Shipping address
                        <span class="row gap-1">
                            @if ($shippingText !== '')
                                <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="Sales.copy({{ \Illuminate\Support\Js::from($shippingText) }}, 'Shipping address')" aria-label="Copy shipping address" title="Copy"><x-admin.icon name="clipboard-document" /></button>
                            @endif
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="$dispatch('open-modal', 'edit-shipping')" aria-label="Edit shipping address" title="Edit"><x-admin.icon name="pencil-square" /></button>
                        </span>
                    </div>
                    @if ($shippingText !== '')
                        <div class="address">{{ $shippingText }}</div>
                        @if ($order->shipping_phone && $order->shipping_phone !== $order->phone)<div class="text-sm mt-1">Tel: {{ $order->shipping_phone }}</div>@endif
                        @php $mapQuery = trim($order->shipping_address_1.' '.$order->shipping_postcode); @endphp
                        @if ($mapQuery !== '')
                            <a class="text-xs" href="https://www.google.com/maps/search/?api=1&query={{ urlencode($mapQuery) }}" target="_blank" rel="noopener noreferrer">View map</a>
                        @endif
                    @else
                        <p class="text-sm text-muted">No shipping address.</p>
                    @endif
                    @if ($order->shipping_method_title)
                        <div class="text-sm mt-2"><x-admin.badge color="gray" icon="truck">{{ $order->shipping_method_title }}</x-admin.badge></div>
                    @endif
                </div>
                <div class="aside-section">
                    <div class="aside-section__title">
                        Billing address
                        <span class="row gap-1">
                            @if (! $sameAddress && $billingText !== '')
                                <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="Sales.copy({{ \Illuminate\Support\Js::from($billingText) }}, 'Billing address')" aria-label="Copy billing address" title="Copy"><x-admin.icon name="clipboard-document" /></button>
                            @endif
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="$dispatch('open-modal', 'edit-billing')" aria-label="Edit billing address" title="Edit"><x-admin.icon name="pencil-square" /></button>
                        </span>
                    </div>
                    @if ($sameAddress && $billingText !== '')
                        <p class="text-sm text-muted">Same as shipping address</p>
                    @elseif ($billingText !== '')
                        <div class="address">{{ $billingText }}</div>
                    @else
                        <p class="text-sm text-muted">No billing address.</p>
                    @endif
                </div>
            </x-admin.card>

            <x-admin.card title="Payment details">
                <dl class="kv kv--stacked">
                    <dt>Method</dt>
                    <dd>{{ $paymentLabel }}</dd>
                    @if ($order->transaction_id)
                        <dt>Transaction ID</dt>
                        <dd class="row gap-1" style="flex-wrap:nowrap">
                            <span class="mono text-sm truncate" title="{{ $order->transaction_id }}">{{ $order->transaction_id }}</span>
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="Sales.copy({{ \Illuminate\Support\Js::from($order->transaction_id) }}, 'Transaction ID')" aria-label="Copy transaction ID" title="Copy"><x-admin.icon name="clipboard-document" /></button>
                        </dd>
                        @if ($transactionLink)
                            <dd><a href="{{ $transactionLink['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm">{{ $transactionLink['label'] }} <x-admin.icon name="arrow-top-right-on-square" size="xs" style="display:inline;vertical-align:-2px" /></a></dd>
                        @endif
                    @endif
                    @if ($order->paid_at)
                        <dt>Paid</dt>
                        <dd><x-admin.time :value="$order->paid_at" format="datetime" /></dd>
                    @endif
                    @if (! $order->transaction_id && ! $order->paid_at && ! $isPaid)
                        <dt>Status</dt><dd class="text-muted">Not paid yet</dd>
                    @endif
                    @if (\Pine\Commerce\Services\Invoices\Invoices::numbering())
                        <dt>Invoice</dt>
                        @if ($order->invoice_number)
                            <dd><a href="{{ route('admin.orders.pdf', ['order' => $order->id, 'document' => 'invoice', 'inline' => 1]) }}" target="_blank" class="mono">{{ $order->invoice_number }}</a>@if ($order->invoice_date) <span class="text-muted text-sm">· <x-admin.time :value="$order->invoice_date" format="date" /></span>@endif</dd>
                        @else
                            <dd class="text-muted">Numbered when the order is {{ \Pine\Commerce\Services\Invoices\Invoices::assignOn() === 'completed' ? 'completed' : 'paid' }}</dd>
                        @endif
                    @endif
                </dl>
            </x-admin.card>

            @if ($attribution || $order->ip_address || $order->user_agent)
                <x-admin.card title="Where the order came from">
                    @if ($attribution)
                        <dl class="kv">
                            @foreach ($attribution as $row)
                                <dt>{{ $row['label'] }}</dt>
                                <dd class="text-sm">@if (preg_match('#^https?://#', $row['value']))<a href="{{ $row['value'] }}" target="_blank" rel="noopener noreferrer nofollow" class="break">{{ Str::limit(preg_replace('#^https?://#', '', $row['value']), 60) }}</a>@else{{ $row['value'] }}@endif</dd>
                            @endforeach
                        </dl>
                    @endif
                    @if ($order->ip_address || $order->user_agent)
                        <details class="mt-3 text-sm">
                            <summary class="link" style="cursor:pointer">Technical details</summary>
                            <dl class="kv kv--stacked mt-2">
                                @if ($order->ip_address)<dt>IP address</dt><dd class="mono">{{ $order->ip_address }}</dd>@endif
                                @if ($order->user_agent)<dt>Browser</dt><dd class="text-xs text-muted break">{{ $order->user_agent }}</dd>@endif
                            </dl>
                        </details>
                    @endif
                </x-admin.card>
            @endif
        </div>
    </div>

    {{-- Modals ---------------------------------------------------------------------------------- --}}
    <x-admin.modal name="order-status" title="Change order status" size="sm" :open="$errors->hasAny(['status'])">
        <form method="POST" action="{{ route('admin.orders.status', $order) }}" id="status-form" class="stack-fields">
            @csrf
            @method('PUT')
            <fieldset class="fieldset">
                <legend class="sr-only">New status</legend>
                <div class="stack stack--xs">
                    @foreach (\Pine\Commerce\Models\Order::STATUSES as $value => $label)
                        <label class="check">
                            <input type="radio" class="radio" name="status" value="{{ $value }}" @checked(old('status', $status) === $value)>
                            <span class="check__text">
                                <span class="check__label">{{ $label }} @if ($value === $status)<span class="text-subtle fw-400">(current)</span>@endif</span>
                                <span class="check__help">{{ [
                                    'pending' => 'Not paid yet.',
                                    'processing' => 'Paid – ready to send. Emails the customer an order confirmation.',
                                    'on-hold' => 'Waiting for payment or a check before sending.',
                                    'completed' => 'Sent. Emails the customer that it’s on its way.',
                                    'cancelled' => 'Won’t be sent. Stock is put back. Doesn’t refund – use Refund for that.',
                                    'refunded' => 'Money returned. Use Refund to record the amount.',
                                    'failed' => 'Payment failed.',
                                ][$value] ?? '' }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-admin.input name="status_note" label="Note" optional placeholder="Added to the timeline" maxlength="500" />
        </form>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button type="submit" form="status-form" variant="primary">Change status</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>

    <x-admin.modal name="order-email" title="Send an email" size="sm" :open="$errors->has('email') && ! $openAddress">
        <form method="POST" action="{{ route('admin.orders.email', $order) }}" id="email-form">
            @csrf
            <div class="stack stack--xs">
                @foreach ($emails as $class => $label)
                    @php $toStore = \Pine\Commerce\Services\Admin\OrderManager::isAdminEmail($class); @endphp
                    <label class="check">
                        <input type="radio" class="radio" name="email" value="{{ $class }}" @checked($loop->first)>
                        <span class="check__text">
                            <span class="check__label">{{ $label }}</span>
                            <span class="check__help">
                                @if ($class === \Pine\Commerce\Mail\Admin\CustomerInvoice::class)
                                    To {{ $order->email }}{{ $payUrl ? ' – with a link to pay' : '' }}
                                    <span style="display:block" data-invoice-attachment="{{ \Pine\Commerce\Mail\Admin\CustomerInvoice::invoiceAttachment($order)['reason'] }}">{{ \Pine\Commerce\Mail\Admin\CustomerInvoice::attachmentNote($order) }}.</span>
                                @else
                                    To {{ $toStore ? 'the store (staff)' : $order->email }}
                                @endif
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        </form>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button type="submit" form="email-form" variant="primary" icon="paper-airplane">Send email</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>

    @if ($canRefund)
        @include('commerce::admin.orders._refund-modal')
    @endif

    @foreach (['shipping' => 'Shipping address', 'billing' => 'Billing address'] as $type => $title)
        <x-admin.modal :name="'edit-'.$type" :title="'Edit '.Str::lower($title)" size="lg" :open="$openAddress === $type">
            <form method="POST" action="{{ route('admin.orders.address', $order) }}" id="address-form-{{ $type }}" class="stack-fields">
                @csrf
                @method('PUT')
                <input type="hidden" name="address_type" value="{{ $type }}">
                <div class="form-grid">
                    <x-admin.input :name="$type.'_first_name'" label="First name" required :value="$order->{$type.'_first_name'}" bag="address" autocomplete="off" />
                    <x-admin.input :name="$type.'_last_name'" label="Last name" :value="$order->{$type.'_last_name'}" bag="address" autocomplete="off" />
                </div>
                <x-admin.input :name="$type.'_company'" label="Company" optional :value="$order->{$type.'_company'}" bag="address" autocomplete="off" />
                <x-admin.input :name="$type.'_address_1'" label="Address" :value="$order->{$type.'_address_1'}" bag="address" autocomplete="off" />
                <x-admin.input :name="$type.'_address_2'" label="Apartment, suite, etc." optional :value="$order->{$type.'_address_2'}" bag="address" autocomplete="off" />
                <div class="form-grid form-grid--3">
                    <x-admin.input :name="$type.'_city'" label="Town / city" :value="$order->{$type.'_city'}" bag="address" autocomplete="off" />
                    <x-admin.input :name="$type.'_county'" label="County" optional :value="$order->{$type.'_county'}" bag="address" autocomplete="off" />
                    <x-admin.input :name="$type.'_postcode'" label="Postcode" :value="$order->{$type.'_postcode'}" bag="address" autocomplete="off" />
                </div>
                <x-admin.field label="Country" :for="'f-'.$type.'_country'" :error="$type.'_country'" bag="address">
                    <select name="{{ $type }}_country" id="f-{{ $type }}_country" class="select">
                        @foreach (Countries::options() as $code => $countryName)
                            <option value="{{ $code }}" @selected(old($type.'_country', $order->{$type.'_country'} ?: 'GB') === $code)>{{ $countryName }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <div class="form-grid">
                    @if ($type === 'billing')
                        <x-admin.input name="email" type="email" label="Email" required :value="$order->email" bag="address" id="f-billing-email" autocomplete="off" help="Order emails go to this address." />
                        <x-admin.input name="phone" type="tel" label="Phone" optional :value="$order->phone" bag="address" id="f-billing-phone" autocomplete="off" />
                    @else
                        <x-admin.input name="shipping_phone" type="tel" label="Delivery phone" optional :value="$order->shipping_phone" bag="address" autocomplete="off" help="For the courier." />
                    @endif
                </div>
            </form>
            <x-slot:footer>
                <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                <x-admin.button type="submit" :form="'address-form-'.$type" variant="primary">Save address</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach
@endsection
