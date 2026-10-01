{{--
    Pick an image from the media library or upload one. Posts public-disk paths (e.g. "uploads/2026/09/photo.jpg").

    Single:   <x-admin.image-picker name="image" label="Image" :value="$category->image" />
              -> request('image') = "uploads/…" or null (removed)
    Multiple: <x-admin.image-picker name="images" label="Media" multiple :value="$product->images" />
              value: paths, or [['path' => …, 'alt' => …]], or ProductImage models. First image = main image; drag to reorder.
              -> request('images') = [['path' => 'uploads/…', 'alt' => '…'], …] in display order ([] when all removed)
    Props: name, label, value, multiple, with-alt (multiple only, default true), help, required, id
    Validate: 'image' => ['nullable', 'string', 'max:255'];  'images' => ['array'], 'images.*.path' => ['required', 'string', 'max:255'], 'images.*.alt' => ['nullable', 'string', 'max:255']
--}}
@props(['name', 'label' => null, 'value' => null, 'multiple' => false, 'withAlt' => true, 'help' => null, 'required' => false, 'id' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $source = old($key, $value);
    if ($source instanceof \Illuminate\Support\Collection) {
        $source = $source->all();
    }
    $rows = $multiple ? (array) $source : ($source ? [$source] : []);
    $items = collect($rows)->map(function ($row) {
        $path = is_array($row) ? ($row['path'] ?? null) : (is_object($row) ? ($row->path ?? null) : $row);
        $alt = is_array($row) ? ($row['alt'] ?? '') : (is_object($row) ? ($row->alt ?? '') : '');

        return $path ? ['path' => (string) $path, 'url' => media_url($path), 'alt' => (string) $alt] : null;
    })->filter()->values()->all();
    $config = [
        'name' => $name, 'multiple' => (bool) $multiple, 'withAlt' => (bool) $withAlt, 'items' => $items,
        'uploadUrl' => route('admin.media.upload'), 'libraryUrl' => route('admin.api.media'),
    ];
@endphp
@if ($multiple)
    @once('admin-sortable')
        @push('vendor')
            <script defer src="{{ commerce_admin_asset('vendor/sortablejs/Sortable-1.15.7.min.js', false) }}"></script>
        @endpush
    @endonce
@endif
<x-admin.field :label="$label" :for="$id" :help="$help" :error="$key" :required="$required" {{ $attributes->only('class') }}>
    <div class="image-picker" x-data="imagePicker(@js($config))" id="{{ $id }}">
        @if (! $multiple)
            <input type="hidden" name="{{ $name }}" :value="single ? single.path : ''" value="{{ $items[0]['path'] ?? '' }}">
            <div class="image-picker__single" @dragover.prevent="dragover = true" @dragleave.prevent="dragover = false" @drop.prevent="onDrop($event)">
                <div class="image-picker__preview" :class="{ 'is-dragover': dragover }">
                    <template x-if="single"><img :src="single.url" alt=""></template>
                    <template x-if="!single"><x-admin.icon name="photo" size="xl" /></template>
                </div>
                <div class="stack stack--sm">
                    <div class="row">
                        <button type="button" class="btn btn--sm" @click="openLibrary()"><x-admin.icon name="photo" /><span x-text="single ? 'Replace' : 'Choose image'">Choose image</span></button>
                        <label class="btn btn--sm"><x-admin.icon name="arrow-up-tray" /><span>Upload</span><input type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" class="sr-only" x-ref="file" @change="upload($event.target.files)"></label>
                        <button type="button" class="btn btn--sm btn--ghost-danger" x-show="single" x-cloak @click="remove(0)"><span>Remove</span></button>
                    </div>
                    <p class="text-xs text-muted break" x-show="single" x-text="single ? single.path : ''"></p>
                    <p class="text-xs text-muted" x-show="!single && !uploading">JPG, PNG, WebP or GIF up to {{ \Pine\Commerce\Http\Controllers\Admin\MediaUploadController::MAX_KB / 1024 }} MB. You can also drop a file here.</p>
                    <p class="text-xs text-muted" x-show="uploading" x-cloak><span class="spinner spinner--sm" style="display:inline-block;vertical-align:-2px"></span> Uploading…</p>
                </div>
            </div>
        @else
            <input type="hidden" name="{{ $name }}" value="">
            <div class="image-grid" x-ref="grid" @dragover.prevent="dragover = true" @dragleave.prevent="dragover = false" @drop.prevent="onDrop($event)">
                <template x-for="(item, index) in items" :key="item.path">
                    <div class="image-grid__item">
                        <img class="image-grid__img" :src="item.url" :alt="item.alt || ''" loading="lazy">
                        <span class="image-grid__handle drag-handle" title="Drag to reorder" aria-hidden="true"><x-admin.icon name="arrows-pointing-out" size="sm" /></span>
                        <div class="image-grid__tools">
                            <button type="button" class="btn btn--sm btn--icon" x-show="index > 0" @click="makeFirst(index)" title="Make this the main image" aria-label="Make main image"><x-admin.icon name="star" /></button>
                            <button type="button" class="btn btn--sm btn--icon" @click="remove(index)" title="Remove" aria-label="Remove image"><x-admin.icon name="trash" /></button>
                        </div>
                        <template x-if="index === 0"><span class="badge badge--dark badge--sm image-grid__badge">Main</span></template>
                        <input type="hidden" :name="name + '[' + index + '][path]'" :value="item.path">
                        @if ($withAlt)
                            <input type="text" class="image-grid__alt" :name="name + '[' + index + '][alt]'" x-model="item.alt" placeholder="Alt text" aria-label="Alt text (describes the image for screen readers and Google)" maxlength="255">
                        @endif
                    </div>
                </template>
                <div class="image-grid__add">
                    <div class="image-picker__drop" :class="{ 'is-dragover': dragover }" style="height:100%;min-height:112px" role="button" tabindex="0" @click="openLibrary()" @keydown.enter.prevent="openLibrary()" @keydown.space.prevent="openLibrary()">
                        <x-admin.icon name="plus" />
                        <span class="fw-600">Add images</span>
                        <span class="text-xs" x-show="!uploading">or drop files</span>
                        <span class="text-xs" x-show="uploading" x-cloak>Uploading…</span>
                    </div>
                </div>
            </div>
        @endif
        @include('commerce::admin.partials.media-library', ['multiple' => $multiple])
    </div>
</x-admin.field>
