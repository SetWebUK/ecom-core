{{-- Settings overview: one tile per settings screen. --}}
@extends('commerce::admin.layouts.app')

@php use Pine\Commerce\Services\Admin\StoreSettings; @endphp

@section('title', 'Settings')

@section('content')
    <x-admin.page-header title="Settings" subtitle="How the shop works: store details, checkout, payments, delivery, emails and staff." />

    <div class="settings-grid">
        @foreach ($groups as $key => $group)
            <a href="{{ StoreSettings::url($key) }}" class="settings-tile">
                <span class="settings-tile__icon"><x-admin.icon :name="$group['icon']" /></span>
                <span>
                    <span class="settings-tile__title" style="display:block">{{ $group['label'] }}</span>
                    <span class="settings-tile__text" style="display:block">{{ $group['description'] }}</span>
                    @if (! empty($summary[$key]))<span class="text-xs text-muted" style="display:block;margin-top:6px">{{ $summary[$key] }}</span>@endif
                </span>
            </a>
        @endforeach
    </div>

    <x-admin.callout type="neutral" class="mt-6" title="Other things you might be looking for">
        Header, mobile and footer links are in <a href="{{ route('admin.menus.index') }}">Menus</a>; the home page banner and sections in
        <a href="{{ route('admin.pages.index', ['template' => 'home']) }}">Pages › Home</a>; @if (commerce_feature('coupons', false))discount codes in <a href="{{ route('admin.coupons.index') }}">Discounts</a>.@endif
        Your own name, email and password are on <a href="{{ route('admin.profile.edit') }}">your profile</a>.
    </x-admin.callout>
@endsection
