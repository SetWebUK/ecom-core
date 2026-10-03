<?php

namespace Pine\Commerce\Import\WooApi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Support\Upserter;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\WooApiImport;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * One WooCommerce REST API import run: pulls the selected entities page by page from the shop (Client) and writes
 * them through the same mappers as the database importer (Import\Mapping), keyed on the shop's ids within the run's
 * import source (Upserter). Order: categories → attributes → tax → shipping → products (+ variations, tags) →
 * customers → coupons → orders (+ refunds, notes, guests) → reviews → pages → posts → media.
 *
 * Every page is read completely from the shop first (variations, refunds, notes, images), then written in one
 * database transaction, followed by a checkpoint (entity + next page) and a progress update on the woo_api_imports
 * row – so an interrupted run resumes where it stopped and a cancel stops after the current page.
 * Dry runs read and map everything and count what would be created / updated, writing nothing (no downloads).
 *
 * Options (WooApiImport::$options): entities (choices, see CHOICES), images (bool), existing (update|skip),
 * orders_after (Y-m-d), since (ISO date: only items modified after it), notes (bool).
 */
class Importer
{
    use Concerns\ImportsCatalog;
    use Concerns\ImportsContent;
    use Concerns\ImportsSales;
    use Concerns\ImportsSetup;

    /** Internal entities in import order. */
    public const ORDER = ['categories', 'attributes', 'tax', 'shipping', 'products', 'customers', 'coupons', 'orders', 'reviews', 'pages', 'posts', 'media'];

    /** Internal entity => the choice (form checkbox) that selects it. */
    public const GROUP = ['tax' => 'shipping_tax', 'shipping' => 'shipping_tax', 'pages' => 'content', 'posts' => 'content'];

    /** The entity checklist of the admin form / --only. */
    public const CHOICES = [
        'categories' => 'Categories',
        'attributes' => 'Attributes & terms',
        'products' => 'Products (variations, tags, images)',
        'customers' => 'Customers (+ guests from orders)',
        'coupons' => 'Coupons',
        'orders' => 'Orders (refunds, notes, tax lines)',
        'reviews' => 'Reviews',
        'shipping_tax' => 'Shipping zones & tax rates',
        'content' => 'Pages & blog posts',
        'media' => 'Media library images',
    ];

    /** Choices the key-less Store API can serve. */
    public const STORE_CHOICES = ['categories', 'attributes', 'products', 'content', 'media'];

    public const LABELS = ['categories' => 'Categories', 'attributes' => 'Attributes', 'tax' => 'Tax rates', 'shipping' => 'Shipping',
        'products' => 'Products', 'customers' => 'Customers', 'coupons' => 'Coupons', 'orders' => 'Orders', 'reviews' => 'Reviews',
        'pages' => 'Pages', 'posts' => 'Blog posts', 'media' => 'Media'];

    protected Upserter $u;

    protected SeoReader $seo;

    protected MediaDownloader $media;

    /** The WordPress REST index (name, home, page_on_front …). */
    protected array $site = [];

    protected string $home = '';

    protected bool $dryRun;

    protected array $progress;

    protected array $checkpoint;

    protected float $lastSave = 0.0;

    public function __construct(protected Client $client, protected WooApiImport $run, protected RunLog $log)
    {
        $this->dryRun = (bool) $run->dry_run;
        $this->u = new Upserter($run->source);
        $this->progress = (array) ($run->progress ?? []) + ['entities' => [], 'issues' => []];
        $this->checkpoint = (array) ($run->checkpoint ?? []);
        $this->seo = new SeoReader($client);
        $this->media = new MediaDownloader($client->connection, $this->u, $this->dryRun, fn ($m) => $this->log->info($m));
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->run->option($key, $default);
    }

