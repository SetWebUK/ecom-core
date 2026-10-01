<?php

namespace Pine\Commerce\Http\Controllers\Admin\Auth;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\User;
use Pine\Commerce\Notifications\AdminResetPassword;
use Pine\Commerce\Services\Admin\StaffPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Forgotten password flow for staff. Uses Laravel's default password broker (same token table as the
 * storefront) but sends its own notification so the link opens the admin reset page. Only active staff
 * accounts can request or complete a reset here; the response never reveals whether an email exists.
 */
class PasswordResetController extends Controller
{
    public const SENT_MESSAGE = 'If that email belongs to a staff account, we’ve sent it a link to reset the password. Check your inbox (and spam folder).';

    public function request(): View
    {
        return view('commerce::admin.auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $user = User::where('email', trim($data['email']))->first();

        if ($user?->canAccessAdmin()) {
            $status = Password::broker()->sendResetLink(
                ['email' => $user->email],
                fn (User $user, string $token) => $user->notify(new AdminResetPassword($token)),
            );

            if ($status === Password::RESET_THROTTLED) {
                return back()->withInput()->withErrors(['email' => 'A reset link was sent very recently. Please wait a minute before asking for another.']);
            }
        }

        return back()->with('status', self::SENT_MESSAGE);
    }

    public function edit(Request $request, string $token): View
    {
        return view('commerce::admin.auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', StaffPassword::rule()],
        ], [], ['password' => 'new password']);

        $user = User::where('email', trim($data['email']))->first();
        if (! $user?->canAccessAdmin()) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'This reset link is invalid or has expired. Request a new one.']);
        }

        $status = Password::broker()->reset(
            ['email' => $user->email, 'password' => $data['password'], 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Sign the account out everywhere (someone else may be using the old password)
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'This reset link is invalid or has expired. Request a new one.']);
        }

        return redirect()->route('admin.login')->with('status', 'Your password has been changed. You can sign in now.');
    }
}
