{{-- One category in the tree (recursive). Every item has a nested list so categories can be dropped into it. --}}
@php
    $category = $node['category'];
    $count = $node['count'];
@endphp
<li data-tree-item data-id="{{ $category->id }}" data-name="{{ $category->name }}" @class(['is-hidden' => ! $category->is_visible]) style="--depth: {{ $depth }}">
    <div class="cat-tree__row">
        <span class="drag-handle" title="Drag to move" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
        <div class="cat-tree__main">
            <x-admin.thumb :src="$category->image" size="sm" icon="folder" />
            <div class="flex-1">
                <a class="cat-tree__name" href="{{ route('admin.categories.edit', $category) }}">{{ $category->name }}</a>
                <div class="cat-tree__path" data-path-for="{{ $category->id }}">/{{ $category->path }}/</div>
            </div>
        </div>
        <div class="cat-tree__meta">
            @unless ($category->show_in_menu)<x-admin.badge size="sm" class="hidden-mobile">Not in menu</x-admin.badge>@endunless
            <a class="cat-tree__count" href="{{ route('admin.products.index', ['category' => $category->id]) }}" title="Products directly in this category">{{ number_format($count) }} {{ Str::plural('product', $count) }}</a>
            <span class="cat-tree__move">
                <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="shift($el, -1)" aria-label="Move {{ $category->name }} up" title="Move up"><x-admin.icon name="chevron-up" /></button>
                <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="shift($el, 1)" aria-label="Move {{ $category->name }} down" title="Move down"><x-admin.icon name="chevron-down" /></button>
            </span>
            <button type="button" class="vis-btn" aria-pressed="{{ $category->is_visible ? 'true' : 'false' }}" @click="toggleVisible($el, @js(route('admin.categories.visibility', $category)))"
                    aria-label="Shown in the shop: {{ $category->name }}" title="Show or hide in the shop">
                <x-admin.icon name="eye" class="vis-btn__on" /><x-admin.icon name="eye-slash" class="vis-btn__off" />
            </button>
            <a class="btn btn--ghost btn--icon btn--sm" href="{{ route('admin.categories.create', ['parent' => $category->id]) }}" aria-label="Add a sub-category to {{ $category->name }}" title="Add sub-category"><x-admin.icon name="plus" /></a>
        </div>
    </div>
    <ul class="cat-tree__list" data-tree-list>
        @foreach ($node['children'] as $child)
            @include('commerce::admin.categories.partials.node', ['node' => $child, 'depth' => $depth + 1])
        @endforeach
    </ul>
</li>
