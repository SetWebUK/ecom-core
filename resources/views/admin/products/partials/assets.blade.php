{{--
    Catalogue page assets: commerce_admin_asset('css/catalogue.css') + js/catalogue.js (Alpine components), plus
    SortableJS when a page drags things. @include('commerce::admin.products.partials.assets', ['sortable' => true])
--}}
@php $v = fn (string $path) => commerce_admin_asset($path); @endphp
@once('catalogue-assets')
    @push('head')
        <link rel="stylesheet" href="{{ $v('css/catalogue.css') }}">
    @endpush
    @push('vendor')
        <script defer src="{{ $v('js/catalogue.js') }}"></script>
    @endpush
@endonce
@if (! empty($sortable))
    @once('admin-sortable')
        @push('vendor')
            <script defer src="{{ commerce_admin_asset('vendor/sortablejs/Sortable-1.15.7.min.js', false) }}"></script>
        @endpush
    @endonce
@endif
