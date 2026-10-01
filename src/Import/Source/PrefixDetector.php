<?php

namespace Pine\Commerce\Import\Source;

use Illuminate\Database\Connection;

/**
 * Finds the WordPress table prefix in a database: every table named "{prefix}options" whose "{prefix}posts" also
 * exists is a candidate; a candidate is confirmed when its options table has a 'siteurl' row.
 */
final class PrefixDetector
{
    /** @param list<string> $tables @return list<string> candidate prefixes (sorted) */
    public static function candidates(array $tables): array
    {
        $set = array_flip($tables);
        $out = [];
        foreach ($tables as $table) {
            if (str_ends_with($table, 'options')) {
                $prefix = substr($table, 0, -strlen('options'));
                if (isset($set[$prefix.'posts']) && isset($set[$prefix.'postmeta'])) {
                    $out[] = $prefix;
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** @return list<string> table names of the connection's database (without applying the connection prefix) */
    public static function tables(Connection $db): array
    {
        return match ($db->getDriverName()) {
            'sqlite' => array_map(fn ($r) => $r->name, $db->select("SELECT name FROM sqlite_master WHERE type = 'table'")),
            default => array_map(fn ($r) => array_values((array) $r)[0],
                $db->select('SELECT table_name FROM information_schema.tables WHERE table_schema = ?', [$db->getDatabaseName()])),
        };
    }

    /** @return list<string> confirmed prefixes */
    public static function detect(Connection $db): array
    {
        $confirmed = [];
        foreach (self::candidates(self::tables($db)) as $prefix) {
            try {
                $row = $db->selectOne('SELECT option_value FROM '.$db->getQueryGrammar()->wrapTable($prefix.'options', '')
                    ." WHERE option_name = 'siteurl'");
            } catch (\Throwable) {
                $row = null;
            }
            if ($row) {
                $confirmed[] = $prefix;
            }
        }

        return $confirmed;
    }
}
