<?php

namespace Pine\Commerce\Import\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Idempotent bulk writes shared by the importers (the WordPress database importer and the WooCommerce REST API
 * importer since 1.5): insert-or-update rows matched on a key, id maps (remote id => local id) and "adopt" for rows
 * created by hand.
 *
 * Rows are owned by an import SOURCE (`import_source` column, since 1.5): null = the WordPress database importer
 * (and every row imported before 1.5), "woo:{host}" = a WooCommerce REST API connection. Lookups on `wp_id` only
 * ever see the rows of their own source, so a remote id 42 of one shop never updates the row of another shop's 42.
 * Natural keys (slug, path, code, email) are not scoped. Tables without the column (before the 1.5 migration ran)
 * are not scoped either.
 */
class Upserter
{
    public const COLUMN = 'import_source';

    /** @var array<string,bool> table => has import_source */
    private static array $columns = [];

    /** Rows inserted / updated by the last save(). */
    public int $created = 0;

    public int $updated = 0;

    public function __construct(public readonly ?string $source = null) {}

    /** Does $table carry the import_source column? (cached per table) */
    public static function scoped(string $table): bool
    {
        try {
            $cacheKey = spl_object_id(DB::connection()->getPdo()).'|'.DB::connection()->getTablePrefix().$table;
        } catch (Throwable) {
            return false;
        }
        if (! array_key_exists($cacheKey, self::$columns)) {
            try {
                self::$columns[$cacheKey] = Schema::hasColumn($table, self::COLUMN);
            } catch (Throwable) {
                self::$columns[$cacheKey] = false;
            }
        }

        return self::$columns[$cacheKey];
    }

    public static function flushColumns(): void
    {
        self::$columns = [];
    }

    /** Limit a query on $table to the rows of this source. */
    public function scope(Builder $query, string $table): Builder
    {
        if (! self::scoped($table)) {
            return $query;
        }

        return $this->source === null ? $query->whereNull($table.'.'.self::COLUMN) : $query->where($table.'.'.self::COLUMN, $this->source);
    }

    /** Imported rows of this source (wp_id set). */
    public function owned(string $table): Builder
    {
        return $this->scope(DB::table($table)->whereNotNull($table.'.wp_id'), $table);
    }

    /** [key => id] of this source's rows ($key = wp_id is scoped, natural keys are not). */
    public function map(string $table, string $key = 'wp_id'): array
    {
        $query = DB::table($table)->whereNotNull($key);
        if ($key === 'wp_id') {
            $this->scope($query, $table);
        }

        return $query->pluck('id', $key)->all();
    }

    /**
     * Insert-or-update rows matched on $key (default wp_id, scoped to this source). Rows must share the same columns.
     * Columns in $keepOnUpdate are only written on insert. New rows get this source's import_source. Returns [key => id].
     */
    public function save(string $table, array $rows, string $key = 'wp_id', array $keepOnUpdate = []): array
    {
        if (! $rows) {
            return [];
        }
        $scoped = $key === 'wp_id' && self::scoped($table);
        $lookup = function (array $keys) use ($table, $key, $scoped): array {
            $out = [];
            foreach (array_chunk($keys, 1000) as $chunk) {
                $query = DB::table($table)->whereIn($key, $chunk);
                if ($scoped) {
                    $this->scope($query, $table);
                }
                $out += $query->pluck('id', $key)->all();
            }

            return $out;
        };
        $existing = $lookup(array_column($rows, $key));

        $inserts = [];
        $updates = [];
        foreach ($rows as $row) {
            $k = $row[$key];
            if (isset($existing[$k])) {
                $updates[] = ['id' => $existing[$k]] + $row;
            } else {
                if ($this->source !== null && self::scoped($table)) {
                    $row[self::COLUMN] = $this->source;
                }
                $inserts[] = $row;
            }
        }

        $this->created = count($inserts);
        $this->updated = count($updates);
        foreach ($this->sameColumns($inserts) as $group) {
            foreach (array_chunk($group, 250) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        if ($updates) {
            $columns = array_values(array_diff(array_keys($updates[0]), array_merge(['id', $key, self::COLUMN], $keepOnUpdate)));
            foreach (array_chunk($updates, 250) as $chunk) {
                DB::table($table)->upsert($chunk, ['id'], $columns);
            }
        }

        return $lookup(array_column($rows, $key));
    }

    /**
     * What save() would do, without writing: [key => id of the existing row] plus how many rows would be created and
     * updated (dry runs).
     *
     * @return array{0: array<int|string,int>, 1:int, 2:int}
     */
    public function plan(string $table, array $rows, string $key = 'wp_id'): array
    {
        $keys = array_values(array_unique(array_column($rows, $key)));
        $existing = [];
        foreach (array_chunk($keys, 1000) as $chunk) {
            $query = DB::table($table)->whereIn($key, $chunk);
            if ($key === 'wp_id') {
                $this->scope($query, $table);
            }
            $existing += $query->pluck('id', $key)->all();
        }

        return [$existing, count($keys) - count($existing), count($existing)];
    }

    /**
     * Rows that don't exist by wp_id yet but collide on a natural unique key with a row that has no wp_id (created
     * by hand or a seeder) take that row over instead of failing on the unique index.
     */
    public function adopt(string $table, array $rows, string $naturalKey): void
    {
        $existing = $this->map($table);
        foreach ($rows as $row) {
            if (! isset($existing[$row['wp_id']])) {
                $update = ['wp_id' => $row['wp_id']];
                if ($this->source !== null && self::scoped($table)) {
                    $update[self::COLUMN] = $this->source;
                }
                DB::table($table)->where($naturalKey, $row[$naturalKey])->whereNull('wp_id')->update($update);
            }
        }
    }

    /** Insert rows in chunks. */
    public function insert(string $table, array $rows, int $chunk = 500): void
    {
        foreach (array_chunk($rows, $chunk) as $part) {
            DB::table($table)->insert($part);
        }
    }

    /** Group rows by their column set (a multi-row insert needs identical columns). @return list<list<array>> */
    private function sameColumns(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[implode(',', array_keys($row))][] = $row;
        }

        return array_values($groups);
    }
}
