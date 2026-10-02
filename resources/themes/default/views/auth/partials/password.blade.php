{{--
    Password input with a show/hide button (js/auth.js; the button stays hidden without JavaScript).
    $id, $name, $label, $autocomplete; optional $error (message), $help, $strength (bool: meter + hints), $match (id of the
    password this one must equal), $minlength.
--}}
@php
    $error = $error ?? null;
    $describedBy = trim(($error ? $id.'-error ' : '').(! empty($help) ? $id.'-help ' : '').(! empty($strength) ? $id.'-strength' : '').(! empty($match) ? $id.'-match' : ''));
@endphp
<div class="field auth-field {{ $error ? 'is-invalid' : '' }}">
    <label for="{{ $id }}">{{ $label }} <span class="req" aria-hidden="true">*</span></label>
    <div class="pw-input">
        <input type="password" name="{{ $name }}" id="{{ $id }}" autocomplete="{{ $autocomplete }}" required aria-required="true"
            @if (! empty($minlength)) minlength="{{ $minlength }}" @endif maxlength="255"
            @if ($error) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
        <button type="button" class="pw-input__toggle" data-password-toggle="{{ $id }}" aria-controls="{{ $id }}" aria-pressed="false" hidden>
            <svg class="pw-input__show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="pw-input__hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a10 10 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
            <span class="sr-only" data-password-toggle-label>Show password</span>
        </button>
    </div>
    @if ($error)<p class="field__error" id="{{ $id }}-error">{{ $error }}</p>@endif
    @if (! empty($help))<p class="field__help" id="{{ $id }}-help">{{ $help }}</p>@endif
    @if (! empty($strength))
        <div class="pw-strength" id="{{ $id }}-strength" data-password-strength="{{ $id }}" data-min="{{ $minlength ?? 8 }}">
            <div class="pw-strength__bar" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            <p class="pw-strength__label" aria-live="polite" data-password-strength-label>Use at least {{ $minlength ?? 8 }} characters.</p>
            <ul class="pw-rules">
                <li data-rule="length">At least {{ $minlength ?? 8 }} characters</li>
                <li data-rule="case">Upper and lower case letters</li>
                <li data-rule="number">A number or symbol</li>
            </ul>
        </div>
    @endif
    @if (! empty($match))
        <p class="field__help pw-match" id="{{ $id }}-match" data-password-match="{{ $match }}" data-for="{{ $id }}" aria-live="polite"></p>
    @endif
</div>
