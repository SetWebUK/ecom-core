<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Pine\Commerce\Support\DatabaseClock;
use RuntimeException;
use Throwable;

/**
 * Re-stores every TIMESTAMP value that was written in the server's time zone (session time zone SYSTEM, see
 * Support\DatabaseClock) so it reads the same once the connection runs in UTC – the step before DB_TIMEZONE=+00:00:
 *
 *   php artisan down
 *   php artisan commerce:db:utc --dry-run     every table converted, checked and rolled back
 *   php artisan commerce:db:utc
 *   DB_TIMEZONE=+00:00 in .env (config/database.php: 'timezone' => env('DB_TIMEZONE')), php artisan config:cache
 *   php artisan up
 *
 * Per table, in one transaction: the rows are read in the current session time zone, written back in +00:00 and the
 * table's checksum (count + CRC32 of every row's timestamps) must read the same before and after, else it is rolled
 * back. Only tables with the connection's prefix; NULL and zero dates are left alone. A marker in `settings` stops a
 * second run, which would shift everything again.
 */
class DatabaseUtcCommand extends Command
{
    protected $signature = 'commerce:db:utc
        {--connection= : Connection to convert (default: database.default)}
        {--dry-run : Convert and check every table, then roll each one back}
        {--force : Do not ask for confirmation}';

    protected $description = 'Re-store TIMESTAMP columns written in the server time zone as UTC (before DB_TIMEZONE=+00:00)';

    public const MARKER_GROUP = 'system';

    public const MARKER_KEY = 'database_timestamps_utc';

    public function handle(): int
    {
        $name = (string) ($this->option('connection') ?: config('database.default'));
        if (! config("database.connections.{$name}")) {
            $this->error("Unknown connection '{$name}'.");

            return self::FAILURE;
        }
        $db = DB::connection($name);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('Only MySQL/MariaDB connections keep TIMESTAMP columns in a session time zone – nothing to convert.');

            return self::FAILURE;
        }
        if (config('app.timezone') !== 'UTC') {
            $this->error('app.timezone is '.config('app.timezone').' – this command converts to UTC only.');

            return self::FAILURE;
        }
        if (! DatabaseClock::problem($name)) {
            $this->components->info('Nothing to do: the connection already keeps the app\'s UTC times (session '.DatabaseClock::sessionZone($name).').');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        $pdo = $db->getPdo();
        $prefix = (string) $db->getTablePrefix();
        $settings = $prefix.'settings';
        if ($done = $this->marker($pdo, $settings)) {
            $this->error("Already converted ({$done}). Switch the connection now – DB_TIMEZONE=+00:00 in .env, php artisan config:cache. "
                .'Running again would shift every timestamp a second time.');

            return self::FAILURE;
        }

        $tables = $this->tables($pdo, $db->getDatabaseName(), $prefix);
        $noKey = array_keys(array_filter($tables, fn ($t) => $t['pk'] === null));
        if ($noKey) {
            $this->error('Refusing: no single-column primary key on '.implode(', ', $noKey).' – nothing was changed.');

            return self::FAILURE;
        }
        $from = DatabaseClock::sessionZone($name);
        $this->line("Re-storing the TIMESTAMP columns of ".count($tables)." table(s) written in {$from} as UTC".($dry ? ' – DRY RUN, every table is rolled back' : '').'.');
        if (! $dry) {
            $this->warn('Back up the database first, and keep the site down (php artisan down) until the connection is switched.');
            if (! $this->option('force') && $this->input->isInteractive() && ! $this->confirm('Convert now?', false)) {
                return self::FAILURE;
            }
        }

        $original = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $total = 0;
        foreach ($tables as $table => $t) {
            try {
                $count = $this->convert($pdo, $table, $t['pk'], $t['columns'], $original, $dry);
            } catch (Throwable $e) {
                $this->error("{$table}: ".$e->getMessage().' – rolled back. Tables before it are '.($dry ? 'rolled back too (dry run)' : 'converted').'.');

                return self::FAILURE;
            }
            $total += $count;
            $this->line(sprintf('  %-32s %7d row(s)', $table, $count));
        }

        if ($dry) {
            $this->components->info("Dry run: {$total} row(s) in ".count($tables).' table(s) convert cleanly – nothing was changed.');

            return self::SUCCESS;
        }
        $this->setMarker($pdo, $settings, $original);
        $this->components->info("{$total} row(s) in ".count($tables).' table(s) re-stored as UTC.');
        $this->line("Now: DB_TIMEZONE=+00:00 in .env (config/database.php: 'timezone' => env('DB_TIMEZONE') on '{$name}'), php artisan config:cache, php artisan up.");

        return self::SUCCESS;
    }

