{{-- Menu builder: nested drag-and-drop tree (3 levels) with inline editing; the whole tree is saved in one POST. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    $isHeader = in_array($menu->location, ['mega', 'main'], true);
    $isMobile = in_array($menu->location, ['mobile_nav', 'mobile'], true);
    $isFooter = str_starts_with($menu->location, 'footer_');
    $initialTree = old('tree') ? (json_decode(old('tree'), true) ?: $tree) : $tree;
@endphp

@section('title', $menu->name.' · Menus')

@section('content')
    <x-admin.page-header :title="$menu->name" :back="route('admin.menus.index')" back-label="Back to menus">
        <x-slot:badges>
            <x-admin.badge color="gray" class="mono">{{ $menu->location }}</x-admin.badge>
            @if ($location)<x-admin.badge color="info">{{ $location['label'] }}</x-admin.badge>@endif
        </x-slot:badges>
        <x-slot:actions>
            <x-admin.button :href="url('/').'/'" icon="arrow-top-right-on-square" target="_blank" rel="noopener">View site</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @error('tree')
        <x-admin.callout type="danger" class="mb-4" title="The menu wasn’t saved">
            <ul class="summary-list mt-1">
                @foreach ($errors->get('tree') as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </x-admin.callout>
    @enderror

    <x-admin.media-bridge />
    <x-admin.link-picker />
    @once('admin-sortable')
        @push('vendor')
            <script defer src="{{ commerce_admin_asset('vendor/sortablejs/Sortable-1.15.7.min.js', false) }}"></script>
        @endpush
    @endonce

    <x-admin.form id="menu-form" :action="route('admin.menus.update', $menu)" method="PUT" dirty save-label="Save menu">
        <div class="layout" x-data="menuBuilder(@js(['items' => $initialTree, 'maxDepth' => 3]))">
            <input type="hidden" name="tree" x-ref="treeInput" :value="serialized" value="{{ json_encode($initialTree) }}">
            <div class="layout__main">
                <x-admin.card flush>
                    <x-slot:header>
                        <div class="flex-1">
                            <h2 class="card__title">Links</h2>
                            <p class="card__subtitle"><span x-text="count">0</span> items · drag <x-admin.icon name="bars-2" size="xs" /> to reorder or nest (up to 3 levels)</p>
                        </div>
                    </x-slot:header>
                    <x-slot:actions>
                        <x-admin.button size="sm" variant="ghost" x-on:click="expandAll(false)">Collapse all</x-admin.button>
                    </x-slot:actions>
                    <div class="card__body">
                        <div class="menu-tree" :class="{ 'is-dragging': dragging }">
                            <p class="repeater__empty" x-show="!items.length" x-cloak>This menu is empty – the site shows its built-in links here until you add some.</p>
                            <ul class="menu-tree__list" data-parent="root" data-depth="1" x-init="bindList($el)">
                                <template x-for="(n1, i1) in items" :key="n1._k">
                                    @include('commerce::admin.menus._node', ['depth' => 1, 'n' => 'n1', 'i' => 'i1', 'list' => 'items', 'parentList' => null, 'parentIndex' => null])
                                </template>
                            </ul>
                        </div>
                        <div class="row mt-4">
                            <x-admin.button variant="primary" icon="link" x-on:click="addLink()">Add a link</x-admin.button>
                            <x-admin.button icon="plus" x-on:click="add(null)">Add custom item</x-admin.button>
                        </div>
                    </div>
                </x-admin.card>
                {{-- CSS classes the active theme's menus understand (theme config menus.style_classes: class => description) --}}
                <datalist id="menu-style-classes">
                    @foreach ((array) theme_config('menus.style_classes', []) as $styleClass => $styleLabel)
                        <option value="{{ $styleClass }}">{{ $styleLabel }}</option>
                    @endforeach
                </datalist>
            </div>

            <div class="layout__aside layout__aside--sticky">
                <x-admin.card title="Menu details">
                    <div class="stack-fields">
                        <x-admin.input name="name" label="Name" :value="$menu->name" required maxlength="120" :help="$isFooter ? 'Shown as the column heading in the footer.' : null" />
                        <x-admin.select name="location" label="Where it appears" :options="$locations" :value="$menu->location" required
                                        help="Each place on the site shows one menu." />
                    </div>
                    @if ($location)
                        <p class="drives mt-3"><x-admin.icon name="map-pin" />{{ $location['where'] }}</p>
                    @endif
                </x-admin.card>

                @if ($isHeader)
                    <x-admin.card title="How the header menu is laid out">
                        <ul class="summary-list">
                            <li>Top-level items are the links across the header.</li>
                            <li>Sub-items <strong>with an image and no sub-items</strong> show as picture tiles (Apple, Desktops …).</li>
                            <li>Otherwise each sub-item is a <strong>column heading</strong> (leave its link blank) with its own links underneath.</li>
                            <li>Style <span class="mono">same-column</span> stacks a heading under the previous column.</li>
                            <li>Style <span class="mono">mega-promo</span> plus an image adds the promo picture on the right.</li>
                            <li>A badge (e.g. “popular”) shows as a small orange tag.</li>
                        </ul>
                    </x-admin.card>
                @elseif ($isMobile)
                    <x-admin.card title="How the mobile menu works">
                        <ul class="summary-list">
                            <li>Items with sub-items open as accordions.</li>
                            <li>A style (CSS class) containing <span class="mono">mnav-cta</span> makes an item the big button at the bottom; <span class="mono">mnav-help</span> the help link.</li>
                        </ul>
                    </x-admin.card>
                @endif

                <x-admin.card title="Tips">
                    <ul class="summary-list">
                        <li>Use <strong>Add a link</strong> to pick a page, category, product or blog post.</li>
                        <li>Changes go live as soon as you save.</li>
                        <li>Keyboard: open an item’s <x-admin.icon name="ellipsis-horizontal" size="xs" /> menu to move or nest it.</li>
                    </ul>
                </x-admin.card>
            </div>
        </div>
    </x-admin.form>

    <div class="form-actions">
        <x-admin.confirm :action="route('admin.menus.destroy', $menu)" variant="ghost-danger" icon="trash"
                         :title="'Delete the “'.($menu->name).'” menu?'" confirm-label="Delete menu"
                         :message="$location ? 'This part of the site will go back to its built-in links. This can’t be undone.' : 'This can’t be undone.'">Delete menu</x-admin.confirm>
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.menus.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="menu-form" variant="primary">Save menu</x-admin.button>
    </div>
@endsection
