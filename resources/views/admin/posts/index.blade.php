{{-- Blog posts: status tabs (published / scheduled / drafts), search, category filter, bulk actions. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php use Pine\Commerce\Http\Controllers\Admin\PostController; @endphp

@section('title', 'Blog posts')

@section('content')
    <x-admin.page-header title="Blog posts" subtitle="Articles on /blog/.">
        <x-slot:actions>
            <x-admin.button :href="route('admin.post-categories.index')" icon="folder">Categories</x-admin.button>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.posts.create')">Write post</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="pencil-square" title="Write your first blog post" description="Helpful articles bring visitors from Google and build trust.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.posts.create')">Write post</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" />
            <x-admin.filters placeholder="Search posts" :chips="$chips" keep="status">
                <x-admin.filter-select name="category" :options="$categoryOptions" placeholder="All categories" label="Category" />
            </x-admin.filters>

            @if ($posts->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No posts match" description="Try a different search or filter." size="sm">
                    <x-admin.button :href="route('admin.posts.index')">Clear filters</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$posts->pluck('id')" selectable :bulk-action="route('admin.posts.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="publish" size="sm" icon="eye">Publish now</x-admin.button>
                        <x-admin.button type="submit" name="action" value="draft" size="sm" icon="eye-slash">Unpublish</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm="The posts disappear from the blog and their addresses stop working. This can’t be undone."
                                        data-confirm-title="Delete the selected posts?" data-confirm-button="Delete posts">Delete</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="title">Post</x-admin.th>
                        <x-admin.th class="hidden-mobile">Category</x-admin.th>
                        <x-admin.th>Status</x-admin.th>
                        <x-admin.th sort="published_at" first="desc">Date</x-admin.th>
                        <x-admin.th class="hidden-mobile">Author</x-admin.th>
                    </x-slot:head>
                    @foreach ($posts as $post)
                        @php $state = PostController::state($post); @endphp
                        <tr>
                            <x-admin.row-check :id="$post->id" :label="'Select '.$post->title" />
                            <td class="stack-title">
                                <div class="cell-main">
                                    <x-admin.thumb :src="$post->featured_image" size="sm" cover />
                                    <div class="flex-1" style="min-width:0">
                                        <a href="{{ route('admin.posts.edit', $post) }}" class="row-link">{{ $post->title }}</a>
                                        <div class="cell-sub mono truncate">/blog/{{ $post->slug }}/</div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Category" class="hidden-mobile">{{ $post->category?->name ?? '—' }}</td>
                            <td data-label="Status">
                                <x-admin.badge :color="['published' => 'success', 'scheduled' => 'info', 'draft' => 'gray'][$state]" dot>{{ PostController::STATES[$state] }}</x-admin.badge>
                            </td>
                            <td class="nowrap text-muted" data-label="Date"><x-admin.time :value="$post->published_at" format="date" /></td>
                            <td class="text-muted hidden-mobile" data-label="Author">{{ $post->author?->full_name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$posts" />
            @endif
        </x-admin.card>
    @endif
@endsection
