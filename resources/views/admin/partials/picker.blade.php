{{--
    Shared markup for the search pickers (<x-admin.product-picker>, <x-admin.category-picker>, <x-admin.customer-picker>).
    Expects: $name, $key, $id, $label, $help, $placeholder, $multiple, $selected (list of options), $endpoint|null, $options|null, $images (bool), $required
    Posts: name[] = ids (multiple) or name = id (single). An empty sentinel input makes "none selected" post as empty.
--}}
@php
    $config = array_filter([
        'name' => $name,
        'multiple' => (bool) $multiple,
        'selected' => array_values($selected),
        'endpoint' => $endpoint ?? null,
        'options' => $options ?? null,
    ], fn ($v) => $v !== null);
@endphp
<x-admin.field :label="$label" :for="$id" :help="$help" :error="$key" :required="$required ?? false" {{ $attributes ?? '' }}>
    <div class="picker" x-data="picker(@js($config))" @click.outside="open = false" @keydown.escape="open = false">
        <div x-ref="inputs">
            <input type="hidden" name="{{ $name }}" value="">
            <template x-for="item in selected" :key="item.id">
                <input type="hidden" :name="name + (multiple ? '[]' : '')" :value="item.id">
            </template>
        </div>
        <div class="search-input" x-show="multiple || !selected.length">
            <x-admin.icon name="magnifying-glass" />
            <input type="text" class="input @if ($errors->has($key)) is-invalid @endif" id="{{ $id }}" x-ref="search" x-model="q" autocomplete="off"
                   placeholder="{{ $placeholder }}" @focus="focus()" @keydown="onKey($event)"
                   role="combobox" aria-autocomplete="list" aria-controls="{{ $id }}-results" :aria-expanded="open.toString()">
        </div>
        <div class="picker__results" id="{{ $id }}-results" role="listbox" x-show="open && (results.length || loading || q.trim())" x-cloak>
            <template x-if="loading && !results.length"><div class="picker__empty"><span class="spinner spinner--sm" style="margin:0 auto"></span></div></template>
            <template x-if="!loading && !results.length && q.trim()"><div class="picker__empty">No matches for “<span x-text="q"></span>”</div></template>
            <template x-for="(item, i) in results" :key="item.id">
                <button type="button" class="picker__option" :class="{ 'is-active': i === active }" :data-option="i" role="option"
                        :aria-selected="isChosen(item.id).toString()" @click="choose(item)" @mouseenter="active = i">
                    @if (! empty($images))
                        <span class="thumb thumb--sm">
                            <template x-if="item.image"><img :src="item.image" alt="" loading="lazy"></template>
                            <template x-if="!item.image"><x-admin.icon name="photo" /></template>
                        </span>
                    @endif
                    <span class="picker__option-main">
                        <span class="picker__option-title" x-text="item.label" style="display:block"></span>
                        <span class="picker__option-sub" x-text="item.sub" x-show="item.sub" style="display:block"></span>
                    </span>
                    <template x-if="isChosen(item.id)"><x-admin.icon name="check" variant="mini" class="text-success" /></template>
                </button>
            </template>
        </div>
        <div class="picker__chips" x-show="selected.length" x-cloak>
            <template x-for="item in selected" :key="item.id">
                <span class="chip" :title="item.sub">
                    <span class="chip__label" x-text="item.label"></span>
                    <button type="button" class="chip__remove" @click="remove(item.id)" :aria-label="'Remove ' + item.label"><x-admin.icon name="x-mark" size="xs" /></button>
                </span>
            </template>
        </div>
    </div>
</x-admin.field>
