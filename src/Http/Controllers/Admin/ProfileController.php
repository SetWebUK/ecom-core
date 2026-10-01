<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Services\Admin\StaffPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The signed-in staff member's own details and password. */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('commerce::admin.profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'current_password' => [Rule::requiredIf(fn () => mb_strtolower(trim((string) $request->input('email'))) !== mb_strtolower($user->email)), 'nullable', 'string'],
        ], [
            'current_password.required' => 'Enter your current password to change your email address.',
        ], ['first_name' => 'first name', 'last_name' => 'last name']);

        $emailChanged = mb_strtolower(trim($data['email'])) !== mb_strtolower($user->email);
        if ($emailChanged && ! StaffPassword::check($user, (string) $data['current_password'])) {
            return back()->withInput($request->except('current_password'))->withErrors(['current_password' => 'That password is not correct.']);
        }

        $user->forceFill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
            'email' => trim($data['email']),
            'phone' => $data['phone'] ?? null,
        ])->save();

        return redirect()->route('admin.profile.edit')->with('success', $emailChanged ? 'Profile saved. Use your new email address next time you sign in.' : 'Profile saved.');
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'max:255', StaffPassword::rule()],
        ], [], ['password' => 'new password']);

        if (! StaffPassword::check($user, $data['current_password'])) {
            return back()->withErrors(['current_password' => 'That password is not correct.'], 'password');
        }

        $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();

        // Keep this session, sign out every other device (database sessions + "remember me" cookies).
        $request->session()->regenerate();
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return redirect()->route('admin.profile.edit')->with('success', 'Password changed. Other devices have been signed out.');
    }
}
