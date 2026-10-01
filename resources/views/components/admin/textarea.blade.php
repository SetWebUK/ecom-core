{{--
    <x-admin.textarea name="description" label="Internal note" rows="3" :value="$coupon->description" counter="255" />
    Props: name, label, value, rows, help, required, optional, counter, id, hint, error, wrapper, code (monospace)
--}}
@props(['name', 'label' => null, 'value' => null, 'rows' => 4, 'help' => null, 'required' => false, 'optional' => false,
        'counter' => null, 'id' => null, 'hint' => null, 'error' => null, 'wrapper' => null, 'code' => false])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $error ??= $key;
    $invalid = $errors->has($error);
    $describedBy = trim(($invalid ? $id.'-error ' : '').($help ? $id.'-help' : ''));
@endphp
<x-admin.field :label="$label" :for="$id" :help="$help" :error="$error" :required="$required" :optional="$optional" :hint="$hint" :class="$wrapper" :x-data="$counter ? 'charCount('.(int) $counter.')' : null">
    @if ($counter)
        <x-slot:labelExtra><span class="field__counter" :class="{ 'is-over': over }" x-text="length + ' / ' + max"></span></x-slot:labelExtra>
    @endif
    <textarea {{ $attributes->class(['textarea', 'textarea--code' => $code, 'is-invalid' => $invalid])->merge([
        'name' => $name, 'id' => $id, 'rows' => $rows, 'required' => $required,
        'aria-invalid' => $invalid ? 'true' : null, 'aria-describedby' => $describedBy ?: null,
    ]) }} @if ($counter) x-ref="field" @endif>{{ old($key, $value) }}</textarea>
</x-admin.field>
