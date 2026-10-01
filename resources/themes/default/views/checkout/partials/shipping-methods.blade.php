{{-- Delivery options (re-rendered by /checkout/update). $totals --}}
@php $chosen = $totals['shipping_method']?->code; @endphp
@if ($totals['shipping_methods'])
    <ul class="choice-list" id="shipping_method">
        @foreach ($totals['shipping_methods'] as $code => $option)
            @php $id = 'shipping_method_0_'.\Illuminate\Support\Str::slug($code, '_'); @endphp
            <li class="choice {{ $code === $chosen ? 'is-selected' : '' }}">
                <input type="radio" name="shipping_method" id="{{ $id }}" value="{{ $code }}" @checked($code === $chosen)>
                <label for="{{ $id }}">
                    <span class="choice__title">{{ $option['method']->name }}</span>
                    @if ($option['method']->description)<span class="choice__desc">{{ $option['method']->description }}</span>@endif
                </label>
                <span class="choice__price">{{ ($option['display_cost'] ?? $option['cost']) > 0 ? money($option['display_cost'] ?? $option['cost']) : 'Free' }}</span>
            </li>
        @endforeach
    </ul>
@else
    <div class="notice notice--info" role="status">
        @if (($totals['shipping_location'] ?? null) && ! ($totals['shipping_zone'] ?? null))
            Sorry, we don’t deliver to {{ \Pine\Commerce\Services\Admin\Countries::name($totals['shipping_location']->country) ?? $totals['shipping_location']->country }}{{ $totals['shipping_location']->postcode !== '' ? ' ('.$totals['shipping_location']->postcode.')' : '' }} yet.
        @else
            There are no delivery options for this address.
        @endif
        Please check it, or contact us for help.
    </div>
@endif
