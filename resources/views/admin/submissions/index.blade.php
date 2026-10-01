{{-- Inbox: form submissions (unread in bold), filters, bulk read/unread/delete, CSV export. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php use Pine\Commerce\Http\Controllers\Admin\SubmissionController; @endphp

@section('title', 'Form submissions')

@section('content')
    <x-admin.page-header title="Form submissions" subtitle="Messages sent through the contact form. New ones are also emailed to the store address.">
        <x-slot:actions>
            @if ($tabs['all']['count'] > 0)
                <x-admin.button :href="route('admin.form-submissions.export', request()->only(['q', 'status', 'form']))" icon="arrow-down-tray" data-no-loading>Export {{ $isFiltered ? 'these' : 'all' }}</x-admin.button>
            @endif
            @if (commerce_feature('newsletter', false))
                <x-admin.button :href="route('admin.newsletter.index')" icon="newspaper">Newsletter</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="inbox" title="No messages yet" description="When someone uses the contact form, their message appears here." />
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" />
            <x-admin.filters placeholder="Search by name, email or message" :chips="$chips" keep="status">
                @if (count($formOptions) > 1)
                    <x-admin.filter-select name="form" :options="$formOptions" placeholder="All forms" label="Form" />
                @endif
            </x-admin.filters>

            @if ($submissions->isEmpty())
                <x-admin.empty icon="magnifying-glass" :title="$status === 'unread' && ! $isFiltered ? 'You’re all caught up' : 'No messages match'" :description="$status === 'unread' ? 'There are no unread messages.' : 'Try a different search or filter.'" size="sm">
                    <x-admin.button :href="route('admin.form-submissions.index')">Show all messages</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$submissions->pluck('id')" selectable :bulk-action="route('admin.form-submissions.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="read" size="sm" icon="envelope-open">Mark as read</x-admin.button>
                        <x-admin.button type="submit" name="action" value="unread" size="sm" icon="envelope">Mark as unread</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm="The messages are removed for good. This can’t be undone." data-confirm-title="Delete the selected messages?" data-confirm-button="Delete messages">Delete</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="name">From</x-admin.th>
                        <x-admin.th>Message</x-admin.th>
                        <x-admin.th sort="created_at" first="desc">Received</x-admin.th>
                    </x-slot:head>
                    @foreach ($submissions as $submission)
                        <tr @class(['is-unread' => ! $submission->read_at])>
                            <x-admin.row-check :id="$submission->id" :label="'Select message from '.($submission->name ?: $submission->email)" />
                            <td class="stack-title" style="max-width:260px">
                                <a href="{{ route('admin.form-submissions.show', $submission) }}" class="row-link">
                                    @unless ($submission->read_at)<span class="unread-dot" aria-hidden="true"></span><span class="sr-only">Unread: </span>@endunless{{ $submission->name ?: 'No name' }}
                                </a>
                                <div class="cell-sub truncate">{{ $submission->email }}</div>
                            </td>
                            <td data-label="Message">
                                <div class="truncate" style="max-width:560px">
                                    @if ($submission->subject && $submission->subject !== 'Website enquiry')<span>{{ $submission->subject }} – </span>@endif<span class="text-muted" style="font-weight:inherit">{{ Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) $submission->message), 140) }}</span>
                                </div>
                            </td>
                            <td class="nowrap text-muted" data-label="Received"><x-admin.time :value="$submission->created_at" /></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$submissions" />
            @endif
        </x-admin.card>
    @endif
@endsection
