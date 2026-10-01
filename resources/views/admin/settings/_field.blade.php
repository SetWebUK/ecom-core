{{-- One setting field (Pine\Commerce\Services\Admin\StoreSettings definition) + what it drives on the site. --}}
@php
    use Pine\Commerce\Services\Admin\StoreSettings;
    $name = StoreSettings::inputName($field['key']);
    $value = StoreSettings::value($field);
    $default = $field['default'] ?? null;
    $help = $field['help'] ?? null;
@endphp
<div class="stack stack--xs" @if (in_array($field['type'], ['textarea', 'list', 'image'], true) || ! empty($field['wide'])) style="grid-column: 1 / -1" @endif>
    @switch($field['type'])
        @case('bool')
            <x-admin.toggle :name="$name" :label="$field['label']" :help="$field['drives'] ?? null" :checked="$value" />
            @break
        @case('image')
            <x-admin.image-picker :name="$name" :label="$field['label']" :value="$value" :help="$help" />
            @break
        @case('link')
            <x-admin.link-input :name="$name" :label="$field['label']" :value="$value" :help="$help" />
            @break
        @case('textarea')
            <x-admin.textarea :name="$name" :label="$field['label']" :value="$value" :rows="$field['rows'] ?? 3" :help="$help" :code="$field['code'] ?? false" :placeholder="is_string($default) ? $default : null" />
            @break
        @case('select')
            <x-admin.select :name="$name" :label="$field['label']" :options="StoreSettings::options($field)" :value="(string) $value" :help="$help" />
            @break
        @case('money')
            <x-admin.money :name="$name" :label="$field['label']" :value="$value" :help="$help" />
            @break
        @case('int')
        @case('decimal')
            <x-admin.input :name="$name" :label="$field['label']" type="number" :value="$value" :min="$field['min'] ?? 0" :max="$field['max'] ?? null"
                           :step="$field['type'] === 'int' ? 1 : 'any'" inputmode="decimal" :help="$help" style="max-width:200px" />
            @break
        @case('list')
            <div class="field" x-data="listInput(@js(old($name, $value)), {{ (int) ($field['items'] ?? 12) }})">
                <div class="field__label"><span>{{ $field['label'] }}</span><span class="field__label-extra" x-text="items.length + ' of ' + max"></span></div>
                <input type="hidden" name="{{ $name }}" value="" x-show="false" :disabled="items.length > 0">
                <ul class="repeater__list" x-ref="list">
                    <template x-for="(item, i) in items" :key="item._k">
                        <li class="list-input__row" data-sortable-item :data-item-key="item._k">
                            <span class="drag-handle" aria-hidden="true" title="Drag to reorder"><x-admin.icon name="bars-2" size="sm" /></span>
                            <input type="text" class="input" :name="@js($name) + '[' + i + ']'" x-model="item.text" maxlength="120" :aria-label="@js($field['label']) + ' ' + (i + 1)">
                            <button type="button" class="btn btn--ghost-danger btn--icon btn--sm" @click="remove(i)" aria-label="Remove"><x-admin.icon name="trash" /></button>
                        </li>
                    </template>
                </ul>
                <div><button type="button" class="btn btn--sm" @click="add()" :disabled="items.length >= max"><x-admin.icon name="plus" /><span>Add</span></button></div>
                @if ($help)<p class="field__help">{{ $help }}</p>@endif
                @error($name)<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@enderror
            </div>
            @break
        @default
            <x-admin.input :name="$name" :label="$field['label']" :type="in_array($field['type'], ['email', 'url'], true) ? $field['type'] : 'text'" :value="$value"
                           :help="$help" :required="! empty($field['required'])" :placeholder="$field['placeholder'] ?? (is_string($default) ? $default : null)" maxlength="{{ $field['max'] ?? 255 }}" />
    @endswitch
    @if ($field['type'] !== 'bool' && ! empty($field['drives']))
        <p class="drives"><x-admin.icon name="sparkles" />{{ $field['drives'] }}</p>
    @endif
</div>
