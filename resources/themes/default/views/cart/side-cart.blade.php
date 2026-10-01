{{-- Side basket contents (GET /cart/fragment and every basket AJAX response). $cart, $totals, $notices, $error --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $lines = $totals['lines'];
@endphp
<div class="side-cart__content" data-count="{{ $totals['count'] }}">
    @if (! empty($error))
        <div class="notice notice--error" role="alert">{{ $error }}</div>
    @endif
    @foreach ($notices ?? [] as $notice)
        <div class="notice notice--info">{{ $notice }}</div>
    @endforeach
    @if ($lines->isEmpty())
        <div class="empty empty--cart">
            {!! $S::icon('bag', 44) !!}
            <p class="empty__title">Your basket is empty</p>
            <p class="muted">Find something you love and add it here.</p>
            <a class="btn btn--primary" href="{{ url('shop') }}/">Start shopping</a>
        </div>
    @else
        <ul class="cart-lines">
            @foreach ($lines as $line)
                @php
                    $available = $cart->availableStock($line->product, $line->variation);
                    $max = $line->product->sold_individually ? 1 : ($available ?? 999);
                @endphp
                <li class="cart-line" data-item-id="{{ $line->id() }}" data-qty="{{ $line->quantity }}" data-max="{{ $max }}">
                    <a class="cart-line__media" href="{{ $line->url() }}" tabindex="-1" aria-hidden="true"><img src="{{ $line->imageUrl() }}" alt="" width="72" height="72" loading="lazy"></a>
                    <div class="cart-line__body">
                        <a class="cart-line__name" href="{{ $line->url() }}">{{ $line->name() }}</a>
                        @if ($options = $line->options())
                            <p class="cart-line__options">@foreach ($options as $label => $value){{ $label }}: {{ $value }}@if (! $loop->last) · @endif @endforeach</p>
                        @endif
                        <div class="cart-line__row">
                            <div class="qty qty--sm" role="group" aria-label="Quantity for {{ $line->name() }}">
                                <button type="button" class="qty__btn" aria-label="Decrease quantity" data-line-qty="-1">{!! $S::icon('minus', 14) !!}</button>
                                <span class="qty__value">{{ $line->quantity }}</span>
                                <button type="button" class="qty__btn" aria-label="Increase quantity" data-line-qty="1" @disabled($line->quantity >= $max)>{!! $S::icon('plus', 14) !!}</button>
                            </div>
                            <span class="cart-line__price">{{ money($line->displaySubtotal($totals['display_incl'] ?? null)) }}</span>
                        </div>
                    </div>
                    <button type="button" class="icon-btn icon-btn--sm cart-line__remove" aria-label="Remove {{ $line->name() }}" data-line-remove>{!! $S::icon('trash', 18) !!}</button>
                </li>
            @endforeach
        </ul>
        <div class="side-cart__foot">
            <dl class="totals">
                <div><dt>Subtotal</dt><dd>{{ money($totals['subtotal_display'] ?? $totals['subtotal']) }}</dd></div>
                @foreach ($totals['coupons_display'] ?? $totals['coupons'] as $code => $amount)
                    <div><dt>Coupon {{ $code }}</dt><dd>−{{ money($amount) }}</dd></div>
                @endforeach
            </dl>
            <p class="muted small">{{ ($totals['display_incl'] ?? false) && ($totals['tax'] ?? 0) > 0 ? 'Prices include '.\Pine\Commerce\Services\Tax\TaxSettings::label().'. Delivery calculated at checkout.' : 'Shipping and taxes calculated at checkout.' }}</p>
            <a class="btn btn--primary btn--lg btn--block" href="{{ url('checkout') }}/">{!! $S::icon('lock', 18) !!}<span>Checkout</span></a>
            <button type="button" class="btn btn--ghost btn--block" data-drawer-close>Continue shopping</button>
        </div>
    @endif
</div>
