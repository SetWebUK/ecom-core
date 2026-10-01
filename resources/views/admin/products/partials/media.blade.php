{{--
    Product editor: Media card. Reuses the shared imagePicker Alpine component (drag to reorder, main image, alt text,
    media library) but uploads to admin.products.images.store (public disk uploads/products/YYYY/MM).
    Posts images[i][path] + images[i][alt] in display order (first = main image), saved by ProductSaver::syncImages().
--}}
@php
    $source = old('images', $product->images);
    $source = $source instanceof \Illuminate\Support\Collection ? $source->all() : (array) $source;
    $items = collect($source)->map(function ($row) {
        $path = is_array($row) ? ($row['path'] ?? null) : ($row->path ?? null);
        $alt = is_array($row) ? ($row['alt'] ?? '') : ($row->alt ?? '');

        return $path ? ['path' => (string) $path, 'url' => media_url($path), 'alt' => (string) $alt] : null;
    })->filter()->values()->all();
    $pickerConfig = [
        'name' => 'images', 'multiple' => true, 'withAlt' => true, 'items' => $items,
        'uploadUrl' => route('admin.products.images.store'), 'libraryUrl' => route('admin.api.media'),
    ];
    $imageErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'images'))->flatten()->unique();
@endphp
<x-admin.card title="Media" class="media-card" id="product-media">
    <x-slot:actions>
        <span class="text-xs text-muted hidden-mobile">Drag to reorder · the first photo is the main image</span>
    </x-slot:actions>
    <div class="image-picker" x-data="imagePicker(@js($pickerConfig))">
        <input type="hidden" name="images" value="">
        <div class="image-grid" x-ref="grid" @dragover.prevent="dragover = true" @dragleave.prevent="dragover = false" @drop.prevent="onDrop($event)">
            <template x-for="(item, index) in items" :key="item.path">
                <div class="image-grid__item">
                    <img class="image-grid__img" :src="item.url" :alt="item.alt || ''" loading="lazy">
                    <span class="image-grid__handle drag-handle" title="Drag to reorder" aria-hidden="true"><x-admin.icon name="arrows-pointing-out" size="sm" /></span>
                    <div class="image-grid__tools">
                        <button type="button" class="btn btn--sm btn--icon" x-show="index > 0" @click="makeFirst(index)" title="Make this the main image" aria-label="Make main image"><x-admin.icon name="star" /></button>
                        <button type="button" class="btn btn--sm btn--icon" x-show="index > 1" title="Move earlier" aria-label="Move this image earlier"
                                @click="(() => { const list = items.slice(); list.splice(index - 1, 0, list.splice(index, 1)[0]); items = list; })()"><x-admin.icon name="chevron-left" /></button>
                        <button type="button" class="btn btn--sm btn--icon" @click="remove(index)" title="Remove from this product" aria-label="Remove image"><x-admin.icon name="trash" /></button>
                    </div>
                    <template x-if="index === 0"><span class="badge badge--dark badge--sm image-grid__badge">Main image</span></template>
                    <input type="hidden" :name="'images[' + index + '][path]'" :value="item.path">
                    <input type="text" class="image-grid__alt" :name="'images[' + index + '][alt]'" x-model="item.alt" placeholder="Alt text" maxlength="255"
                           :aria-label="'Alt text for image ' + (index + 1) + ' (describes the photo for screen readers and Google)'">
                </div>
            </template>
            <div class="image-grid__add">
                <div class="image-picker__drop" :class="{ 'is-dragover': dragover }" style="height:100%;min-height:128px">
                    <x-admin.icon name="photo" />
                    <div class="row" style="justify-content:center">
                        <label class="btn btn--sm btn--primary"><x-admin.icon name="arrow-up-tray" /><span>Upload</span>
                            <input type="file" multiple accept="image/jpeg,image/png,image/gif,image/webp,image/avif" class="sr-only" x-ref="file" @change="upload($event.target.files)">
                        </label>
                        <button type="button" class="btn btn--sm" @click="openLibrary()"><span>Library</span></button>
                    </div>
                    <span class="text-xs" x-show="!uploading">or drop photos here</span>
                    <span class="text-xs" x-show="uploading" x-cloak><span class="spinner spinner--sm" style="display:inline-block;vertical-align:-2px"></span> Uploading <span x-text="uploading"></span>…</span>
                </div>
            </div>
        </div>
        <p class="text-xs text-muted mt-2">JPG, PNG, WebP or GIF up to {{ \Pine\Commerce\Http\Controllers\Admin\MediaUploadController::MAX_KB / 1024 }} MB each. Square photos on a white background look best.</p>
        @foreach ($imageErrors as $message)
            <p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>
        @endforeach
        @include('commerce::admin.partials.media-library', ['multiple' => true])
    </div>
</x-admin.card>
