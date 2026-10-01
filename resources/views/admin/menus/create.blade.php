{{-- New menu: name + location, then the builder. --}}
@extends('commerce::admin.layouts.app', ['width' => 'narrow'])

@section('title', 'Add menu')

@section('content')
    <x-admin.page-header title="Add menu" :back="route('admin.menus.index')" back-label="Back to menus" />

    <x-admin.form id="menu-form" :action="route('admin.menus.store')" dirty save-label="Create menu">
        <x-admin.card>
            <div class="stack-fields">
                <x-admin.input name="name" label="Name" :value="$menu->name" required maxlength="120" autofocus help="For footer columns this is also the column heading." />
                @if ($locations)
                    <x-admin.select name="location" label="Where it appears" :options="$locations" :value="$menu->location" placeholder="Choose…" required />
                @else
                    <x-admin.input name="location" label="Location key" :value="$menu->location" required help="Every place on the site already has a menu. A new key won’t be shown until the design uses it." />
                @endif
            </div>
        </x-admin.card>
    </x-admin.form>

    <div class="form-actions">
        <x-admin.button :href="route('admin.menus.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="menu-form" variant="primary">Create menu</x-admin.button>
    </div>
@endsection
