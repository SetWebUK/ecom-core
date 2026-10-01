{{-- Media library modal used inside x-data="imagePicker(…)" (image-picker component, editor bridge). Teleported to <body>. --}}
<template x-teleport="body">
    <div @class(['modal']) @if (! empty($top)) style="z-index: 1400" @endif x-show="library.open" x-cloak role="dialog" aria-modal="true" aria-label="Media library"
         @keydown.escape.stop="closeLibrary()">
        <div class="modal__backdrop" x-show="library.open" x-transition.opacity @click="closeLibrary()"></div>
        <div class="modal__panel modal__panel--xl" x-show="library.open" x-trap.noscroll="library.open"
             x-transition:enter="enter" x-transition:enter-start="pop-from" x-transition:enter-end="to-visible">
            <div class="modal__header">
                <h2 class="modal__title">{{ ! empty($multiple) ? 'Add images' : 'Choose an image' }}</h2>
                <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="closeLibrary()" aria-label="Close"><x-admin.icon name="x-mark" /></button>
            </div>
            <div class="modal__body" @dragover.prevent="dragover = true" @dragleave.prevent="dragover = false" @drop.prevent="onDrop($event)">
                <div class="row row--nowrap mb-4">
                    <div class="search-input">
                        <x-admin.icon name="magnifying-glass" />
                        <input type="search" class="input input--sm" placeholder="Search by file name or alt text" aria-label="Search media"
                               x-ref="librarySearch" x-model="library.q" @input="searchLibrary()" @keydown.enter.prevent>
                    </div>
                    <label class="btn btn--primary">
                        <x-admin.icon name="arrow-up-tray" /><span>Upload</span>
                        <input type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" class="sr-only" x-ref="libraryFile" @change="upload($event.target.files)" {{ ! empty($multiple) ? 'multiple' : '' }}>
                    </label>
                </div>
                <div class="callout callout--neutral mb-4" x-show="uploading > 0" x-cloak>
                    <span class="spinner spinner--sm"></span><span>Uploading <span x-text="uploading"></span> image(s)…</span>
                </div>
                <div class="image-picker__drop mb-4" :class="{ 'is-dragover': dragover }" x-show="dragover" x-cloak>
                    <x-admin.icon name="arrow-down-tray" /> Drop images to upload
                </div>
                <div class="media-library">
                    <template x-for="m in library.data" :key="m.id">
                        <button type="button" class="media-tile" :class="{ 'is-selected': isChosen(m) }" @click="toggleChosen(m)" :title="m.filename" :aria-pressed="isChosen(m).toString()">
                            <span class="media-tile__img"><img :src="m.thumb || m.url" :alt="m.alt || ''" loading="lazy"></span>
                            <span class="media-tile__name" x-text="m.filename"></span>
                            <template x-if="isChosen(m)"><span class="media-tile__check"><x-admin.icon name="check" variant="mini" size="sm" /></span></template>
                        </button>
                    </template>
                    <template x-for="i in (library.loading && !library.data.length ? 12 : 0)" :key="'s' + i">
                        <span class="skeleton" style="aspect-ratio:1;border-radius:8px"></span>
                    </template>
                </div>
                <template x-if="!library.loading && !library.data.length">
                    <x-admin.empty icon="photo" title="No images found" description="Upload an image, or try a different search." size="sm" />
                </template>
                <div class="text-center mt-4" x-show="library.page < library.lastPage">
                    <button type="button" class="btn" @click="loadLibrary(library.page + 1)" :class="{ 'is-loading': library.loading }"><span>Load more</span></button>
                </div>
            </div>
            @if (! empty($multiple))
                <div class="modal__footer modal__footer--between">
                    <span class="text-sm text-muted" x-text="library.chosen.length ? library.chosen.length + ' selected' : 'Click images to select them'"></span>
                    <div class="row">
                        <button type="button" class="btn" @click="closeLibrary()">Cancel</button>
                        <button type="button" class="btn btn--primary" :disabled="!library.chosen.length" @click="confirmLibrary()"><span>Add selected</span></button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</template>
