{{--
    Link search dialog (one per page): pages, categories, products, blog posts and common shop links, or a custom URL.
    Open it from JavaScript: Admin.pickLink(function (url, item) { … }, currentUrl). <x-admin.link-input> includes it.
    <x-admin.link-picker />
--}}
@once('admin-content-js')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('js/content.js') }}"></script>
    @endpush
@endonce
@once('admin-link-picker')
    <div x-data="linkPicker(@js(['endpoint' => route('admin.api.links')]))">
        <template x-teleport="body">
            <div class="modal" x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="link-picker-title" @keydown.escape.stop="close()" style="z-index: 1300">
                <div class="modal__backdrop" x-show="open" x-transition.opacity @click="close()"></div>
                <div class="modal__panel modal__panel--lg" x-show="open" x-trap.noscroll="open"
                     x-transition:enter="enter" x-transition:enter-start="pop-from" x-transition:enter-end="to-visible">
                    <div class="modal__header">
                        <h2 class="modal__title" id="link-picker-title">Choose a link</h2>
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="close()" aria-label="Close"><x-admin.icon name="x-mark" /></button>
                    </div>
                    <div class="modal__body link-picker">
                        <div class="search-input">
                            <x-admin.icon name="magnifying-glass" />
                            <input type="search" class="input" x-ref="search" x-model="q" @keydown="onKey($event)" placeholder="Search pages, categories, products and blog posts"
                                   aria-label="Search for a page to link to" role="combobox" aria-controls="link-picker-results" aria-autocomplete="list" :aria-expanded="(flat.length > 0).toString()">
                        </div>
                        <div class="link-picker__results" id="link-picker-results" role="listbox" aria-label="Links">
                            <template x-for="group in groups" :key="group.label">
                                <div class="link-picker__group" role="group" :aria-label="group.label">
                                    <div class="link-picker__group-title" x-text="group.label"></div>
                                    <template x-for="item in group.items" :key="group.label + item.url">
                                        <button type="button" class="link-picker__option" role="option" :data-link-index="item._i" :aria-selected="(active === item._i).toString()"
                                                :class="{ 'is-active': active === item._i }" @click="choose(item)" @mouseenter="active = item._i">
                                            <span class="flex-1" style="min-width:0">
                                                <span class="link-picker__label" x-text="item.label"></span>
                                                <span class="link-picker__url mono" x-text="item.url"></span>
                                            </span>
                                            <span class="badge badge--gray badge--sm" x-show="item.sub" x-text="item.sub"></span>
                                        </button>
                                    </template>
                                </div>
                            </template>
                            <div class="text-center text-muted text-sm" style="padding: 24px 0" x-show="loading && !flat.length"><span class="spinner" style="margin: 0 auto"></span></div>
                            <div class="text-center text-muted text-sm" style="padding: 24px 0" x-show="!loading && !flat.length && q.trim()" x-cloak>Nothing found for “<span x-text="q"></span>”.</div>
                        </div>
                        <div class="link-picker__custom">
                            <label for="link-picker-custom" class="field__label">Or enter any address</label>
                            <div class="row row--nowrap">
                                <input type="text" id="link-picker-custom" class="input mono" x-ref="custom" x-model="custom" placeholder="/contact-us/  or  https://…  or  mailto:…  or  tel:…" @keydown.enter.prevent="useCustom()">
                                <button type="button" class="btn" @click="useCustom()"><span>Use this</span></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endonce
