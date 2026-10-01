{{-- Sales area assets (sales.css + sales.js). @include('commerce::admin.orders._assets') once per Sales page. --}}
@once('admin-sales-assets')
    @push('head')
        <link rel="stylesheet" href="{{ commerce_admin_asset('css/sales.css') }}">
    @endpush
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('js/sales.js') }}"></script>
    @endpush
@endonce
