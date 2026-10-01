{{-- One checkout input. $name, $label, $values, optional $type, $required, $autocomplete, $maxlength, $inputmode, $options (select), $wide --}}
@php
    $type = $type ?? 'text';
    $required = $required ?? false;
    $value = (string) (($values ?? [])[$name] ?? '');
    $hasError = isset($errors) && $errors->has($name);
@endphp
<div class="field {{ ! empty($wide) ? 'field--full' : '' }} {{ $hasError ? 'has-error' : '' }}" id="{{ $name }}_field">
    <label for="{{ $name }}">{{ $label }}@if ($required) <span class="req" aria-hidden="true">*</span>@else <span class="opt">(optional)</span>@endif</label>
    @if ($type === 'select')
        <select name="{{ $name }}" id="{{ $name }}" @if ($required) required aria-required="true" @endif autocomplete="{{ $autocomplete ?? 'off' }}">
            @foreach ($options as $optValue => $optLabel)
                <option value="{{ $optValue }}" @selected($value === (string) $optValue || ($value === '' && $loop->first))>{{ $optLabel }}</option>
            @endforeach
        </select>
    @else
        <input type="{{ $type }}" name="{{ $name }}" id="{{ $name }}" value="{{ $value }}" autocomplete="{{ $autocomplete ?? 'off' }}"
            @if ($required) required aria-required="true" @endif
            @isset($maxlength) maxlength="{{ $maxlength }}" @endisset
            @isset($inputmode) inputmode="{{ $inputmode }}" @endisset
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $name }}_error" @endif>
    @endif
    @if ($hasError)
        <p class="field__error" id="{{ $name }}_error">{{ $errors->first($name) }}</p>
    @endif
</div>
