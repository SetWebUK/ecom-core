{{-- Media library: grid/list, filters, drag-and-drop upload, detail drawer (alt text, copy URL, where used, delete), folder scan. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Http\Controllers\Admin\MediaController;
    $viewUrl = fn (string $v) => request()->fullUrlWithQuery(['view' => $v === 'grid' ? null : $v, 'page' => null, 'per_page' => null]);
    $config = [
        'uploadUrl' => route('admin.media.upload'),
        'showUrl' => route('admin.media.show', ['media' => '__ID__']),
    ];
@endphp

@section('title', 'Media')

@once('admin-content-js')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('js/content.js') }}"></script>
    @endpush
@endonce

@section('content')
<div x-data="mediaManager(@js($config))" @drop.window.prevent="dragover = false" @dragleave.window="if (!$event.relatedTarget) dragover = false">
    <x-admin.page-header title="Media" :subtitle="number_format($total).' files · images used on products, pages, posts and menus.'">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.media.scan') }}" data-confirm="Looks through the uploads folder on the server and adds any images that aren’t in the library yet. Nothing is deleted or changed."
                  data-confirm-title="Scan the uploads folder?" data-confirm-button="Scan now" data-confirm-danger="false">
                @csrf
                <x-admin.button type="submit" icon="arrow-path">Scan uploads folder</x-admin.button>
            </form>
            <label class="btn btn--primary">
                <x-admin.icon name="arrow-up-tray" /><span>Upload images</span>
                <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/gif,image/webp,image/avif" x-ref="file" @change="upload($event.target.files)">
            </label>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="callout callout--neutral mb-4" x-show="queue > 0" x-cloak role="status">
        <span class="spinner spinner--sm"></span>
        <span>Uploading <span x-text="done + failed"></span> of <span x-text="queue"></span>…</span>
    </div>

    <x-admin.card flush>
        <div class="row row--between" style="padding: 12px 16px 0">
            <div class="segmented" role="group" aria-label="Layout">
                <a href="{{ $viewUrl('grid') }}" @class(['segmented__item', 'is-active' => $view === 'grid']) @if ($view === 'grid') aria-current="true" @endif><x-admin.icon name="squares-2x2" size="sm" />&nbsp;Grid</a>
                <a href="{{ $viewUrl('list') }}" @class(['segmented__item', 'is-active' => $view === 'list']) @if ($view === 'list') aria-current="true" @endif><x-admin.icon name="list-bullet" size="sm" />&nbsp;List</a>
            </div>
            <span class="text-xs text-muted hidden-mobile">Tip: drop images anywhere on this page to upload them.</span>
        </div>
        <x-admin.filters placeholder="Search by file name, title or alt text" :chips="$chips" keep="view">
            <x-admin.filter-select name="folder" :options="$folderOptions" placeholder="All folders / months" label="Folder" />
            <x-admin.filter-select name="type" :options="MediaController::TYPES" placeholder="All types" label="File type" />
        </x-admin.filters>

        @if ($media->isEmpty())
            @if ($isFiltered)
                <x-admin.empty icon="magnifying-glass" title="No files match" description="Try a different search or filter." size="sm">
                    <x-admin.button :href="route('admin.media.index', ['view' => $view === 'list' ? 'list' : null])">Clear filters</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.empty icon="photo" title="Your media library is empty" description="Upload images here, or scan the uploads folder for images that are already on the server.">
                    <label class="btn btn--primary"><x-admin.icon name="arrow-up-tray" /><span>Upload images</span>
                        <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/gif,image/webp,image/avif" @change="upload($event.target.files)"></label>
                </x-admin.empty>
            @endif
        @elseif ($view === 'grid')
            <div class="media-grid">
                @foreach ($media as $item)
                    <button type="button" class="media-card" data-media-id="{{ $item->id }}" @click="show({{ $item->id }})" aria-label="{{ $item->filename }} – details">
                        <span class="media-card__img">
                            {{-- 150×150 crop when WordPress / the uploader made one (many imported icons have none), else the original --}}
                            <img src="{{ \Pine\Commerce\Http\Controllers\Admin\MediaUploadController::present($item)['thumb'] }}" alt="{{ $item->alt }}" loading="lazy" decoding="async"
                                 onerror="this.onerror=null;this.src={{ Illuminate\Support\Js::from(media_url($item->path)) }}">
                            @if ($item->mime_type === 'image/svg+xml')<span class="badge badge--dark badge--sm media-card__type">SVG</span>@endif
                        </span>
                        <span class="media-card__name">{{ $item->filename }}</span>
                        <span class="media-card__meta">
                            @if ($item->width){{ $item->width }}×{{ $item->height }} · @endif{{ $item->size ? MediaController::bytes((int) $item->size) : MediaController::typeLabel($item->mime_type) }}
                        </span>
                    </button>
                @endforeach
            </div>
        @else
            <x-admin.table :ids="$media->pluck('id')" selectable :bulk-action="route('admin.media.bulk')" stack>
                <x-slot:bulk>
                    <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                    data-confirm="The files are removed from the server. Anywhere they are still used will show a broken image. This can’t be undone."
                                    data-confirm-title="Delete the selected files?" data-confirm-button="Delete files">Delete</x-admin.button>
                </x-slot:bulk>
                <x-slot:head>
                    <x-admin.th sort="filename">File</x-admin.th>
                    <x-admin.th sort="width" first="desc" class="hidden-mobile">Dimensions</x-admin.th>
                    <x-admin.th sort="size" align="right" first="desc">Size</x-admin.th>
                    <x-admin.th>Folder</x-admin.th>
                    <x-admin.th sort="id" first="desc" class="hidden-mobile">Added</x-admin.th>
                </x-slot:head>
                @foreach ($media as $item)
                    <tr data-media-id="{{ $item->id }}">
                        <x-admin.row-check :id="$item->id" :label="'Select '.$item->filename" />
                        <td class="stack-title">
                            <div class="cell-main">
                                <x-admin.thumb :src="$item->path" :alt="$item->alt" size="sm" />
                                <div class="flex-1" style="min-width:0">
                                    <button type="button" class="btn btn--plain truncate" style="max-width:100%" @click="show({{ $item->id }})">{{ $item->filename }}</button>
                                    <div class="cell-sub truncate">{{ $item->alt ?: 'No alt text' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="nowrap hidden-mobile" data-label="Dimensions">{{ $item->width ? $item->width.' × '.$item->height : '—' }}</td>
                        <td class="num" data-label="Size">{{ $item->size ? MediaController::bytes((int) $item->size) : '—' }}</td>
                        <td class="nowrap mono text-muted" data-label="Folder">{{ $item->folder ?? '—' }}</td>
                        <td class="nowrap text-muted hidden-mobile" data-label="Added"><x-admin.time :value="$item->created_at" format="date" /></td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
        <x-admin.pagination :paginator="$media" />
    </x-admin.card>

    {{-- Drop anywhere to upload --}}
    <div class="media-drop" x-show="dragover" x-cloak x-transition.opacity @dragover.prevent @drop.prevent.stop="onDrop($event)">
        <div class="media-drop__msg"><x-admin.icon name="arrow-down-tray" /> Drop images to upload them</div>
    </div>

    {{-- Detail drawer --}}
    <template x-teleport="body">
        <div class="drawer" x-show="drawer.open" x-cloak role="dialog" aria-modal="true" aria-labelledby="media-drawer-title" @keydown.escape.window="drawer.open && close()">
            <div class="modal__backdrop" x-show="drawer.open" x-transition.opacity @click="close()"></div>
            <div class="drawer__panel drawer__panel--lg" x-show="drawer.open" x-trap.noscroll="drawer.open"
                 x-transition:enter="enter" x-transition:enter-start="slide-from" x-transition:enter-end="to-visible"
                 x-transition:leave="leave" x-transition:leave-start="to-visible" x-transition:leave-end="slide-from">
                <div class="modal__header">
                    <h2 class="modal__title truncate" id="media-drawer-title" x-text="drawer.item ? drawer.item.filename : 'Loading…'">Image details</h2>
                    <button type="button" class="btn btn--ghost btn--icon btn--sm" x-ref="drawerClose" @click="close()" aria-label="Close"><x-admin.icon name="x-mark" /></button>
                </div>
                <div class="modal__body">
                    <div class="loading-block" x-show="drawer.loading"><span class="spinner spinner--lg"></span></div>
                    <template x-if="drawer.item && !drawer.loading">
                        <div class="stack">
                            <div class="media-detail__preview"><img :src="drawer.item.url" :alt="drawer.item.alt"></div>
                            <x-admin.callout type="warning" x-show="!drawer.item.exists" title="The file is missing on the server">The library entry exists but the file itself can’t be found.</x-admin.callout>
                            <dl class="kv">
                                <dt>Type</dt><dd x-text="drawer.item.type"></dd>
                                <dt>Dimensions</dt><dd x-text="drawer.item.dimensions || '—'"></dd>
                                <dt>File size</dt><dd x-text="drawer.item.size || '—'"></dd>
                                <dt>Folder</dt><dd class="mono" x-text="drawer.item.folder || '—'"></dd>
                                <dt>Added</dt><dd x-text="drawer.item.added || '—'"></dd>
                            </dl>
                            <div class="field">
                                <div class="field__label"><span>File address</span></div>
                                <div class="copy-field">
                                    <span x-text="drawer.item.absolute_url"></span>
                                    <button type="button" class="btn btn--sm" @click="copy(drawer.item.absolute_url)"><x-admin.icon name="clipboard-document" /><span>Copy</span></button>
                                    <a class="btn btn--sm btn--icon" :href="drawer.item.url" target="_blank" rel="noopener" aria-label="Open in a new tab" title="Open in a new tab"><x-admin.icon name="arrow-top-right-on-square" /></a>
                                </div>
                            </div>
                            <form class="stack-fields" @submit.prevent="save()">
                                <div class="field">
                                    <div class="field__label"><label for="media-alt">Alt text</label><span class="field__label-extra">describes the image for screen readers and Google</span></div>
                                    <input type="text" id="media-alt" class="input" x-model="drawer.alt" maxlength="255">
                                </div>
                                <div class="field">
                                    <div class="field__label"><label for="media-title">Title</label></div>
                                    <input type="text" id="media-title" class="input" x-model="drawer.title" maxlength="255">
                                </div>
                                <div><button type="submit" class="btn btn--primary" :class="{ 'is-loading': drawer.saving }" :disabled="drawer.saving"><span>Save details</span></button></div>
                            </form>
                            <div class="divider"></div>
                            <div class="field">
                                <div class="field__label"><span>Where it’s used</span><span class="field__label-extra" x-text="drawer.item.usage_count ? drawer.item.usage_count + ' place(s)' : ''"></span></div>
                                <p class="text-sm text-muted" x-show="!drawer.item.usage_count">Not found on any product, page, post, menu or setting. It’s safe to delete.</p>
                                <ul class="usage-list" x-show="drawer.item.usage_count">
                                    <template x-for="use in drawer.item.usage" :key="use.type + use.label">
                                        <li>
                                            <template x-if="use.url"><a :href="use.url"><span class="badge badge--gray badge--sm" x-text="use.type"></span><span class="truncate" x-text="use.label"></span></a></template>
                                            <template x-if="!use.url"><span class="usage-list__item"><span class="badge badge--gray badge--sm" x-text="use.type"></span><span class="truncate" x-text="use.label"></span></span></template>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="modal__footer modal__footer--between" x-show="drawer.item">
                    <button type="button" class="btn btn--ghost-danger" @click="remove()" :disabled="drawer.saving"><x-admin.icon name="trash" /><span>Delete file</span></button>
                    <button type="button" class="btn" @click="close()">Close</button>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection
