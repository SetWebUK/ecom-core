{{--
    <x-admin.page-header title="Discounts" subtitle="Codes customers enter at checkout">
        <x-slot:actions><x-admin.button variant="primary" icon="plus" :href="…">Create discount</x-admin.button></x-slot:actions>
    </x-admin.page-header>
    <x-admin.page-header :title="$coupon->code" :back="route('admin.coupons.index')">
        <x-slot:badges><x-admin.badge color="success">Active</x-admin.badge></x-slot:badges>
    </x-admin.page-header>
    Props: title, subtitle, back (url), back-label. Slots: badges, actions, meta (after subtitle)
--}}
@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => 'Back'])
<div {{ $attributes->class(['page-header']) }}>
    @if ($back)
        <a href="{{ $back }}" class="btn btn--ghost btn--icon page-header__back" aria-label="{{ $backLabel }}" title="{{ $backLabel }}"><x-admin.icon name="arrow-left" /></a>
    @endif
    <div class="page-header__main">
        <div class="page-header__title-row">
            <h1 class="page-header__title">{{ $title }}</h1>
            {{ $badges ?? '' }}
        </div>
        @if ($subtitle || isset($meta))
            <p class="page-header__subtitle">{{ $subtitle }}{{ $meta ?? '' }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="page-header__actions">{{ $actions }}</div>
    @endisset
</div>
