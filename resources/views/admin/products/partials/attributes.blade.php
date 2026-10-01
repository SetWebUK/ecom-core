{{--
    Product editor: Attributes card (filters + specification data). Condition and Brand are set in the side panel.
    Posts product_attributes[i][attribute_id|values[]|visible|variation] for every row (this card's and the Variants options).
--}}
@php $attrErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'product_attributes'))->flatten()->unique(); @endphp
<x-admin.card title="Attributes" subtitle="Used by the shop’s filters (e.g. Size, Colour) and the product’s specification details.">
    <x-slot:actions>
        <button type="button" class="btn btn--sm btn--plain" @click="addRow(false)"><x-admin.icon name="plus" /><span>Add attribute</span></button>
    </x-slot:actions>

    {{-- Posted values for every row, in order --}}
    <input type="hidden" name="product_attributes" value="">
    <template x-for="(row, i) in rows" :key="'h-' + row.uid">
        <span hidden>
            <input type="hidden" :name="'product_attributes[' + i + '][attribute_id]'" :value="row.attribute_id">
            <input type="hidden" :name="'product_attributes[' + i + '][visible]'" :value="row.visible ? 1 : 0">
            <input type="hidden" :name="'product_attributes[' + i + '][variation]'" :value="row.variation ? 1 : 0">
            <template x-for="vid in row.values" :key="vid"><input type="hidden" :name="'product_attributes[' + i + '][values][]'" :value="vid"></template>
        </span>
    </template>

    <template x-if="!rows.some(r => !isVariantRow(r))">
        <p class="text-sm text-muted">No attributes yet. Add the ones customers filter by – Size, Colour, Material.</p>
    </template>
    <div class="attr-rows" x-ref="attrList">
        <template x-for="row in rows.filter(r => !isVariantRow(r))" :key="row.uid">
            @include('commerce::admin.products.partials.attribute-row', ['variant' => false])
        </template>
    </div>
    @foreach ($attrErrors as $message)
        <p class="field__error mt-2"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>
    @endforeach
    <p class="text-xs text-muted mt-3">Values you add here are shared across products. Manage them in <a href="{{ route('admin.attributes.index') }}">Products › Attributes</a>.</p>

    <template x-teleport="body">
        <x-admin.modal name="new-attribute" title="New attribute" size="sm">
            <div class="field">
                <label class="field__label" for="new-attribute-name">Name</label>
                <input type="text" id="new-attribute-name" class="input" x-model="newAttribute" placeholder="e.g. Graphics card" maxlength="190" @keydown.enter.prevent="createAttribute()">
                <p class="field__help">You can add its values straight after.</p>
            </div>
            <x-slot:footer>
                <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                <x-admin.button variant="primary" x-on:click="createAttribute()">Create attribute</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    </template>
</x-admin.card>
