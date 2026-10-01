{{-- Pages list: status tabs, search, template filter, sortable columns, bulk publish/unpublish/delete. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Pages')

@section('content')
    <x-admin.page-header title="Pages" subtitle="Information pages such as About us, Delivery and Warranty – and the home page.">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.pages.create')">Add page</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="document-text" title="Add your first page" description="Pages hold information such as your delivery policy or warranty terms.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.pages.create')">Add page</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" />
            <x-admin.filters placeholder="Search by title or address" :chips="$chips" keep="status">
                <x-admin.filter-select name="template" :options="$templateOptions" placeholder="All templates" label="Template" />
            </x-admin.filters>

            @if ($pages->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No pages match" description="Try a different search or filter." size="sm">
                    <x-admin.button :href="route('admin.pages.index')">Clear filters</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$pages->pluck('id')" selectable :bulk-action="route('admin.pages.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="publish" size="sm" icon="eye">Publish</x-admin.button>
                        <x-admin.button type="submit" name="action" value="draft" size="sm" icon="eye-slash">Unpublish</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm="Visitors will get a “page not found” error at these addresses. The home page is never deleted. This can’t be undone."
                                        data-confirm-title="Delete the selected pages?" data-confirm-button="Delete pages">Delete</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="title">Title</x-admin.th>
                        <x-admin.th sort="path">Address</x-admin.th>
                        <x-admin.th sort="template" class="hidden-mobile">Template</x-admin.th>
                        <x-admin.th>Status</x-admin.th>
                        <x-admin.th sort="updated_at" first="desc">Updated</x-admin.th>
                    </x-slot:head>
                    @foreach ($pages as $page)
                        <tr>
                            <x-admin.row-check :id="$page->id" :label="'Select '.$page->title" />
                            <td class="stack-title">
                                <div class="cell-main">
                                    @if ($page->path === '' || $page->template === 'home')
                                        <x-admin.icon name="home" size="sm" class="text-muted" label="Home page" />
                                    @endif
                                    <div class="flex-1">
                                        <a href="{{ route('admin.pages.edit', $page) }}" class="row-link">{{ $page->title }}</a>
                                        @if ($page->noindex)<div class="cell-sub">Hidden from Google</div>@endif
                                    </div>
                                </div>
                            </td>
                            <td data-label="Address" class="mono text-muted break" style="max-width:340px">/{{ $page->path }}{{ $page->path !== '' ? '/' : '' }}</td>
                            <td data-label="Template" class="hidden-mobile">{{ Pine\Commerce\Services\Admin\PageBlocks::templates()[$page->template]['label'] ?? ucfirst($page->template) }}</td>
                            <td data-label="Status"><x-admin.status-badge type="product" :status="$page->status" /></td>
                            <td class="nowrap text-muted" data-label="Updated"><x-admin.time :value="$page->updated_at" /></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$pages" />
            @endif
        </x-admin.card>
    @endif
@endsection
