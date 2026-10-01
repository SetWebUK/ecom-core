{{--
    Product editor: Specifications card – the "Technical specification" table on the product page (product_specs).
    Rows are reorderable; posts specs[i][key|label|value|description]. `key` (e.g. "memory") is kept for rows that came
    from the import so the shop can still pick out specific rows for the cards.
--}}
@php $specErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'specs'))->flatten()->unique(); @endphp
<x-admin.card title="Specifications" subtitle="The specification table on the product page. Drag to reorder.">
    <x-slot:actions>
        <button type="button" class="btn btn--sm btn--plain" @click="addSpec()"><x-admin.icon name="plus" /><span>Add row</span></button>
    </x-slot:actions>
    <input type="hidden" name="specs" value="">
    <template x-if="!specs.length">
        <x-admin.empty icon="list-bullet" title="No specifications yet" description="Add rows like Material → 100% cotton, Size → Medium." size="sm">
            <button type="button" class="btn btn--sm" @click="addSpec()"><x-admin.icon name="plus" /><span>Add the first row</span></button>
        </x-admin.empty>
    </template>
    <div class="spec-rows" x-ref="specList" x-show="specs.length">
        <template x-for="(spec, i) in specs" :key="spec.uid">
            <div class="spec-row" data-sort-item>
                <span class="drag-handle" title="Drag to reorder" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
                <div>
                    <input type="hidden" :name="'specs[' + i + '][key]'" :value="spec.key || ''">
                    <input type="text" class="input" data-spec-label :name="'specs[' + i + '][label]'" x-model="spec.label" placeholder="Name, e.g. Memory" maxlength="255" :aria-label="'Specification ' + (i + 1) + ' name'">
                </div>
                <div class="spec-row__value">
                    <input type="text" class="input" :name="'specs[' + i + '][value]'" x-model="spec.value" placeholder="Value, e.g. 8GB DDR4" maxlength="2000" :aria-label="'Specification ' + (i + 1) + ' value'">
                </div>
                <div class="inline-actions">
                    <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="moveSpec(i, -1)" :disabled="i === 0" aria-label="Move up" title="Move up"><x-admin.icon name="chevron-up" /></button>
                    <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="moveSpec(i, 1)" :disabled="i === specs.length - 1" aria-label="Move down" title="Move down"><x-admin.icon name="chevron-down" /></button>
                    <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="removeSpec(spec)" :aria-label="'Remove ' + (spec.label || 'row')" title="Remove"><x-admin.icon name="trash" /></button>
                </div>
                <div class="spec-row__desc">
                    <input type="text" class="input input--sm" :name="'specs[' + i + '][description]'" x-model="spec.description" placeholder="Optional explanation shown under the value, e.g. “Great for multitasking”" maxlength="255" :aria-label="'Specification ' + (i + 1) + ' explanation'">
                </div>
            </div>
        </template>
    </div>
    @foreach ($specErrors as $message)
        <p class="field__error mt-2"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>
    @endforeach
</x-admin.card>
