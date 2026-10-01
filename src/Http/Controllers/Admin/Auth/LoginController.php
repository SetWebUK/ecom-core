<?php

namespace Pine\Commerce\Http\Controllers\Admin\Auth;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()?->canAccessAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return view('commerce::admin.auth.login', [
            'signedInCustomer' => $request->user(), // a customer is signed in on the shop
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticateStaff();

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->to($this->intendedAdminUrl($request))
            ->with('success', 'Welcome back, '.($user->first_name ?: $user->name).'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'You have been signed out.');
    }

    /** Honour the page the user was trying to reach, but only if it is inside the back office. */
    protected function intendedAdminUrl(Request $request): string
    {
        $intended = (string) $request->session()->pull('url.intended', '');
        $adminRoot = url('/admin');

        if ($intended !== '' && (str_starts_with($intended, $adminRoot.'/') || $intended === $adminRoot)) {
            return $intended;
        }

        return route('admin.dashboard');
    }
}
