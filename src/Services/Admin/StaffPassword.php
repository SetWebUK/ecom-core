<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\User;
use Pine\Commerce\Support\WpPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Password checks for back-office sign-in and the profile page.
 *
 * Accounts imported from WordPress keep their WordPress hash ($wp$2y$…, phpass $P$…, md5) until the
 * first successful sign-in, when the hash is silently upgraded to Laravel's bcrypt. Passwords are never
 * changed by this class – only re-hashed.
 */
class StaffPassword
{
    /** Rule for new staff passwords (reset form, profile, staff users). */
    public static function rule(): Password
    {
        return Password::min(10)->letters()->numbers();
    }

    public static function check(?User $user, string $plain): bool
    {
        $hash = $user?->getAuthPassword();

        if (! $user || ! $hash) {
            // Burn roughly the same time as a real check so response timing doesn't reveal unknown emails.
            Hash::check($plain, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

            return false;
        }

        $valid = false;
        try {
            $valid = Hash::check($plain, $hash);
        } catch (Throwable) {
            // Not a bcrypt hash (e.g. imported from WordPress) – fall through to the WordPress check.
        }

        if (! $valid && WpPassword::check($plain, $hash)) {
            $valid = true;
            static::rehash($user, $plain);
        } elseif ($valid && static::needsRehash($hash)) {
            static::rehash($user, $plain);
        }

        return $valid;
    }

    protected static function needsRehash(string $hash): bool
    {
        try {
            return Hash::needsRehash($hash);
        } catch (Throwable) {
            return false;
        }
    }

    /** Store a fresh Laravel hash of the same password (no model events fired). */
    protected static function rehash(User $user, string $plain): void
    {
        $user->forceFill(['password' => Hash::make($plain)])->saveQuietly();
    }
}
