<?php

namespace Pine\Commerce\Import\Source;

/**
 * Reads the database settings of a WordPress install from wp-config.php WITHOUT executing it: the file is tokenised
 * (token_get_all – a pure parser) to drop comments, then define('DB_*', '…') and $table_prefix = '…' are matched
 * with regular expressions. Values that are not string literals (getenv(), constants, concatenation) are reported
 * as unresolved so the caller can ask for explicit --db-* options.
 */
final class WpConfig
{
    public const KEYS = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE'];

    /**
     * @return array{DB_NAME:?string,DB_USER:?string,DB_PASSWORD:?string,DB_HOST:?string,DB_CHARSET:?string,DB_COLLATE:?string,table_prefix:?string,unresolved:list<string>}
     */
    public static function parse(string $php): array
    {
        $code = self::stripComments($php);
        $out = array_fill_keys(self::KEYS, null) + ['table_prefix' => null, 'unresolved' => []];

        foreach (self::KEYS as $key) {
            // define( 'DB_NAME' , 'value' ); – a single or double quoted literal. The first definition counts, like PHP
            // (a redefinition only raises a warning). Anything else (getenv(), concatenation) is "unresolved".
            $pattern = '/\bdefine\s*\(\s*([\'"])'.$key.'\1\s*,\s*(?:([\'"])((?:\\\\.|(?!\2).)*)\2|([^;]*?))\s*\)\s*;/s';
            if (preg_match($pattern, $code, $m)) {
                if (($m[2] ?? '') !== '') {
                    $out[$key] = self::unquote($m[3], $m[2]);
                } else {
                    $out['unresolved'][] = $key;
                }
            }
        }
        if (preg_match('/\$table_prefix\s*=\s*(?:([\'"])((?:\\\\.|(?!\1).)*)\1|([^;]*))\s*;/s', $code, $m)) {
            if (($m[1] ?? '') !== '') {
                $out['table_prefix'] = self::unquote($m[2], $m[1]);
            } else {
                $out['unresolved'][] = 'table_prefix';
            }
        }

        return $out;
    }

    /**
     * wp-config.php of a WordPress root: {path}/wp-config.php, else one directory up when that file is not part of
     * another install (WordPress' own lookup rule).
     */
    public static function locate(string $wpPath): ?string
    {
        $wpPath = rtrim($wpPath, '/');
        if (is_file($wpPath.'/wp-config.php')) {
            return $wpPath.'/wp-config.php';
        }
        $up = dirname($wpPath);
        if (is_file($up.'/wp-config.php') && ! is_file($up.'/wp-settings.php')) {
            return $up.'/wp-config.php';
        }

        return null;
    }

    public static function fromPath(string $wpPath): ?array
    {
        $file = self::locate($wpPath);

        return $file ? self::parse((string) file_get_contents($file)) + ['file' => $file] : null;
    }

    /**
     * DB_HOST forms: "host", "host:3307", "host:/path/mysql.sock", ":/path/mysql.sock", "[::1]:3307".
     *
     * @return array{host:string,port:?string,socket:?string}
     */
    public static function splitHost(?string $host): array
    {
        $host = trim((string) $host);
        $out = ['host' => $host === '' ? 'localhost' : $host, 'port' => null, 'socket' => null];
        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $host, $m)) {
            return ['host' => $m[1], 'port' => $m[2] ?? null, 'socket' => null];
        }
        if (substr_count($host, ':') === 1) {
            [$h, $rest] = explode(':', $host, 2);
            $out['host'] = $h === '' ? 'localhost' : $h;
            if (ctype_digit($rest)) {
                $out['port'] = $rest;
            } elseif ($rest !== '') {
                $out['socket'] = $rest;
            }
        }

        return $out;
    }

    private static function stripComments(string $php): string
    {
        if (! str_contains($php, '<?')) {
            $php = "<?php\n".$php;
        }
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= ' ';

                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    private static function unquote(string $value, string $quote): string
    {
        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
        }

        return stripcslashes($value);
    }
}
