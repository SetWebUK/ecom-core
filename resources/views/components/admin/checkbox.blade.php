{{--
    <x-admin.checkbox name="individual_use" label="Can't be combined with other codes" :checked="$coupon->individual_use" help="…" />
    Posts "1" when ticked and "0" when not (hidden input) so the value can be switched off. :unchecked="null" to post nothing.
    Props: name, label, checked, help, value (default "1"), unchecked (default "0"), id, error
--}}
@props(['name', 'label', 'checked' => false, 'help' => null, 'value' => '1', 'unchecked' => '0', 'id' => null, 'error' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name, str_ends_with($name, '[]') ? (string) $value : null);
    $error ??= $key;
    $old = old($key);
    $isChecked = $old !== null ? (is_array($old) ? in_array((string) $value, array_map('strval', $old), true) : (string) $old === (string) $value) : (bool) $checked;
    $message = $errors->first($error);
@endphp
<div class="field">
    <label class="check" for="{{ $id }}">
        @if ($unchecked !== null && ! str_ends_with($name, '[]'))<input type="hidden" name="{{ $name }}" value="{{ $unchecked }}">@endif
        <input type="checkbox" {{ $attributes->class(['checkbox'])->merge(['name' => $name, 'id' => $id, 'value' => $value]) }} @checked($isChecked) @if ($help) aria-describedby="{{ $id }}-help" @endif>
        <span class="check__text">
            <span class="check__label">{{ $label }}</span>
            @if ($help)<span class="check__help" id="{{ $id }}-help">{{ $help }}</span>@endif
        </span>
    </label>
    @if ($message)<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@endif
</div>
