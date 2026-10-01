{{-- Add a customer: contact details, optional billing address, marketing consent, set-password invite, private note. --}}
@extends('commerce::admin.layouts.app')

@php $countries = \Pine\Commerce\Services\Admin\Countries::options(); @endphp

@section('title', 'Add customer')

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header title="Add customer" :back="route('admin.customers.index')" back-label="Back to customers"
                         subtitle="For phone or trade customers. They can sign in once they’ve set a password." />

    <x-admin.form id="customer-form" :action="route('admin.customers.store')" dirty save-label="Save customer">
        <div class="layout">
            <div class="layout__main">
                <x-admin.card title="Customer">
                    <div class="stack-fields">
                        <div class="form-grid">
                            <x-admin.input name="first_name" label="First name" required autofocus autocomplete="off" />
                            <x-admin.input name="last_name" label="Last name" optional autocomplete="off" />
                        </div>
                        <x-admin.input name="email" type="email" label="Email" required autocomplete="off" help="Used for order emails and to sign in." />
                        <x-admin.input name="phone" type="tel" label="Phone" optional autocomplete="off" />
                        <x-admin.checkbox name="marketing_opt_in" label="Customer agreed to receive marketing emails" help="Only tick this if they have asked to hear about offers." />
                    </div>
                </x-admin.card>

                <x-admin.card title="Address" subtitle="Optional – used to fill in orders you create for them.">
                    <div class="stack-fields">
                        <x-admin.input name="billing_company" label="Company" optional autocomplete="off" />
                        <x-admin.input name="billing_address_1" label="Address" optional autocomplete="off" />
                        <x-admin.input name="billing_address_2" label="Apartment, suite, etc." optional autocomplete="off" />
                        <div class="form-grid form-grid--3">
                            <x-admin.input name="billing_city" label="Town / city" optional autocomplete="off" />
                            <x-admin.input name="billing_county" label="County" optional autocomplete="off" />
                            <x-admin.input name="billing_postcode" label="Postcode" optional autocomplete="off" />
                        </div>
                        <x-admin.select name="billing_country" label="Country" :options="$countries" value="GB" />
                    </div>
                </x-admin.card>
            </div>

            <div class="layout__aside">
                <x-admin.card title="Account">
                    <x-admin.toggle name="send_invite" label="Email an invite to set a password" help="They get a link to choose a password and sign in to see their orders." />
                </x-admin.card>
                <x-admin.card title="Notes" subtitle="Private – the customer never sees these.">
                    <x-admin.textarea name="admin_note" label="Note" rows="4" placeholder="e.g. Trade customer – invoices by email." />
                </x-admin.card>
            </div>
        </div>
    </x-admin.form>

    <div class="form-actions">
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.customers.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="customer-form" variant="primary">Save customer</x-admin.button>
    </div>
@endsection
