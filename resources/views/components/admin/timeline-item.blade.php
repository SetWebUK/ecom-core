{{--
    Props: icon, color (primary|success|warning|danger), time (Carbon), author, bubble (boxed content), customer (blue "sent to customer" bubble)
    Slot "meta": extra items after the time (e.g. a badge).
--}}
@props(['icon' => null, 'color' => null, 'time' => null, 'author' => null, 'bubble' => false, 'customer' => false])
<li {{ $attributes->class(['timeline__item']) }}>
    <span @class(['timeline__dot', 'timeline__dot--'.$color => $color])>
        @if ($icon)<x-admin.icon :name="$icon" />@endif
    </span>
    <div class="timeline__body">
        <div @class(['timeline__content', 'timeline__content--bubble' => $bubble || $customer, 'timeline__content--customer' => $customer])>{{ $slot }}</div>
        @if ($time || $author || isset($meta))
            <div class="timeline__meta">
                @if ($author)<span>{{ $author }}</span><span aria-hidden="true">·</span>@endif
                @if ($time)<x-admin.time :value="$time" />@endif
                {{ $meta ?? '' }}
            </div>
        @endif
    </div>
</li>
