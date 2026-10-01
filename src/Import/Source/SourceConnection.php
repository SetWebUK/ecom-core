<?php

namespace Pine\Commerce\Import\Source;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves the source WordPress database and registers it as the read-only runtime connection `wordpress_import`.
 *
 * Precedence per setting: explicit option (--db-*, --prefix) > wp-config.php of --wp-path (parsed, never included)
 * > the configured connection (`commerce-import.source.connection`, e.g. 'wordpress' built from WP_DB_* env).
 * The prefix, when not given, comes from wp-config.php, else the configured connection's prefix if its options
 * table exists, else auto-detection (PrefixDetector). MySQL sessions are forced READ ONLY.
 */
final class SourceConnection
{
    public const NAME = 'wordpress_import';

    /** @var array<string,string> human readable description of where each setting came from */
    public array $origin = [];

    public ?array $wpConfig = null;

    public function __construct(private readonly array $options, private readonly array $config = []) {}

    /** Build + register the connection and return its name. */
    public function register(): string
    {
        $base = $this->baseConfig();
        $wpPath = $this->options['wp_path'] ?? null;
        if ($wpPath) {
            $this->wpConfig = WpConfig::fromPath($wpPath);
            if (! $this->wpConfig) {
                throw new RuntimeException("No wp-config.php found in $wpPath (or its parent directory).");
            }
            $wc = $this->wpConfig;
            foreach (['DB_NAME' => 'database', 'DB_USER' => 'username', 'DB_PASSWORD' => 'password'] as $k => $key) {
                if ($wc[$k] !== null) {
                    $base[$key] = $wc[$k];
                    $this->origin[$key] = 'wp-config.php';
                }
            }
            if ($wc['DB_HOST'] !== null) {
                $host = WpConfig::splitHost($wc['DB_HOST']);
                $base['host'] = $host['host'];
                $base['port'] = $host['port'] ?? ($base['port'] ?? '3306');
                $base['unix_socket'] = $host['socket'] ?? '';
                $this->origin['host'] = 'wp-config.php';
            }
            if ($wc['DB_CHARSET']) {
                $base['charset'] = $wc['DB_CHARSET'] === 'utf8' ? 'utf8mb4' : $wc['DB_CHARSET'];
            }
            if ($wc['unresolved']) {
                $this->origin['unresolved'] = implode(', ', $wc['unresolved']).' (not string literals – pass --db-* options)';
            }
        }
        foreach (['db_host' => 'host', 'db_port' => 'port', 'db_name' => 'database', 'db_user' => 'username', 'db_pass' => 'password', 'db_socket' => 'unix_socket'] as $opt => $key) {
            if (($this->options[$opt] ?? null) !== null && $this->options[$opt] !== '') {
                $base[$key] = $this->options[$opt];
                $this->origin[$key] = '--'.str_replace('_', '-', $opt);
            }
        }
        if (empty($base['database'])) {
            throw new RuntimeException('No source database: pass --wp-path, --db-name/--db-user/--db-pass or configure the "'
                .($this->config['connection'] ?? 'wordpress').'" connection (WP_DB_* in .env).');
        }

        $explicitPrefix = $this->options['prefix'] ?? null;
        $configPrefix = $base['prefix'] ?? '';
        $base['prefix'] = '';
        $base['prefix_indexes'] = false;
        if (($base['driver'] ?? 'mysql') !== 'sqlite') {
            $base['options'] = (array) ($base['options'] ?? []);
            $base['options'][\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET SESSION TRANSACTION READ ONLY';
            $base['strict'] = false;
        }
        config(['database.connections.'.self::NAME => $base]);
        DB::purge(self::NAME);
        $db = DB::connection(self::NAME);

        if ($explicitPrefix !== null && $explicitPrefix !== '') {
            $prefix = $explicitPrefix;
            $this->origin['prefix'] = '--prefix';
        } elseif ($wpPath && ($this->wpConfig['table_prefix'] ?? null) !== null) {
            $prefix = $this->wpConfig['table_prefix'];
            $this->origin['prefix'] = 'wp-config.php';
        } elseif ($configPrefix !== '' && $this->hasOptionsTable($db, $configPrefix)) {
            $prefix = $configPrefix;
            $this->origin['prefix'] = 'connection config';
        } else {
            $found = PrefixDetector::detect($db);
            if (count($found) !== 1) {
                throw new RuntimeException($found
                    ? 'Several WordPress installs in '.$base['database'].' (prefixes: '.implode(', ', $found).') – pass --prefix.'
                    : 'No WordPress tables (*options + *posts) found in '.$base['database'].' – pass --prefix.');
            }
            $prefix = $found[0];
            $this->origin['prefix'] = 'auto-detected';
        }

        $base['prefix'] = $prefix;
        config(['database.connections.'.self::NAME => $base]);
        DB::purge(self::NAME);

        return self::NAME;
    }

    private function hasOptionsTable($db, string $prefix): bool
    {
        try {
            $db->selectOne('SELECT 1 FROM '.$db->getQueryGrammar()->wrapTable($prefix.'options', '').' LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function baseConfig(): array
    {
        $name = $this->config['connection'] ?? 'wordpress';
        $base = $name ? (array) config('database.connections.'.$name, []) : [];
        $base += [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => '', 'username' => '', 'password' => '',
            'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => false, 'engine' => null,
        ];
        if ($name) {
            $this->origin['base'] = "connection '$name'";
        }

        return $base;
    }
}
