@extends('account.frame')

@section('account_content')
<h1 class="page-title">Account details</h1>
<form class="form card" action="{{ route('account.details.save') }}" method="post" novalidate>
    @csrf
    <div class="form-grid">
        <div class="field"><label for="account_first_name">First name <span class="req" aria-hidden="true">*</span></label><input type="text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="{{ old('account_first_name', $user->first_name) }}" required></div>
        <div class="field"><label for="account_last_name">Last name <span class="req" aria-hidden="true">*</span></label><input type="text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="{{ old('account_last_name', $user->last_name) }}" required></div>
        <div class="field field--full"><label for="account_display_name">Display name <span class="req" aria-hidden="true">*</span></label><input type="text" name="account_display_name" id="account_display_name" value="{{ old('account_display_name', $user->name) }}" required><p class="field__help">How your name appears in reviews.</p></div>
        <div class="field field--full"><label for="account_email">Email address <span class="req" aria-hidden="true">*</span></label><input type="email" name="account_email" id="account_email" autocomplete="email" value="{{ old('account_email', $user->email) }}" required></div>
    </div>
    <fieldset class="fieldset">
        <legend>Change password</legend>
        <div class="form-grid">
            <div class="field field--full"><label for="password_current">Current password <span class="opt">(leave blank to keep it)</span></label><input type="password" name="password_current" id="password_current" autocomplete="current-password"></div>
            <div class="field"><label for="password_1">New password</label><input type="password" name="password_1" id="password_1" autocomplete="new-password" minlength="8"></div>
            <div class="field"><label for="password_2">Confirm new password</label><input type="password" name="password_2" id="password_2" autocomplete="new-password"></div>
        </div>
    </fieldset>
    <button type="submit" class="btn btn--primary" name="save_account_details" value="Save changes">Save changes</button>
</form>
@endsection
