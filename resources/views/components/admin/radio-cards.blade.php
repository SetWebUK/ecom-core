{{--
    <x-admin.radio-cards name="type" label="Discount type" :value="$coupon->type" :options="[
        'percent' => ['label' => 'Percentage', 'help' => 'e.g. 10% off', 'icon' => 'receipt-percent'],
        'fixed_cart' => ['label' => 'Fixed amount', 'help' => '£ off the basket', 'icon' => 'banknotes'],
    ]" />
    options: [value => label] or [value => ['label', 'help', 'icon']]. Extra attributes (e.g. x-model) go on every radio.
    Props: name, label, options, value, help, required, id, error, columns (min card width in px)
--}}
@props(['name', 'label' => null, 'options' => [], 'value' => null, 'help' => null, 'required' => false, 'id' => null, 'error' => null, 'columns' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $error ??= $key;
    $current = (string) old($key, $value);
    $message = $errors->first($error);
@endphp
<fieldset class="fieldset field" @if ($message) aria-invalid="true" @endif>
    @if ($label)<legend>{{ $label }}@if ($required)<span class="field__required" aria-hidden="true">*</span>@endif</legend>@endif
    <div class="radio-cards" @if ($columns) style="grid-template-columns: repeat(auto-fit, minmax({{ (int) $columns }}px, 1fr))" @endif>
        @foreach ($options as $optionValue => $option)
            @php $option = is_array($option) ? $option : ['label' => $option]; @endphp
            <label class="radio-card" for="{{ $id }}-{{ $loop->index }}">
                <input type="radio" {{ $attributes->merge(['name' => $name, 'id' => $id.'-'.$loop->index, 'value' => $optionValue]) }} @checked($current === (string) $optionValue) @required($required)>
                @if (! empty($option['icon']))<span class="radio-card__icon"><x-admin.icon :name="$option['icon']" size="sm" /></span>@endif
                <span class="radio-card__text">
                    <span class="radio-card__label">{{ $option['label'] }}</span>
                    @if (! empty($option['help']))<span class="radio-card__help">{{ $option['help'] }}</span>@endif
                </span>
            </label>
        @endforeach
    </div>
    @if ($message)<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@endif
    @if ($help)<p class="field__help">{{ $help }}</p>@endif
</fieldset>
