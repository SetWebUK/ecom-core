{{--
    One hidden media library for the page, opened from JavaScript: Admin.pickImage(function (item) { … item.path … }).
    Used by the page builder, menu builder and rich-text dialogs. Shares its @once key with <x-admin.rich-editor>'s
    own bridge, so a page never gets two. <x-admin.media-bridge />
--}}
@once('admin-content-js')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('js/content.js') }}"></script>
    @endpush
@endonce
@once('admin-editor-media-bridge')
    <div x-data="imagePicker(@js(['name' => '', 'multiple' => false, 'items' => [], 'uploadUrl' => route('admin.media.upload'), 'libraryUrl' => route('admin.api.media'), 'editorBridge' => true]))" hidden>
        @include('commerce::admin.partials.media-library', ['top' => true, 'multiple' => false])
    </div>
@endonce
