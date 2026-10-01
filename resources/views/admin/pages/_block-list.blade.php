{{--
    Repeater for a list inside a block (add / remove / reorder by drag or buttons), bound to blocksEditor.
    $listModel JS array expression · $listName JS input-name expression · $listErr JS error-key expression · $fields · $meta · $blankKey
--}}
@php
    $itemNoun = $meta['item'] ?? 'item';
    $max = (int) ($meta['max'] ?? 50);
    $titleField = $meta['title'] ?? array_key_first($fields);
    $imageFields = collect($fields)->filter(fn ($s) => Pine\Commerce\Services\Admin\PageBlocks::type($s)[0] === 'image')->keys();
    $otherFields = collect($fields)->reject(fn ($s) => Pine\Commerce\Services\Admin\PageBlocks::type($s)[0] === 'image');
@endphp
<div class="repeater">
    @if (! empty($meta['label']) && ! ($hideLabel ?? false))
        <div class="field__label"><span>{{ $meta['label'] }}</span><span class="field__label-extra" x-text="{{ $listModel }}.length + ' of {{ $max }}'"></span></div>
    @endif
    <template x-if="!{{ $listModel }}.length"><input type="hidden" :name="{{ $listName }}" value=""></template>
    <p class="repeater__empty" x-show="!{{ $listModel }}.length" x-cloak>{{ $meta['empty'] ?? 'Nothing here yet.' }}</p>
    <ul class="repeater__list" x-init="sortable($el, () => {{ $listModel }})">
        <template x-for="(item, i) in {{ $listModel }}" :key="item._k">
            <li class="repeater__item" data-sortable-item :data-item-key="item._k" :class="{ 'is-invalid': hasErrors({{ $listErr }} + '.' + i) }"
                x-init="if (hasErrors({{ $listErr }} + '.' + i)) item._open = true">
                <div class="repeater__head">
                    <span class="drag-handle" title="Drag to reorder" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
                    <button type="button" class="repeater__title" @click="item._open = !item._open" :aria-expanded="(!!item._open).toString()">
                        <x-admin.icon name="chevron-right" variant="mini" />
                        <span class="repeater__index" x-text="i + 1"></span>
                        @if ($imageFields->isNotEmpty())
                            <template x-if="item.{{ $imageFields->first() }}"><img :src="mediaUrl(item.{{ $imageFields->first() }})" alt="" class="menu-node__thumb" style="width:24px;height:24px"></template>
                        @endif
                        <span class="repeater__title-text" x-text="preview(item.{{ $titleField }}) || {{ Illuminate\Support\Js::from(ucfirst($itemNoun).' ') }} + (i + 1)"></span>
                    </button>
                    <div class="repeater__tools">
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="move({{ $listModel }}, i, -1)" :disabled="i === 0" aria-label="Move up" title="Move up"><x-admin.icon name="arrow-up" /></button>
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="move({{ $listModel }}, i, 1)" :disabled="i === {{ $listModel }}.length - 1" aria-label="Move down" title="Move down"><x-admin.icon name="arrow-down" /></button>
                        <button type="button" class="btn btn--ghost-danger btn--icon btn--sm" @click="remove({{ $listModel }}, i)" aria-label="Remove {{ $itemNoun }}" title="Remove"><x-admin.icon name="trash" /></button>
                    </div>
                </div>
                <div class="repeater__body" x-show="item._open" x-collapse>
                    @php $itemName = $listName." + '[' + i + ']'"; $itemErr = $listErr." + '.' + i"; @endphp
                    @if ($imageFields->isNotEmpty())
                        <div class="repeater__grid">
                            <div>
                                @foreach ($imageFields as $fk)
                                    @include('commerce::admin.pages._block-field', ['fk' => $fk, 'spec' => $fields[$fk], 'model' => 'item', 'nameExpr' => $itemName, 'errExpr' => $itemErr, 'staticName' => null])
                                @endforeach
                            </div>
                            <div class="repeater__fields">
                                @foreach ($otherFields as $fk => $fspec)
                                    @include('commerce::admin.pages._block-field', ['fk' => $fk, 'spec' => $fspec, 'model' => 'item', 'nameExpr' => $itemName, 'errExpr' => $itemErr, 'staticName' => null])
                                @endforeach
                            </div>
                        </div>
                    @else
                        @foreach ($otherFields as $fk => $fspec)
                            @include('commerce::admin.pages._block-field', ['fk' => $fk, 'spec' => $fspec, 'model' => 'item', 'nameExpr' => $itemName, 'errExpr' => $itemErr, 'staticName' => null])
                        @endforeach
                    @endif
                </div>
            </li>
        </template>
    </ul>
    <div class="repeater__foot">
        <button type="button" class="btn btn--sm" @click="add({{ $listModel }}, @js($blankKey))" :disabled="{{ $listModel }}.length >= {{ $max }}"><x-admin.icon name="plus" /><span>Add {{ $itemNoun }}</span></button>
        @if (! empty($meta['foot']))<span class="text-xs text-muted">{{ $meta['foot'] }}</span>@endif
    </div>
</div>
