{{--
    Label + help + validation error around any control. The form components below use it; use it directly for custom controls:
    <x-admin.field label="Colour" for="colour" error="colour" help="Shown on the product page">
        <input id="colour" name="colour" class="input">
    </x-admin.field>
    Props: label, for, help, error (error key, e.g. "meta.title"), bag (named error bag, e.g. validateWithBag('password', …)),
           required, optional, hint (small text right of the label)
    Slots: labelExtra (right side of the label row), helpSlot (rich help markup)
--}}
@props(['label' => null, 'for' => null, 'help' => null, 'error' => null, 'bag' => null, 'required' => false, 'optional' => false, 'hint' => null])
@php
    $message = $error ? $errors->getBag($bag ?: 'default')->first($error) : null;
@endphp
<div {{ $attributes->class(['field', 'is-invalid' => (bool) $message]) }}>
    @if ($label || isset($labelExtra) || $hint)
        <div class="field__label">
            @if ($label)
                <label @if ($for) for="{{ $for }}" @endif>{{ $label }}@if ($required)<span class="field__required" aria-hidden="true">*</span>@endif @if ($optional)<span class="field__optional">(optional)</span>@endif</label>
            @endif
            @if ($hint)<span class="field__label-extra">{{ $hint }}</span>@endif
            {{ $labelExtra ?? '' }}
        </div>
    @endif
    {{ $slot }}
    @if ($message)
        <p class="field__error" @if ($for) id="{{ $for }}-error" @endif><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>
    @endif
    @if ($help)
        <p class="field__help" @if ($for) id="{{ $for }}-help" @endif>{{ $help }}</p>
    @endif
    @isset($helpSlot)
        <div class="field__help">{{ $helpSlot }}</div>
    @endisset
</div>
