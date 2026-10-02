<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Import\AdapterRegistry;
use Pine\Commerce\Import\Contracts\Step;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Pipeline;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Import\RenderedSite\RenderedSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\SourceConnection;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * One-way migration of a WordPress/WooCommerce store into this application's database (docs/IMPORTER.md).
 * The source is opened read-only; every record is matched on its WordPress id (or a natural key) and updated in
 * place, so the command can be re-run at any time before go-live.
 */
class ImportWordPressCommand extends Command
{
    protected $signature = 'commerce:import-wordpress
        {--wp-path= : WordPress root – wp-config.php is parsed (never included) for the DB credentials and $table_prefix}
        {--db-host= : Source DB host (overrides wp-config / the configured connection)}
        {--db-port= : Source DB port}
        {--db-name= : Source DB name}
        {--db-user= : Source DB user}
        {--db-pass= : Source DB password}
        {--db-socket= : Source DB unix socket}
        {--prefix= : WordPress table prefix (default: wp-config, else auto-detected)}
        {--site-url= : Base URL of a running copy of the source site, to render page-builder content over HTTP}
        {--site-host= : Host header to send with --site-url}
        {--snapshots= : Directory of pre-rendered HTML snapshots (comma separated for several)}
        {--uploads-path= : wp-content/uploads to copy from (default {wp-path}/wp-content/uploads)}
        {--copy-uploads : Copy/verify upload files into the public disk (uploads/)}
        {--target= : Target DB connection (default: the default connection), e.g. scratch}
        {--only= : Comma list of sections/steps (settings,media,users,catalog,orders,extras,content,menus,redirects, step keys, aliases)}
        {--skip= : Comma list of sections/steps to skip}
        {--orders-source= : auto|hpos|posts}
        {--core-only : Ignore the client importer config/adapters (package defaults only) – shows what a new client gets}
        {--no-wp-cli : Do not ask wp-cli for permalinks}
        {--dry-run : Read, transform and report – every write is rolled back}
        {--fresh : Delete previously imported records of the selected steps first}
        {--force : Allow --fresh on the default connection when it already holds imported data}
        {--detect : Print the detected site profile and adapters, then exit}';

    /** The pre-package command name keeps working. */
    protected $aliases = ['import:wordpress'];

    protected $description = 'Import a WordPress/WooCommerce store: products, orders, customers, content, menus, redirects, media';

    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');
        $started = microtime(true);

        $config = $this->importConfig();
        if ($target = $this->option('target')) {
            if (! config('database.connections.'.$target)) {
                $this->error("Unknown target connection '$target'.");

                return self::FAILURE;
            }
            // Only switch the default. Never DB::purge() here: services resolved at boot (e.g. the database cache
            // store) keep the old Connection object, and when it reconnects the manager refreshes the PDO of the
            // live connection too – which resets its transaction level and silently commits a --dry-run.
            config(['database.default' => $target]);
        }

        // ---------------------------------------------------------------- source
        $wpPath = $this->option('wp-path') ?: ($config['source']['wp_path'] ?? null);
        $source = new SourceConnection([
            'wp_path' => $wpPath, 'prefix' => $this->option('prefix'),
            'db_host' => $this->option('db-host'), 'db_port' => $this->option('db-port'), 'db_name' => $this->option('db-name'),
            'db_user' => $this->option('db-user'), 'db_pass' => $this->option('db-pass'), 'db_socket' => $this->option('db-socket'),
        ], $config['source'] ?? []);
        try {
            $connection = $source->register();
            $wp = new WordPressSource($connection);
            $wp->db()->getPdo();
            $site = SiteProfile::detect($wp);
        } catch (\Throwable $e) {
            $this->error('Cannot open the WordPress database: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($wpPath && is_file($versionFile = rtrim($wpPath, '/').'/wp-includes/version.php')
            && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($versionFile), $m)) {
            $site->wpVersion = $m[1]; // parsed, never included
        }

        $snapshots = $this->option('snapshots') ? explode(',', $this->option('snapshots')) : (array) ($config['source']['snapshots'] ?? []);
        $rendered = new RenderedSource(array_values(array_filter(array_map('trim', $snapshots))),
            $this->option('site-url') ?: ($config['source']['site_url'] ?? null),
            $this->option('site-host') ?: ($config['source']['site_host'] ?? null),
            storage_path('app/import/rendered'));
        // client adapters: config commerce-import.adapters.extra + Commerce::importAdapter()
        $registry = new AdapterRegistry(AdapterRegistry::CORE,
            array_merge((array) ($config['adapters']['extra'] ?? []), app(ExtensionRegistry::class)->importAdapters()),
            (array) ($config['adapters']['disable'] ?? []), (array) ($config['adapters']['enable'] ?? []));

        $ctx = new ImportContext($this, $wp, $site, $registry, $rendered, $config);
        $ctx->fresh = (bool) $this->option('fresh');
        $ctx->dryRun = (bool) $this->option('dry-run');
        $ctx->options = [
            'wp_path' => $wpPath, 'uploads_path' => $this->option('uploads-path'), 'copy_uploads' => (bool) $this->option('copy-uploads'),
            'orders_source' => $this->option('orders-source') ?: null, 'no_wp_cli' => (bool) $this->option('no-wp-cli'),
        ];
        $ctx->boot();