    /**
     * Tables (with the prefix) that have TIMESTAMP columns: name => [pk, columns].
     *
     * @return array<string, array{pk:?string, columns:list<string>}>
     */
    protected function tables(PDO $pdo, string $database, string $prefix): array
    {
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix).'%';
        $q = $pdo->prepare("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? AND table_name LIKE ? AND data_type = 'timestamp' ORDER BY table_name, ordinal_position");
        $q->execute([$database, $like]);
        $tables = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$table, $column]) {
            $tables[$table]['columns'][] = $column;
        }
        $keys = $pdo->prepare("SELECT column_name FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND constraint_name = 'PRIMARY'");
        foreach ($tables as $table => $t) {
            $keys->execute([$database, $table]);
            $pk = $keys->fetchAll(PDO::FETCH_COLUMN);
            $tables[$table]['pk'] = count($pk) === 1 ? (string) $pk[0] : null;
        }

        return $tables;
    }

    /** Re-store one table; returns the rows written. Throws (after rolling back) when the checksums differ. */
    protected function convert(PDO $pdo, string $table, string $pk, array $columns, string $original, bool $dry): int
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET time_zone = '.$pdo->quote($original));
            $before = $this->checksum($pdo, $table, $pk, $columns);
            $rows = $pdo->query('SELECT `'.$pk.'`, `'.implode('`, `', $columns).'` FROM `'.$table.'` FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);

            $pdo->exec("SET time_zone = '+00:00'");
            $statements = [];
            $written = 0;
            foreach ($rows as $row) {
                $set = array_values(array_filter($columns, fn ($c) => $row[$c] !== null && ! str_starts_with((string) $row[$c], '0000-00-00')));
                if (! $set) {
                    continue;
                }
                $statements[implode(',', $set)] ??= $pdo->prepare('UPDATE `'.$table.'` SET '.implode(', ', array_map(fn ($c) => "`{$c}` = ?", $set)).' WHERE `'.$pk.'` = ?');
                $statements[implode(',', $set)]->execute([...array_map(fn ($c) => $row[$c], $set), $row[$pk]]);
                $written++;
            }
            $after = $this->checksum($pdo, $table, $pk, $columns);
            if ($before !== $after) {
                throw new RuntimeException("checksum changed ({$before} → {$after})");
            }
            $dry ? $pdo->rollBack() : $pdo->commit();

            return $written;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->exec('SET time_zone = '.$pdo->quote($original));
        }
    }

    /** "rows:sum of CRC32(pk|timestamps…)" as read in the session's current time zone. */
    protected function checksum(PDO $pdo, string $table, string $pk, array $columns): string
    {
        $list = implode(', ', array_map(fn ($c) => "IFNULL(`{$c}`, 'NULL')", $columns));

        return (string) $pdo->query("SELECT CONCAT(COUNT(*), ':', IFNULL(SUM(CRC32(CONCAT_WS('|', `{$pk}`, {$list}))), 0)) FROM `{$table}`")->fetchColumn();
    }

    protected function marker(PDO $pdo, string $settings): ?string
    {
        try {
            $q = $pdo->prepare("SELECT value FROM `{$settings}` WHERE `key` = ?");
            $q->execute([self::MARKER_KEY]);
            $value = $q->fetchColumn();

            return $value === false ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    protected function setMarker(PDO $pdo, string $settings, string $original): void
    {
        $pdo->exec("SET time_zone = '+00:00'");
        try {
            $now = gmdate('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO `{$settings}` (`group`, `key`, `value`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?)")
                ->execute([self::MARKER_GROUP, self::MARKER_KEY, "converted from {$original} on {$now} UTC", $now, $now]);
        } finally {
            $pdo->exec('SET time_zone = '.$pdo->quote($original));
        }
    }
}
