{{-- Refund modal (order page). Expects $order, $refundLines, $refundable, $shippingRefundable, $gatewayRefunds, $paymentLabel. --}}
@php
    $refundErrors = $errors->getBag('refund');
    $config = [
        'lines' => array_map(fn ($l) => ['id' => $l['id'], 'unit' => (float) $l['unit'], 'remaining' => $l['remaining']], $refundLines),
        'shippingMax' => (float) $shippingRefundable,
        'refundable' => (float) $refundable,
        'old' => $refundErrors->any() ? ['lines' => old('lines', []), 'shipping' => old('shipping'), 'amount' => old('amount')] : null,
    ];
@endphp
<x-admin.modal name="refund" title="Refund" size="lg" :open="$refundErrors->any()">
    <form method="POST" action="{{ route('admin.orders.refunds.store', $order) }}" id="refund-form" x-data="refundForm(@js($config))" class="stack">
        @csrf
        @if ($refundErrors->any())
            <x-admin.callout type="danger" title="The refund wasn’t made">
                <ul style="margin:0;padding-left:18px">@foreach ($refundErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </x-admin.callout>
        @endif

        <div>
            <div class="row row--between mb-2">
                <h3 class="card__section-title" style="margin:0">Items to refund</h3>
                <button type="button" class="btn btn--plain" @click="everything()">Refund everything</button>
            </div>
            <div class="table-wrap table-wrap--sticky-off" style="border:1px solid var(--border-subtle);border-radius:var(--radius-lg)">
                <table class="table table--compact table--static-head">
                    <thead><tr><th scope="col">Item</th><th scope="col" class="num">Price</th><th scope="col" class="num">Quantity</th><th scope="col" class="num">Refund</th></tr></thead>
                    <tbody>
                        @foreach ($refundLines as $line)
                            <tr>
                                <td>
                                    <div class="fw-600">{{ $line['name'] }}</div>
                                    <div class="cell-sub">
                                        @if ($line['sku'])SKU {{ $line['sku'] }} · @endif
                                        {{ $line['quantity'] }} ordered{{ $line['refunded'] ? ', '.$line['refunded'].' already refunded' : '' }}
                                    </div>
                                </td>
                                <td class="num">{{ money($line['unit']) }}</td>
                                <td class="num">
                                    @if ($line['remaining'] > 0)
                                        <label class="sr-only" for="refund-qty-{{ $line['id'] }}">Quantity of {{ $line['name'] }} to refund</label>
                                        <span class="row row--end gap-1" style="flex-wrap:nowrap">
                                            <input type="number" min="0" max="{{ $line['remaining'] }}" step="1" inputmode="numeric" id="refund-qty-{{ $line['id'] }}"
                                                   name="lines[{{ $line['id'] }}]" class="input input--sm input--qty" x-model="qty[{{ $line['id'] }}]" @input="sync()">
                                            <span class="text-muted text-xs">/ {{ $line['remaining'] }}</span>
                                        </span>
                                    @else
                                        <span class="text-muted text-xs">Fully refunded</span>
                                    @endif
                                </td>
                                <td class="num" x-text="money(lineAmount(@js(['id' => $line['id'], 'unit' => (float) $line['unit'], 'remaining' => $line['remaining']])))">£0.00</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="form-grid">
            @if ($shippingRefundable > 0)
                <x-admin.field label="Refund shipping" for="f-refund-shipping" error="shipping" bag="refund" :hint="'Up to '.money($shippingRefundable)">
                    <div class="input-group">
                        <span class="input-group__addon">£</span>
                        <input type="text" inputmode="decimal" name="shipping" id="f-refund-shipping" class="input input--num" placeholder="0.00" x-model="shipping" @input="sync()" autocomplete="off">
                    </div>
                </x-admin.field>
            @endif
            <x-admin.field label="Refund amount" for="f-refund-amount" error="amount" bag="refund" required :hint="'Available: '.money($refundable)">
                <div class="input-group" :class="{ 'is-invalid': tooMuch }">
                    <span class="input-group__addon">£</span>
                    <input type="text" inputmode="decimal" name="amount" id="f-refund-amount" class="input input--num" placeholder="0.00" x-model="amount" @input="edited()" autocomplete="off" required>
                </div>
            </x-admin.field>
        </div>
        <p class="text-xs text-muted" style="margin-top:-8px">
            <span x-show="!custom">Worked out from the items{{ $shippingRefundable > 0 ? ' and shipping' : '' }} above. You can type a different amount.</span>
            <span x-show="custom" x-cloak>Custom amount. <button type="button" class="btn btn--plain text-xs" @click="reset()">Use the calculated <span x-text="money(calculated)"></span></button></span>
            <span x-show="tooMuch" x-cloak class="text-danger"> More than can be refunded ({{ money($refundable) }}).</span>
        </p>

        <x-admin.input name="reason" label="Reason" optional maxlength="500" bag="refund" placeholder="e.g. Returned – faulty keyboard" help="Only staff see this. It’s saved on the order’s timeline." />

        <div x-show="anyItems" x-cloak>
            <x-admin.checkbox name="restock" label="Put the refunded items back in stock" x-model="restock" help="Leave unticked if the items aren’t coming back or can’t be resold." />
        </div>

        @if (! $gatewayRefunds)
            <x-admin.callout type="neutral" icon="information-circle">
                {{ $paymentLabel }} refunds can’t be sent from here. Refund the customer in the payment provider’s dashboard (or by bank transfer) first, then record the refund so the order and reports are correct.
            </x-admin.callout>
        @endif
    </form>
    <x-slot:footer>
        <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
        @if ($gatewayRefunds)
            <x-admin.button type="submit" form="refund-form" name="method" value="manual"
                            data-confirm-title="Record a manual refund?" data-confirm="No money is sent to the customer from here – only use this if you have already refunded them another way." data-confirm-button="Record refund">Record manual refund</x-admin.button>
            <x-admin.button type="submit" form="refund-form" name="method" value="gateway" variant="primary"
                            data-confirm-title="Refund the customer now?" :data-confirm="'The money goes back to the customer through '.$paymentLabel.'. This can’t be undone.'" data-confirm-button="Refund customer">Refund customer</x-admin.button>
        @else
            <x-admin.button type="submit" form="refund-form" name="method" value="manual" variant="primary"
                            data-confirm-title="Record this refund?" data-confirm="The refund is saved on the order and taken off your sales figures. No money is sent from here." data-confirm-button="Record refund">Record refund</x-admin.button>
        @endif
    </x-slot:footer>
</x-admin.modal>
