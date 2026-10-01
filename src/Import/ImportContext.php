<?php

namespace Pine\Commerce\Import;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Import\Contracts\OrderSource;
use Pine\Commerce\Import\Contracts\SeoProvider;
use Pine\Commerce\Import\Orders\HposOrderSource;
use Pine\Commerce\Import\Orders\LegacyPostsOrderSource;
use Pine\Commerce\Import\Permalinks\Permalinks;
use Pine\Commerce\Import\RenderedSite\RenderedSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Shared state for one import run: the read-only source (WordPressSource + SiteProfile), the adapters, rendered
 * HTML access, the importer config (`commerce-import`), id maps (wp id -> Laravel id), bulk upsert helpers and the
 * summary/warning log printed at the end. Target writes go to the default DB connection (the command switches it
 * for --target).
 */
class ImportContext
{
    /** @var array<string, array{wp:int|string, laravel:int|string, note:string}> */
    public array $summary = [];

    /** @var string[] */
    public array $warnings = [];

    public bool $fresh = false;

    public bool $dryRun = false;

    /** Free-form options of the run (copy_uploads, uploads_path, wp_path, orders_source …). */
    public array $options = [];

    private array $maps = [];

    private ?array $attachments = null;

    private ?Permalinks $permalinks = null;

    private ?OrderSource $orderSource = null;

    /** @var array<string,mixed> values steps share within a run */
    private array $state = [];

    public function __construct(
        public ?Command $command,
        public WordPressSource $wp,
        public SiteProfile $site,
        public AdapterRegistry $adapters,
        public RenderedSource $rendered,
        public array $config = [],
    ) {}

    /** Configure the Formatter for this site and boot the adapters (call once, after construction). */
    public function boot(): static
    {
        Formatter::configure([
            'hosts' => $this->legacyHosts(),
            'upload_bases' => $this->uploadBases(),
            'replace' => (array) $this->config('content.replace', []),
            'components' => (array) $this->config('content.components', []),
            'site_name' => $this->site->blogName,
        ]);
        $this->adapters->boot($this);
        foreach ($this->adapters->providers(SeoProvider::class) as $seo) {
            if ($name = ($seo->siteSettings()['site_name'] ?? null)) {
                Formatter::configure(['site_name' => $name]);
                break;
            }
        }

        return $this;
    }

    public function config(string $key, $default = null)
    {
        return Arr::get($this->config, $key, $default);
    }

    /** Hosts whose links become relative: source siteurl/home + `commerce-import.legacy_hosts`. */
    public function legacyHosts(): array
    {
        return array_values(array_unique(array_merge($this->site->hosts(), (array) $this->config('legacy_hosts', []))));
    }

    /** Upload base URLs that are not {legacy host}/wp-content/uploads (custom upload_url_path, CDN). */
    public function uploadBases(): array
    {
        $bases = (array) $this->config('media.upload_urls', []);
        $url = $this->site->uploadsUrl;
        if ($url !== '' && ! preg_match('#^(?:https?:)?//[^/]+/wp-content/uploads/?$#i', $url)) {
            $bases[] = $url;
        }

        return array_values(array_unique($bases));
    }

    public function permalinks(): Permalinks
    {
        return $this->permalinks ??= new Permalinks($this);
    }

    /** Order storage: --orders-source / `orders.source` (auto|hpos|posts), auto = SiteProfile::$ordersStorage. */
    public function orderSource(): OrderSource
    {
        if ($this->orderSource) {
            return $this->orderSource;
        }
        $mode = ($this->options['orders_source'] ?? null) ?: ($this->config('orders.source') ?: 'auto');
        $mode = $mode === 'auto' ? $this->site->ordersStorage : $mode;

        return $this->orderSource = $mode === 'hpos'
            ? new HposOrderSource($this->wp, $this->site->timezone)
            : new LegacyPostsOrderSource($this->wp, $this->site->timezone);
    }

    public function state(string $key, $default = null)
    {
        return $this->state[$key] ?? $default;
    }

    public function setState(string $key, $value): void
    {
        $this->state[$key] = $value;
    }

    // ------------------------------------------------------------------ output

