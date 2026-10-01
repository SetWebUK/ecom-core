{{-- Blog categories. --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Blog categories')

@section('content')
    <x-admin.page-header title="Blog categories" subtitle="Group blog posts. Each category has its own page at /blog/category/…/.">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.post-categories.create')">Add category</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($categories->isEmpty())
        <div class="card">
            <x-admin.empty icon="folder" title="No blog categories" description="Posts without a category are listed as “Uncategorised”.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.post-categories.create')">Add category</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.table stack>
                <x-slot:head>
                    <x-admin.th>Name</x-admin.th>
                    <x-admin.th>Address</x-admin.th>
                    <x-admin.th align="right">Posts</x-admin.th>
                    <x-admin.th class="table__actions"><span class="sr-only">Actions</span></x-admin.th>
                </x-slot:head>
                @foreach ($categories as $category)
                    <tr>
                        <td class="stack-title"><a href="{{ route('admin.post-categories.edit', $category) }}" class="row-link">{{ $category->name }}</a></td>
                        <td data-label="Address" class="mono text-muted">/blog/category/{{ $category->slug }}/</td>
                        <td class="num" data-label="Posts">
                            @if ($category->posts_count)
                                <a href="{{ route('admin.posts.index', ['category' => $category->id]) }}">{{ number_format($category->posts_count) }}</a>
                            @else
                                0
                            @endif
                        </td>
                        <td class="table__actions">
                            <x-admin.button :href="url('blog/category/'.$category->slug)" icon="arrow-top-right-on-square" variant="ghost" size="sm" label="View on the site" target="_blank" rel="noopener" />
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </x-admin.card>
    @endif
@endsection
