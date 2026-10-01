{{--
    Compact select for <x-admin.filters>; reads its current value from the query string and submits on change.
    <x-admin.filter-select name="type" :options="Pine\Commerce\Models\Coupon::TYPES" placeholder="All types" label="Type" />
    Props: name, options ([value => label]), placeholder (the "any" option), label (screen-reader label)
--}}
@props(['name', 'options' => [], 'placeholder' => 'Any', 'label' => null])
@php $current = is_scalar(request($name)) ? (string) request($name) : ''; @endphp
<label class="sr-only" for="filter-{{ $name }}">{{ $label ?? $placeholder }}</label>
<select name="{{ $name }}" id="filter-{{ $name }}" {{ $attributes->class(['select', 'select--sm']) }}>
    <option value="">{{ $placeholder }}</option>
    @foreach ($options as $value => $optionLabel)
        <option value="{{ $value }}" @selected($current === (string) $value)>{{ $optionLabel }}</option>
    @endforeach
</select>
