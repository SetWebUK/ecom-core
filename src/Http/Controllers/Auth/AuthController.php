<?php

namespace Pine\Commerce\Http\Controllers\Auth;

use Pine\Commerce\Support\Features;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Mail\CustomerNewAccount;
use Pine\Commerce\Models\User;
use Pine\Commerce\Notifications\CustomerResetPassword;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Support\WpPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Storefront sign in / registration / lost password (WooCommerce "My account" forms).
 *
 * Passwords imported from WordPress ($wp$2y$, phpass $P$, md5) are accepted once and replaced with a
 * Laravel hash. Customers imported without a password are pointed at "Lost your password?".
 * Staff can sign in here too and stay on the storefront (the back office has its own /admin/login).
 */
class AuthController extends Controller
{
    protected const MAX_ATTEMPTS = 5;

    protected const DECAY_SECONDS = 300;

    public function login(Request $request, Cart $cart)
    {
        $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:4096'],
        ], [
            'username.required' => 'Error: Username is required.',
            'password.required' => 'Error: The password field is empty.',
        ]);

        $login = trim((string) $request->input('username'));
        $key = 'storefront-login:'.sha1(mb_strtolower($login).'|'.$request->ip());
        $ipKey = 'storefront-login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $seconds = max(RateLimiter::availableIn($key), RateLimiter::tooManyAttempts($ipKey, 30) ? RateLimiter::availableIn($ipKey) : 0);
            throw ValidationException::withMessages([
                'username' => sprintf('Too many failed login attempts. Please try again in %d minute%s.', ceil($seconds / 60), ceil($seconds / 60) == 1 ? '' : 's'),
            ])->redirectTo($this->back($request));
        }

        $user = $this->findUser($login);
        $error = null;
        if (! $user) {
            $error = str_contains($login, '@')
                ? 'Unknown email address. Check again or try your username.'
                : 'Error: That username is not registered on this site. If you are unsure of your username, try your email address instead.';
        } elseif ($user->password === null || $user->password === '') {
            $error = 'Our store has moved to a new system, so please set a new password: use “Lost your password?” below and we will email you a link.';
        } elseif (! $this->checkPassword($user, (string) $request->input('password'))) {
            $error = 'Error: The password you entered for that '.(str_contains($login, '@') ? 'email address' : 'username').' is incorrect. Lost your password?';
        } elseif (! $user->is_active) {
            $error = 'Your account has been disabled. Please contact us if you need help.';
        }

        if ($error) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['username' => $error])->redirectTo($this->back($request));
        }

        RateLimiter::clear($key);
        Auth::login($user, $request->boolean('rememberme'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $cart->reset(true); // the guest basket is merged into the customer's basket on the next read

        return redirect()->to($this->redirectTarget($request));
    }

    public function register(Request $request)
    {
        if (! static::registrationEnabled()) {
            abort(404);
        }
        $key = 'storefront-register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many registration attempts. Please try again later.'])->redirectTo(route('account'));
        }
        RateLimiter::hit($key, 3600);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ], [
            'email.required' => 'Error: Please provide a valid email address.',
            'email.email' => 'Error: Please provide a valid email address.',
            'password.required' => 'Error: Please enter an account password.',
            'password.min' => 'Error: Please enter a password of at least 8 characters.',
        ]);
        if (User::whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Error: An account is already registered with your email address. Please log in.',
            ])->redirectTo(route('account'));
        }

        $user = new User;
        $user->forceFill([
            'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: Str::before($data['email'], '@'),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => mb_strtolower($data['email']),
            'role' => 'customer',
            'is_active' => true,
            'password' => $data['password'],
        ])->save();

        try {
            Mail::to($user->email)->send(new CustomerNewAccount($user));
        } catch (\Throwable $e) {
            Log::warning('Welcome email failed for '.$user->email.': '.$e->getMessage());
        }

        Auth::login($user);
        $request->session()->regenerate();
        app(Cart::class)->reset(true);

        return redirect()->to($this->redirectTarget($request));
    }

    public function logout(Request $request, Cart $cart)
    {
        // GET /my-account/customer-logout/ needs the per-session token (WooCommerce's nonce check)
        if ($request->isMethod('GET') && Auth::check() && ! hash_equals(static::logoutToken(), is_string($request->query('_token_logout')) ? $request->query('_token_logout') : '')) {
            return response(theme_view('account.logout-confirm'));
        }

        Auth::logout();
        $cart->forgetCookie();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('account'));
    }

    /** Per-session token for the GET logout link (keeps the CSRF token out of URLs). */
    public static function logoutToken(): string
    {
        return substr(hash_hmac('sha256', 'logout|'.session()->getId(), (string) config('app.key')), 0, 20);
    }

    public static function logoutUrl(): string
    {
        return route('logout.get', ['_token_logout' => static::logoutToken()]);
    }

    /** Customers may register: feature switch "registration" (config) AND Settings › Checkout › "Customers can create an account". */
    public static function registrationEnabled(): bool
    {
        return Features::enabled('registration', false) && filter_var(setting('account.registration', true), FILTER_VALIDATE_BOOL);
    }

    // ------------------------------------------------------------------ lost / reset password

    public function showForgot(Request $request)
    {
        if (Auth::check()) {
            return redirect()->to(route('account.details'));
        }

        return theme_view('auth.lost-password', ['sent' => session('reset_sent', false)]);
    }

    public function sendReset(Request $request)
    {
        $request->validate(['user_login' => ['required', 'string', 'max:190']], ['user_login.required' => 'Enter a username or email address.']);
        $key = 'storefront-reset:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['user_login' => 'Too many password reset requests. Please try again in a few minutes.']);
        }
        RateLimiter::hit($key, 300);

        $user = $this->findUser(trim((string) $request->input('user_login')));
        if ($user && $user->is_active) {
            $status = Password::broker()->sendResetLink(
                ['email' => $user->email],
                fn (User $u, string $token) => $u->notify(new CustomerResetPassword($token)),
            );
            if ($status !== Password::RESET_LINK_SENT && $status !== Password::RESET_THROTTLED) {
                Log::info('Password reset link not sent for user '.$user->id.': '.$status);
            }
        }

        // Same answer whether or not the account exists (no account enumeration)
        return redirect()->to(route('password.request'))->with('reset_sent', true);
    }

    public function showReset(Request $request, string $token)
    {
        return theme_view('auth.reset-password', [
            'token' => $token,
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'password.required' => 'Please enter your password.',
            'password.min' => 'Please enter a password of at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['password' => 'This key is invalid or has already been used. Please reset your password again if needed.']);
        }

        return redirect()->to(route('account'))->with('account_message', 'Your password has been reset successfully.');
    }

    // ------------------------------------------------------------------ helpers

    protected function findUser(string $login): ?User
    {
        if ($login === '') {
            return null;
        }
        if (str_contains($login, '@')) {
            return User::whereRaw('LOWER(email) = ?', [mb_strtolower($login)])->first();
        }
        $matches = User::where('name', $login)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Laravel hash first, then the legacy WordPress formats (rehashed on success). */
    protected function checkPassword(User $user, string $password): bool
    {
        $hash = (string) $user->password;
        $algo = password_get_info($hash)['algoName'] ?? 'unknown';
        if ($algo !== 'unknown' && ! str_starts_with($hash, '$wp')) {
            try {
                if (Hash::check($password, $hash)) {
                    if (Hash::needsRehash($hash)) {
                        $user->forceFill(['password' => Hash::make($password)])->saveQuietly();
                    }

                    return true;
                }
            } catch (\RuntimeException) {
                // hash made by another algorithm than the current driver - fall through
            }
        }

        if (WpPassword::check($password, $hash)) {
            $user->forceFill(['password' => Hash::make($password)])->saveQuietly();

            return true;
        }

        return false;
    }

    protected function redirectTarget(Request $request): string
    {
        $target = is_string($request->input('redirect')) ? $request->input('redirect') : '';
        // site-relative paths only: no //host, /\host (browsers read \ as /), whitespace/control characters or /admin
        if (preg_match('#^/(?![/\\\\])[^\\\\\s\x00-\x1f]*$#', $target) && ! str_starts_with($target, '/admin')) {
            return url($target);
        }

        return route('account');
    }

    protected function back(Request $request): string
    {
        $target = is_string($request->input('redirect')) ? $request->input('redirect') : '';

        if (str_starts_with($target, '/checkout/order-pay/')) {
            return route('account', ['redirect' => $target]); // keep the way back to the payment form
        }

        return str_starts_with($target, '/checkout') ? url('checkout') : route('account');
    }
}
