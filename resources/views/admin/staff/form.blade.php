{{-- Add / edit a staff account (administrators only). --}}
@extends('commerce::admin.layouts.app')

@php
    $editing = $member->exists;
    $isSelf = $isSelf ?? false;
    $lastAdmin = $lastAdmin ?? false;
    $saveLabel = $editing ? 'Save' : 'Add staff';
    $roles = [
        'admin' => ['label' => 'Administrator', 'help' => 'Everything, including payments and staff', 'icon' => 'shield-check'],
        'manager' => ['label' => 'Shop manager', 'help' => 'Orders, products, content and settings', 'icon' => 'user'],
    ];
    if ($editing && ! $isSelf) {
        $roles['customer'] = ['label' => 'No access', 'help' => 'Turn back into a customer account', 'icon' => 'no-symbol'];
    }
@endphp

@section('title', $editing ? $member->full_name.' · Staff' : 'Add staff')

@section('content')
    <x-admin.page-header :title="$editing ? $member->full_name : 'Add staff'" :subtitle="$editing ? $member->email : null" :back="route('admin.staff.index')" back-label="Back to staff accounts">
        @if ($editing)
            <x-slot:badges>
                <x-admin.badge :color="$member->role === 'admin' ? 'dark' : 'gray'">{{ Pine\Commerce\Models\User::ROLES[$member->role] ?? $member->role }}</x-admin.badge>
                @unless ($member->is_active)<x-admin.badge color="warning">Switched off</x-admin.badge>@endunless
                @if ($isSelf)<x-admin.badge color="info">You</x-admin.badge>@endif
            </x-slot:badges>
        @endif
    </x-admin.page-header>

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'staff'])
        <div class="stack">
            <x-admin.form id="staff-form" :action="$editing ? route('admin.staff.update', $member) : route('admin.staff.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
                <div class="stack">
                    <x-admin.card title="Details">
                        <div class="form-grid">
                            <x-admin.input name="first_name" label="First name" :value="$member->first_name ?: $member->name" required maxlength="100" :autofocus="! $editing" autocomplete="off" />
                            <x-admin.input name="last_name" label="Last name" :value="$member->last_name" optional maxlength="100" autocomplete="off" />
                            <x-admin.input name="email" type="email" label="Email" :value="$member->email" required maxlength="190" autocomplete="off" help="They sign in with this address." />
                            <x-admin.input name="phone" type="tel" label="Phone" :value="$member->phone" optional maxlength="40" autocomplete="off" />
                        </div>
                    </x-admin.card>

                    <x-admin.card title="Access">
                        <div class="stack-fields">
                            @if ($isSelf)
                                <input type="hidden" name="role" value="{{ $member->role }}">
                                <input type="hidden" name="is_active" value="1">
                                <x-admin.callout type="neutral">You can’t change your own role or switch off your own account – ask another administrator.</x-admin.callout>
                            @else
                                <x-admin.radio-cards name="role" :value="old('role', $member->role)" :options="$roles" />
                                @if ($lastAdmin)
                                    <p class="text-sm text-muted">This is the only active administrator, so they must stay an administrator.</p>
                                @endif
                                <x-admin.toggle name="is_active" label="Can sign in" help="Switch off to block access straight away (they are signed out). Nothing is deleted." :checked="$member->is_active ?? true" />
                            @endif
                        </div>
                    </x-admin.card>

                    @unless ($editing)
                        <x-admin.card title="Password">
                            <div class="stack-fields" x-data="{ mode: @js(old('password_mode', 'invite')) }">
                                <x-admin.radio-cards name="password_mode" :value="old('password_mode', 'invite')" x-model="mode" :options="[
                                    'invite' => ['label' => 'Email them a link', 'help' => 'They choose their own password (recommended)', 'icon' => 'envelope'],
                                    'set' => ['label' => 'Set a password now', 'help' => 'Tell them the password yourself', 'icon' => 'key'],
                                ]" />
                                <div class="form-grid" x-show="mode === 'set'" x-cloak>
                                    <x-admin.input name="password" type="password" label="Password" autocomplete="new-password" help="At least 10 characters with letters and numbers." x-bind:disabled="mode !== 'set'" />
                                    <x-admin.input name="password_confirmation" type="password" label="Type it again" autocomplete="new-password" x-bind:disabled="mode !== 'set'" />
                                </div>
                            </div>
                        </x-admin.card>
                    @endunless
                </div>
            </x-admin.form>

            <div class="form-actions">
                <span class="flex-1"></span>
                <x-admin.button :href="route('admin.staff.index')">Cancel</x-admin.button>
                <x-admin.button type="submit" form="staff-form" variant="primary">{{ $saveLabel }}</x-admin.button>
            </div>

            @if ($editing)
                <x-admin.card title="Password" subtitle="Staff can change their own password on their profile page.">
                    <div class="stack">
                        <div class="row row--between">
                            <div>
                                <p class="text-sm fw-600">Send a password link</p>
                                <p class="text-sm text-muted">Emails {{ $member->email }} a link to choose a new password.</p>
                            </div>
                            <form method="POST" action="{{ route('admin.staff.password', $member) }}" data-confirm="{{ $member->email }} will get an email with a link to choose a new password. Their current password keeps working until they do." data-confirm-title="Send a password link?" data-confirm-button="Send link" data-confirm-danger="false">
                                @csrf
                                <input type="hidden" name="mode" value="link">
                                <x-admin.button type="submit" icon="envelope">Send password link</x-admin.button>
                            </form>
                        </div>
                        <div class="divider" style="margin:0"></div>
                        <form method="POST" action="{{ route('admin.staff.password', $member) }}" class="stack-fields"
                              data-confirm="{{ $isSelf ? 'Your password will change.' : $member->full_name.' will be signed out and must use the new password.' }}" data-confirm-title="Set a new password?" data-confirm-button="Set password" data-confirm-danger="false">
                            @csrf
                            <input type="hidden" name="mode" value="set">
                            <p class="text-sm fw-600">Or set a new password</p>
                            <div class="form-grid">
                                <x-admin.input name="password" type="password" label="New password" bag="password" autocomplete="new-password" help="At least 10 characters with letters and numbers." />
                                <x-admin.input name="password_confirmation" type="password" label="Type it again" bag="password" autocomplete="new-password" />
                            </div>
                            <div><x-admin.button type="submit" icon="key">Set password</x-admin.button></div>
                        </form>
                    </div>
                </x-admin.card>

                <x-admin.card title="Activity">
                    <dl class="kv">
                        <dt>Last signed in</dt><dd><x-admin.time :value="$member->last_login_at" format="datetime" empty="Never" /></dd>
                        <dt>Account created</dt><dd><x-admin.time :value="$member->created_at" format="datetime" /></dd>
                    </dl>
                </x-admin.card>
            @endif
        </div>
    </div>
@endsection
