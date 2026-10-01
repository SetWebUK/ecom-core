{{-- Categories: nested tree with drag-and-drop (reorder + move into another category), product counts and visibility. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Categories')

@section('content')
    @include('commerce::admin.products.partials.assets', ['sortable' => true])

    <x-admin.page-header title="Categories" :subtitle="number_format($total).' categories. Drag to reorder, or drop one onto another to make it a sub-category.'">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.categories.create')">Add category</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($total === 0)
        <div class="card">
            <x-admin.empty icon="folder" title="Add your first category" description="Categories group products in the shop’s menus and give them their web address.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.categories.create')">Add category</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.filters placeholder="Search categories" />

            @if ($matches !== null)
                {{-- Search results: flat list, no dragging --}}
                @if ($matches->isEmpty())
                    <x-admin.empty icon="magnifying-glass" title="No categories match" description="Try a different search." size="sm">
                        <x-admin.button :href="route('admin.categories.index')">Clear search</x-admin.button>
                    </x-admin.empty>
                @else
                    <ul class="cat-tree">
                        @foreach ($matches as $category)
                            <li>
                                <div class="cat-tree__row">
                                    <div class="cat-tree__main">
                                        <x-admin.thumb :src="$category->image" size="sm" icon="folder" />
                                        <div class="flex-1">
                                            <a class="cat-tree__name" href="{{ route('admin.categories.edit', $category) }}">{{ $category->name }}</a>
                                            <div class="cat-tree__path">/{{ $category->path }}/</div>
                                        </div>
                                    </div>
                                    <div class="cat-tree__meta">
                                        @unless ($category->is_visible)<x-admin.badge size="sm">Hidden</x-admin.badge>@endunless
                                        <span class="cat-tree__count">{{ number_format($counts[$category->id] ?? 0) }} {{ Str::plural('product', $counts[$category->id] ?? 0) }}</span>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <div x-data="categoryTree(@js(['url' => route('admin.categories.reorder')]))" :class="{ 'is-busy': saving }">
                    <ul class="cat-tree cat-tree__list" data-tree-list>
                        @foreach ($tree as $node)
                            @include('commerce::admin.categories.partials.node', ['node' => $node, 'depth' => 0])
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-admin.card>
        <p class="text-xs text-muted mt-3">Tip: use the arrow buttons (or Tab to them) to move a category up or down without dragging. Moving a category into another one changes its web address – the old addresses are redirected automatically.</p>
    @endif
@endsection
