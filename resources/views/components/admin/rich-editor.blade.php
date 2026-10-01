{{--
    Rich-text editor (TinyMCE 6, self-hosted). Keeps imported HTML intact – any element/attribute (classes, inline styles,
    data-*, ids, iframes) survives a save. "Source code" button edits raw HTML. Images: upload/paste/drag (-> admin.media.upload)
    or browse the media library. The raw textarea stays usable if the editor fails to load.
    <x-admin.rich-editor name="content" label="Content" :value="$page->content" height="600" />
    <x-admin.rich-editor name="description" :value="$product->description" content-css="/assets/css/site.css" />
    Props: name, label, value, height, help, required, id, content-css (URL of CSS for the editing area), uploads (bool)
    Store the posted value as-is (trusted staff HTML) and output it with {!! !!} on the storefront.
--}}
@props(['name', 'label' => null, 'value' => null, 'height' => 480, 'help' => null, 'required' => false, 'id' => null, 'contentCss' => null, 'uploads' => true, 'bodyClass' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $config = array_filter([
        'height' => (int) $height,
        'uploadUrl' => $uploads ? route('admin.media.upload') : null,
        'contentCss' => $contentCss,
        'bodyClass' => $bodyClass,
    ], fn ($v) => $v !== null);
@endphp
@once('admin-tinymce')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('vendor/tinymce-6.8.6/tinymce.min.js', false) }}"></script>
    @endpush
@endonce
<x-admin.field :label="$label" :for="$id" :help="$help" :error="$key" :required="$required" {{ $attributes->only('class') }}>
    <div class="rich-editor" x-data="richEditor(@js($config))">
        <textarea x-ref="textarea" name="{{ $name }}" id="{{ $id }}" class="textarea textarea--code" rows="14" style="min-height: {{ (int) $height }}px" @required($required)>{{ old($key, $value) }}</textarea>
        <div x-show="!ready" class="text-xs text-muted mt-1" aria-live="polite"><span class="spinner spinner--sm" style="display:inline-block;vertical-align:-2px"></span> Loading editor…</div>
    </div>
</x-admin.field>
@if ($uploads)
    @once('admin-editor-media-bridge')
        {{-- One hidden media library that TinyMCE's image dialog can open ("browse" button) --}}
        <div x-data="imagePicker(@js(['name' => '', 'multiple' => false, 'items' => [], 'uploadUrl' => route('admin.media.upload'), 'libraryUrl' => route('admin.api.media'), 'editorBridge' => true]))" hidden>
            @include('commerce::admin.partials.media-library', ['top' => true, 'multiple' => false])
        </div>
    @endonce
@endif
