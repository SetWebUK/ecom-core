<?php

namespace Pine\Commerce\Support;

use Illuminate\Support\Facades\DB;

/**
 * Identifiers for hand-written (raw) SQL that respect the connection's table prefix and quoting.
 *
 * Laravel adds the prefix only to identifiers it builds itself; inside selectRaw()/whereRaw()/DB::raw() a bare
 * "orders" or "order_items.quantity" misses it and fails on a prefixed connection ("pc_orders"). Use:
 *
 *   Sql::col('order_items.quantity')   // "pc_order_items"."quantity"  (MySQL: `order_items`.`quantity` without prefix)
 *   Sql::table('orders')               // "pc_orders"
 *   Sql::table('orders as o')          // "pc_orders" as "o"  (then refer to o.column – aliases are not prefixed)
 */
final class Sql
{
    /** Quoted, prefixed column ("table.column" or a bare column). */
    public static function col(string $column, ?string $connection = null): string
    {
        return DB::connection($connection)->getQueryGrammar()->wrap($column);
    }

    /** Quoted, prefixed table name (an "as alias" suffix is kept). */
    public static function table(string $table, ?string $connection = null): string
    {
        return DB::connection($connection)->getQueryGrammar()->wrapTable($table);
    }

    /**
     * Quote + prefix every "table.column" of the given tables inside a raw SQL fragment:
     *   Sql::qualify('SUM(order_items.quantity - order_items.refunded_quantity)', ['order_items'])
     * Only the listed table names are touched (aliases such as "o.total" stay as they are).
     *
     * @param  list<string>  $tables
     */
    public static function qualify(string $sql, array $tables, ?string $connection = null): string
    {
        if (! $tables) {
            return $sql;
        }
        $names = implode('|', array_map(fn ($t) => preg_quote($t, '/'), $tables));
        $grammar = DB::connection($connection)->getQueryGrammar();

        return preg_replace_callback('/(?<![\w."`\'])('.$names.')\.([A-Za-z_][A-Za-z0-9_]*|\*)(?![\w"`])/',
            fn (array $m) => $m[2] === '*' ? $grammar->wrapTable($m[1]).'.*' : $grammar->wrap($m[1].'.'.$m[2]), $sql);
    }

    /** Several columns at once: Sql::cols(['orders.total', 'orders.refunded_total']) => ['orders.total' => '"pc_orders"."total"', …] */
    public static function cols(array $columns, ?string $connection = null): array
    {
        $out = [];
        foreach ($columns as $column) {
            $out[$column] = self::col($column, $connection);
        }

        return $out;
    }
}
