@extends('commerce::admin.layouts.app', ['width' => 'narrow'])

@section('title', 'Your profile')

@section('content')
    <x-admin.page-header title="Your profile" :subtitle="(Pine\Commerce\Models\User::ROLES[$user->role] ?? ucfirst($user->role)).' · Last signed in '.($user->last_login_at ? \Pine\Commerce\View\Components\Admin\Ui::smartDate($user->last_login_at) : 'never')" />

    <div class="stack">
        <x-admin.form :action="route('admin.profile.update')" method="PUT" dirty id="profile-form">
            <x-admin.card title="Details">
                <div class="stack-fields">
                    <div class="form-grid">
                        <x-admin.input name="first_name" label="First name" :value="$user->first_name ?: \Illuminate\Support\Str::before($user->name, ' ')" required autocomplete="given-name" />
                        <x-admin.input name="last_name" label="Last name" :value="$user->last_name" autocomplete="family-name" />
                    </div>
                    <x-admin.input name="email" type="email" label="Email" :value="$user->email" required autocomplete="email" help="You sign in with this address." />
                    <x-admin.input name="phone" type="tel" label="Phone" optional :value="$user->phone" autocomplete="tel" />
                    <x-admin.input name="current_password" type="password" label="Current password" autocomplete="current-password" help="Only needed if you change your email address." />
                </div>
                <x-slot:footer>
                    <x-admin.button type="submit" variant="primary">Save details</x-admin.button>
                </x-slot:footer>
            </x-admin.card>
        </x-admin.form>

        <x-admin.form :action="route('admin.profile.password')" method="PUT" id="password-form">
            <x-admin.card title="Change password" subtitle="At least 10 characters, with letters and numbers. Other devices will be signed out.">
                <div class="stack-fields">
                    <x-admin.input name="current_password" id="f-pw-current" type="password" label="Current password" autocomplete="current-password" required bag="password" />
                    <x-admin.input name="password" type="password" label="New password" autocomplete="new-password" required bag="password" />
                    <x-admin.input name="password_confirmation" type="password" label="Confirm new password" autocomplete="new-password" required bag="password" />
                </div>
                <x-slot:footer>
                    <x-admin.button type="submit" variant="primary">Change password</x-admin.button>
                </x-slot:footer>
            </x-admin.card>
        </x-admin.form>
    </div>
@endsection
