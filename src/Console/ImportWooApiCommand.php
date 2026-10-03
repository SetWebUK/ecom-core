<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Import\WooApi\Client;
use Pine\Commerce\Import\WooApi\Connection;
use Pine\Commerce\Import\WooApi\Importer;
use Pine\Commerce\Import\WooApi\Probe;
use Pine\Commerce\Import\WooApi\RunLog;
use Pine\Commerce\Import\WooApi\RunManager;
use Pine\Commerce\Import\WooApi\SameSite;
use Pine\Commerce\Import\WooApi\StoredConnection;
use Pine\Commerce\Models\WooApiImport;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * Import from a WooCommerce shop's REST API (docs/IMPORTER.md "Importing via the WooCommerce REST API").
 *
 *   php artisan commerce:import-woo-api 12          run #12, created in Admin › Import › WooCommerce API (what the admin
 *                                                   starts in the background; resumes from its checkpoint)
 *   php artisan commerce:import-woo-api --url=https://shop.example --key=ck_… --secret=cs_… [--only=products,orders] [--dry-run] [--since=2026-09-01]
 *   php artisan commerce:import-woo-api --test      test the connection: store, versions, counts, permission problems
 *
 * Without --url/--key/--secret the values come from WOO_API_URL / WOO_API_KEY / WOO_API_SECRET, else from the
 * connection saved in the admin. Secrets are never printed or logged.
 */
class ImportWooApiCommand extends Command
{
    protected $signature = 'commerce:import-woo-api
        {run? : A run created in Admin › Import › WooCommerce API}
        {--url= : Shop address (env WOO_API_URL)}
        {--key= : Consumer key ck_… (env WOO_API_KEY)}
        {--secret= : Consumer secret cs_… (env WOO_API_SECRET)}
        {--wp-user= : WordPress user for a WordPress application password (private pages/posts)}
        {--wp-password= : WordPress application password (env WOO_API_WP_PASSWORD)}
        {--auth=auto : auto | basic | query | oauth}
        {--insecure : Do not verify the TLS certificate (staging shops only)}
        {--store : Public Store API, no key – catalogue, pages, posts and media only}
        {--only= : Comma list: categories, attributes, products, customers, coupons, orders, reviews, shipping_tax, content, media (default: everything)}
        {--dry-run : Read and map everything, write nothing}
        {--since= : Only items changed since this date (incremental re-sync)}
        {--orders-after= : Only orders placed on or after this date (Y-m-d)}
        {--skip-existing : Leave items that were imported before untouched}
        {--no-images : Do not download images}
        {--no-notes : Do not import order notes (one request per order)}
        {--same-site : This shop was imported from the same site with commerce:import-wordpress – update those rows}
        {--test : Test the connection and show what there is to import}';

    protected $description = 'Import products, categories, customers, orders … from a WooCommerce shop\'s REST API (API key) or its public Store API';

    public const ALIASES = ['shipping' => 'shipping_tax', 'tax' => 'shipping_tax', 'pages' => 'content', 'posts' => 'content', 'blog' => 'content',
        'images' => 'media', 'variations' => 'products', 'tags' => 'products', 'refunds' => 'orders', 'guests' => 'customers'];

