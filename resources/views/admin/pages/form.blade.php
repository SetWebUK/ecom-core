{{-- Create/edit page: content editor (storefront CSS), address + template, SEO, structured blocks (home page builder, FAQ). --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\PageBlocks;

    $editing = $page->exists;
    $template = (string) old('template', $page->template ?: 'default');
    $isHome = $editing && $page->template === 'home';
    // after a failed save, show what was posted (lists replace the stored ones as a whole)
    $blocks = PageBlocks::withSkeleton(is_array(old('blocks')) ? array_replace($blocks, old('blocks')) : $blocks, $template);
    $faqBlocks = PageBlocks::withSkeleton(['faq' => $blocks['faq'] ?? []], 'faq');
    $extraJson = old('blocks_extra', $extra ? json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
    $saveLabel = $editing ? 'Save' : 'Save page';
    $initial = [
        'template' => $template,
        'parentId' => (string) old('parent_id', $page->parent_id),
        'slug' => (string) old('slug', $page->slug),
        'savedTemplate' => $editing ? $page->template : null,
        'paths' => $parentPaths,
        'base' => rtrim(url('/'), '/'),
        'templates' => collect(PageBlocks::templates())->map(fn ($t) => $t['help'])->all(),
    ];
    $showTitle = (bool) old('blocks.show_title', $blocks['show_title'] ?? false);
@endphp

@section('title', $editing ? $page->title.' · Pages' : 'Add page')

@section('content')
    <x-admin.page-header :title="$editing ? $page->title : 'Add page'" :back="route('admin.pages.index')" back-label="Back to pages">
        @if ($editing)
            <x-slot:badges>
                <x-admin.status-badge type="product" :status="$page->status" />
                @if ($isHome)<x-admin.badge color="info" icon="home">Home page</x-admin.badge>@endif
            </x-slot:badges>
            <x-slot:actions>
                <x-admin.button :href="$page->url" icon="eye" target="_blank" rel="noopener">{{ $page->status === 'published' ? 'View page' : 'Preview' }}</x-admin.button>
                <x-admin.dropdown label="More actions" icon="ellipsis-horizontal" icon-only>
                    @unless ($isHome)
                        <x-admin.dropdown-item :href="route('admin.pages.create', ['parent' => $page->id])" icon="document-plus">Add a sub-page</x-admin.dropdown-item>
                        <x-admin.dropdown-item type="submit" form="duplicate-page" icon="document-duplicate">Duplicate</x-admin.dropdown-item>
                    @endunless
                    <x-admin.dropdown-item :href="route('admin.menus.index')" icon="bars-3">Edit menus</x-admin.dropdown-item>
                </x-admin.dropdown>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($systemNote)
        <x-admin.callout type="warning" class="mb-4" title="The shop builds this page itself">
            /{{ $page->path }}/ shows {{ $systemNote }}, so the content below isn’t displayed to customers. You can still change its title for menus.
        </x-admin.callout>
    @endif

    <x-admin.form id="page-form" :action="$editing ? route('admin.pages.update', $page) : route('admin.pages.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <div class="layout" x-data="pageForm(@js($initial))">
            <div class="layout__main">
                <x-admin.card>
                    <div class="stack-fields">
                        <x-admin.input name="title" label="Title" :value="$page->title" required maxlength="255" :autofocus="! $editing" />
                        @unless ($isHome)
                            <div x-show="template !== 'home'" x-data="{ changed: false }" @input="if ($event.target.name === 'content') changed = true">
                                <input type="hidden" name="content_changed" :value="changed ? 1 : 0" value="0">
                                <x-admin.rich-editor name="content" label="Content" :value="$page->content" height="620" :content-css="$contentCss"
                                                     :body-class="'page-template-default page elementor-page'.($page->wp_id ? ' page-id-'.$page->wp_id.' elementor-page-'.$page->wp_id : '')" />
                                @if ($page->template === 'blog')
                                    <p class="field__help mt-2">Put <code class="mono">[{{ collect(\Pine\Commerce\Commerce::shortcodes()->tagsFor('blog_index'))->last() }}]</code> where the grid of blog posts should appear.</p>
                                @endif
                            </div>
                        @endunless
                        <template x-if="template === 'home' && savedTemplate !== 'home'">
                            <x-admin.callout type="info" title="Save the page to build the home page">The home page is made of sections (banner, category tiles, best sellers, FAQ …). They appear here after you save.</x-admin.callout>
                        </template>
                    </div>
                </x-admin.card>

                @if ($isHome)
                    <x-admin.html-editor />
                    <x-admin.link-picker />
                    <div class="row row--between">
                        <div>
                            <h2 class="card__title">Home page sections</h2>
                            <p class="card__subtitle">In the order they appear on the site. Click a section to edit it.</p>
                        </div>
                        <x-admin.button :href="$page->url" icon="arrow-top-right-on-square" size="sm" target="_blank" rel="noopener">View home page</x-admin.button>
                    </div>
                    @include('commerce::admin.pages._blocks', ['schema' => PageBlocks::schema('home'), 'blocks' => $blocks, 'template' => 'home', 'collapsed' => true, 'allowFallback' => true])
                @else
                    <x-admin.html-editor />
                    <fieldset class="fieldset" x-show="template === 'faq'" :disabled="template !== 'faq'" x-cloak>
                        <x-admin.card title="Questions and answers" subtitle="Shown as an accordion under the content, and to Google as FAQ results. Drag to reorder.">
                            @include('commerce::admin.pages._blocks', ['schema' => ['faq' => PageBlocks::schema('faq')['faq']], 'blocks' => $faqBlocks, 'template' => 'faq', 'collapsed' => false, 'bare' => true])
                        </x-admin.card>
                    </fieldset>
                    {{-- theme templates with a block schema (theme config blocks.templates / blocks.schemas) --}}
                    @foreach (PageBlocks::templates() as $themeTemplate => $themeMeta)
                        @continue(PageBlocks::isCore($themeTemplate) || ! PageBlocks::schema($themeTemplate))
                        <fieldset class="fieldset" x-show="template === @js($themeTemplate)" :disabled="template !== @js($themeTemplate)" x-cloak>
                            @include('commerce::admin.pages._blocks', ['schema' => PageBlocks::schema($themeTemplate), 'blocks' => PageBlocks::withSkeleton($blocks, $themeTemplate), 'template' => $themeTemplate, 'collapsed' => true])
                        </fieldset>
                    @endforeach
                @endif

                <x-admin.card title="Search engine listing" subtitle="How this page appears in Google.">
                    <x-admin.seo-fields :title="$page->meta_title" :description="$page->meta_description" title-source="#f-title"
                                        :fallback-title="$page->title" :fallback-description="$isHome ? null : $page->content"
                                        :url="$editing ? $page->url : null" :slug-source="$editing ? null : '#f-slug'" :base-url="$editing ? null : url('/')" />
                    <div class="mt-4">
                        <x-admin.toggle name="noindex" label="Hide from search engines" help="Adds “noindex” so Google won’t list this page. The page still works for visitors." :checked="$page->noindex" />
                    </div>
                </x-admin.card>

                <x-admin.card x-data="{ open: {{ $extraJson !== '' || $errors->has('blocks_extra') ? 'true' : 'false' }} }">
                    <x-slot:header>
                        <button type="button" class="builder-section__toggle" @click="open = !open" :aria-expanded="open.toString()">
                            <x-admin.icon name="chevron-right" />
                            <span class="flex-1">
                                <span class="card__title" style="display:block">Advanced data</span>
                                <span class="card__subtitle" style="display:block">Extra structured settings stored with this page (JSON). Only change this if you know what it does.</span>
                            </span>
                        </button>
                    </x-slot:header>
                    <div x-show="open" x-collapse x-cloak>
                        <x-admin.textarea name="blocks_extra" label="Data (JSON object)" :value="$extraJson" rows="8" code optional
                                          help="Keys edited in the form above take priority over the same keys here." />
                    </div>
                </x-admin.card>
            </div>

            <div class="layout__aside">
                <x-admin.card title="Visibility">
                    <x-admin.radio-cards name="status" :value="old('status', $page->status ?: 'draft')" :options="[
                        'published' => ['label' => 'Published', 'help' => 'Visible on the website', 'icon' => 'eye'],
                        'draft' => ['label' => 'Draft', 'help' => 'Only staff can preview it', 'icon' => 'eye-slash'],
                    ]" />
                </x-admin.card>

                <x-admin.card title="Address">
                    @if ($isHome)
                        <p class="text-sm">This is the home page: <a href="{{ url('/') }}/" target="_blank" rel="noopener" class="mono">{{ url('/') }}/</a></p>
                        <input type="hidden" name="slug" value="{{ $page->slug }}">
                    @else
                        <div class="stack-fields">
                            <x-admin.select name="parent_id" label="Parent page" :options="$parents" :value="$page->parent_id" placeholder="None (top level)" x-model="parentId"
                                            help="Sub-pages get addresses like /parent/page/." />
                            <x-admin.field label="URL handle" for="f-slug" error="slug" required>
                                <div @class(['input-group', 'is-invalid' => $errors->has('slug')])>
                                    <span class="input-group__addon mono text-xs" x-text="prefix" style="max-width:55%;overflow:hidden;text-overflow:ellipsis">/</span>
                                    <input type="text" name="slug" id="f-slug" class="input mono" value="{{ old('slug', $page->slug) }}" x-model="slug" maxlength="190"
                                           x-data="slugField('#f-title')" autocomplete="off" spellcheck="false" @error('slug') aria-invalid="true" aria-describedby="f-slug-error" @enderror>
                                </div>
                            </x-admin.field>
                            <p class="text-xs text-muted break">Address: <a :href="url" target="_blank" rel="noopener" class="mono" x-text="url"></a></p>
                            @if ($editing)
                                @if (commerce_feature('redirects', false))<p class="text-xs text-muted">Changing the address breaks old links – add a <a href="{{ route('admin.redirects.create', ['from' => '/'.$page->path.'/']) }}">redirect</a> from the old one.</p>@endif
                            @endif
                        </div>
                    @endif
                </x-admin.card>

                <x-admin.card title="Template">
                    <div class="stack-fields">
                        @if ($isHome)
                            <input type="hidden" name="template" value="home">
                            <p class="text-sm"><x-admin.badge color="info" icon="home">Home page</x-admin.badge></p>
                            <p class="field__help">This page is the shop’s home page. Its sections are edited on the left.</p>
                        @else
                            <x-admin.select name="template" label="Layout" :options="collect($templates)->map(fn ($t) => $t['label'])->all()" :value="$template" x-model="template" required />
                            <p class="field__help" x-text="templates[template] || ''"></p>
                        @endif
                        @unless ($isHome)
                            <div x-show="template !== 'home' && template !== 'blog'">
                                <input type="hidden" name="blocks[show_title]" value="0" :disabled="template === 'home' || template === 'blog'">
                                <x-admin.checkbox name="blocks[show_title]" label="Show the page title above the content" :checked="$showTitle" :unchecked="null"
                                                  help="Pages designed in Elementor hide the title by default." x-bind:disabled="template === 'home' || template === 'blog'" />
                            </div>
                        @endunless
                    </div>
                </x-admin.card>

                @if ($editing)
                    <x-admin.card title="Details">
                        <dl class="kv">
                            <dt>Created</dt><dd><x-admin.time :value="$page->created_at" format="datetime" /></dd>
                            <dt>Last edited</dt><dd><x-admin.time :value="$page->updated_at" /></dd>
                            @if ($page->children()->exists())
                                <dt>Sub-pages</dt>
                                <dd>
                                    @foreach ($page->children()->orderBy('title')->limit(10)->get(['id', 'title']) as $child)
                                        <a href="{{ route('admin.pages.edit', $child) }}">{{ $child->title }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                </dd>
                            @endif
                        </dl>
                        <div class="mt-4">
                            <x-admin.input type="number" name="sort_order" label="Order" :value="$page->sort_order" step="1" help="Lower numbers come first in page lists." />
                        </div>
                    </x-admin.card>
                @endif
            </div>
        </div>
    </x-admin.form>

    @if ($editing && ! $isHome)
        <form id="duplicate-page" method="POST" action="{{ route('admin.pages.duplicate', $page) }}" hidden>@csrf</form>
    @endif

    <div class="form-actions">
        @if ($editing && ! $isHome)
            <x-admin.confirm :action="route('admin.pages.destroy', $page)" variant="ghost-danger" icon="trash"
                             :title="'Delete “'.($page->title).'”?'" confirm-label="Delete page"
                             :message="'Visitors will get a “page not found” error at /'.($page->path).'/ and menu links to it will break. This can’t be undone.'">Delete page</x-admin.confirm>
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.pages.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="page-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            // Live address preview (parent path + handle) and template help for the page form
            Alpine.data('pageForm', function (initial) {
                var form = {
                    get prefix() {
                        var parent = this.paths[this.parentId];
                        return '/' + (parent ? parent + '/' : '');
                    },
                    get url() {
                        return this.base + this.prefix + (this.slug ? this.slug.replace(/^\/+|\/+$/g, '') + '/' : '');
                    }
                };
                Object.keys(initial).forEach(function (key) { form[key] = initial[key]; });
                return form;
            });
        });
    </script>
@endpush
