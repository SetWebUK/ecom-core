{{-- Create/edit a shipping zone and list its delivery options (drag to order). --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\Countries;
    $editing = $zone->exists;
    $countries = array_values(array_filter($zone->regionList(), fn ($r) => ! str_contains($r, ':')));
    $extra = implode(', ', array_filter($zone->regionList(), fn ($r) => str_contains($r, ':')));
@endphp

@section('title', $editing ? $zone->name.' · Shipping' : 'Add shipping zone')

@section('content')
    <x-admin.page-header :title="$editing ? $zone->name : 'Add shipping zone'" :back="route('admin.shipping.index')" back-label="Back to shipping" />

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'shipping'])
        <div class="stack">
            <x-admin.form id="zone-form" :action="$editing ? route('admin.shipping.zones.update', $zone) : route('admin.shipping.zones.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$editing ? 'Save zone' : 'Add zone'">
                <x-admin.card title="Zone">
                    <div class="stack-fields">
                        <x-admin.input name="name" label="Zone name" :value="$zone->name" required maxlength="120" :autofocus="! $editing" help="For you only, e.g. “UK mainland”, “Highlands & Islands”, “Europe”." />
                        <x-admin.select name="regions[]" label="Countries" :options="Countries::options()" :value="old('regions', $countries)" multiple size="8" optional error="regions"
                                        help="Hold Ctrl (⌘ on a Mac) to choose several. None = every country (a “rest of the world” zone – keep it last)." />
                        <x-admin.input name="regions_extra" label="Regions" :value="old('regions_extra', $extra)" optional class="mono" placeholder="US:CA, ES:PM"
                                       help="Optional parts of a country as country:code (matches the county/state the customer types)." />
                        <x-admin.textarea name="postcodes" label="Limit to postcodes" :value="old('postcodes', implode("\n", $zone->postcodeList()))" rows="4" optional code
                                          help="One per line. BT* = every BT postcode, HS1-HS9 = districts 1 to 9, IV51 = one outcode, 10000...19999 = a number range, !KW15 excludes. Blank = any postcode." />
                    </div>
                </x-admin.card>
            </x-admin.form>

            @if ($editing)
                <x-admin.card flush title="Delivery options in this zone" subtitle="Drag to change the order at checkout. The first available option is selected by default.">
                    <x-slot:actions><x-admin.button size="sm" variant="primary" icon="plus" :href="route('admin.shipping.create', ['zone' => $zone->id])">Add delivery option</x-admin.button></x-slot:actions>
                    @if ($methods->isEmpty())
                        <x-admin.empty icon="truck" title="No delivery options yet" description="Customers whose address falls in this zone can’t check out until you add one." size="sm" />
                    @else
                        <x-admin.sortable-list :url="route('admin.shipping.zones.methods.reorder', $zone)">
                            @foreach ($methods as $method)
                                <li class="sortable-list__item" data-id="{{ $method->id }}">
                                    <span class="drag-handle" aria-hidden="true" title="Drag to reorder"><x-admin.icon name="bars-2" size="sm" /></span>
                                    <div class="flex-1" style="min-width:0">
                                        <a href="{{ route('admin.shipping.edit', $method) }}" class="row-link fw-600">{{ $method->name }}</a>
                                        <div class="cell-sub truncate">
                                            {{ $method->typeLabel() }} · {{ $method->summary() }}
                                            @if ($method->min_order_amount && $method->typeKey() !== 'free_shipping') · orders over {{ money($method->min_order_amount) }}@endif
                                            @unless ($method->isTaxable()) · not taxed @endunless
                                            @if ($usage[$method->code] ?? 0) · used on {{ number_format($usage[$method->code]) }} orders @endif
                                        </div>
                                    </div>
                                    @if ($method->is_active)
                                        <x-admin.badge color="success" dot>Active</x-admin.badge>
                                    @else
                                        <x-admin.badge color="gray">Off</x-admin.badge>
                                    @endif
                                    <x-admin.button :href="route('admin.shipping.edit', $method)" icon="pencil-square" variant="ghost" size="sm" :label="'Edit '.$method->name" />
                                </li>
                            @endforeach
                        </x-admin.sortable-list>
                    @endif
                </x-admin.card>
            @endif

            <div class="form-actions">
                @if ($editing)
                    <x-admin.confirm :action="route('admin.shipping.zones.destroy', $zone)" variant="ghost-danger" icon="trash" :title="'Delete “'.$zone->name.'”?'" confirm-label="Delete zone"
                                     message="Its delivery options are deleted too. Past orders keep their delivery details.">Delete zone</x-admin.confirm>
                @endif
                <span class="flex-1"></span>
                <x-admin.button :href="route('admin.shipping.index')">Cancel</x-admin.button>
                <x-admin.button type="submit" form="zone-form" variant="primary">{{ $editing ? 'Save zone' : 'Add zone' }}</x-admin.button>
            </div>
        </div>
    </div>
@endsection