        if ($this->option('detect')) {
            $this->printProfile($ctx, $source);

            return self::SUCCESS;
        }

        // ---------------------------------------------------------------- steps
        try {
            $pipeline = static::pipeline($registry);
            $steps = $pipeline->select($this->option('only'), $this->option('skip'));
            // steps of switched-off features (config commerce.features.*) are skipped, e.g. no posts without the blog
            $off = array_filter($steps, fn (Step $s) => ($feature = Features::forImportStep($s)) !== null && ! Features::enabled($feature, false));
            if ($off) {
                $this->warn('Feature switched off – skipping '.implode(', ', array_map(fn (Step $s) => $s->key().' ('.Features::forImportStep($s).')', $off)).'.');
                $steps = array_values(array_filter($steps, fn (Step $s) => ! in_array($s, $off, true)));
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $wooOnly = array_filter($steps, fn (Step $s) => in_array($s->section(), ['catalog', 'orders', 'extras'], true));
        if ($wooOnly && ! $registry->isActive('woocommerce')) {
            if ($this->option('only')) {
                $this->error('WooCommerce is not active on the source site – import only content, menus, redirects, users or settings (--only=…).');

                return self::FAILURE;
            }
            // a plain WordPress site: import what exists instead of failing on the missing WooCommerce tables
            $steps = array_values(array_filter($steps, fn (Step $s) => ! in_array($s, $wooOnly, true)));
            $this->warn('WooCommerce is not active on the source site – skipping '.implode(', ', array_map(fn (Step $s) => $s->key(), $wooOnly)).'.');
        }
        if (in_array($this->option('orders-source'), ['hpos'], true) && ! $wp->hasTable('wc_orders')) {
            $this->error('--orders-source=hpos but the source has no wc_orders table.');

            return self::FAILURE;
        }
        if ($ctx->fresh && ! $this->option('target') && ! $ctx->dryRun && ! $this->option('force')
            && DB::table('products')->whereNotNull('wp_id')->exists()) {
            $this->error('--fresh on the default connection deletes imported data that may have been edited since. '
                .'Use --target=<connection> for test imports, or add --force.');

            return self::FAILURE;
        }

        $this->line(sprintf('Source <info>%s</info> (%s, prefix %s) – WordPress %s, WooCommerce %s, orders in %s%s',
            $site->homeUrl ?: $site->siteUrl, $site->database, $site->prefix, $site->wpVersion ?? '?', $site->wooVersion ?? '–',
            $ctx->orderSource()->storage(), $ctx->dryRun ? ' – <comment>DRY RUN</comment>' : ''));
        $this->line('Target connection <info>'.config('database.default').'</info>'.(($p = DB::connection()->getTablePrefix()) ? " (prefix $p)" : '')
            .'; adapters: '.implode(', ', array_map(fn ($a) => $a->key(), $registry->active())));
        if ($ctx->fresh) {
            $this->warn('--fresh: removing previously imported records for: '.implode(', ', array_map(fn ($s) => $s->key(), $steps)));
        }

        DB::disableQueryLog();
        $wp->db()->disableQueryLog();
        try {
            Pipeline::run($ctx, $steps, function (Step $step, callable $run) use ($ctx) {
                $t = microtime(true);
                $this->components->task($step->key(), function () use ($run) {
                    $run();

                    return true;
                });
                $ctx->info(sprintf('%s done in %.1fs', $step->key(), microtime(true) - $t));
            });
        } catch (\Throwable $e) {
            $this->writeLog($ctx, $e);
            $this->error(($ctx->dryRun ? 'Dry run' : 'Import').' failed: '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');

            return self::FAILURE;
        }

        if (! $ctx->dryRun) {
            // the WooCommerce REST API importer (1.5) recognises this site: same host = update these rows, not new ones
            try {
                \Pine\Commerce\Models\Setting::set('import.wordpress.site_url', $site->homeUrl ?: $site->siteUrl, 'import');
            } catch (\Throwable) {
                // settings table missing on an unusual target – only a convenience
            }
            Cache::forget('settings.all');
            foreach (DB::table('menus')->pluck('location') as $location) {
                Cache::forget('menu.'.$location);
            }
        }

        $this->newLine();
        $this->table(['Entity', 'WordPress', 'Laravel'.($ctx->dryRun ? ' (dry run)' : ''), 'Notes'], collect($ctx->summary)
            ->map(fn ($r, $label) => [$label, $r['wp'], $r['laravel'], $r['note']])->values()->all());

        if ($ctx->warnings) {
            $this->newLine();
            $this->warn(count($ctx->warnings).' warning(s):');
            foreach (array_slice($ctx->warnings, 0, 60) as $w) {
                $this->line('  - '.$w);
            }
            if (count($ctx->warnings) > 60) {
                $this->line('  … '.(count($ctx->warnings) - 60).' more (see the log)');
            }
        }
        $log = $this->writeLog($ctx);

        $this->newLine();
        $this->info(sprintf('WordPress import %s in %.1fs. Report: %s', $ctx->dryRun ? 'dry run finished (nothing written)' : 'finished',
            microtime(true) - $started, str_replace(base_path().'/', '', $log)));

        return self::SUCCESS;
    }

    /** `commerce-import` config; --core-only = the package defaults (client mappings/adapters ignored), source settings kept. */
    private function importConfig(): array
    {
        $config = (array) config('commerce-import', []);
        if ($this->option('core-only')) {
            $defaults = require dirname(__DIR__, 2).'/config/commerce-import.php';
            $defaults['source'] = $config['source'] ?? $defaults['source'];
            $config = $defaults;
        }

        return $config;
    }

    private function writeLog(ImportContext $ctx, ?\Throwable $error = null): string
    {
        $file = storage_path('logs/import-wordpress-'.now()->format('Ymd-His').'.log');
        $payload = [
            'finished_at' => now()->toDateTimeString(),
            'dry_run' => $ctx->dryRun,
            'target' => config('database.default'),
            'source' => ['site' => $ctx->site->homeUrl, 'database' => $ctx->site->database, 'prefix' => $ctx->site->prefix,
                'orders' => $ctx->site->ordersStorage],
            'adapters' => array_map(fn ($a) => $a->key(), $ctx->adapters->active()),
            'summary' => $ctx->summary,
            'warnings' => $ctx->warnings,
        ];
        if ($error) {
            $payload['error'] = $error->getMessage().' @ '.$error->getFile().':'.$error->getLine();
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        @file_put_contents($file, $json);
        @file_put_contents(storage_path('logs/import-wordpress.log'), $json); // latest run

        return $file;
    }

    private function printProfile(ImportContext $ctx, SourceConnection $source): void
    {
        $site = $ctx->site;
        $woo = $site->woo;
        $this->table(['Setting', 'Value'], [
            ['Site URL / home', $site->siteUrl.' / '.$site->homeUrl],
            ['Database / prefix', $site->database.' / '.$site->prefix.' ('.($source->origin['prefix'] ?? '?').')'],
            ['Credentials from', collect($source->origin)->except('prefix')->map(fn ($v, $k) => "$k: $v")->implode(', ')],
            ['WordPress', ($site->wpVersion ?? '?').' (db_version '.($site->wpDbVersion ?? '?').')'],
            ['WooCommerce', $site->wooVersion ?? '–'],
            ['Theme', $site->theme.($site->template !== $site->theme ? ' (parent '.$site->template.')' : '')],
            ['Permalinks', ($site->permalinkStructure ?: 'plain').' | product base '.($woo['permalinks']['product_base'] ?: '(default)')
                .' | category base '.($woo['permalinks']['category_base'] ?: '(default)')],
            ['Orders storage', $site->ordersStorage.($site->hposSync ? ' (sync on)' : '').' → reading '.$ctx->orderSource()->storage()],
            ['Currency', ($woo['currency'] ?? '?').' ('.($woo['currency_pos'] ?? '').', '.($woo['decimals'] ?? '').' dp)'],
            ['Tax', ($woo['calc_taxes'] ?? false ? 'enabled' : 'disabled').', prices '.(($woo['prices_include_tax'] ?? false) ? 'incl.' : 'excl.').' tax, shop display '.($woo['tax_display_shop'] ?? '')],
            ['Units', ($woo['weight_unit'] ?? '').' / '.($woo['dimension_unit'] ?? '')],
            ['Country / timezone', ($woo['default_country'] ?? '').' / '.$site->timezone],
            ['Uploads URL', $site->uploadsUrl],
            ['Front page / posts page', $site->pageOnFront.' / '.$site->pageForPosts],
            ['Nav menu locations', $site->menuLocations ? collect($site->menuLocations)->map(fn ($t, $l) => "$l=$t")->implode(', ') : '(none)'],
            ['Rendered HTML', $ctx->rendered->canFetch() ? 'snapshots + HTTP' : 'snapshots only'],
        ]);
        $this->line('Active plugins ('.count($site->activePlugins).'): '.implode(', ', array_map(fn ($p) => explode('/', $p)[0], $site->activePlugins)));
        $rows = [];
        foreach ($ctx->adapters->all() as $key => $a) {
            $rows[] = [$key, $a['adapter']->label(), $a['active'] ? '<info>yes</info>' : 'no', $a['client'] ? 'client' : 'core',
                $a['adapter']->priority(), implode(', ', AdapterRegistry::capabilitiesOf($a['adapter']))];
        }
        $this->table(['Adapter', 'Label', 'Active', 'Kind', 'Priority', 'Capabilities'], $rows);
        $this->line('Steps: '.implode(' → ', array_map(fn ($s) => $s->key(), static::pipeline($ctx->adapters)->steps())));
    }

    /** Core steps + steps of active adapters + client steps registered with Commerce::importStep(). */
    public static function pipeline(AdapterRegistry $registry): Pipeline
    {
        $client = array_map(fn ($step) => is_string($step) ? app($step) : $step, app(ExtensionRegistry::class)->importSteps());

        return new Pipeline(array_merge($registry->steps(), $client));
    }
}
