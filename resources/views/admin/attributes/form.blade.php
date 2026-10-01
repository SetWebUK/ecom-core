{{-- Create/edit an attribute, and manage its values (add, rename, reorder, delete unused, merge duplicates). --}}
@extends('commerce::admin.layouts.app')

@php
    $editing = $attribute->exists;
    $saveLabel = $editing ? 'Save' : 'Create attribute';
@endphp

@section('title', $editing ? $attribute->name.' · Attributes' : 'Add attribute')

@section('content')
    @include('commerce::admin.products.partials.assets', ['sortable' => true])

    <x-admin.page-header :title="$editing ? $attribute->name : 'Add attribute'" :back="route('admin.attributes.index')" back-label="Back to attributes">
        @if ($editing && $locked)
            <x-slot:badges><x-admin.badge color="success" dot>Shop filter</x-admin.badge></x-slot:badges>
        @endif
    </x-admin.page-header>

    <div class="layout">
        <div class="layout__main">
            <x-admin.form id="attribute-form" :action="$editing ? route('admin.attributes.update', $attribute) : route('admin.attributes.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
                <x-admin.card title="Details">
                    <div class="stack-fields" x-data="{ auto: @js(! $editing && ! old('slug')) }">
                        <x-admin.input name="name" label="Name" required maxlength="190" :value="$attribute->name" placeholder="e.g. Memory" :autofocus="! $editing"
                                       x-on:input="if (auto) { $el.form.querySelector('#f-slug').value = Admin.slugify($event.target.value); }" />
                        @if ($locked)
                            <x-admin.input name="slug" label="Slug" :value="$attribute->slug" readonly class="input--mono"
                                           help="The shop’s filter sidebar and filter links use this slug, so it can’t be changed." />
                        @else
                            <x-admin.input name="slug" label="Slug" :value="$attribute->slug" class="input--mono" maxlength="190" x-on:input="auto = $event.target.value === ''"
                                           help="Used in filter links and variant data. Lower-case letters, numbers and dashes. Changing it updates existing variants automatically." />
                        @endif
                        <x-admin.toggle name="is_filterable" label="Customers can filter by it" help="Offers it as a filter in the shop (where the shop’s filter sidebar supports it)." :checked="(bool) $attribute->is_filterable" />
                    </div>
                </x-admin.card>
            </x-admin.form>

            @if ($editing)
                <x-admin.card title="Values" flush :subtitle="$values->count().' '.Str::plural('value', $values->count()).'. Drag to change the order they’re listed in.'">
                    <div class="card__section">
                        <form method="POST" action="{{ route('admin.attributes.values.store', $attribute) }}" class="row row--nowrap">
                            @csrf
                            <label class="sr-only" for="new-value">New value</label>
                            <input type="text" id="new-value" name="value" class="input @error('value') is-invalid @enderror" placeholder="Add a value, e.g. 32GB" maxlength="190" required value="{{ old('value') }}">
                            <x-admin.button type="submit" icon="plus">Add</x-admin.button>
                        </form>
                        @error('value')<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@enderror
                    </div>

                    @if ($duplicates)
                        <div class="card__section">
                            <x-admin.callout type="warning" title="Possible duplicates">
                                {{ $duplicates }} {{ Str::plural('value', $duplicates) }} look the same apart from spelling or spacing. Tick them below and use <strong>Merge</strong> to combine them.
                            </x-admin.callout>
                        </div>
                    @endif

                    @if ($values->isEmpty())
                        <x-admin.empty icon="list-bullet" title="No values yet" description="Add the options products can have, e.g. 8GB, 16GB." size="sm" />
                    @else
                        <div x-data="{ picked: [] }">
                            <div class="bulk-bar" x-show="picked.length" x-cloak style="position:static">
                                <span class="bulk-bar__count" x-text="picked.length + ' selected'"></span>
                                <x-admin.button size="sm" icon="arrows-pointing-in" x-on:click="$dispatch('open-modal', 'merge-values')" x-bind:disabled="picked.length < 2">Merge…</x-admin.button>
                                <span class="bulk-bar__spacer"></span>
                                <button type="button" class="btn btn--ghost btn--sm" @click="picked = []"><span>Clear selection</span></button>
                            </div>
                            <x-admin.sortable-list :url="route('admin.attributes.values.reorder', $attribute)">
                                @foreach ($values as $value)
                                    @php $used = $value->products_count + $value->variants_count; @endphp
                                    <li class="value-row" data-id="{{ $value->id }}" x-data="valueRow(@js(['url' => route('admin.attributes.values.update', [$attribute, $value]), 'value' => $value->value]))" x-show="!removed">
                                        <span class="drag-handle" title="Drag to reorder" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
                                        <input type="checkbox" class="checkbox" value="{{ $value->id }}" x-model="picked" aria-label="Select {{ $value->value }}">
                                        <div class="value-row__name">
                                            <template x-if="!editing">
                                                <span><button type="button" class="cell-button" @click="edit()" :title="'Rename'"><span x-text="value">{{ $value->value }}</span><x-admin.icon name="pencil" /></button>
                                                    <span class="value-row__slug mono">{{ $value->slug }}</span></span>
                                            </template>
                                            <template x-if="editing">
                                                <form class="row row--nowrap" @submit.prevent="save()" data-no-loading>
                                                    <input type="text" class="input input--sm" x-ref="input" x-model="draft" maxlength="190" @keydown.escape.stop="cancel()" aria-label="New name for {{ $value->value }}">
                                                    <button type="submit" class="btn btn--sm btn--primary" :class="{ 'is-loading': saving }"><span>Save</span></button>
                                                    <button type="button" class="btn btn--sm btn--ghost" @click="cancel()">Cancel</button>
                                                </form>
                                            </template>
                                        </div>
                                        <span class="value-row__uses">
                                            @if ($value->products_count){{ number_format($value->products_count) }} {{ Str::plural('product', $value->products_count) }}@endif
                                            @if ($value->variants_count){{ $value->products_count ? ' · ' : '' }}{{ $value->variants_count }} {{ Str::plural('variant', $value->variants_count) }}@endif
                                            @unless ($used)<span class="text-subtle">Unused</span>@endunless
                                        </span>
                                        @if ($used)
                                            <span class="btn btn--ghost btn--icon btn--sm is-disabled" title="In use – merge it into another value instead" aria-hidden="true" style="opacity:.35"><x-admin.icon name="trash" /></span>
                                        @else
                                            <button type="button" class="btn btn--ghost-danger btn--icon btn--sm" @click="remove()" aria-label="Delete {{ $value->value }}" title="Delete"><x-admin.icon name="trash" /></button>
                                        @endif
                                    </li>
                                @endforeach
                            </x-admin.sortable-list>

                            <x-admin.modal name="merge-values" title="Merge values" size="sm">
                                <form method="POST" action="{{ route('admin.attributes.values.merge', $attribute) }}" id="merge-form">
                                    @csrf
                                    <template x-for="id in picked" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                                    <p class="text-sm mb-4">Products and variants using the selected values will use the one you keep. The others are deleted.</p>
                                    <div class="field">
                                        <label class="field__label" for="merge-target">Keep</label>
                                        <select id="merge-target" name="target_id" class="select" required x-data="{ labels: @js($values->pluck('value', 'id')) }">
                                            <template x-for="id in picked" :key="id"><option :value="id" x-text="labels[id]"></option></template>
                                        </select>
                                    </div>
                                </form>
                                <x-slot:footer>
                                    <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                                    <x-admin.button type="submit" form="merge-form" variant="primary">Merge <span x-text="picked.length"></span> values</x-admin.button>
                                </x-slot:footer>
                            </x-admin.modal>
                        </div>
                    @endif
                </x-admin.card>
            @endif
        </div>

        <div class="layout__aside">
            <x-admin.card title="How attributes work" subdued>
                <ul class="summary-list">
                    <li>Pick an attribute’s values on each product (Products › edit › Attributes).</li>
                    <li>Filterable attributes appear as filters on category pages.</li>
                    <li>Attributes can also be the options of products with variants (e.g. Memory: 8GB / 16GB).</li>
                    <li>Renaming a value keeps its slug, so filter links keep working.</li>
                </ul>
            </x-admin.card>
            @if ($editing)
                <x-admin.card title="Usage">
                    <dl class="kv">
                        <dt>Values</dt><dd>{{ $values->count() }}</dd>
                        <dt>Products</dt><dd>{{ number_format($productCount) }}</dd>
                        <dt>Variants</dt><dd>{{ number_format($values->sum('variants_count')) }}</dd>
                    </dl>
                </x-admin.card>
            @endif
        </div>
    </div>

    <div class="form-actions">
        @if ($editing && ! $locked)
            <x-admin.confirm :action="route('admin.attributes.destroy', $attribute)" variant="ghost-danger" icon="trash" :title="'Delete '.($attribute->name).'?'"
                             message="Its values are removed from every product that uses them (and from the shop’s filters). This can’t be undone." confirm-label="Delete attribute">Delete attribute</x-admin.confirm>
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.attributes.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="attribute-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection
