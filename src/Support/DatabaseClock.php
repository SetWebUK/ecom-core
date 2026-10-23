<?php

namespace Pine\Commerce\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Does a MySQL/MariaDB connection read and write TIMESTAMP columns in the app's time zone?
 *
 * Laravel writes wall-clock strings in config('app.timezone') (UTC by default) and MySQL interprets them in the
 * SESSION time zone, which is the server's own ("SYSTEM") unless config/database.php sets 'timezone'. On a server set
 * to UK time every summer timestamp is stored an hour out, and a UTC value inside the hour the clocks skip in March
 * (e.g. 2025-03-30 01:39:36) is refused: "Incorrect datetime value" – which stopped a WooCommerce import mid-way.
 *
 * The session offset is probed in January and July (UNIX_TIMESTAMP reads its argument in the session time zone), so
 * a mismatch is found in winter too, when UK time equals UTC. Fix: php artisan commerce:db:utc.
 */
final class DatabaseClock
{
    /** Why the connection does not keep the app's times, or null (it does, or it is not MySQL/MariaDB). */
    public static function problem(?string $connection = null): ?string
    {
        try {
            $db = DB::connection($connection);
            if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                return null;
            }
            $app = new DateTimeZone((string) config('app.timezone', 'UTC'));
            $year = (int) date('Y');
            foreach (["$year-01-15 12:00:00", "$year-07-15 12:00:00"] as $wall) {
                $session = (int) $db->selectOne('SELECT UNIX_TIMESTAMP(?) AS t', [$wall])->t;
                if ($session !== (new DateTimeImmutable($wall, $app))->getTimestamp()) {
                    return 'the database session time zone ('.static::sessionZone($connection).') is not the app time zone ('
                        .$app->getName().'): TIMESTAMP columns are stored shifted and times in the hour the clocks skip are refused';
                }
            }
        } catch (Throwable) {
            return null; // no connection: the doctor's Connection check reports that
        }

        return null;
    }

    /** The session time zone as MySQL reports it ("SYSTEM (BST)", "+00:00", "Europe/London"). */
    public static function sessionZone(?string $connection = null): string
    {
        $tz = DB::connection($connection)->selectOne('SELECT @@session.time_zone AS s, @@system_time_zone AS y');

        return $tz->s === 'SYSTEM' ? 'SYSTEM ('.$tz->y.')' : (string) $tz->s;
    }

    /** One-line fix for the doctor and the importers. */
    public static function fix(): string
    {
        return 'Back up the database, then php artisan commerce:db:utc --dry-run, php artisan commerce:db:utc, add DB_TIMEZONE=+00:00 to .env '
            ."(config/database.php: 'timezone' => env('DB_TIMEZONE') on the connection) and php artisan config:cache.";
    }
}
