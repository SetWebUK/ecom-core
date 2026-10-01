{{--
    One field of a structured block, bound to the blocksEditor Alpine component.
    $fk field key · $spec schema entry · $model JS object expression ("b.hero" / "item") · $nameExpr JS expression of the
    object's input-name prefix · $errExpr JS expression of its error-key prefix · $staticName PHP name prefix (outside lists)
--}}
@php
    [$ftype, , $fmeta] = Pine\Commerce\Services\Admin\PageBlocks::type($spec);
    $label = $fmeta['label'] ?? Illuminate\Support\Str::headline($fk);
    $n = $nameExpr." + '[".$fk."]'";
    $e = $errExpr." + '.".$fk."'";
    $m = $model.'.'.$fk;
@endphp
@if ($ftype === 'ids')
    <x-admin.product-picker :name="$staticName.'['.$fk.']'" :label="$label" :value="data_get($blocks, str_replace(['blocks[', '][', ']'], ['', '.', ''], $staticName).'.'.$fk, [])" :help="$fmeta['help'] ?? null" />
@elseif ($ftype === 'bool')
    <div class="field">
        <label class="check">
            <input type="hidden" :name="{{ $n }}" value="0">
            <input type="checkbox" class="checkbox" :name="{{ $n }}" value="1" x-model="{{ $m }}">
            <span class="check__text"><span class="check__label">{{ $label }}</span></span>
        </label>
    </div>
@elseif ($ftype === 'image')
    <div class="field">
        <div class="field__label"><span>{{ $label }}</span></div>
        <div class="media-slot">
            <button type="button" class="media-slot__preview" @click="pickImage({{ $model }}, @js($fk))" :aria-label="{{ $m }} ? @js('Change '.strtolower($label)) : @js('Choose '.strtolower($label))">
                <template x-if="{{ $m }}"><img :src="mediaUrl({{ $m }})" alt=""></template>
                <template x-if="!{{ $m }}"><x-admin.icon name="photo" size="lg" /></template>
            </button>
            <div class="row gap-1">
                <button type="button" class="btn btn--sm" @click="pickImage({{ $model }}, @js($fk))"><span x-text="{{ $m }} ? 'Change' : 'Choose'">Choose</span></button>
                <button type="button" class="btn btn--sm btn--ghost-danger" x-show="{{ $m }}" x-cloak @click="{{ $m }} = ''"><span>Remove</span></button>
            </div>
            <span class="media-slot__path" x-text="{{ $m }}"></span>
            <input type="hidden" :name="{{ $n }}" :value="{{ $m }}">
        </div>
        <p class="field__error" x-show="err({{ $e }})" x-cloak><x-admin.icon name="exclamation-circle" variant="mini" /><span x-text="err({{ $e }})"></span></p>
    </div>
@elseif ($ftype === 'html')
    <div class="field">
        <div class="field__label"><span>{{ $label }}</span><button type="button" class="btn btn--plain text-sm" @click="editHtml({{ $model }}, @js($fk), @js($label))"><span>Edit</span></button></div>
        <div class="html-preview" role="button" tabindex="0" aria-label="Edit {{ strtolower($label) }}" x-html="{{ $m }}"
             @click="editHtml({{ $model }}, @js($fk), @js($label))" @keydown.enter.prevent="editHtml({{ $model }}, @js($fk), @js($label))"></div>
        <input type="hidden" :name="{{ $n }}" :value="{{ $m }}">
        <p class="field__error" x-show="err({{ $e }})" x-cloak><x-admin.icon name="exclamation-circle" variant="mini" /><span x-text="err({{ $e }})"></span></p>
    </div>
@else
    <div class="field">
        <div class="field__label"><label :for="fid({{ $n }})">{{ $label }}</label></div>
        @if ($ftype === 'link')
            <div class="input-group" :class="{ 'is-invalid': err({{ $e }}) }">
                <input type="text" class="input mono" :id="fid({{ $n }})" :name="{{ $n }}" x-model="{{ $m }}" maxlength="500" placeholder="/page-address/" autocomplete="off" spellcheck="false">
                <button type="button" class="btn btn--sm" @click="pickLink({{ $model }}, @js($fk))"><x-admin.icon name="link" /><span>Browse</span></button>
            </div>
        @elseif ($ftype === 'int')
            <input type="number" class="input" style="max-width:140px" :id="fid({{ $n }})" :name="{{ $n }}" x-model.number="{{ $m }}"
                   min="{{ $fmeta['min'] ?? 0 }}" max="{{ $fmeta['max'] ?? 1000 }}" step="1" :class="{ 'is-invalid': err({{ $e }}) }">
        @elseif (in_array($ftype, ['multiline', 'textarea'], true))
            <textarea class="textarea" :id="fid({{ $n }})" :name="{{ $n }}" x-model="{{ $m }}" rows="{{ $fmeta['rows'] ?? ($ftype === 'multiline' ? 2 : 3) }}" :class="{ 'is-invalid': err({{ $e }}) }"></textarea>
        @else
            <input type="text" class="input" :id="fid({{ $n }})" :name="{{ $n }}" x-model="{{ $m }}" maxlength="500" :class="{ 'is-invalid': err({{ $e }}) }">
        @endif
        <p class="field__error" x-show="err({{ $e }})" x-cloak><x-admin.icon name="exclamation-circle" variant="mini" /><span x-text="err({{ $e }})"></span></p>
        @if (! empty($fmeta['help']) || $ftype === 'multiline')
            <p class="field__help">{{ $fmeta['help'] ?? 'Press Enter to start a new line.' }}</p>
        @endif
    </div>
@endif
