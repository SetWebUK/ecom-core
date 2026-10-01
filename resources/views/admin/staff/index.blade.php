{{-- Settings › Staff accounts (administrators only). --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Staff accounts · Settings')

@section('content')
    <x-admin.page-header title="Staff accounts" subtitle="People who can sign in to this back office." :back="route('admin.settings.index')" back-label="All settings">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="user-plus" :href="route('admin.staff.create')">Add staff</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'staff'])
        <div class="stack">
            <x-admin.card flush>
                <x-admin.status-tabs :tabs="$tabs" :current="$status" />
                @if ($staff->isEmpty())
                    <x-admin.empty icon="user-group" title="Nobody here" description="No staff accounts match this filter." size="sm" />
                @else
                    <x-admin.table stack>
                        <x-slot:head>
                            <x-admin.th>Name</x-admin.th>
                            <x-admin.th>Role</x-admin.th>
                            <x-admin.th>Last signed in</x-admin.th>
                        </x-slot:head>
                        @foreach ($staff as $member)
                            <tr @class(['is-muted' => ! $member->is_active])>
                                <td class="stack-title">
                                    <a href="{{ route('admin.staff.edit', $member) }}" class="row-link">{{ $member->full_name }}</a>
                                    @if ($member->is(auth()->user()))<x-admin.badge color="info" size="sm">You</x-admin.badge>@endif
                                    <div class="cell-sub">{{ $member->email }}</div>
                                </td>
                                <td data-label="Role">
                                    <x-admin.badge :color="$member->role === 'admin' ? 'dark' : 'gray'">{{ Pine\Commerce\Models\User::ROLES[$member->role] ?? $member->role }}</x-admin.badge>
                                    @unless ($member->is_active)<x-admin.badge color="warning">Switched off</x-admin.badge>@endunless
                                </td>
                                <td class="nowrap text-muted" data-label="Last signed in"><x-admin.time :value="$member->last_login_at" empty="Never" /></td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif
            </x-admin.card>
            <x-admin.callout type="neutral" title="Roles">
                <strong>Administrators</strong> can do everything, including payment settings and staff accounts.
                <strong>Shop managers</strong> can run the shop day to day – orders, products, customers, content and settings – but can’t see payment keys or manage staff.
            </x-admin.callout>
        </div>
    </div>
@endsection
