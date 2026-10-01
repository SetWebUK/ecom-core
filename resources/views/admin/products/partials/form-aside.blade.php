{{-- Product editor: side panel (inside x-data="productForm(...)"). --}}
@php
    use Pine\Commerce\Services\Admin\OrderStatus;
    $statusHelp = [
        'published' => 'Visible in the shop.',
        'draft' => 'Hidden from customers while you work on it.',
        'private' => 'Hidden from customers; staff can still preview it.',
    ];
@endphp

<x-admin.card title="Status">
    <div class="stack-fields" x-data="{ status: @js(old('status', $product->status ?: 'draft')) }">
        <x-admin.select name="status" label="Status" :options="['published' => 'Active (published)', 'draft' => 'Draft', 'private' => 'Private']" :value="$product->status ?: 'draft'" x-model="status" />
        <p class="text-xs text-muted" style="margin-top:-8px" x-text="@js($statusHelp)[status] || ''">{{ $statusHelp[$product->status ?: 'draft'] ?? '' }}</p>
        <x-admin.datetime name="published_at" label="Published on" optional :value="$product->published_at" help="UK time. Used for “newest” sorting; filled in automatically when you publish." />
    </div>
</x-admin.card>

<x-admin.card title="Product type">
    <x-admin.radio-cards name="type" :value="$product->type ?: 'simple'" x-model="type" :options="[
        'simple' => ['label' => 'Single product', 'help' => 'One price and one stock level', 'icon' => 'cube'],
        'variable' => ['label' => 'With variants', 'help' => 'e.g. a choice of memory or colour', 'icon' => 'squares-2x2'],
    ]" />
    <p class="text-xs text-muted mt-2" x-show="type === 'simple' && variations.length" x-cloak>This product’s existing variants are kept but not shown in the shop while it’s a single product.</p>
</x-admin.card>

<x-admin.card title="Organisation">
    <div class="stack-fields">
        {{-- Categories: tree checklist with search + main category --}}
        <x-admin.field label="Categories" for="cat-search" error="category_ids" help="The main category decides the product’s web address and breadcrumb.">
            <input type="hidden" name="category_ids[]" value="">
            <div class="picker__chips mb-2" x-show="categoryIds.length" x-cloak>
                <template x-for="c in selectedCategories" :key="c.id">
                    <span class="chip" :class="{ 'chip--main': c.main }" :title="c.main ? 'Main category' : ''">
                        <span class="chip__label"><span x-text="c.name"></span><template x-if="c.main"><span class="text-xs"> · main</span></template></span>
                        <button type="button" class="chip__remove" @click="uncheck(c.id)" :aria-label="'Remove ' + c.name"><x-admin.icon name="x-mark" size="xs" /></button>
                    </span>
                </template>
            </div>
            <div class="search-input">
                <x-admin.icon name="magnifying-glass" />
                <input type="search" id="cat-search" class="input input--sm" placeholder="Search categories" x-model="catQuery" autocomplete="off" @keydown.enter.prevent>
            </div>
            <div class="row row--between mt-2 text-xs">
                <span class="text-muted"><span x-text="categoryIds.length"></span> selected</span>
                <button type="button" class="btn btn--plain btn--sm" @click="catSelectedOnly = !catSelectedOnly" x-text="catSelectedOnly ? 'Show all' : 'Show selected'"></button>
            </div>
            <div class="check-tree" role="group" aria-label="Categories" x-ref="catTree">
                @foreach ($categories as $category)
                    @php $label = $category->name.' '.$category->path; @endphp
                    <div class="check-tree__item" style="--depth: {{ (int) $category->depth }}" x-show="catVisible({{ $category->id }}, @js($label))">
                        <label>
                            <input type="checkbox" class="checkbox" name="category_ids[]" value="{{ $category->id }}" x-model="categoryIds">
                            <span class="check-tree__name" title="/{{ $category->path }}/">{{ $category->name }}</span>
                            @unless ($category->is_visible)<x-admin.badge size="sm">Hidden</x-admin.badge>@endunless
                        </label>
                        <label class="check-tree__primary" x-show="isChecked({{ $category->id }})" :class="{ 'is-primary': primaryId === '{{ $category->id }}' }" title="Use as the main category">
                            <input type="radio" name="primary_category_id" value="{{ $category->id }}" x-model="primaryId">
                            <span x-text="primaryId === '{{ $category->id }}' ? 'Main' : 'Make main'">Main</span>
                        </label>
                    </div>
                @endforeach
            </div>
            @error('primary_category_id')<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@enderror
        </x-admin.field>

        @if (commerce_feature('product_condition'))
        <x-admin.field label="Condition" for="f-condition" error="condition" help="Shown as the condition badge and used by the Condition filter.">
            <select name="condition" id="f-condition" class="select">
                <option value="">Not set</option>
                @foreach ($conditionOptions as $option)
                    <option value="{{ $option }}" @selected(old('condition', $conditionValue) === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </x-admin.field>
        @endif

        @if (commerce_feature('product_brand'))
        <x-admin.field label="Brand" for="f-brand" error="brand">
            <input type="text" name="brand" id="f-brand" class="input" list="brand-options" maxlength="100" autocomplete="off" value="{{ old('brand', $brandValue) }}"
                   placeholder="{{ $product->brand && ! $brandValue ? 'Detected from title: '.$product->brand : 'Brand name' }}">
            <datalist id="brand-options">
                @foreach ($brandOptions as $option)<option value="{{ $option }}"></option>@endforeach
            </datalist>
            <x-slot:helpSlot>Used by the Brand filter and the brand logo. A new brand is added to the list automatically.</x-slot:helpSlot>
        </x-admin.field>
        @endif

        <x-admin.toggle name="is_featured" label="Featured" help="Highlight it in featured product sections." :checked="(bool) $product->is_featured" />
    </div>
</x-admin.card>

@if ($product->exists && $sales)
    <x-admin.card title="Sales">
        <dl class="kv">
            <dt>Sold</dt>
            <dd>{{ number_format($sales['units']) }} {{ Str::plural('unit', $sales['units']) }} in {{ number_format($sales['orders']) }} {{ Str::plural('order', $sales['orders']) }}</dd>
            <dt>Revenue</dt>
            <dd>{{ money($sales['revenue']) }}</dd>
            <dt>Last ordered</dt>
            <dd>@if ($sales['last'])<x-admin.time :value="$sales['last']" format="date" />@else <span class="text-subtle">Never</span>@endif</dd>
            @if ($product->review_count)
                <dt>Rating</dt>
                <dd>{{ number_format((float) $product->average_rating, 1) }} / 5 from @if (commerce_feature('reviews', false))<a href="{{ route('admin.reviews.index', ['q' => $product->name, 'status' => 'all']) }}">{{ $product->review_count }} {{ Str::plural('review', $product->review_count) }}</a>@else{{ $product->review_count }} {{ Str::plural('review', $product->review_count) }}@endif</dd>
            @endif
            <dt>Added</dt>
            <dd><x-admin.time :value="$product->created_at" format="date" /></dd>
            <dt>Last edited</dt>
            <dd><x-admin.time :value="$product->updated_at" /></dd>
        </dl>
        @if ($sales['orders'] && Route::has('admin.orders.index'))
            <x-slot:footer><a href="{{ route('admin.orders.index', ['q' => $product->sku ?: $product->name]) }}" class="text-sm">See orders</a></x-slot:footer>
        @endif
    </x-admin.card>
@endif