    /** Run (or resume) the import. Throws on a fatal error after recording it; a cancel ends with status cancelled. */
    public function run(): void
    {
        $this->run->refresh(); // database defaults (warnings, errors) and a cancel requested meanwhile
        $this->run->forceFill(['status' => 'running', 'started_at' => $this->run->started_at ?? now(), 'pid' => getmypid() ?: null, 'error' => null])->save();
        $this->log->info(($this->checkpoint ? 'Resuming' : 'Starting').' import #'.$this->run->id.' from '.$this->client->connection->describe()
            .($this->dryRun ? ' – DRY RUN (nothing is written)' : '').'; source '.($this->run->source ?? 'database-import rows (same site)'));
        try {
            $this->boot();
            foreach ($this->run->entities() as $entity) {
                $state = $this->progress['entities'][$entity]['status'] ?? 'waiting';
                if (in_array($state, ['done', 'skipped'], true)) {
                    continue;
                }
                $this->entity($entity, 'running');
                $this->save(true);
                $started = microtime(true);
                $this->{'import'.Str::studly($entity)}();
                if (($this->progress['entities'][$entity]['status'] ?? '') === 'running') {
                    $this->entity($entity, 'done');
                }
                $p = $this->progress['entities'][$entity];
                $this->log->info(sprintf('%s done in %.1fs: %d fetched, %d created, %d updated, %d skipped, %d failed', self::LABELS[$entity],
                    microtime(true) - $started, $p['fetched'] ?? 0, $p['created'] ?? 0, $p['updated'] ?? 0, $p['skipped'] ?? 0, $p['failed'] ?? 0));
                $this->checkpoint = ['done' => array_values(array_unique(array_merge($this->checkpoint['done'] ?? [], [$entity])))];
                $this->save(true);
            }
            $this->finish('completed');
        } catch (Cancelled) {
            $this->log->warning('Cancelled – the import stopped after the last complete page. Start it again to resume.');
            $this->finish('cancelled');
        } catch (Throwable $e) {
            $message = $this->client->connection->mask($e->getMessage());
            $this->log->error('Import failed: '.$message.($e instanceof WooApiException ? '' : ' ('.basename($e->getFile()).':'.$e->getLine().')'));
            $this->run->forceFill(['error' => Str::limit($message, 2000)])->save();
            $this->finish('failed');
            throw $e;
        }
    }

