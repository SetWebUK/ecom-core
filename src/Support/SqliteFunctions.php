<?php

namespace Pine\Commerce\Support;

use Closure;
use Illuminate\Database\Connection;
use PDO;

/**
 * SQLite compatibility for the few MySQL functions the package's raw SQL uses (search suggestions order by
 * CHAR_LENGTH, the sales report buckets by DATE_FORMAT, the admin search/customer list use CONCAT_WS).
 *
 * The platform targets MySQL/MariaDB in production; SQLite is supported for development, CI and throw-away
 * rehearsals (a file or in-memory database). Every SQLite connection the application opens gets these functions,
 * registered lazily when its PDO is first created (CommerceServiceProvider listens to ConnectionEstablished), so
 * the SQL itself stays exactly the same on MySQL. CONCAT / CONCAT_WS are built into SQLite from 3.44 and only
 * polyfilled below that.
 */
class SqliteFunctions
{
    /** Wrap the connection's (lazy) PDOs so the functions exist before its first query. No-op for other drivers. */
    public static function attach(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }
        foreach (['getRawPdo' => 'setPdo', 'getRawReadPdo' => 'setReadPdo'] as $get => $set) {
            $pdo = $connection->{$get}();
            if ($pdo instanceof PDO) {
                static::register($pdo);
            } elseif ($pdo instanceof Closure) {
                $connection->{$set}(function () use ($pdo) {
                    $resolved = $pdo();
                    if ($resolved instanceof PDO) {
                        static::register($resolved);
                    }

                    return $resolved;
                });
            }
        }
    }

    /** Register the functions on a SQLite PDO (safe to call more than once). */
    public static function register(PDO $pdo): void
    {
        $functions = [
            'CHAR_LENGTH' => [fn ($value) => $value === null ? null : mb_strlen((string) $value), 1],
            'DATE_FORMAT' => [fn ($date, $format) => static::dateFormat($date, $format), 2],
        ];
        $version = (string) $pdo->query('select sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.44.0', '<')) {
            $functions['CONCAT_WS'] = [fn ($separator, ...$parts) => $separator === null ? null
                : implode((string) $separator, array_filter($parts, fn ($p) => $p !== null)), -1];
            $functions['CONCAT'] = [fn (...$parts) => in_array(null, $parts, true) ? null : implode('', $parts), -1];
        }
        foreach ($functions as $name => [$callback, $args]) {
            method_exists($pdo, 'createFunction')
                ? $pdo->createFunction($name, $callback, $args)
                : $pdo->sqliteCreateFunction($name, $callback, $args);
        }
    }

    /** MySQL DATE_FORMAT() for the specifiers the package uses (and the common ones around them). */
    public static function dateFormat(mixed $date, mixed $format): ?string
    {
        if ($date === null || $format === null || ($time = strtotime((string) $date)) === false) {
            return null;
        }
        $map = ['%Y' => 'Y', '%y' => 'y', '%m' => 'm', '%c' => 'n', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%k' => 'G',
            '%i' => 'i', '%s' => 's', '%S' => 's', '%x' => 'o', '%v' => 'W', '%M' => 'F', '%b' => 'M', '%a' => 'D',
            '%W' => 'l', '%j' => 'z', '%%' => '%'];

        return preg_replace_callback('/%.|[^%]+/', function ($m) use ($map, $time) {
            if (isset($map[$m[0]])) {
                return $m[0] === '%j' ? sprintf('%03d', (int) date('z', $time) + 1) : date($map[$m[0]], $time);
            }

            return $m[0];
        }, (string) $format);
    }
}
