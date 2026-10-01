{{--
    <x-admin.input name="code" label="Discount code" :value="$coupon->code" required help="Customers type this at checkout." />
    <x-admin.input name="weight" label="Weight" type="number" step="0.001" suffix="kg" :value="$product->weight" />
    <x-admin.input name="meta_title" label="Title" counter="60" />               live "12 / 60" counter
    <x-admin.input name="slug" label="URL handle" prefix="/shop/" x-data="slugField('#f-name')" />   extra attributes go on the <input>
    Props: name, label, type, value, help, required, optional, prefix, suffix, counter, id, hint, error (error key), bag (named error bag), wrapper (class on .field)
    Old input and validation errors are handled for you (name "meta[title]" -> key "meta.title").
--}}
@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'help' => null, 'required' => false, 'optional' => false,
        'prefix' => null, 'suffix' => null, 'counter' => null, 'id' => null, 'hint' => null, 'error' => null, 'bag' => null, 'wrapper' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $error ??= $key;
    $current = $type === 'password' ? null : old($key, $value);
    if ($current instanceof \DateTimeInterface) {
        $current = $current->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i');
    }
    $invalid = $errors->getBag($bag ?: 'default')->has($error);
    $describedBy = trim(($invalid ? $id.'-error ' : '').($help ? $id.'-help' : ''));
    $group = $prefix !== null || $suffix !== null;
    $inputAttributes = $attributes->class(['input', 'is-invalid' => $invalid && ! $group])->merge([
        'type' => $type, 'name' => $name, 'id' => $id, 'value' => is_array($current) ? null : $current,
        'required' => $required, 'aria-invalid' => $invalid ? 'true' : null, 'aria-describedby' => $describedBy ?: null,
    ]);
@endphp
<x-admin.field :label="$label" :for="$id" :help="$help" :error="$error" :bag="$bag" :required="$required" :optional="$optional" :hint="$hint" :class="$wrapper" :x-data="$counter ? 'charCount('.(int) $counter.')' : null">
    @if ($counter)
        <x-slot:labelExtra><span class="field__counter" :class="{ 'is-over': over }" x-text="length + ' / ' + max"></span></x-slot:labelExtra>
    @endif
    @if ($group)
        <div @class(['input-group', 'is-invalid' => $invalid])>
            @if ($prefix !== null)<span class="input-group__addon">{{ $prefix }}</span>@endif
            <input {{ $inputAttributes }} @if ($counter) x-ref="field" @endif>
            @if ($suffix !== null)<span class="input-group__addon">{{ $suffix }}</span>@endif
        </div>
    @else
        <input {{ $inputAttributes }} @if ($counter) x-ref="field" @endif>
    @endif
</x-admin.field>
