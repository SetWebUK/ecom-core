{{-- Redirects: old address -> new address, with hit counts, bulk actions and CSV import/export. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Redirects')

@section('content')
    <x-admin.page-header title="Redirects" subtitle="Send visitors (and Google) from old or mistyped addresses to the right page.">
        <x-slot:actions>
            <x-admin.dropdown label="Import / export" icon="arrows-up-down">
                <x-admin.dropdown-item icon="arrow-up-tray" x-on:click="close(); $dispatch('open-modal', 'import-redirects')">Import from CSV</x-admin.dropdown-item>
                <x-admin.dropdown-item :href="route('admin.redirects.export', request()->only(['q', 'status', 'type']))" icon="arrow-down-tray">Export {{ $isFiltered ? 'these' : 'all' }} as CSV</x-admin.dropdown-item>
            </x-admin.dropdown>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.redirects.create')">Add redirect</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if (session('import_problems'))
        <x-admin.callout type="warning" class="mb-4" title="Some rows weren’t imported">
            <ul class="summary-list mt-1">
                @foreach (session('import_problems') as $problem)<li>{{ $problem }}</li>@endforeach
            </ul>
        </x-admin.callout>
    @endif

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="arrow-uturn-right" title="No redirects yet" description="Add one when you rename or remove a page, so old links and Google results keep working.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.redirects.create')">Add redirect</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" />
            <x-admin.filters placeholder="Search old or new addresses" :chips="$chips" keep="status">
                <x-admin.filter-select name="type" :options="$typeOptions" placeholder="All types" label="Type" />
            </x-admin.filters>

            @if ($redirects->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No redirects match" description="Try a different search or filter." size="sm">
                    <x-admin.button :href="route('admin.redirects.index')">Clear filters</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$redirects->pluck('id')" selectable :bulk-action="route('admin.redirects.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="activate" size="sm" icon="play">Switch on</x-admin.button>
                        <x-admin.button type="submit" name="action" value="deactivate" size="sm" icon="pause">Switch off</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm="Visitors to these old addresses will get “page not found” instead. This can’t be undone."
                                        data-confirm-title="Delete the selected redirects?" data-confirm-button="Delete redirects">Delete</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="from_path">Old address</x-admin.th>
                        <x-admin.th>Goes to</x-admin.th>
                        <x-admin.th sort="status_code" class="hidden-mobile">Type</x-admin.th>
                        <x-admin.th sort="hits" align="right" first="desc">Visits</x-admin.th>
                        <x-admin.th sort="last_hit_at" first="desc">Last visit</x-admin.th>
                    </x-slot:head>
                    @foreach ($redirects as $redirect)
                        <tr @class(['is-muted' => ! $redirect->is_active])>
                            <x-admin.row-check :id="$redirect->id" :label="'Select /'.$redirect->from_path.'/'" />
                            <td class="stack-title">
                                <a href="{{ route('admin.redirects.edit', $redirect) }}" class="row-link mono break">/{{ $redirect->from_path }}/</a>
                                @unless ($redirect->is_active)<div class="cell-sub">Switched off</div>@endunless
                            </td>
                            <td data-label="Goes to" class="mono text-sm break" style="max-width:380px">@if ((int) $redirect->status_code === 410)<span class="text-muted">Gone – “page removed”</span>@else{{ $redirect->to_url }}@endif</td>
                            <td data-label="Type" class="hidden-mobile"><x-admin.badge :color="(int) $redirect->status_code === 410 ? 'warning' : (in_array($redirect->status_code, [301, 308]) ? 'gray' : 'info')" size="sm">{{ $redirect->status_code }}</x-admin.badge></td>
                            <td class="num" data-label="Visits">{{ number_format($redirect->hits) }}</td>
                            <td class="nowrap text-muted" data-label="Last visit"><x-admin.time :value="$redirect->last_hit_at" empty="Never" /></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$redirects" />
            @endif
        </x-admin.card>
    @endif

    <x-admin.modal name="import-redirects" title="Import redirects from CSV" :open="$errors->has('file') || $errors->has('mode')">
        <form id="import-redirects-form" method="POST" action="{{ route('admin.redirects.import') }}" enctype="multipart/form-data" class="stack-fields">
            @csrf
            <p class="text-sm text-muted">One redirect per row: <span class="mono">old address, new address, type</span> (type is optional – 301 if left out; 410 = removed for good, leave the new address empty). A header row is fine. Example:</p>
            <pre class="copy-field" style="white-space:pre-wrap">/old-shirts-page/,/clothing/mens-shirts/,301
/summer-sale/,/shop/,302
/discontinued-range/,,410</pre>
            <x-admin.field label="CSV file" for="import-file" error="file" required>
                <input type="file" name="file" id="import-file" accept=".csv,text/csv,text/plain" class="input" required>
            </x-admin.field>
            <x-admin.radio-cards name="mode" value="update" :options="[
                'update' => ['label' => 'Update existing', 'help' => 'Rows for addresses that already redirect replace them'],
                'skip' => ['label' => 'Keep existing', 'help' => 'Only add new addresses'],
            ]" />
        </form>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button type="submit" form="import-redirects-form" variant="primary" icon="arrow-up-tray">Import</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>
@endsection
