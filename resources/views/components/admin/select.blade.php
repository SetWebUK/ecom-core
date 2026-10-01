{{--
    <x-admin.select name="type" label="Discount type" :options="Pine\Commerce\Models\Coupon::TYPES" :value="$coupon->type" />
    <x-admin.select name="category_id" label="Category" :options="$options" placeholder="No category" />
    <x-admin.select name="tags[]" multiple :options="…" :value="[1, 2]" />
    options: [value => label] or grouped [group label => [value => label]]. Or pass <option>s in the slot.
    Props: name, label, options, value, placeholder, help, required, optional, id, error, wrapper, multiple
--}}
@props(['name', 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'help' => null, 'required' => false,
        'optional' => false, 'id' => null, 'error' => null, 'wrapper' => null, 'multiple' => false, 'hint' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $error ??= $key;
    $current = old($key, $value);
    $selected = collect(is_array($current) ? $current : [$current])->map(fn ($v) => (string) $v)->all();
    $invalid = $errors->has($error);
    $describedBy = trim(($invalid ? $id.'-error ' : '').($help ? $id.'-help' : ''));
@endphp
<x-admin.field :label="$label" :for="$id" :help="$help" :error="$error" :required="$required" :optional="$optional" :hint="$hint" :class="$wrapper">
    <select {{ $attributes->class(['select', 'is-invalid' => $invalid])->merge([
        'name' => $name, 'id' => $id, 'required' => $required, 'multiple' => $multiple,
        'aria-invalid' => $invalid ? 'true' : null, 'aria-describedby' => $describedBy ?: null,
    ]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $optionValue => $optionLabel)
            @if (is_array($optionLabel))
                <optgroup label="{{ $optionValue }}">
                    @foreach ($optionLabel as $v => $l)
                        <option value="{{ $v }}" @selected(in_array((string) $v, $selected, true))>{{ $l }}</option>
                    @endforeach
                </optgroup>
            @else
                <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $selected, true))>{{ $optionLabel }}</option>
            @endif
        @endforeach
        {{ $slot }}
    </select>
</x-admin.field>
