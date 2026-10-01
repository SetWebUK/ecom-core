{{--
    <x-admin.toggle name="is_active" label="Active" help="Customers can use this code." :checked="$coupon->is_active" />
    On/off switch. Posts "1"/"0" (hidden input) so switching off is saved.
    Props: name, label, checked, help, value, unchecked, id, error, left (switch on the left)
--}}
@props(['name', 'label', 'checked' => false, 'help' => null, 'value' => '1', 'unchecked' => '0', 'id' => null, 'error' => null, 'left' => false])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $error ??= $key;
    $old = old($key);
    $isChecked = $old !== null ? (string) $old === (string) $value : (bool) $checked;
    $message = $errors->first($error);
@endphp
<div class="field">
    <label @class(['toggle', 'toggle--left' => $left]) for="{{ $id }}">
        <span class="toggle__text">
            <span class="toggle__label">{{ $label }}</span>
            @if ($help)<span class="toggle__help" id="{{ $id }}-help">{{ $help }}</span>@endif
        </span>
        <span class="switch">
            @if ($unchecked !== null)<input type="hidden" name="{{ $name }}" value="{{ $unchecked }}">@endif
            <input type="checkbox" role="switch" {{ $attributes->merge(['name' => $name, 'id' => $id, 'value' => $value]) }} @checked($isChecked) @if ($help) aria-describedby="{{ $id }}-help" @endif>
            <span class="switch__track" aria-hidden="true"></span>
        </span>
    </label>
    @if ($message)<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@endif
</div>
