{{--
    Structured page data editor (blocksEditor). Renders every list/object of a template's schema as a collapsible card.
    $schema · $blocks (current values) · $collapsed (start closed except the first) · $allowFallback (home: empty list = original items)
--}}
@once('admin-sortable')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('vendor/sortablejs/Sortable-1.15.7.min.js', false) }}"></script>
    @endpush
@endonce
@php
    use Pine\Commerce\Services\Admin\PageBlocks;
    $errorBag = collect($errors->getMessages())->filter(fn ($v, $k) => str_starts_with($k, 'blocks.'))->all();
@endphp
<div class="stack" x-data="blocksEditor(@js(['blocks' => $blocks, 'errors' => $errorBag, 'blanks' => PageBlocks::blanks($template)]))">
    @foreach ($schema as $key => $spec)
        @php [$type, $fields, $meta] = PageBlocks::type($spec); @endphp
        @continue(! in_array($type, ['list', 'object'], true))
        @php
            if ($type === 'list' && ($allowFallback ?? false)) {
                $meta['empty'] = 'No '.Illuminate\Support\Str::plural($meta['item'] ?? 'item').' – the site shows the original ones until you add some.';
            }
        @endphp
        @if ($bare ?? false)
            @include('commerce::admin.pages._block-list', ['listModel' => "b.{$key}", 'listName' => "'blocks[{$key}]'", 'listErr' => "'blocks.{$key}'", 'fields' => $fields, 'meta' => $meta, 'blankKey' => $key, 'hideLabel' => true])
            @continue
        @endif
        <section class="card builder-section" id="section-{{ $key }}" x-data="{ open: @js(! ($collapsed ?? false) || $loop->first) || hasErrors('blocks.{{ $key }}') }">
            <header class="card__header">
                <button type="button" class="builder-section__toggle" @click="open = !open" :aria-expanded="open.toString()" aria-controls="section-{{ $key }}-body">
                    <x-admin.icon name="chevron-right" />
                    <span class="flex-1">
                        <span class="card__title" style="display:block">{{ $meta['label'] ?? Illuminate\Support\Str::headline($key) }}</span>
                        @if (! empty($meta['description']))<span class="card__subtitle" style="display:block">{{ $meta['description'] }}</span>@endif
                    </span>
                </button>
                <span class="badge badge--danger badge--sm" x-show="hasErrors('blocks.{{ $key }}')" x-cloak>Needs attention</span>
            </header>
            <div class="card__body" id="section-{{ $key }}-body" x-show="open" x-collapse>
                @if ($type === 'object')
                    <div class="stack-fields">
                        @foreach ($fields as $fk => $fspec)
                            @php [$ftype, $ffields, $fmeta] = PageBlocks::type($fspec); @endphp
                            @if ($ftype === 'list')
                                @if ($allowFallback ?? false)
                                    @php $fmeta['empty'] = 'No '.Illuminate\Support\Str::plural($fmeta['item'] ?? 'item').' – the site shows the original ones until you add some.'; @endphp
                                @endif
                                @include('commerce::admin.pages._block-list', ['listModel' => "b.{$key}.{$fk}", 'listName' => "'blocks[{$key}][{$fk}]'", 'listErr' => "'blocks.{$key}.{$fk}'", 'fields' => $ffields, 'meta' => $fmeta, 'blankKey' => "{$key}.{$fk}"])
                            @else
                                @include('commerce::admin.pages._block-field', ['fk' => $fk, 'spec' => $fspec, 'model' => "b.{$key}", 'nameExpr' => "'blocks[{$key}]'", 'errExpr' => "'blocks.{$key}'", 'staticName' => "blocks[{$key}]"])
                            @endif
                        @endforeach
                    </div>
                @else
                    @include('commerce::admin.pages._block-list', ['listModel' => "b.{$key}", 'listName' => "'blocks[{$key}]'", 'listErr' => "'blocks.{$key}'", 'fields' => $fields, 'meta' => $meta, 'blankKey' => $key, 'hideLabel' => true])
                @endif
            </div>
        </section>
    @endforeach
</div>
