{{--
    Carrier + tracking number of the fulfilment forms (Ready to send, Edit tracking). $order, $carriers (names, from
    config commerce.shipping.carriers plus carriers used before). A drop-down rather than a typed box with suggestions:
    browsers only suggest entries matching what is already typed, so a pre-filled "DPD" hid every other carrier.
    "Other…" reveals a box for a carrier that isn't listed (FulfilOrderRequest swaps it in).
--}}
@php
    $current = old('tracking_carrier', $order->tracking_carrier ?: config('commerce.shipping.default_carrier'));
    $current = $current === null ? '' : (string) $current;
    $options = array_combine($carriers, $carriers);
    if ($current !== '' && $current !== '__other' && ! isset($options[$current])) {
        $options[$current] = $current;
    }
    $options['__other'] = 'Other…';
@endphp
<div class="form-grid" x-data="{ carrier: @js($current) }">
    <x-admin.select name="tracking_carrier" label="Carrier" optional :options="$options" :value="$current" placeholder="No carrier" x-model="carrier" />
    <x-admin.input name="tracking_number" label="Tracking number" optional :value="$order->tracking_number" autocomplete="off" />
    <template x-if="carrier === '__other'">
        <x-admin.input name="tracking_carrier_other" label="Carrier name" :value="old('tracking_carrier_other')" autocomplete="off" required />
    </template>
</div>