    public function handle(RunManager $manager): int
    {
        if (! Features::enabled('woo_api_import', false)) {
            $this->error('The WooCommerce API import is switched off (config commerce.features.woo_api_import).');

            return self::FAILURE;
        }
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        if ($this->argument('run') !== null) {
            $run = WooApiImport::query()->find((int) $this->argument('run'));
            if ($run && $run->isResumable()) {
                $run = $manager->resume($run); // interrupted / failed / cancelled: continue from its checkpoint
            }
            if (! $run || $run->status !== 'pending') {
                $this->error('Import #'.$this->argument('run').' is not waiting to run'.($run ? " (status: {$run->status})" : '').'.');

                return self::FAILURE;
            }
            try {
                $connection = $this->runConnection($run);
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            return $this->runImport($run, $connection);
        }

        try {
            $connection = $this->connection();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($connection->url === '' || (! $connection->store && ! $connection->hasKeys())) {
            $this->error('Give --url, --key and --secret (or WOO_API_URL / WOO_API_KEY / WOO_API_SECRET), or --store for the public catalogue.');

            return self::FAILURE;
        }
        if ($this->option('test')) {
            return $this->test($connection);
        }

        try {
            $entities = $this->entities($connection);
            $run = $manager->create($connection, [
                'entities' => $entities,
                'images' => ! $this->option('no-images'),
                'existing' => $this->option('skip-existing') ? 'skip' : 'update',
                'orders_after' => $this->option('orders-after'),
                'since' => $this->option('since'),
                'notes' => ! $this->option('no-notes'),
            ], (bool) $this->option('dry-run'), $this->option('same-site') || SameSite::matches($connection), null, 'cli');
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line('Import #'.$run->id.' – '.$connection->describe().' – '.implode(', ', $entities).($run->dry_run ? ' – <comment>DRY RUN</comment>' : ''));

        return $this->runImport($run, $connection);
    }

    protected function runImport(WooApiImport $run, Connection $connection): int
    {
        $lock = RunManager::lock();
        if (! $lock->acquire(5)) {
            $this->error('Another WooCommerce API import is running.');

            return self::FAILURE;
        }
        $log = RunLog::for($run->id, $connection);
        $client = new Client($connection, null, fn (string $line) => $log->debug($line));
        $importer = new Importer($client, $run, $log);
        $status = self::SUCCESS;
        try {
            $importer->run();
        } catch (Throwable $e) {
            $this->error('Import failed: '.$connection->mask($e->getMessage()));
            $status = self::FAILURE;
        } finally {
            $lock->release();
        }
        $run->refresh();
        $rows = [];
        foreach ($run->entities() as $entity) {
            $p = $run->entityProgress($entity);
            $rows[] = [Importer::LABELS[$entity], $p['status'], $p['total'] ?? '—', $p['fetched'], $p['created'], $p['updated'], $p['skipped'], $p['failed']];
        }
        $this->table(['Entity', 'Status', 'Total', 'Read', $run->dry_run ? 'Would create' : 'Created', $run->dry_run ? 'Would update' : 'Updated', 'Skipped', 'Failed'], $rows);
        $this->line(sprintf('Import #%d %s – %d warnings, %d errors. Log: %s', $run->id, $run->status, $run->warnings, $run->errors,
            str_replace(base_path().'/', '', $log->file)));

        return $status === self::SUCCESS && $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    protected function test(Connection $connection): int
    {
        $result = (new Probe(new Client($connection)))->run();
        if ($result['store']) {
            $this->line('Store: <info>'.$result['store']['name'].'</info> ('.$result['store']['home'].')');
        }
        if ($result['versions']) {
            $this->line('WooCommerce '.$result['versions']['woocommerce'].', WordPress '.$result['versions']['wordpress'].', currency '.$result['versions']['currency']);
        }
        $this->line('Authentication: '.$result['auth'].', REST URLs: '.$result['route_style']);
        if ($result['counts']) {
            $this->table(['Entity', 'Items'], collect($result['counts'])->map(fn ($n, $e) => [$e, $n ?? '–'])->values()->all());
        }
        foreach ($result['problems'] as $entity => $problem) {
            $this->warn("$entity: $problem");
        }
        if ($result['error']) {
            $this->error($result['error']);

            return self::FAILURE;
        }
        $this->info('Connection OK.');

        return self::SUCCESS;
    }

    /** Connection from options → env → the admin's saved connection. */
    protected function connection(): Connection
    {
        $saved = StoredConnection::values();
        $pick = fn (string $option, string $env, string $savedKey) => ($this->option($option) ?: env($env)) ?: ($saved[$savedKey] ?? '');
        $url = $pick('url', 'WOO_API_URL', 'url');
        $fromSaved = ! $this->option('url') && ! env('WOO_API_URL');

        return Connection::make([
            'url' => $url,
            'key' => $pick('key', 'WOO_API_KEY', 'key'),
            'secret' => $pick('secret', 'WOO_API_SECRET', 'secret'),
            'wp_user' => $this->option('wp-user') ?: ($fromSaved ? $saved['wp_user'] : (env('WOO_API_WP_USER') ?: '')),
            'wp_password' => $this->option('wp-password') ?: (env('WOO_API_WP_PASSWORD') ?: ($fromSaved ? $saved['wp_password'] : '')),
            'verify_tls' => $this->option('insecure') ? false : ($fromSaved ? $saved['verify_tls'] : true),
            'auth' => $this->option('auth') !== 'auto' ? $this->option('auth') : ($fromSaved ? $saved['auth'] : 'auto'),
            'store' => $this->option('store') || ($fromSaved && $saved['store']),
        ]);
    }

    /** The connection of a stored run: --url/--key… or WOO_API_* when given, else the admin's saved connection; it must point at the run's shop. */
    protected function runConnection(WooApiImport $run): Connection
    {
        $connection = $this->option('url') || env('WOO_API_URL')
            ? $this->connection()
            : Connection::make(['store' => $run->mode === 'store'] + StoredConnection::values());
        if ($connection->url !== $run->site_url) {
            throw new \RuntimeException("Import #{$run->id} reads {$run->site_url}, but the connection points at ".($connection->url ?: '(nothing)').' – pass --url/--key/--secret for that shop.');
        }

        return $connection;
    }

    /** @return list<string> */
    protected function entities(Connection $connection): array
    {
        $available = $connection->store ? Importer::STORE_CHOICES : array_keys(Importer::CHOICES);
        $only = array_filter(array_map(fn ($e) => self::ALIASES[strtolower(trim($e))] ?? strtolower(trim($e)), explode(',', (string) $this->option('only'))));
        if (! $only) {
            return $available;
        }
        $unknown = array_diff($only, array_keys(Importer::CHOICES));
        if ($unknown) {
            throw new \InvalidArgumentException('Unknown --only value(s): '.implode(', ', $unknown).'. Valid: '.implode(', ', array_keys(Importer::CHOICES)).' (aliases: '.implode(', ', array_keys(self::ALIASES)).')');
        }

        return array_values(array_intersect($available, $only));
    }
}
