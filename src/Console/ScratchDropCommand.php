<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drops every table of a prefixed test connection (default "scratch", prefix zz_…) – used after fresh-install and
 * importer tests that share the client database. Refuses an empty prefix or one that does not start with "zz_",
 * so the real tables can never be dropped by it.
 */
class ScratchDropCommand extends Command
{
    protected $signature = 'commerce:scratch:drop
        {--connection=scratch : Prefixed connection whose tables are dropped}
        {--force : Do not ask for confirmation}';

    protected $description = 'Drop the zz_-prefixed tables of the scratch test connection';

    public function handle(): int
    {
        $name = (string) $this->option('connection');
        if (! config("database.connections.{$name}")) {
            $this->error("Unknown connection '{$name}'.");

            return self::FAILURE;
        }

        $tables = static::tables($name);
        if ($tables === null) {
            $this->error("Refusing: connection '{$name}' has no table prefix starting with zz_.");

            return self::FAILURE;
        }
        if (! $tables) {
            $this->components->info('No '.config("database.connections.{$name}.prefix").'* tables to drop.');

            return self::SUCCESS;
        }

        $this->line('Tables: '.implode(', ', $tables));
        if (! $this->option('force') && $this->input->isInteractive() && ! $this->confirm('Drop these '.count($tables).' tables?', true)) {
            return self::FAILURE;
        }

        static::drop($name);
        $this->components->info('Dropped '.count($tables).' table(s).');

        return self::SUCCESS;
    }

    /**
     * Full names of the connection's prefixed tables, or null when the prefix is not a safe zz_ prefix.
     *
     * @return list<string>|null
     */
    public static function tables(string $connection): ?array
    {
        $prefix = (string) config("database.connections.{$connection}.prefix", '');
        if ($prefix === '' || ! str_starts_with($prefix, 'zz_')) {
            return null;
        }
        $db = DB::connection($connection);
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix).'%';

        return array_map(fn ($r) => (string) $r->t, $db->select(
            'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ? ORDER BY table_name',
            [$db->getDatabaseName(), $like]
        ));
    }

    /** Drop the connection's zz_ tables (no-op for an unsafe prefix). Returns the number dropped. */
    public static function drop(string $connection): int
    {
        $tables = static::tables($connection);
        if (! $tables) {
            return 0;
        }
        $pdo = DB::connection($connection)->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                // guard again on the full name: only ever zz_ tables
                if (str_starts_with($table, 'zz_')) {
                    $pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '', $table).'`');
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        DB::purge($connection);

        return count($tables);
    }
}
