@extends('account.frame')

@section('account_content')
@php $countries = \Pine\Commerce\Services\Checkout\CheckoutService::countries(); @endphp
<h1 class="page-title">Addresses</h1>
<p class="muted">These addresses are filled in for you at checkout.</p>
<div class="details-grid">
    @foreach (['billing' => 'Billing address', 'shipping' => 'Delivery address'] as $type => $title)
        @php $a = $addresses->get($type); @endphp
        <div class="card">
            <h2 class="card__title">{{ $title }}</h2>
            <address>
                @if ($a && $a->address_1)
                    {!! implode('<br>', array_map('e', array_values(array_filter([$a->full_name, $a->company, $a->address_1, $a->address_2, $a->city, $a->county, $a->postcode, $countries[$a->country] ?? $a->country])))) !!}
                @else
                    <span class="muted">Not set up yet.</span>
                @endif
            </address>
            <a class="btn btn--outline btn--sm" href="{{ route('account.address.edit', ['type' => $type]) }}">{{ $a ? 'Edit' : 'Add' }}</a>
        </div>
    @endforeach
</div>
@endsection
