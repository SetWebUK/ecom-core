<?php

namespace Pine\Commerce\Http\Requests\Admin\Auth;

use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Admin\StaffPassword;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Back-office sign-in. Throttled to 5 attempts a minute per email + IP (and 20 per IP overall).
 * Only active staff (role admin|manager) may sign in; WordPress password hashes are accepted and upgraded.
 */
class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const MAX_ATTEMPTS_PER_IP = 20;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /** Validate the credentials and return the staff user (does not log them in). */
    public function authenticateStaff(): User
    {
        $this->ensureIsNotRateLimited();

        $user = User::where('email', $this->string('email')->trim()->value())->first();

        if (! StaffPassword::check($user, (string) $this->input('password'))) {
            $this->hit();

            throw ValidationException::withMessages(['email' => 'Those details don’t match a staff account. Check your email and password.']);
        }

        if (! $user->isStaff()) {
            $this->hit();

            throw ValidationException::withMessages(['email' => 'This is a customer account, so it can’t open the back office. Customers sign in on the shop’s “My account” page.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This staff account has been deactivated. Ask an administrator to re-enable it.']);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    protected function hit(): void
    {
        RateLimiter::hit($this->throttleKey(), 60);
        RateLimiter::hit($this->ipThrottleKey(), 60);
    }

    protected function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP) => $this->ipThrottleKey(),
            default => null,
        };
        if ($key === null) {
            return;
        }

        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => "Too many sign-in attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    public function throttleKey(): string
    {
        return 'admin-login:'.Str::transliterate(Str::lower($this->string('email')->trim()->value())).'|'.$this->ip();
    }

    protected function ipThrottleKey(): string
    {
        return 'admin-login-ip:'.$this->ip();
    }
}
