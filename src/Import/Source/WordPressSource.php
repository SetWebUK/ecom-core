<?php

namespace Pine\Commerce\Import\Source;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only access helpers for the source WordPress/WooCommerce database (the runtime connection registered by
 * SourceConnection – table prefix applied by the connection config). All loaders are bulk (whereIn chunks) so the
 * importer never issues per-row queries against WordPress. Nothing in this class writes.
 */
class WordPressSource
{
    private array $optionCache = [];

    private ?array $tables = null;

    public function __construct(private readonly string $connection = SourceConnection::NAME) {}

    public function connectionName(): string
    {
        return $this->connection;
    }

    public function db(): Connection
    {
        return DB::connection($this->connection);
    }

    public function prefix(): string
    {
        return $this->db()->getTablePrefix();
    }

    /** Does the (unprefixed) table exist, e.g. hasTable('wc_orders')? */
    public function hasTable(string $table): bool
    {
        if ($this->tables === null) {
            $this->tables = array_flip(PrefixDetector::tables($this->db()));
        }

        return isset($this->tables[$this->prefix().$table]);
    }

    /** Active plugins (site + network), e.g. ['woocommerce/woocommerce.php', …]. */
    public function activePlugins(): array
    {
        $plugins = array_values((array) $this->option('active_plugins', []));
        $network = $this->hasTable('sitemeta') ? self::unserialize($this->table('sitemeta')->where('meta_key', 'active_sitewide_plugins')->value('meta_value')) : [];

        return array_values(array_unique(array_merge($plugins, is_array($network) ? array_keys($network) : [])));
    }

    /** Number of posts of a type (any status except trash/auto-draft). */
    public function countPosts(string $type): int
    {
        return $this->table('posts')->where('post_type', $type)->whereNotIn('post_status', ['trash', 'auto-draft'])->count();
    }

    /** Number of terms in a taxonomy. */
    public function countTerms(string $taxonomy): int
    {
        return $this->table('term_taxonomy')->where('taxonomy', $taxonomy)->count();
    }

    public function metaKeyExists(string ...$keys): bool
    {
        return $this->table('postmeta')->whereIn('meta_key', $keys)->exists();
    }

    public function table(string $table): Builder
    {
        return $this->db()->table($table);
    }

    /** Fully prefixed table name for raw SQL. */
    public function t(string $table): string
    {
        return $this->db()->getTablePrefix().$table;
    }

    /**
     * Posts of the given type(s).
     *
     * @param  string|array  $types
     * @param  array|null  $statuses  null = every status except trash / auto-draft
     */
    public function posts($types, ?array $statuses = null): Collection
    {
        $query = $this->table('posts')->whereIn('post_type', (array) $types);
        if ($statuses === null) {
            $query->whereNotIn('post_status', ['trash', 'auto-draft']);
        } else {
            $query->whereIn('post_status', $statuses);
        }

        return $query->orderBy('ID')->get();
    }

    /**
     * First value of every meta key for the given posts: [post_id => [meta_key => value]].
     *
     * @param  array|null  $keys  restrict to these meta keys
     */
    public function postMeta(array $postIds, ?array $keys = null): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($postIds)), 2000) as $chunk) {
            $query = $this->table('postmeta')->select('post_id', 'meta_key', 'meta_value')
                ->whereIn('post_id', $chunk)->orderBy('meta_id');
            if ($keys) {
                $query->whereIn('meta_key', $keys);
            }
            foreach ($query->cursor() as $row) {
                if (! isset($out[$row->post_id][$row->meta_key])) {
                    $out[$row->post_id][$row->meta_key] = $row->meta_value;
                }
            }
        }

        return $out;
    }

    /** Every value of one meta key: [post_id => [values...]] (e.g. _wp_old_slug). */
    public function postMetaMulti(array $postIds, string $key): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($postIds)), 2000) as $chunk) {
            foreach ($this->table('postmeta')->select('post_id', 'meta_value')->whereIn('post_id', $chunk)
                ->where('meta_key', $key)->orderBy('meta_id')->cursor() as $row) {
                $out[$row->post_id][] = $row->meta_value;
            }
        }

        return $out;
    }

    public function termMeta(array $termIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($termIds)), 2000) as $chunk) {
            foreach ($this->table('termmeta')->select('term_id', 'meta_key', 'meta_value')->whereIn('term_id', $chunk)
                ->orderBy('meta_id')->cursor() as $row) {
                $out[$row->term_id][$row->meta_key] ??= $row->meta_value;
            }
        }

        return $out;
    }

    public function userMeta(array $userIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($userIds)), 2000) as $chunk) {
            foreach ($this->table('usermeta')->select('user_id', 'meta_key', 'meta_value')->whereIn('user_id', $chunk)
                ->orderBy('umeta_id')->cursor() as $row) {
                $out[$row->user_id][$row->meta_key] ??= $row->meta_value;
            }
        }

        return $out;
    }

    /** Terms of one or more taxonomies, keyed by term_id. */
    public function terms($taxonomies): Collection
    {
        return $this->table('terms as t')
            ->join('term_taxonomy as tt', 'tt.term_id', '=', 't.term_id')
            ->whereIn('tt.taxonomy', (array) $taxonomies)
            ->select('t.term_id', 't.name', 't.slug', 'tt.term_taxonomy_id', 'tt.taxonomy', 'tt.parent', 'tt.description', 'tt.count')
            ->orderBy('t.term_id')
            ->get()
            ->keyBy('term_id');
    }

    /**
     * Term assignments for objects: [object_id => [ {term_id, taxonomy, term_order}, ... ]].
     */
    public function objectTerms(array $objectIds, $taxonomies): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($objectIds)), 2000) as $chunk) {
            $rows = $this->table('term_relationships as tr')
                ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
                ->whereIn('tr.object_id', $chunk)
                ->whereIn('tt.taxonomy', (array) $taxonomies)
                ->select('tr.object_id', 'tt.term_id', 'tt.taxonomy', 'tr.term_order')
                ->orderBy('tr.object_id')->orderBy('tr.term_order')->orderBy('tt.term_id')
                ->get();
            foreach ($rows as $row) {
                $out[$row->object_id][] = $row;
            }
        }

        return $out;
    }

    public function option(string $name, $default = null)
    {
        if (! array_key_exists($name, $this->optionCache)) {
            $value = $this->table('options')->where('option_name', $name)->value('option_value');
            $this->optionCache[$name] = $value === null ? null : self::unserialize($value);
        }

        return $this->optionCache[$name] ?? $default;
    }

    /** maybe_unserialize() without ever instantiating objects. */
    public static function unserialize($value)
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if ($trimmed === 'N;' || preg_match('/^[aOsbid]:/', $trimmed)) {
            $result = @unserialize($trimmed, ['allowed_classes' => false]);
            if ($result !== false || $trimmed === 'b:0;') {
                return $result;
            }
        }

        return $value;
    }

    /** Convert a WP GMT datetime string to a value for our (UTC) timestamps; null for zero dates. */
    public static function gmt(?string $value): ?string
    {
        if (! $value || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return $value;
    }

    /** Unix timestamp (as stored by WooCommerce) to a UTC datetime string. */
    public static function ts($value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', (int) $value);
    }

    public static function decimal($value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric(str_replace(',', '', (string) $value))) {
            return null;
        }

        return round((float) str_replace(',', '', (string) $value), 2);
    }

    public static function yes($value): bool
    {
        return in_array(strtolower((string) $value), ['yes', '1', 'true', 'on'], true);
    }
}
