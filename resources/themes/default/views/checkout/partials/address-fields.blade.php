{{-- Address fields for $prefix 'shipping' | 'billing' (same names as every theme – CheckoutService validates them). --}}
@php
    $section = $prefix === 'billing' ? 'billing' : 'shipping';
    $countries = $countries ?? \Pine\Commerce\Services\Checkout\CheckoutService::countries();
@endphp
<div class="form-grid">
    @include('checkout.partials.field', ['name' => $prefix.'_first_name', 'label' => 'First name', 'required' => true, 'autocomplete' => $section.' given-name', 'maxlength' => 100])
    @include('checkout.partials.field', ['name' => $prefix.'_last_name', 'label' => 'Last name', 'required' => true, 'autocomplete' => $section.' family-name', 'maxlength' => 100])
    @include('checkout.partials.field', ['name' => $prefix.'_company', 'label' => 'Company', 'autocomplete' => $section.' organization', 'maxlength' => 150, 'wide' => true])
    @include('checkout.partials.field', ['name' => $prefix.'_address_1', 'label' => 'Street address', 'required' => true, 'autocomplete' => $section.' address-line1', 'maxlength' => 190, 'wide' => true])
    @include('checkout.partials.field', ['name' => $prefix.'_address_2', 'label' => 'Flat, suite, unit, etc.', 'autocomplete' => $section.' address-line2', 'maxlength' => 190, 'wide' => true])
    @include('checkout.partials.field', ['name' => $prefix.'_city', 'label' => 'Town / City', 'required' => true, 'autocomplete' => $section.' address-level2', 'maxlength' => 100])
    @include('checkout.partials.field', ['name' => $prefix.'_state', 'label' => 'County', 'autocomplete' => $section.' address-level1', 'maxlength' => 100])
    @include('checkout.partials.field', ['name' => $prefix.'_postcode', 'label' => 'Postcode', 'required' => true, 'autocomplete' => $section.' postal-code', 'maxlength' => 12])
    @include('checkout.partials.field', ['name' => $prefix.'_country', 'label' => 'Country / Region', 'type' => 'select', 'required' => true, 'options' => $countries, 'autocomplete' => $section.' country'])
    @include('checkout.partials.field', ['name' => $prefix.'_phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'autocomplete' => $prefix === 'billing' ? 'tel' : $section.' tel', 'maxlength' => 40, 'inputmode' => 'tel', 'wide' => true])
</div>
