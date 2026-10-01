{{-- Write/edit a blog post: title, content, excerpt, featured image, category, author, publish date (scheduling), SEO. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Http\Controllers\Admin\PostController;
    use Pine\Commerce\Services\Admin\LocalTime;
    $editing = $post->exists;
    $saveLabel = $editing ? 'Save' : 'Save post';
    $initial = [
        'status' => (string) old('status', $post->status ?: 'draft'),
        'publishedAt' => (string) old('published_at', LocalTime::toInput($post->published_at)),
    ];
@endphp

@section('title', $editing ? $post->title.' · Blog' : 'Write post')

@section('content')
    <x-admin.page-header :title="$editing ? $post->title : 'Write post'" :back="route('admin.posts.index')" back-label="Back to blog posts">
        @if ($editing)
            <x-slot:badges>
                <x-admin.badge :color="['published' => 'success', 'scheduled' => 'info', 'draft' => 'gray'][$state]" dot>{{ PostController::STATES[$state] }}</x-admin.badge>
            </x-slot:badges>
            @if ($state === 'published')
                <x-slot:actions><x-admin.button :href="$post->url" icon="eye" target="_blank" rel="noopener">View post</x-admin.button></x-slot:actions>
            @endif
        @endif
    </x-admin.page-header>

    <x-admin.form id="post-form" :action="$editing ? route('admin.posts.update', $post) : route('admin.posts.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <div class="layout" x-data="postForm(@js($initial))">
            <div class="layout__main">
                <x-admin.card>
                    <div class="stack-fields">
                        <x-admin.input name="title" label="Title" :value="$post->title" required maxlength="255" :autofocus="! $editing" />
                        <div x-data="{ changed: false }" @input="if ($event.target.name === 'content') changed = true">
                            <input type="hidden" name="content_changed" :value="changed ? 1 : 0" value="0">
                            <x-admin.rich-editor name="content" label="Content" :value="$post->content" height="560" :content-css="$contentCss" body-class="single single-post" />
                        </div>
                        <x-admin.textarea name="excerpt" label="Excerpt" :value="$post->excerpt" rows="3" counter="300" optional
                                          help="Short summary for the blog list and Google. Leave blank to use the start of the post." />
                    </div>
                </x-admin.card>

                <x-admin.card title="Search engine listing">
                    <x-admin.seo-fields :title="$post->meta_title" :description="$post->meta_description" title-source="#f-title" description-source="#f-excerpt"
                                        :fallback-title="$post->title" :fallback-description="$post->excerpt ?: $post->content"
                                        :url="$editing ? $post->url : null" slug-source="#f-slug" :base-url="url('blog')" />
                </x-admin.card>
            </div>

            <div class="layout__aside">
                <x-admin.card title="Publishing">
                    <div class="stack-fields">
                        <x-admin.radio-cards name="status" :value="$initial['status']" x-model="status" :options="[
                            'published' => ['label' => 'Published', 'help' => 'On the blog (from the date below)', 'icon' => 'eye'],
                            'draft' => ['label' => 'Draft', 'help' => 'Hidden from visitors', 'icon' => 'eye-slash'],
                        ]" />
                        <x-admin.datetime name="published_at" label="Publish date" :value="$post->published_at" x-model="publishedAt" optional
                                          help="UK time. Pick a future date to schedule the post. Blank = now." />
                        <p class="text-sm" x-show="scheduled" x-cloak><x-admin.badge color="info" icon="clock">Scheduled</x-admin.badge> Goes live on <span x-text="when"></span>.</p>
                    </div>
                </x-admin.card>

                <x-admin.card title="Featured image">
                    <x-admin.image-picker name="featured_image" :value="$post->featured_image" help="Shown on the blog list and when the post is shared." />
                </x-admin.card>

                <x-admin.card title="Organisation">
                    <div class="stack-fields">
                        <x-admin.select name="post_category_id" label="Category" :options="$categories" :value="$post->post_category_id" placeholder="Uncategorised" />
                        <x-admin.select name="author_id" label="Author" :options="$authors" :value="$post->author_id" placeholder="No author" />
                        <x-admin.input name="slug" label="URL handle" :value="$post->slug" prefix="/blog/" class="mono" maxlength="190" x-data="slugField('#f-title')"
                                       help="Leave blank to make one from the title." />
                        @if ($editing)
                            <p class="text-xs text-muted">Changing the handle breaks old links – add a <a href="{{ route('admin.redirects.create', ['from' => '/blog/'.$post->slug.'/']) }}">redirect</a>.</p>
                        @endif
                    </div>
                </x-admin.card>
            </div>
        </div>
    </x-admin.form>

    <div class="form-actions">
        @if ($editing)
            <x-admin.confirm :action="route('admin.posts.destroy', $post)" variant="ghost-danger" icon="trash"
                             :title="'Delete “'.($post->title).'”?'" confirm-label="Delete post"
                             message="The post disappears from the blog and its address stops working. This can’t be undone.">Delete post</x-admin.confirm>
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.posts.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="post-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            // "Scheduled" hint for a published post with a future date
            Alpine.data('postForm', function (initial) {
                var form = {
                    get scheduled() {
                        var d = this.publishedAt ? new Date(this.publishedAt) : null;
                        return this.status === 'published' && d && !isNaN(d) && d > new Date();
                    },
                    get when() {
                        var d = new Date(this.publishedAt);
                        return isNaN(d) ? '' : d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    }
                };
                Object.keys(initial).forEach(function (key) { form[key] = initial[key]; });
                return form;
            });
        });
    </script>
@endpush
