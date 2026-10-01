{{--
    One menu item (and its sub-items) in the menu builder. Rendered inside x-for; recursion stops at 3 levels.
    $depth · $n node var · $i index var · $list JS expression of the list it sits in · $parentList / $parentIndex (null at the top)
--}}
@php
    $child = 'n'.($depth + 1);
    $childIndex = 'i'.($depth + 1);
@endphp
<li class="menu-node" :data-key="{{ $n }}._k">
    <div class="menu-node__card">
        <div class="menu-node__row">
            <span class="drag-handle" title="Drag to move" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
            <button type="button" class="menu-node__main" @click="{{ $n }}._open = !{{ $n }}._open" :aria-expanded="(!!{{ $n }}._open).toString()">
                <template x-if="{{ $n }}.icon"><img class="menu-node__thumb" :src="mediaUrl({{ $n }}.icon)" alt=""></template>
                <span class="menu-node__text">
                    <span class="menu-node__label" x-text="{{ $n }}.label || 'Untitled item'"></span>
                    <span class="menu-node__url" x-text="{{ $n }}.url || '{{ $depth === 1 ? 'No link' : 'Column heading – no link' }}'"></span>
                </span>
            </button>
            <span class="menu-node__meta">
                <span class="badge badge--attention badge--sm" x-show="{{ $n }}.badge" x-text="{{ $n }}.badge"></span>
                <span class="badge badge--gray badge--sm" x-show="kind({{ $n }}, {{ $depth }})" x-text="kind({{ $n }}, {{ $depth }})"></span>
                <span class="badge badge--outline badge--sm" x-show="{{ $n }}.open_in_new_tab">New tab</span>
            </span>
            <div class="menu-node__actions">
                <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="{{ $n }}._open = !{{ $n }}._open" :aria-label="{{ $n }}._open ? 'Close' : 'Edit'" :title="{{ $n }}._open ? 'Close' : 'Edit'"><x-admin.icon name="pencil-square" /></button>
                @if ($depth < 3)
                    <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="add({{ $n }})" aria-label="Add a sub-item" title="Add a sub-item"><x-admin.icon name="plus" /></button>
                @endif
                <x-admin.dropdown label="More actions for this item" icon="ellipsis-horizontal" icon-only size="sm" variant="ghost">
                    <button type="button" class="dropdown__item" role="menuitem" @click="moveUp({{ $list }}, {{ $i }}); close()" :disabled="{{ $i }} === 0"><x-admin.icon name="arrow-up" />Move up</button>
                    <button type="button" class="dropdown__item" role="menuitem" @click="moveDown({{ $list }}, {{ $i }}); close()" :disabled="{{ $i }} === {{ $list }}.length - 1"><x-admin.icon name="arrow-down" />Move down</button>
                    <button type="button" class="dropdown__item" role="menuitem" @click="indent({{ $list }}, {{ $i }}, {{ $depth }}); close()" :disabled="!canIndent({{ $list }}, {{ $i }}, {{ $depth }})"><x-admin.icon name="arrow-right" />Make it a sub-item of the one above</button>
                    @if ($parentList)
                        <button type="button" class="dropdown__item" role="menuitem" @click="outdent({{ $list }}, {{ $i }}, {{ $parentList }}, {{ $parentIndex }}); close()"><x-admin.icon name="arrow-left" />Move out one level</button>
                    @endif
                    <div class="dropdown__sep"></div>
                    <button type="button" class="dropdown__item dropdown__item--danger" role="menuitem" @click="close(); remove({{ $list }}, {{ $i }})"><x-admin.icon name="trash" />Remove</button>
                </x-admin.dropdown>
            </div>
        </div>
        <div class="menu-node__edit" x-show="{{ $n }}._open" x-collapse>
            <div class="form-grid">
                <div class="field">
                    <div class="field__label"><label :for="'mi-' + {{ $n }}._k + '-label'">Label<span class="field__required" aria-hidden="true">*</span></label></div>
                    <input type="text" class="input" :id="'mi-' + {{ $n }}._k + '-label'" x-model="{{ $n }}.label" maxlength="500" data-label-input required>
                    <p class="field__help">Store settings can be inserted: <code>{store.phone}</code>, <code>{store.email}</code>, <code>{store.name}</code> – e.g. link <code>tel:{store.phone}</code>.</p>
                </div>
                <div class="field">
                    <div class="field__label"><label :for="'mi-' + {{ $n }}._k + '-url'">Link</label><span class="field__label-extra">{{ $depth === 1 ? 'Blank = not clickable' : 'Blank = column heading' }}</span></div>
                    <div class="input-group">
                        <input type="text" class="input mono" :id="'mi-' + {{ $n }}._k + '-url'" x-model="{{ $n }}.url" maxlength="1000" placeholder="/page-address/" autocomplete="off" spellcheck="false">
                        <button type="button" class="btn btn--sm" @click="pickLink({{ $n }})"><x-admin.icon name="link" /><span>Browse</span></button>
                    </div>
                </div>
                <div class="field">
                    <div class="field__label"><label :for="'mi-' + {{ $n }}._k + '-badge'">Badge</label><span class="field__label-extra">optional</span></div>
                    <input type="text" class="input" :id="'mi-' + {{ $n }}._k + '-badge'" x-model="{{ $n }}.badge" maxlength="40" placeholder="e.g. popular">
                </div>
                <div class="field">
                    <div class="field__label"><label :for="'mi-' + {{ $n }}._k + '-class'">Style</label><span class="field__label-extra">optional</span></div>
                    <input type="text" class="input mono" :id="'mi-' + {{ $n }}._k + '-class'" x-model="{{ $n }}.css_class" maxlength="190" list="menu-style-classes" placeholder="e.g. mega-promo" autocomplete="off">
                </div>
                <div class="field">
                    <div class="field__label"><span>Image</span><span class="field__label-extra">tiles and promos</span></div>
                    <div class="row">
                        <button type="button" class="media-slot__preview media-slot__preview--sm" @click="pickImage({{ $n }})" :aria-label="{{ $n }}.icon ? 'Change image' : 'Choose image'">
                            <template x-if="{{ $n }}.icon"><img :src="mediaUrl({{ $n }}.icon)" alt=""></template>
                            <template x-if="!{{ $n }}.icon"><x-admin.icon name="photo" /></template>
                        </button>
                        <button type="button" class="btn btn--sm" @click="pickImage({{ $n }})"><span x-text="{{ $n }}.icon ? 'Change' : 'Choose'">Choose</span></button>
                        <button type="button" class="btn btn--sm btn--ghost-danger" x-show="{{ $n }}.icon" @click="{{ $n }}.icon = ''"><span>Remove</span></button>
                    </div>
                </div>
                <div class="field" style="align-self:end">
                    <label class="check"><input type="checkbox" class="checkbox" x-model="{{ $n }}.open_in_new_tab"><span class="check__text"><span class="check__label">Open in a new tab</span></span></label>
                </div>
            </div>
        </div>
    </div>
    @if ($depth < 3)
        <ul class="menu-tree__list" :data-parent="{{ $n }}._k" data-depth="{{ $depth + 1 }}" x-init="bindList($el)">
            <template x-for="({{ $child }}, {{ $childIndex }}) in {{ $n }}.children" :key="{{ $child }}._k">
                @include('commerce::admin.menus._node', ['depth' => $depth + 1, 'n' => $child, 'i' => $childIndex, 'list' => $n.'.children', 'parentList' => $list, 'parentIndex' => $i])
            </template>
        </ul>
    @endif
</li>