    /** Site facts and the content formatter (links to the old site made relative, uploads → /storage/uploads/). */
    protected function boot(): void
    {
        $this->site = (new Probe($this->client))->index();
        $this->home = rtrim((string) ($this->site['home'] ?? $this->client->connection->url), '/') ?: $this->client->connection->url;
        $hosts = array_values(array_unique(array_filter([parse_url($this->home, PHP_URL_HOST), parse_url((string) ($this->site['url'] ?? ''), PHP_URL_HOST),
            $this->client->connection->host()])));
        $config = (array) config('commerce-import', []);
        Formatter::configure([
            'hosts' => array_merge($hosts, (array) ($config['legacy_hosts'] ?? [])),
            'upload_bases' => (array) ($config['media']['upload_urls'] ?? []),
            'replace' => (array) ($config['content']['replace'] ?? []),
            'components' => (array) ($config['content']['components'] ?? ['wp_sitemap_page' => 'sitemap']),
            'site_name' => html_entity_decode((string) ($this->site['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ]);
    }

    protected function finish(string $status): void
    {
        if (! $this->dryRun && $status === 'completed') {
            Cache::forget('settings.all');
            Setting::flushMemo();
        }
        $this->progress['finished'] = true;
        $this->run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'summary' => ['entities' => $this->progress['entities'], 'requests' => $this->client->requests,
                'images_downloaded' => $this->media->downloaded, 'images_reused' => $this->media->reused],
        ]);
        $this->save(true);
        $this->log->info(ucfirst($status).' – '.$this->client->requests.' API requests, '.$this->media->downloaded.' images downloaded, '
            .$this->run->warnings.' warnings, '.$this->run->errors.' errors.');
    }

    // ------------------------------------------------------------------ paging, progress, checkpoints

    /**
     * Walk a collection page by page from the checkpoint; $handle(items, page) runs in one transaction per page.
     */
    protected function paged(string $entity, string $route, array $query, string $namespace, callable $handle): void
    {
        $start = (int) (($this->checkpoint['entity'] ?? null) === $entity ? ($this->checkpoint['page'] ?? 1) : 1);
        if ($start > 1) {
            $this->log->info(self::LABELS[$entity].': resuming at page '.$start);
        }
        foreach ($this->client->pages($route, $query, $namespace, $start) as [$page, $items, $totalPages, $total]) {
            if ($total !== null) {
                $this->progress['entities'][$entity]['total'] = $total;
            }
            $this->bump($entity, 'fetched', count($items));
            $handle($items, $page); // the handler writes in its own transaction(s), after any further API reads
            $this->checkpoint = ['entity' => $entity, 'page' => $page + 1, 'done' => $this->checkpoint['done'] ?? []] + array_diff_key($this->checkpoint, ['entity' => 1, 'page' => 1, 'done' => 1]);
            $this->save(true);
            $this->checkCancel();
        }
    }

    /** Every item of a (small) collection, e.g. categories that must be written parents first. */
    protected function all(string $entity, string $route, array $query = [], string $namespace = 'wc/v3'): array
    {
        $all = [];
        foreach ($this->client->pages($route, $query, $namespace) as [$page, $items, $totalPages, $total]) {
            array_push($all, ...$items);
            if ($total !== null && $entity !== '') {
                $this->progress['entities'][$entity]['total'] = $total;
            }
            $this->checkCancel();
        }

        return $all;
    }

    protected function transaction(callable $callback): mixed
    {
        return $this->dryRun ? $callback() : DB::transaction($callback);
    }

    /** Upsert, or (dry run) count what would happen. Returns [key => id] of the rows (existing rows only in a dry run). */
    protected function upsert(string $entity, string $table, array $rows, string $key = 'wp_id', array $keepOnUpdate = []): array
    {
        if (! $rows) {
            return [];
        }
        if ($this->dryRun) {
            [$existing, $created, $updated] = $this->u->plan($table, $rows, $key);
            $this->bump($entity, 'created', $created);
            $this->bump($entity, 'updated', $updated);

            return $existing;
        }
        $map = $this->u->save($table, $rows, $key, $keepOnUpdate);
        $this->bump($entity, 'created', $this->u->created);
        $this->bump($entity, 'updated', $this->u->updated);

        return $map;
    }

    /** "existing = skip": drop rows whose key already exists (counted as skipped). */
    protected function withoutExisting(string $entity, string $table, array $rows, string $key = 'wp_id'): array
    {
        if ($this->option('existing', 'update') !== 'skip' || ! $rows) {
            return $rows;
        }
        [$existing] = $this->u->plan($table, $rows, $key);
        $kept = array_values(array_filter($rows, fn ($r) => ! isset($existing[$r[$key]])));
        $this->bump($entity, 'skipped', count($rows) - count($kept));

        return $kept;
    }

    protected function entity(string $entity, string $status): void
    {
        $this->progress['entities'][$entity] = ['status' => $status] + ($this->progress['entities'][$entity] ?? [])
            + ['total' => null, 'fetched' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $this->progress['current'] = $status === 'running' ? $entity : null;
    }

    protected function bump(string $entity, string $counter, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }
        $this->progress['entities'][$entity][$counter] = ($this->progress['entities'][$entity][$counter] ?? 0) + $by;
    }

    /** A warning or error about one remote item (shown on the progress page with its remote id, logged). */
    protected function issue(string $level, string $entity, int|string|null $remoteId, string $message): void
    {
        $message = $this->client->connection->mask($message);
        $line = self::LABELS[$entity].($remoteId !== null && $remoteId !== '' ? ' #'.$remoteId : '').': '.$message;
        $level === 'error' ? $this->log->error($line) : $this->log->warning($line);
        if ($level === 'error') {
            $this->bump($entity, 'failed');
            $this->run->errors++;
        } else {
            $this->run->warnings++;
        }
        $this->progress['issues'][] = ['level' => $level, 'entity' => $entity, 'id' => $remoteId, 'message' => Str::limit($message, 500), 'at' => now()->format('H:i:s')];
        if (count($this->progress['issues']) > 200) {
            $this->progress['issues'] = array_slice($this->progress['issues'], -200);
        }
    }

    /** Persist progress (always when $force, else at most every 2 seconds). */
    protected function save(bool $force = false): void
    {
        if (! $force && microtime(true) - $this->lastSave < 2) {
            return;
        }
        $this->lastSave = microtime(true);
        $this->run->forceFill(['progress' => $this->progress, 'checkpoint' => $this->checkpoint ?: null]);
        // the run row is written outside any page transaction (a failed page must not lose the progress)
        $this->run->saveQuietly();
    }

    protected function checkCancel(): void
    {
        $status = WooApiImport::query()->whereKey($this->run->getKey())->value('status');
        if ($status === 'cancelling') {
            throw new Cancelled;
        }
    }

    // ------------------------------------------------------------------ helpers shared by the entities

    /** A value of a unique column that no other row (except $ownId) uses: "value", "value-2" … */
    protected function unique(string $table, string $column, string $value, ?int $ownId = null, int $max = 190): string
    {
        $value = Str::limit($value, $max, '');
        $candidate = $value;
        for ($i = 2; DB::table($table)->where($column, $candidate)->when($ownId, fn ($q) => $q->where('id', '!=', $ownId))->exists(); $i++) {
            $candidate = Str::limit($value, $max - 6, '').'-'.$i;
        }

        return $candidate;
    }

    /**
     * Rows of $table holding one of $values in $column that this source does not own (other shop / database import),
     * except rows without a wp_id (made by hand – those are adopted). @return array<string,int> value => id
     */
    protected function foreign(string $table, string $column, array $values): array
    {
        if (! $values) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($values)), 500) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk)->whereNotNull('wp_id');
            if (Upserter::scoped($table)) {
                $this->u->source === null ? $query->whereNotNull(Upserter::COLUMN)
                    : $query->where(fn ($q) => $q->whereNull(Upserter::COLUMN)->orWhere(Upserter::COLUMN, '!=', $this->u->source));
            }
            $out += $query->pluck('id', $column)->all();
        }

