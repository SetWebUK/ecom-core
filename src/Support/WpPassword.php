<?php

namespace Pine\Commerce\Support;

/**
 * Verifies passwords against hashes imported from WordPress, so customers and staff can log in with
 * their existing password. After a successful check the caller should rehash with Laravel's Hash
 * facade and store the new hash (see needsRehash()).
 *
 * Supported formats (mirrors wp_check_password() in WordPress 6.8+):
 *  - "$wp$2y$..."  WP 6.8+ bcrypt of base64(HMAC-SHA384(password, 'wp-sha384'))
 *  - "$P$" / "$H$" phpass portable hashes (WordPress < 6.8, phpBB)
 *  - 32-char md5 hex (very old WordPress)
 *  - anything password_verify() understands (plain bcrypt "$2y$", argon2)
 */
class WpPassword
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function check(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || strlen($plain) > 4096) {
            return false;
        }

        if (str_starts_with($hash, '$wp')) {
            // wp_hash_password() trims before hashing; wp_check_password() does not – accept either.
            foreach (array_unique([$plain, trim($plain)]) as $candidate) {
                $prehashed = base64_encode(hash_hmac('sha384', $candidate, 'wp-sha384', true));
                if (password_verify($prehashed, substr($hash, 3))) {
                    return true;
                }
            }

            return false;
        }

        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return hash_equals($hash, self::phpassPortable($plain, $hash));
        }

        if (strlen($hash) <= 32) {
            return hash_equals($hash, md5($plain));
        }

        return password_verify($plain, $hash);
    }

    /** True when the stored hash is a WordPress format that should be replaced by a Laravel hash. */
    public static function isWordPressHash(?string $hash): bool
    {
        return $hash !== null && (str_starts_with($hash, '$wp') || str_starts_with($hash, '$P$')
            || str_starts_with($hash, '$H$') || (strlen($hash) <= 32 && $hash !== ''));
    }

    /** phpass "portable" algorithm: iterated salted md5, custom base64 encoding. */
    private static function phpassPortable(string $password, string $setting): string
    {
        $failure = str_starts_with($setting, '*0') ? '*1' : '*0';

        $countLog2 = strpos(self::ITOA64, $setting[3] ?? '');
        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return $failure;
        }
        $salt = substr($setting, 4, 8);
        if (strlen($salt) !== 8) {
            return $failure;
        }

        $count = 1 << $countLog2;
        $hash = md5($salt.$password, true);
        do {
            $hash = md5($hash.$password, true);
        } while (--$count);

        return substr($setting, 0, 12).self::encode64($hash, 16);
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3F];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }
}
