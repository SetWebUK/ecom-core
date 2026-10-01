{{-- Settings › Shipping: zones (drag to order), unzoned delivery options, shipping classes, countries sold to. --}}
@extends('commerce::admin.layouts.app')

@php use Pine\Commerce\Services\Admin\Countries; @endphp

@section('title', 'Shipping · Settings')

@section('content')
    <x-admin.page-header title="Shipping" subtitle="Where you deliver, the options customers choose from at checkout and what they cost." :back="route('admin.settings.index')" back-label="All settings">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.shipping.zones.create')">Add shipping zone</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'shipping'])
        <div class="stack">
            <x-admin.card flush title="Shipping zones" subtitle="Checkout uses the FIRST zone that matches the delivery address (country, region, postcode) and offers that zone’s options. Drag to change the order – put specific zones (e.g. Highlands & Islands) above general ones.">
                @if ($zones->isEmpty())
                    <x-admin.empty icon="globe-europe-africa" title="No shipping zones" description="Every delivery option below is offered wherever its country list allows. Add zones to charge differently by country or postcode.">
                        <x-admin.button variant="primary" icon="plus" :href="route('admin.shipping.zones.create')">Add shipping zone</x-admin.button>
                    </x-admin.empty>
                @else
                    <x-admin.sortable-list :url="route('admin.shipping.zones.reorder')">
                        @foreach ($zones as $zone)
                            <li class="sortable-list__item" data-id="{{ $zone->id }}">
                                <span class="drag-handle" aria-hidden="true" title="Drag to reorder"><x-admin.icon name="bars-2" size="sm" /></span>
                                <div class="flex-1" style="min-width:0">
                                    <a href="{{ route('admin.shipping.zones.edit', $zone) }}" class="row-link fw-600">{{ $zone->name }}</a>
                                    <div class="cell-sub truncate">
                                        {{ $zone->regionsLabel() }}@if ($zone->postcodeList()) · postcodes {{ implode(', ', array_slice($zone->postcodeList(), 0, 5)) }}{{ count($zone->postcodeList()) > 5 ? ' …' : '' }}@endif
                                    </div>
                                    <div class="cell-sub truncate">
                                        @forelse ($zone->methods as $method)
                                            <span @class(['text-muted' => ! $method->is_active])>{{ $method->name }} ({{ $method->summary() }})</span>@if (! $loop->last) · @endif
                                        @empty
                                            <span class="text-warning">No delivery options – customers in this zone cannot check out.</span>
                                        @endforelse
                                    </div>
                                </div>
                                <x-admin.button :href="route('admin.shipping.zones.edit', $zone)" icon="pencil-square" variant="ghost" size="sm" :label="'Edit '.$zone->name" />
                            </li>
                        @endforeach
                    </x-admin.sortable-list>
                @endif
            </x-admin.card>

            @if ($methods->isNotEmpty() || $zones->isEmpty())
                <x-admin.card flush title="{{ $zones->isEmpty() ? 'Delivery options' : 'Delivery options without a zone' }}" subtitle="Offered after the zone’s options, wherever their own country list allows (how every option worked before zones).">
                    <x-slot:actions><x-admin.button size="sm" icon="plus" :href="route('admin.shipping.create')">Add option</x-admin.button></x-slot:actions>
                    @if ($methods->isEmpty())
                        <x-admin.empty icon="truck" title="No delivery options" description="Customers can’t choose delivery at checkout until you add at least one." size="sm" />
                    @else
                        <x-admin.sortable-list :url="route('admin.shipping.reorder')">
                            @foreach ($methods as $method)
                                <li class="sortable-list__item" data-id="{{ $method->id }}">
                                    <span class="drag-handle" aria-hidden="true" title="Drag to reorder"><x-admin.icon name="bars-2" size="sm" /></span>
                                    <div class="flex-1" style="min-width:0">
                                        <a href="{{ route('admin.shipping.edit', $method) }}" class="row-link fw-600">{{ $method->name }}</a>
                                        <div class="cell-sub truncate">
                                            {{ $method->typeLabel() }} · {{ $method->summary() }}
                                            @if ($method->min_order_amount && $method->typeKey() !== 'free_shipping') · orders over {{ money($method->min_order_amount) }}@endif
                                            · {{ $method->countries ? collect($method->countries)->map(fn ($c) => Countries::name($c))->implode(', ') : 'All countries' }}
                                            @if ($usage[$method->code] ?? 0) · used on {{ number_format($usage[$method->code]) }} orders @endif
                                        </div>
                                    </div>
                                    @if ($method->is_active)
                                        <x-admin.badge color="success" dot>Active</x-admin.badge>
                                    @else
                                        <x-admin.badge color="gray">Off</x-admin.badge>
                                    @endif
                                    <x-admin.button :href="route('admin.shipping.edit', $method)" icon="pencil-square" variant="ghost" size="sm" :label="'Edit '.($method->name)" />
                                </li>
                            @endforeach
                        </x-admin.sortable-list>
                    @endif
                </x-admin.card>
            @endif

            <x-admin.card title="Countries you sell to" subtitle="The country list at checkout. Each country also needs a zone (or an unzoned option) with a delivery option." id="selling-countries">
                <x-admin.form :action="route('admin.shipping.countries.update')" method="PUT">
                    <div class="stack-fields">
                        <x-admin.select name="countries[]" label="Countries" :options="Countries::options()" :value="array_keys($sellTo)" multiple size="8" error="countries"
                                        help="Hold Ctrl (⌘ on a Mac) to choose several." />
                        <div><x-admin.button type="submit" size="sm">Save countries</x-admin.button></div>
                    </div>
                </x-admin.form>
            </x-admin.card>

            <x-admin.card title="Shipping classes" subtitle="Group products that cost more (or less) to send, e.g. “Bulky”. Flat-rate options can then charge per class. Set a product’s class on its page." id="shipping-classes">
                @if ($classes->isNotEmpty())
                    <ul class="stack stack--sm" style="list-style:none;margin:0 0 var(--s-3);padding:0">
                        @foreach ($classes as $class)
                            <li class="row row--between">
                                <span><span class="fw-600">{{ $class->name }}</span> <span class="text-xs text-muted mono">#{{ $class->id }}</span>
                                    · {{ number_format((int) ($classUsage[$class->id] ?? 0)) }} {{ Str::plural('product', (int) ($classUsage[$class->id] ?? 0)) }}
                                    @if ($class->description)<span class="text-muted"> · {{ $class->description }}</span>@endif</span>
                                <x-admin.confirm :action="route('admin.shipping.classes.destroy', $class)" variant="ghost-danger" size="sm" icon="trash"
                                                 :title="'Delete “'.$class->name.'”?'" message="Products in this class go back to having no class." confirm-label="Delete class">Delete</x-admin.confirm>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('admin.shipping.classes.store') }}" class="form-grid" style="align-items:flex-end">
                    @csrf
                    <x-admin.input name="name" label="New shipping class" bag="shippingClass" maxlength="120" placeholder="e.g. Bulky" />
                    <x-admin.input name="description" label="Description" bag="shippingClass" optional maxlength="500" />
                    <div><x-admin.button type="submit" icon="plus">Add class</x-admin.button></div>
                </form>
            </x-admin.card>

            <x-admin.callout type="neutral" title="Good to know">
                Prices are entered the way Settings › <a href="{{ route('admin.settings.edit', 'tax') }}">Tax</a> says (with or without VAT).
                A free-shipping coupon unlocks “Free shipping” options that require a coupon.
                The price shown in the Google Shopping feed is set under <a href="{{ route('admin.settings.edit', 'seo') }}">SEO &amp; tracking</a>.
            </x-admin.callout>
        </div>
    </div>
@endsection
