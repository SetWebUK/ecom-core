{{--
    Drag-to-reorder list (SortableJS). Each direct child needs data-id and a .drag-handle.
    With url: POSTs {ids: [...]} (new order) as JSON after every drop and shows a toast – return {message?} from the endpoint.
    Without url: include <input type="hidden" name="positions[ID]" data-position> in each item; values are renumbered 0..n.
    <x-admin.sortable-list :url="route('admin.categories.reorder')">
        @foreach ($categories as $category)
            <li class="sortable-list__item" data-id="{{ $category->id }}">
                <span class="drag-handle" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
                <span class="flex-1">{{ $category->name }}</span>
            </li>
        @endforeach
    </x-admin.sortable-list>
    Also fires a "sorted" event ({ids}) – listen with x-on:sorted="…". Props: url, handle (selector), group (shared name for nested lists), tag
--}}
@props(['url' => null, 'handle' => '.drag-handle', 'group' => null, 'tag' => 'ul'])
@once('admin-sortable')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('vendor/sortablejs/Sortable-1.15.7.min.js', false) }}"></script>
    @endpush
@endonce
<{{ $tag }} {{ $attributes->class(['sortable-list']) }} x-data="sortableList(@js(array_filter(['url' => $url, 'handle' => $handle, 'group' => $group])))">
    {{ $slot }}
</{{ $tag }}>
