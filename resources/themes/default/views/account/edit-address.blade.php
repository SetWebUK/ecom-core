@extends('account.frame')

@section('account_content')
@php
    $title = $type === 'billing' ? 'Billing address' : 'Delivery address';
    $f = function (string $name, string $label, bool $required = false, string $inputType = 'text', ?string $autocomplete = null, bool $wide = false) use ($type, $values, $errors) {
        $id = $type.'_'.$name;
        $error = $errors->first($id);

        return '<div class="field'.($wide ? ' field--full' : '').($error ? ' has-error' : '').'"><label for="'.$id.'">'.e($label).($required ? ' <span class="req" aria-hidden="true">*</span>' : ' <span class="opt">(optional)</span>').'</label>'
            .'<input type="'.$inputType.'" name="'.$id.'" id="'.$id.'" value="'.e($values[$name] ?? '').'" maxlength="190"'.($autocomplete ? ' autocomplete="'.$autocomplete.'"' : '').($required ? ' required aria-required="true"' : '').($error ? ' aria-invalid="true"' : '').'>'
            .($error ? '<p class="field__error">'.e($error).'</p>' : '').'</div>';
    };
@endphp
<p><a class="link-back" href="{{ route('account.addresses') }}">← Addresses</a></p>
<h1 class="page-title">{{ $title }}</h1>
<form class="form card" method="post" action="{{ route('account.address.save', ['type' => $type]) }}" novalidate>
    @csrf
    <div class="form-grid">
        {!! $f('first_name', 'First name', true, 'text', 'given-name') !!}
        {!! $f('last_name', 'Last name', true, 'text', 'family-name') !!}
        {!! $f('company', 'Company', false, 'text', 'organization', true) !!}
        {!! $f('address_1', 'Street address', true, 'text', 'address-line1', true) !!}
        {!! $f('address_2', 'Flat, suite, unit, etc.', false, 'text', 'address-line2', true) !!}
        {!! $f('city', 'Town / City', true, 'text', 'address-level2') !!}
        {!! $f('state', 'County', false, 'text', 'address-level1') !!}
        {!! $f('postcode', 'Postcode', true, 'text', 'postal-code') !!}
        <div class="field">
            <label for="{{ $type }}_country">Country / Region <span class="req" aria-hidden="true">*</span></label>
            <select name="{{ $type }}_country" id="{{ $type }}_country" autocomplete="country">
                @foreach (\Pine\Commerce\Services\Checkout\CheckoutService::countries() as $code => $name)
                    <option value="{{ $code }}" @selected(($values['country'] ?? '') === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        {!! $f('phone', 'Phone', $type === 'billing', 'tel', 'tel') !!}
        @if ($type === 'billing')
            {!! $f('email', 'Email address', true, 'email', 'email', true) !!}
        @endif
    </div>
    <button type="submit" class="btn btn--primary" name="save_address" value="Save address">Save address</button>
</form>
@endsection
