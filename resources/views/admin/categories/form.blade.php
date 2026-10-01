{{-- Create/edit a product category. Changing the URL handle or parent offers 301 redirects for every affected address. --}}
@extends('commerce::admin.layouts.app')

@php
    $editing = $category->exists;
    $parentPath = $category->parent_id ? ($parentPaths[$category->parent_id] ?? null) : null;
    $config = [
        'name' => (string) old('name', $category->name),
        'slug' => (string) old('slug', $category->slug),
        'slugAuto' => ! $editing && ! old('slug'),
        'parentId' => (string) old('parent_id', $category->parent_id),
        'paths' => $parentPaths,
        'originalPath' => $editing ? $category->path : null,
        'siteUrl' => rtrim(url('/'), '/'),
    ];
    $saveLabel = $editing ? 'Save' : 'Create category';
@endphp

@section('title', $editing ? $category->name.' · Categories' : 'Add category')

@section('content')
    @include('commerce::admin.products.partials.assets')

    <x-admin.page-header :title="$editing ? $category->name : 'Add category'" :back="route('admin.categories.index')" back-label="Back to categories">
        @if ($editing)
            <x-slot:badges>
                @unless ($category->is_visible)<x-admin.badge>Hidden</x-admin.badge>@endunless
            </x-slot:badges>
            <x-slot:meta>
                @foreach ($ancestors as $ancestor)<a href="{{ route('admin.categories.edit', $ancestor) }}">{{ $ancestor->name }}</a> › @endforeach{{ $category->name }}
            </x-slot:meta>
            <x-slot:actions>
                <x-admin.button :href="$category->url" icon="arrow-top-right-on-square" target="_blank" rel="noopener"><span class="view-store-label">View in shop</span></x-admin.button>
                <x-admin.button :href="route('admin.products.index', ['category' => $category->id])" icon="tag">Products</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <x-admin.form id="category-form" :action="$editing ? route('admin.categories.update', $category) : route('admin.categories.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <div class="layout" x-data="categoryForm(@js($config))">
            <div class="layout__main">
                <x-admin.card>
                    <div class="stack-fields">
                        <x-admin.input name="name" label="Name" required maxlength="190" x-model="name" :autofocus="! $editing" />
                        <x-admin.field label="URL handle" for="f-slug" error="slug" required>
                            <div @class(['input-group', 'is-invalid' => $errors->has('slug')])>
                                <span class="input-group__addon mono text-xs" x-text="prefix">/{{ $parentPath ? $parentPath.'/' : '' }}</span>
                                <input type="text" name="slug" id="f-slug" class="input input--mono" x-model="slug" @input="slugAuto = $event.target.value === ''" @blur="slug = Admin.slugify(slug)"
                                       maxlength="190" required autocomplete="off" spellcheck="false" value="{{ old('slug', $category->slug) }}">
                            </div>
                            <p class="url-preview"><x-admin.icon name="link" /><span class="break" x-text="siteUrl + prefix + (slug || 'category') + '/'"></span></p>
                        </x-admin.field>

                        @if ($editing)
                            <div x-show="pathChanged" x-cloak>
                                <x-admin.callout type="warning" title="This changes the web address">
                                    <p>The category moves from <strong class="mono">/{{ $category->path }}/</strong> to <strong class="mono" x-text="newPath"></strong>.
                                        This also changes the address of {{ $childCount ? $childCount.' '.Str::plural('sub-category', $childCount).' and ' : '' }}{{ number_format($affectedProducts) }} {{ Str::plural('product', $affectedProducts) }}.</p>
                                    <div class="mt-2">
                                        <input type="hidden" name="create_redirects" value="0">
                                        <label class="check" for="f-create_redirects">
                                            <input type="checkbox" class="checkbox" id="f-create_redirects" name="create_redirects" value="1" @checked(old('create_redirects', '1') === '1')>
                                            <span class="check__text"><span class="check__label">Redirect all the old addresses to the new ones (recommended)</span>
                                                <span class="check__help">Keeps links, bookmarks and Google results working. You can review them later under Content › Redirects.</span></span>
                                        </label>
                                    </div>
                                </x-admin.callout>
                            </div>
                        @endif

                        <x-admin.select name="parent_id" label="Parent category" :options="$parentOptions" :value="$category->parent_id" placeholder="None (top level)" x-model="parentId"
                                        help="Sub-categories sit under their parent in menus and web addresses." />

                        <x-admin.rich-editor name="description" label="Description" :value="$category->description" height="260"
                                             help="Shown at the top of the category page, above the products." />
                        <x-admin.rich-editor name="extra_content" label="Extra content" :value="$category->extra_content" height="360"
                                             help="Longer text shown below the products (buying guides, FAQs) – good for search engines." />
                    </div>
                </x-admin.card>

                <x-admin.card title="Search engine listing">
                    <x-admin.seo-fields :title="$category->meta_title" :description="$category->meta_description" title-source="#f-name" description-source="#f-description"
                                        :fallback-title="$category->name" :fallback-description="$category->description" :url="$editing ? $category->url : null" />
                </x-admin.card>
            </div>

            <div class="layout__aside">
                <x-admin.card title="Visibility">
                    <div class="stack-fields">
                        <x-admin.toggle name="is_visible" label="Shown in the shop" help="Hidden categories return “page not found”; their products stay available." :checked="(bool) $category->is_visible" />
                        <x-admin.toggle name="show_in_menu" label="Shown in menus" help="Include it in category lists and menus built from categories." :checked="(bool) $category->show_in_menu" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Image">
                    <x-admin.image-picker name="image" :value="$category->image" help="Used on category tiles and menus." />
                </x-admin.card>

                @if ($editing)
                    <x-admin.card title="Summary">
                        <dl class="kv">
                            <dt>Products</dt>
                            <dd><a href="{{ route('admin.products.index', ['category' => $category->id]) }}">{{ number_format($productCount) }} directly</a>@if ($childCount)<span class="text-muted">, {{ number_format($affectedProducts) }} incl. sub-categories</span>@endif</dd>
                            <dt>Sub-categories</dt>
                            <dd>{{ $childCount ?: 'None' }}</dd>
                            <dt>Created</dt>
                            <dd><x-admin.time :value="$category->created_at" format="date" /></dd>
                            <dt>Last edited</dt>
                            <dd><x-admin.time :value="$category->updated_at" /></dd>
                        </dl>
                    </x-admin.card>
                @endif
            </div>
        </div>
    </x-admin.form>

    <div class="form-actions">
        @if ($editing)
            @if ($productCount === 0 && $childCount === 0)
                <x-admin.confirm :action="route('admin.categories.destroy', $category)" variant="ghost-danger" icon="trash" :title="'Delete '.($category->name).'?'"
                                 message="It’s empty, so no products are affected. Its web address will redirect to the parent category. This can’t be undone." confirm-label="Delete category">
                    <x-slot:fields><input type="hidden" name="create_redirects" value="1"></x-slot:fields>
                    Delete category
                </x-admin.confirm>
            @else
                <x-admin.button variant="ghost-danger" icon="trash" x-data x-on:click="$dispatch('open-modal', 'delete-category')">Delete category</x-admin.button>
            @endif
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.categories.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="category-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>

    @if ($editing && ($productCount > 0 || $childCount > 0))
        <x-admin.modal name="delete-category" :title="'Delete '.($category->name).'?'" :open="$errors->has('move_to')">
            <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" id="delete-category-form">
                @csrf
                @method('DELETE')
                <div class="stack-fields">
                    <p class="text-sm">
                        @if ($productCount)This category has <strong>{{ number_format($productCount) }} {{ Str::plural('product', $productCount) }}</strong>. Choose where they should go – nothing is deleted except the category itself.@endif
                        @if ($childCount) Its {{ $childCount }} {{ Str::plural('sub-category', $childCount) }} will move up a level.@endif
                    </p>
                    @if ($productCount)
                        <x-admin.select name="move_to" label="Move its products to" :options="$moveOptions" placeholder="Choose a category…" required />
                    @endif
                    <x-admin.checkbox name="create_redirects" id="f-delete-create_redirects" label="Redirect the old web addresses" help="The category (and its products’ old addresses) redirect to where they’ve moved." :checked="true" />
                </div>
            </form>
            <x-slot:footer>
                <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                <x-admin.button type="submit" form="delete-category-form" variant="danger">Delete category</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            // URL handle that follows the name, with a live path preview and a warning when the address changes
            Alpine.data('categoryForm', function (config) {
                return {
                    name: config.name,
                    slug: config.slug,
                    slugAuto: config.slugAuto,
                    parentId: config.parentId || '',
                    siteUrl: config.siteUrl,
                    init: function () {
                        var self = this;
                        this.$watch('name', function (v) { if (self.slugAuto) self.slug = Admin.slugify(v); });
                    },
                    get prefix() {
                        var parent = this.parentId && config.paths[this.parentId];
                        return '/' + (parent ? parent + '/' : '');
                    },
                    get newPath() { return this.prefix + this.slug + '/'; },
                    get pathChanged() {
                        return !!config.originalPath && this.slug !== '' && this.newPath !== '/' + config.originalPath + '/';
                    }
                };
            });
        });
    </script>
@endpush
