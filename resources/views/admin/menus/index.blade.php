{{-- Menus: every menu with where it appears on the site. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Menus')

@section('content')
    <x-admin.page-header title="Menus" subtitle="The links in the header, mobile menu and footer. Click a menu to add, remove or drag its links.">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.menus.create')">Add menu</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($missing)
        <x-admin.callout type="neutral" class="mb-4" title="Some parts of the site use their built-in links">
            No menu has been set up for:
            @foreach ($missing as $key => $location)
                <a href="{{ route('admin.menus.create', ['location' => $key]) }}">{{ $location['label'] }}</a>@if (! $loop->last), @endif
            @endforeach
            – create one to edit those links.
        </x-admin.callout>
    @endif

    @if ($rows->isEmpty())
        <div class="card">
            <x-admin.empty icon="bars-3" title="No menus yet" description="The site shows its built-in navigation until you create menus.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.menus.create')">Add menu</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.table stack>
                <x-slot:head>
                    <x-admin.th>Menu</x-admin.th>
                    <x-admin.th>Shown on the site</x-admin.th>
                    <x-admin.th align="right">Links</x-admin.th>
                    <x-admin.th class="hidden-mobile">Updated</x-admin.th>
                </x-slot:head>
                @foreach ($rows as $row)
                    @php $menu = $row['menu']; @endphp
                    <tr @class(['is-muted' => ! $row['inUse']])>
                        <td class="stack-title">
                            <a href="{{ route('admin.menus.edit', $menu) }}" class="row-link">{{ $menu->name }}</a>
                            <div class="cell-sub mono">{{ $menu->location }}</div>
                        </td>
                        <td data-label="Shown on the site">
                            @if ($row['inUse'])
                                <x-admin.badge color="success" dot>{{ $row['location']['label'] }}</x-admin.badge>
                                <div class="cell-sub">{{ $row['location']['where'] }}</div>
                            @elseif ($row['location'])
                                <x-admin.badge color="gray">Not in use</x-admin.badge>
                                <div class="cell-sub">{{ $row['location']['where'] }}</div>
                            @else
                                <x-admin.badge color="gray">Not shown</x-admin.badge>
                                <div class="cell-sub">Imported from the old site – the new design doesn’t display this menu.</div>
                            @endif
                        </td>
                        <td class="num" data-label="Links">{{ number_format($menu->items_count) }}</td>
                        <td class="nowrap text-muted hidden-mobile" data-label="Updated"><x-admin.time :value="$menu->updated_at" /></td>
                    </tr>
                @endforeach
            </x-admin.table>
        </x-admin.card>
    @endif
@endsection
