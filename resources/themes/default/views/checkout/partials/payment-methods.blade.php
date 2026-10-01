{{-- Payment method choices. $gateways (code => Gateway), $selected --}}
@php $selected = $selected ?? array_key_first($gateways); @endphp
<ul class="choice-list payment-methods" id="payment">
    @foreach ($gateways as $code => $gateway)
        <li class="choice choice--payment {{ $code === $selected ? 'is-selected' : '' }}" data-gateway="{{ $code }}">
            <input id="payment_method_{{ $code }}" type="radio" name="payment_method" value="{{ $code }}" @checked($code === $selected)>
            <label for="payment_method_{{ $code }}">
                <span class="choice__title">{{ $gateway->title() }}</span>
                @if ($icons = $gateway->icons())<span class="choice__icons">{!! $icons !!}</span>@endif
            </label>
            <div class="choice__panel" @if ($code !== $selected) hidden @endif data-gateway-panel>
                @if ($gateway->description())<p>{{ $gateway->description() }}</p>@endif
@if ($gatewayHtml = $gateway->checkoutHtml()){!! $gatewayHtml !!}@endif
                @if ($code === 'stripe')
                    <div class="stripe-element" data-stripe-element></div>
                    <p class="field__error" role="alert" data-stripe-errors></p>
                @endif
            </div>
        </li>
    @endforeach
</ul>
