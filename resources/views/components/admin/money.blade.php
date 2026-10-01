{{--
    <x-admin.money name="minimum_spend" label="Minimum spend" :value="$coupon->minimum_spend" help="Leave blank for no minimum." />
    £ prefix, decimal keyboard on phones, value shown with 2 decimals. Validate with 'nullable|numeric|min:0' (+ 'decimal:0,2').
    Props: same as <x-admin.input> (name, label, value, help, required, optional, placeholder…)
--}}
@props(['name', 'value' => null, 'symbol' => '£'])
@php
    $formatted = is_numeric($value) ? number_format((float) $value, 2, '.', '') : $value;
@endphp
<x-admin.input :name="$name" :value="$formatted" :prefix="$symbol" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" class="input--num" {{ $attributes }} />
