{{-- Newsletter subscribers: search, status tabs, unsubscribe/resubscribe, delete, CSV export. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Newsletter')

@section('content')
    <x-admin.page-header title="Newsletter" subtitle="People who signed up with the footer form. Export the list to send campaigns from your mailing tool.">
        <x-slot:actions>
            @if ($tabs['all']['count'] > 0)
                <x-admin.button :href="route('admin.newsletter.export', request()->only(['q', 'status', 'source']))" icon="arrow-down-tray" data-no-loading>Export {{ $isFiltered ? 'these' : 'all' }} as CSV</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="newspaper" title="No subscribers yet" description="When someone signs up with the newsletter form in the site footer, they appear here." />
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" />
            <x-admin.filters placeholder="Search by email" :chips="$chips" keep="status">
                @if (count($sourceOptions) > 1)
                    <x-admin.filter-select name="source" :options="$sourceOptions" placeholder="Any sign-up form" label="Signed up via" />
                @endif
            </x-admin.filters>

            @if ($subscribers->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No subscribers match" description="Try a different search or filter." size="sm">
                    <x-admin.button :href="route('admin.newsletter.index')">Clear filters</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$subscribers->pluck('id')" selectable :bulk-action="route('admin.newsletter.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="unsubscribe" size="sm" icon="no-symbol">Unsubscribe</x-admin.button>
                        <x-admin.button type="submit" name="action" value="resubscribe" size="sm" icon="check">Subscribe again</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm="They are removed from the list completely (use Unsubscribe to keep a record). This can’t be undone."
                                        data-confirm-title="Delete the selected subscribers?" data-confirm-button="Delete">Delete</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="email">Email</x-admin.th>
                        <x-admin.th>Status</x-admin.th>
                        <x-admin.th class="hidden-mobile">Signed up via</x-admin.th>
                        <x-admin.th sort="created_at" first="desc">Signed up</x-admin.th>
                        <x-admin.th class="table__actions"><span class="sr-only">Actions</span></x-admin.th>
                    </x-slot:head>
                    @foreach ($subscribers as $subscriber)
                        <tr @class(['is-muted' => $subscriber->unsubscribed_at])>
                            <x-admin.row-check :id="$subscriber->id" :label="'Select '.$subscriber->email" />
                            <td class="stack-title"><a href="mailto:{{ $subscriber->email }}" class="row-link break">{{ $subscriber->email }}</a></td>
                            <td data-label="Status">
                                @if ($subscriber->unsubscribed_at)
                                    <x-admin.badge color="gray">Unsubscribed</x-admin.badge>
                                    <div class="cell-sub"><x-admin.time :value="$subscriber->unsubscribed_at" format="date" /></div>
                                @else
                                    <x-admin.badge color="success" dot>Subscribed</x-admin.badge>
                                @endif
                            </td>
                            <td class="text-muted hidden-mobile" data-label="Signed up via">{{ $subscriber->source ? Illuminate\Support\Str::headline($subscriber->source) : '—' }}</td>
                            <td class="nowrap text-muted" data-label="Signed up"><x-admin.time :value="$subscriber->created_at" /></td>
                            <td class="table__actions">
                                <x-admin.dropdown :label="'Actions for '.($subscriber->email)" icon="ellipsis-horizontal" icon-only size="sm" variant="ghost">
                                    <x-admin.confirm as="menu-item" :action="route('admin.newsletter.toggle', $subscriber)" method="PATCH" :danger="false"
                                                     :icon="$subscriber->unsubscribed_at ? 'check' : 'no-symbol'"
                                                     :title="$subscriber->unsubscribed_at ? 'Subscribe '.$subscriber->email.' again?' : 'Unsubscribe '.$subscriber->email.'?'"
                                                     :message="$subscriber->unsubscribed_at ? 'Only do this if they asked to be added back.' : 'They stay on the list, marked as unsubscribed (exports show their status).'"
                                                     :confirm-label="$subscriber->unsubscribed_at ? 'Subscribe again' : 'Unsubscribe'">{{ $subscriber->unsubscribed_at ? 'Subscribe again' : 'Unsubscribe' }}</x-admin.confirm>
                                    <x-admin.confirm as="menu-item" :action="route('admin.newsletter.destroy', $subscriber)" icon="trash" :title="'Delete '.($subscriber->email).'?'"
                                                     message="They are removed from the list completely. This can’t be undone." confirm-label="Delete">Delete</x-admin.confirm>
                                </x-admin.dropdown>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$subscribers" />
            @endif
        </x-admin.card>
    @endif
@endsection