    public function info(string $message): void
    {
        $this->command?->line('  <fg=gray>›</> '.$message);
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function count(string $label, $wp, $laravel, string $note = ''): void
    {
        $this->summary[$label] = ['wp' => $wp, 'laravel' => $laravel, 'note' => $note];
    }

    // ------------------------------------------------------------------ id maps

    /** [wp_id => id] for a Laravel table (cached until forget()). */
    public function map(string $table, string $key = 'wp_id'): array
    {
        $cacheKey = $table.'.'.$key;
        if (! isset($this->maps[$cacheKey])) {
            $this->maps[$cacheKey] = DB::table($table)->whereNotNull($key)->pluck('id', $key)->all();
        }

        return $this->maps[$cacheKey];
    }

    public function forget(string $table): void
    {
        foreach (array_keys($this->maps) as $key) {
            if (str_starts_with($key, $table.'.')) {
                unset($this->maps[$key]);
            }
        }
    }

    /**
     * Insert-or-update rows matched on $key (default wp_id). Rows must share the same columns.
     * Columns in $keepOnUpdate are only written on insert. Returns [key => id].
     */
    public function save(string $table, array $rows, string $key = 'wp_id', array $keepOnUpdate = []): array
    {
        if (! $rows) {
            return [];
        }
        $existing = [];
        foreach (array_chunk(array_column($rows, $key), 1000) as $chunk) {
            $existing += DB::table($table)->whereIn($key, $chunk)->pluck('id', $key)->all();
        }

        $inserts = [];
        $updates = [];
        foreach ($rows as $row) {
            $k = $row[$key];
            if (isset($existing[$k])) {
                $updates[] = ['id' => $existing[$k]] + $row;
            } else {
                $inserts[] = $row;
            }
        }

        foreach (array_chunk($inserts, 250) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        if ($updates) {
            $columns = array_values(array_diff(array_keys($updates[0]), array_merge(['id', $key], $keepOnUpdate)));
            foreach (array_chunk($updates, 250) as $chunk) {
                DB::table($table)->upsert($chunk, ['id'], $columns);
            }
        }

        $this->forget($table);
        $map = [];
        foreach (array_chunk(array_column($rows, $key), 1000) as $chunk) {
            $map += DB::table($table)->whereIn($key, $chunk)->pluck('id', $key)->all();
        }

        return $map;
    }

    /**
     * Rows that don't exist by wp_id yet but collide on a natural unique key with a row that has no
     * wp_id (created by hand or a seeder) take that row over instead of failing on the unique index.
     */
    public function adopt(string $table, array $rows, string $naturalKey): void
    {
        $existing = $this->map($table);
        foreach ($rows as $row) {
            if (! isset($existing[$row['wp_id']])) {
                DB::table($table)->where($naturalKey, $row[$naturalKey])->whereNull('wp_id')->update(['wp_id' => $row['wp_id']]);
            }
        }
        $this->forget($table);
    }

    /** Insert rows in chunks. */
    public function insert(string $table, array $rows, int $chunk = 500): void
    {
        foreach (array_chunk($rows, $chunk) as $part) {
            DB::table($table)->insert($part);
        }
    }

    // ------------------------------------------------------------------ WordPress helpers

    /**
     * Attachment lookup: [attachment_id => ['path' => 'uploads/…', 'alt' => …, 'exists' => bool]].
     */
    public function attachments(): array
    {
        if ($this->attachments !== null) {
            return $this->attachments;
        }
        $posts = $this->wp->table('posts')->where('post_type', 'attachment')->pluck('ID')->all();
        $meta = $this->wp->postMeta($posts, ['_wp_attached_file', '_wp_attachment_image_alt']);
        $this->attachments = [];
        foreach ($posts as $id) {
            $file = $meta[$id]['_wp_attached_file'] ?? null;
            if (! $file) {
                continue;
            }
            $path = 'uploads/'.ltrim($file, '/');
            $this->attachments[$id] = [
                'path' => $path,
                'alt' => Formatter::decode($meta[$id]['_wp_attachment_image_alt'] ?? '') ?: null,
                'exists' => is_file(Storage::disk('public')->path($path)),
            ];
        }

        return $this->attachments;
    }

    public function forgetAttachments(): void
    {
        $this->attachments = null;
    }

    public function attachmentPath($id): ?string
    {
        $id = (int) $id;

        return $id > 0 ? ($this->attachments()[$id]['path'] ?? null) : null;
    }

    /** Rendered snapshot file by name (e.g. 'home.html'). */
    public function referenceHtml(string $file): ?string
    {
        return $this->rendered->file($file);
    }

    /**
     * <title> and meta description exactly as WordPress/the SEO plugin rendered them for a URL path ('' = home), from
     * the rendered source. Used where the plugin generated the value (e.g. %excerpt% descriptions) instead of storing it.
     *
     * @return array{title: ?string, description: ?string}
     */
    public function renderedSeo(string $path): array
    {
        $html = $this->rendered->html($path);
        $out = ['title' => null, 'description' => null];
        if (! $html) {
            return $out;
        }
        if (preg_match('#<title>(.*?)</title>#s', $html, $m)) {
            $out['title'] = Formatter::replace(Formatter::decode($m[1])) ?: null;
        }
        if (preg_match('#<meta name="description" content="([^"]*)"#', $html, $m)) {
            $out['description'] = Formatter::replace(Formatter::decode($m[1])) ?: null;
        }

        return $out;
    }

    /** First SEO provider answer for a post (pages, posts, products). */
    public function postSeo(\Pine\Commerce\Import\Data\WpPost $post, array $meta, array $vars): ?\Pine\Commerce\Import\Data\SeoData
    {
        foreach ($this->adapters->providers(SeoProvider::class) as $provider) {
            if ($seo = $provider->postSeo($post, $meta, $vars)) {
                return $seo;
            }
        }

        return null;
    }

    public function termSeo(\Pine\Commerce\Import\Data\WpTerm $term, string $taxonomy, array $vars): ?\Pine\Commerce\Import\Data\SeoData
    {
        foreach ($this->adapters->providers(SeoProvider::class) as $provider) {
            if ($seo = $provider->termSeo($term, $taxonomy, $vars)) {
                return $seo;
            }
        }

        return null;
    }

    /**
     * The term the source site's breadcrumb showed for a post (SEO adapters implementing BreadcrumbTermProvider),
     * or null when no adapter knows (the breadcrumb then follows the URL category).
     *
     * @param  list<array{id:int, name:string, parent:int}>  $terms
     */
    public function breadcrumbTermId(\Pine\Commerce\Import\Data\WpPost $post, array $meta, string $taxonomy, array $terms): ?int
    {
        foreach ($this->adapters->providers(\Pine\Commerce\Import\Contracts\BreadcrumbTermProvider::class) as $provider) {
            if (($id = $provider->breadcrumbTermId($post, $meta, $taxonomy, $terms)) !== null) {
                return $id;
            }
        }

        return null;
    }

    public function primaryTermId(\Pine\Commerce\Import\Data\WpPost $post, array $meta, string $taxonomy): ?int
    {
        foreach ($this->adapters->providers(SeoProvider::class) as $provider) {
            if (($id = $provider->primaryTermId($post, $meta, $taxonomy)) !== null) {
                return $id;
            }
        }

        return null;
    }
}
