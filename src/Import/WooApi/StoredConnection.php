<?php

namespace Pine\Commerce\Import\WooApi;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Pine\Commerce\Models\Setting;

/**
 * The connection saved in Admin › Import › WooCommerce API (settings group "woo_api"): site URL, authentication
 * mode, TLS switch, WordPress user name in clear; consumer key, consumer secret and the application password
 * encrypted with the app key (Crypt::encryptString) and never sent back to the browser – the form only learns
 * whether one is saved; an empty box keeps the saved value (same pattern as the payment gateway secrets).
 */
final class StoredConnection
{
    public const SECRETS = ['key', 'secret', 'wp_password'];

    public static function load(): Connection
    {
        return Connection::make(self::values());
    }

    /** Decrypted values. @return array<string,mixed> */
    public static function values(): array
    {
        $out = [
            'url' => (string) Setting::get('woo_api.url', ''),
            'wp_user' => (string) Setting::get('woo_api.wp_user', ''),
            'auth' => (string) Setting::get('woo_api.auth', 'auto'),
            'verify_tls' => Setting::get('woo_api.verify_tls', true) !== false && Setting::get('woo_api.verify_tls', true) !== '0',
            'store' => Setting::get('woo_api.mode', 'rest') === 'store',
        ];
        foreach (self::SECRETS as $field) {
            $out[$field] = self::decrypt(Setting::get('woo_api.'.$field));
        }

        return $out;
    }

    /** For the form: plain fields + which secrets are saved (true/false), never the secrets. */
    public static function form(): array
    {
        $values = self::values();
        foreach (self::SECRETS as $field) {
            $values[$field] = $values[$field] !== '';
        }

        return $values;
    }

    /**
     * Save the form. Secrets: a non-empty value replaces the saved one, an empty one keeps it, "{field}_clear" removes it.
     *
     * @param  array<string,mixed>  $input
     */
    public static function save(array $input): void
    {
        Setting::set('woo_api.url', Connection::normaliseUrl((string) ($input['url'] ?? '')), 'woo_api');
        Setting::set('woo_api.wp_user', trim((string) ($input['wp_user'] ?? '')), 'woo_api');
        $auth = (string) ($input['auth'] ?? 'auto');
        Setting::set('woo_api.auth', in_array($auth, Connection::AUTH_MODES, true) ? $auth : 'auto', 'woo_api');
        Setting::set('woo_api.verify_tls', (bool) ($input['verify_tls'] ?? true), 'woo_api');
        Setting::set('woo_api.mode', ($input['mode'] ?? 'rest') === 'store' ? 'store' : 'rest', 'woo_api');
        foreach (self::SECRETS as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($field === 'wp_password') {
                $value = preg_replace('/\s+/', '', $value) ?? '';
            }
            if ($value !== '') {
                Setting::set('woo_api.'.$field, Crypt::encryptString($value), 'woo_api');
            } elseif (! empty($input[$field.'_clear'])) {
                Setting::set('woo_api.'.$field, '', 'woo_api');
            }
        }
    }

    private static function decrypt(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return ''; // saved with another APP_KEY – enter it again
        }
    }
}
