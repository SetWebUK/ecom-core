{{--
    One attribute row of the product editor (used by the Attributes card and the Variants "Options" list, inside
    <template x-for="row in …">). The posted inputs for every row are rendered once in attributes.blade.php.
    $variant: true in the Variants card (no drag handle, no "used for variants" switch).
--}}
<div class="attr-row" data-sort-item>
    @if (empty($variant))
        <span class="drag-handle" title="Drag to reorder" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
    @else
        <span class="drag-handle" style="visibility:hidden" aria-hidden="true"></span>
    @endif

    <div>
        <template x-if="attr(row.attribute_id)">
            <div class="fw-600 text-sm" style="padding-top:8px" x-text="attr(row.attribute_id).name"></div>
        </template>
        <template x-if="!attr(row.attribute_id)">
            <div>
                <label class="sr-only" :for="'attr-select-' + row.uid">Attribute</label>
                <select class="select" :id="'attr-select-' + row.uid"
                        @change="if ($event.target.value === '__new') { $event.target.value = ''; $dispatch('open-modal', 'new-attribute') } else { row.attribute_id = parseInt($event.target.value, 10); row.open = true; touch(); const rowEl = $el.closest('.attr-row'); $nextTick(() => rowEl && rowEl.querySelector('.token-field__input') && rowEl.querySelector('.token-field__input').focus()) }">
                    <option value="">Choose an attribute…</option>
                    <template x-for="a in availableAttributes(row)" :key="a.id">
                        <option :value="a.id" x-text="a.name"></option>
                    </template>
                    <option value="__new">+ Create a new attribute…</option>
                </select>
            </div>
        </template>
    </div>

    <div class="token-wrap" :data-row="row.uid">
        <div class="token-field" x-show="attr(row.attribute_id)" @click="$el.querySelector('input').focus()" @click.outside="row.open = false">
            <template x-for="vid in row.values" :key="vid">
                <span class="chip">
                    <span class="chip__label" x-text="valueLabel(row.attribute_id, vid)"></span>
                    <button type="button" class="chip__remove" @click.stop="removeValue(row, vid)" :aria-label="'Remove ' + valueLabel(row.attribute_id, vid)"><x-admin.icon name="x-mark" size="xs" /></button>
                </span>
            </template>
            <input type="text" class="token-field__input" x-model="row.query" @focus="row.open = true" @input="row.open = true" @keydown="valueKey(row, $event)"
                   :placeholder="row.values.length ? 'Add another…' : 'Type to find or add a value…'" :aria-label="'Values for ' + (attr(row.attribute_id)?.name || 'attribute')" autocomplete="off">
            <span class="spinner spinner--sm" x-show="row.busy" x-cloak></span>
            <div class="token-field__menu" x-show="row.open && (suggestions(row).length || (row.query.trim() && !exactMatch(row)))" x-cloak>
                <template x-if="row.query.trim() && !exactMatch(row)">
                    <button type="button" class="token-field__option token-field__option--create" @click.stop="addValue(row)">
                        <x-admin.icon name="plus" size="sm" /><span>Add “<span x-text="row.query.trim()"></span>”</span>
                    </button>
                </template>
                <template x-for="v in suggestions(row)" :key="v.id">
                    <button type="button" class="token-field__option" @click.stop="pickValue(row, v)" x-text="v.value"></button>
                </template>
            </div>
        </div>
        <div class="attr-row__options mt-2" x-show="attr(row.attribute_id)">
            @if (empty($variant))
                <label class="check"><input type="checkbox" class="checkbox" x-model="row.visible" @change="touch()"><span class="check__text"><span class="check__label">Show in the product’s specifications</span></span></label>
                <label class="check" x-show="type === 'variable'"><input type="checkbox" class="checkbox" x-model="row.variation" @change="touch()"><span class="check__text"><span class="check__label">Use for variants</span></span></label>
            @else
                <button type="button" class="btn btn--plain btn--sm" @click="row.variation = false; touch()">Don’t use for variants</button>
            @endif
            <button type="button" class="btn btn--plain btn--sm" x-show="attr(row.attribute_id) && attr(row.attribute_id).values.length > row.values.length" @click="selectAllValues(row)">Select all values</button>
        </div>
    </div>

    <div class="attr-row__tools">
        <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="removeRow(row)" :aria-label="'Remove ' + (attr(row.attribute_id)?.name || 'row')" title="Remove"><x-admin.icon name="trash" /></button>
    </div>
</div>