        return $out;
    }

    /** 301 from the shop's old path to the new one (redirects feature on, real runs, never over an existing rule). */
    protected function redirect(?string $from, string $to): bool
    {
        if ($this->dryRun || $from === null || ! Features::enabled('redirects', false)) {
            return false;
        }
        $from = strtolower(trim(rawurldecode($from), '/'));
        $to = '/'.trim($to, '/').'/';
        if ($from === '' || '/'.$from.'/' === strtolower($to) || Redirect::query()->where('from_path', $from)->exists()) {
            return false;
        }
        $now = now()->format('Y-m-d H:i:s');
        DB::table('redirects')->insert(['from_path' => Str::limit($from, 250, ''), 'to_url' => $to, 'status_code' => 301, 'is_active' => true,
            'hits' => 0, 'created_at' => $now, 'updated_at' => $now]);

        return true;
    }

    protected function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    protected function images(): bool
    {
        return (bool) $this->option('images', true);
    }

    protected function store(): bool
    {
        return $this->client->connection->store;
    }

    /** Download an image (when images are on) and return its public-disk path; failures are warnings. */
    protected function image(string $entity, int|string $remoteId, ?array $image): ?string
    {
        if (! $image || empty($image['src']) || ! $this->images()) {
            return null;
        }
        $path = $this->media->fetch((string) $image['src'], isset($image['id']) ? (int) $image['id'] : null, $image['alt'] ?? null, $image['name'] ?? null, $error);
        if ($path === null) {
            $this->issue('warning', $entity, $remoteId, 'image not imported ('.$error.'): '.UrlGuard::redact((string) $image['src']));
        }

        return $path;
    }

    /** `modified_after` for incremental runs (REST only). */
    protected function since(array $query): array
    {
        $since = $this->option('since');
        if ($since && ! $this->store()) {
            $query['modified_after'] = gmdate('Y-m-d\TH:i:s', strtotime((string) $since));
        }

        return $query;
    }
}
