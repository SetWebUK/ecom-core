{{-- Checkout order summary (re-rendered by /checkout/update and coupon requests). $cart, $totals
     Amounts follow Settings › Tax "prices in the basket" ($totals['display_incl']); tax is listed per rate. --}}
@php $incl = (bool) ($totals['display_incl'] ?? false); @endphp
<div class="summary">
    <ul class="summary__lines">
        @foreach ($totals['lines'] as $line)
            <li class="summary-line">
                <span class="summary-line__media"><img src="{{ $line->imageUrl() }}" alt="{{ $line->imageAlt() }}" width="56" height="56" loading="lazy"><span class="summary-line__qty">{{ $line->quantity }}</span></span>
                <span class="summary-line__body">
                    <span class="summary-line__name">{{ $line->name() }}</span>
                    @if ($options = $line->options())
                        <span class="summary-line__options">@foreach ($options as $label => $value){{ $label }}: {{ $value }}@if (! $loop->last) · @endif @endforeach</span>
                    @endif
                    @if ($line->discount > 0)
                        <span class="summary-line__discount">Discount −{{ money(round($line->displaySubtotal($incl) - $line->displayTotal($incl), 2)) }}</span>
                    @endif
                </span>
                <span class="summary-line__price">{{ money($line->displaySubtotal($incl)) }}</span>
            </li>
        @endforeach
    </ul>

    @if (commerce_feature('coupons'))
    <div class="coupon" data-coupon>
        <label for="coupon-code" class="sr-only">Discount code</label>
        <input type="text" id="coupon-code" placeholder="Discount code" autocomplete="off" maxlength="100" data-coupon-input form="coupon-form-none">
        <button type="button" class="btn btn--outline" data-coupon-apply>Apply</button>
    </div>
    @endif

    @if (commerce_feature('loyalty') && auth()->check())
        {{-- loyalty points (Services\Loyalty\Loyalty): use them on this order, or keep them --}}
        @php
            $L = \Pine\Commerce\Services\Loyalty\Loyalty::class;
            $pointsBalance = $L::balance(auth()->id());
            $pointsUsed = (float) ($totals['coupons'][$L::CODE] ?? 0) > 0;
            $pointsUsable = $L::usable(auth()->id(), (float) ($totals['subtotal_display'] ?? $totals['subtotal']));
        @endphp
        @if ($pointsUsed || $pointsUsable > 0)
            <div class="points-box" data-points>
                @if ($pointsUsed)
                    <span>Using your {{ $L::label() }} ({{ number_format($pointsBalance) }} available).</span>
                    <button type="button" class="btn btn--outline btn--sm" data-points-remove="{{ route('cart.points.remove') }}">Keep them</button>
                @else
                    <span>You have <strong>{{ number_format($pointsBalance) }} {{ $L::label() }}</strong> – use {{ number_format($pointsUsable) }} for {{ money($L::value($pointsUsable)) }} off.</span>
                    <button type="button" class="btn btn--outline btn--sm" data-points-apply="{{ route('cart.points') }}">Use my {{ $L::label() }}</button>
                @endif
            </div>
        @elseif ($pointsBalance > 0 && ! $L::canSpend())
            <p class="points-box points-box--note muted">You have {{ number_format($pointsBalance) }} {{ $L::label() }}. Using them at checkout is paused for now@if ($L::earnRate() > 0) – you still earn {{ $L::label() }} on this order@endif.</p>
        @elseif ($pointsBalance > 0)
            <p class="points-box points-box--note muted">You have {{ number_format($pointsBalance) }} {{ $L::label() }}@if ($L::minRedeem() > $pointsBalance) – you can use them from {{ number_format($L::minRedeem()) }}@endif.</p>
        @endif
    @endif

    <dl class="totals">
        <div><dt>Subtotal</dt><dd>{{ money($totals['subtotal_display'] ?? $totals['subtotal']) }}</dd></div>
        @foreach ($totals['coupons_display'] ?? $totals['coupons'] as $code => $amount)
            @if (in_array($code, $totals['automatic_coupons'] ?? [], true))
                <div class="totals__discount"><dt>{{ $totals['coupon_labels'][$code] ?? $code }}</dt><dd>−{{ money($amount) }}</dd></div>
            @else
                <div class="totals__discount"><dt>Discount <span class="chip chip--sm">{{ $code }} <button type="button" class="chip__x" aria-label="Remove discount {{ $code }}" data-coupon-remove="{{ $code }}">×</button></span></dt><dd>−{{ money($amount) }}</dd></div>
            @endif
        @endforeach
        @if ($totals['shipping_method'])
            <div><dt>Delivery</dt><dd>{{ ($totals['shipping_display'] ?? $totals['shipping']) > 0 ? money($totals['shipping_display'] ?? $totals['shipping']) : 'Free' }}</dd></div>
        @endif
        @if ($totals['tax'] > 0 && ! $incl)
            @forelse ($totals['tax_lines'] ?? [] as $taxLine)
                @if ($taxLine['tax'] + $taxLine['shipping_tax'] > 0)
                    <div class="totals__tax"><dt>{{ $taxLine['label'] }}</dt><dd>{{ money($taxLine['tax'] + $taxLine['shipping_tax']) }}</dd></div>
                @endif
            @empty
                <div class="totals__tax"><dt>{{ $cart->taxLabel() }}</dt><dd>{{ money($totals['tax']) }}</dd></div>
            @endforelse
        @endif
        <div class="totals__grand"><dt>Total</dt><dd>{{ money($totals['total']) }}</dd></div>
        @if ($totals['tax'] > 0 && $incl)
            @php $includedTaxLines = array_values(array_filter($totals['tax_lines'] ?? [], fn ($l) => $l['tax'] + $l['shipping_tax'] > 0)); @endphp
            <div class="totals__includes"><dt>Includes</dt><dd>@foreach ($includedTaxLines as $taxLine){{ money($taxLine['tax'] + $taxLine['shipping_tax']) }} {{ $taxLine['label'] }}@if (! $loop->last), @endif @endforeach{{ empty($totals['tax_lines']) ? money($totals['tax']).' '.$cart->taxLabel() : '' }}</dd></div>
        @endif
    </dl>
</div>
