<?php

namespace Pine\Commerce\Support;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Commerce;
use Pine\Commerce\Services\Payments\PaymentManager;
use Throwable;

/**
 * Health checks of a pine/commerce install, shared by `php artisan commerce:doctor` and Admin › Settings › System.
 *
 * Every check returns ['status' => pass|warn|fail|info, 'title', 'message', 'fix' (what to do, null when nothing)].
 * Checks never write anything and never throw: a check that cannot run reports itself as a warning.
 */
class Doctor
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const INFO = 'info';

    /** @var list<array{status:string, group:string, title:string, message:string, fix:?string}> */
    protected array $results = [];

    protected string $group = 'Application';

    public function __construct(protected ?string $connection = null)
    {
        $this->connection ??= config('database.default');
    }

    /** @return list<array{status:string, group:string, title:string, message:string, fix:?string}> */
    public function run(): array
    {
        $this->results = [];

        foreach ([
            'Application' => 'application',
            'Database' => 'database',
            'Files & assets' => 'files',
            'Theme' => 'theme',
            'Mail' => 'mail',
            'Payments' => 'payments',
            'Server' => 'server',
            'Importer' => 'importer',
        ] as $group => $method) {
            $this->group = $group;
            try {
                $this->{$method}();
            } catch (Throwable $e) {
                $this->warn($group.' checks', 'Could not run: '.$e->getMessage());
            }
        }

        return $this->results;
    }

    /** @return array{pass:int, warn:int, fail:int, info:int} */
    public static function counts(array $results): array
    {
        $counts = [self::PASS => 0, self::WARN => 0, self::FAIL => 0, self::INFO => 0];
        foreach ($results as $r) {
            $counts[$r['status']]++;
        }

        return $counts;
    }

    // ---------------------------------------------------------------------------------------------------------------

    protected function application(): void
    {
        $production = app()->environment('production');

        $this->info('Platform', 'pine/commerce '.Commerce::VERSION.', Laravel '.app()->version().', PHP '.PHP_VERSION);

        config('app.key')
            ? $this->pass('APP_KEY', 'Set.')
            : $this->fail('APP_KEY', 'Missing – sessions, cookies and the encrypted payment keys cannot work.', 'php artisan key:generate');

        $production
            ? $this->pass('APP_ENV', 'production')
            : $this->warn('APP_ENV', 'Running as "'.app()->environment().'".', 'Set APP_ENV=production on the live server.');

        if (config('app.debug')) {
            $production
                ? $this->fail('APP_DEBUG', 'Debug mode is ON in production – error pages leak code and secrets.', 'Set APP_DEBUG=false in .env, then php artisan optimize.')
                : $this->warn('APP_DEBUG', 'Debug mode is on (fine locally, never on a live site).', 'Set APP_DEBUG=false before go-live.');
        } else {
            $this->pass('APP_DEBUG', 'Off.');
        }

        $url = (string) config('app.url');
        if (! str_starts_with($url, 'https://')) {
            $this->{$production ? 'fail' : 'warn'}('APP_URL', "\"{$url}\" is not https – links, cookies and payment return URLs need https.", 'Set APP_URL=https://your-domain (no trailing slash).');
        } elseif (str_ends_with($url, '/')) {
            $this->warn('APP_URL', "\"{$url}\" ends with a slash.", 'Remove the trailing slash from APP_URL.');
        } elseif (preg_match('#^https://(localhost|127\.|example\.)#', $url)) {
            $this->warn('APP_URL', "\"{$url}\" looks like a placeholder.", 'Set APP_URL to the real site address.');
        } else {
            $this->pass('APP_URL', $url);
        }

        config('commerce.legacy_aliases')
            ? $this->warn('Legacy class aliases', 'commerce.legacy_aliases is on (transitional App\\… names).', 'Update code that uses old App\\… classes, then set legacy_aliases to false.')
            : $this->pass('Legacy class aliases', 'Off.');

        if ($production) {
            app()->configurationIsCached()
                ? $this->pass('Config cache', 'Cached.')
                : $this->warn('Config cache', 'Configuration is not cached (slower, and .env is read on every request).', 'php artisan optimize');
            app()->routesAreCached()
                ? $this->pass('Route cache', 'Cached.')
                : $this->warn('Route cache', 'Routes are not cached.', 'php artisan optimize');
        }

        if (filter_var(setting('seo.discourage_search_engines', false), FILTER_VALIDATE_BOOL)) {
            $this->warn('Search engines', 'The site asks search engines not to index it (Settings › SEO & tracking).', 'Untick "Ask search engines not to index this site" at go-live (keep it on staging).');
        } else {
            $this->pass('Search engines', 'Indexing allowed.');
        }
    }

    protected function database(): void
    {
        try {
            DB::connection($this->connection)->getPdo();
            $this->pass('Connection', "Connected ({$this->connection}: ".DB::connection($this->connection)->getDatabaseName().').');
        } catch (Throwable $e) {
            $this->fail('Connection', "Cannot connect ({$this->connection}): ".$e->getMessage(), 'Check DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env.');

            return;
        }

        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        $repository->setSource($this->connection);
        if (! $repository->repositoryExists()) {
            $this->fail('Migrations', 'The database has not been installed yet.', 'php artisan commerce:install');

            return;
        }
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $pending = array_values(array_diff(array_keys($files), $repository->getRan()));
        $pending
            ? $this->fail('Migrations', count($pending).' pending: '.implode(', ', array_slice($pending, 0, 5)).(count($pending) > 5 ? ' …' : ''), 'php artisan migrate --force')
            : $this->pass('Migrations', 'Up to date ('.count($files).').');

        $db = DB::connection($this->connection);
        $admins = $db->table('users')->where('role', 'admin')->where('is_active', true)->count();
        $admins
            ? $this->pass('Administrator', $admins.' active administrator account(s).')
            : $this->fail('Administrator', 'No active administrator can sign in to the back office.', 'php artisan commerce:install --admin-email=you@example.com');

        $shipping = $db->table('shipping_methods')->where('is_active', true)->count();
        $shipping
            ? $this->pass('Shipping', $shipping.' active delivery option(s).')
            : $this->fail('Shipping', 'No active delivery option – customers cannot check out.', 'Admin › Settings › Shipping, or php artisan commerce:install.');

        $home = $db->table('pages')->where('path', '')->where('status', 'published')->exists();
        $home
            ? $this->pass('Home page', 'Published.')
            : $this->warn('Home page', 'No published page with the home template.', 'Admin › Content › Pages, or php artisan commerce:install.');
    }

    protected function files(): void
    {
        $storage = public_path('storage');
        if (is_link($storage)) {
            $this->fail('public/storage', 'Is a symlink – LiteSpeed will not serve uploads through it.', 'rm public/storage && mkdir public/storage, move the files from storage/app/public into it, then php artisan commerce:install (adds the .htaccess).');
        } elseif (! is_dir($storage)) {
            $this->fail('public/storage', 'Missing – uploaded images cannot be stored or served.', 'php artisan commerce:install (creates it as a real directory).');
        } elseif (! is_file($storage.'/.htaccess')) {
            $this->warn('public/storage', 'Real directory, but without the hardening .htaccess (scripts could run from uploads).', 'php artisan commerce:install (writes public/storage/.htaccess).');
        } else {
            $this->pass('public/storage', 'Real directory with hardening .htaccess.');
        }

        foreach (['storage' => storage_path(), 'storage/logs' => storage_path('logs'), 'storage/framework' => storage_path('framework'), 'bootstrap/cache' => base_path('bootstrap/cache'), 'public/storage' => $storage] as $label => $dir) {
            if (is_dir($dir) && ! is_writable($dir)) {
                $this->fail("{$label} writable", "{$label} is not writable by PHP.", "chmod -R ug+rwX {$label}");
            }
        }

        $base = trim((string) config('commerce.admin.assets_url', 'vendor/commerce/admin'), '/');
        $state = static::adminAssetsState();
        match ($state['status']) {
            'missing' => $this->fail('Admin assets', "Not published to public/{$base}.", 'php artisan commerce:publish'),
            'symlink' => $this->fail('Admin assets', "public/{$base} is a symlink – LiteSpeed will not serve it.", "Remove the link, then php artisan commerce:publish"),
            'stale' => $this->warn('Admin assets', count($state['stale']).' file(s) in public/'.$base.' differ from the package (e.g. '.implode(', ', array_slice($state['stale'], 0, 3)).').', 'php artisan commerce:publish'),
            default => $this->pass('Admin assets', "Published to public/{$base} and up to date ({$state['files']} files)."),
        };

        $placeholder = (string) config('commerce.media.placeholder', 'images/placeholder.png');
        is_file(public_path($placeholder))
            ? $this->pass('Placeholder image', 'public/'.$placeholder)
            : $this->warn('Placeholder image', 'public/'.$placeholder.' is missing (products without images show a broken image).', 'php artisan commerce:publish');

        // image sizes (commerce.images): a PHP image library must be there to generate them
        $sizes = \Pine\Commerce\Services\Media\Images::sizes();
        $driver = \Pine\Commerce\Services\Media\ImageGenerator::makeDriver((string) \Pine\Commerce\Services\Media\Images::config('driver', 'auto'));
        if (! $sizes || ! \Pine\Commerce\Services\Media\Images::config('generate_on_upload', true)) {
            $this->info('Image sizes', 'Not generated on upload (commerce.images).');
        } elseif (! $driver) {
            $this->warn('Image sizes', 'Neither the Imagick nor the GD PHP extension is available – uploads get no size variants.', 'Enable the imagick or gd PHP extension');
        } else {
            $webp = \Pine\Commerce\Services\Media\Images::config('webp', true) ? ($driver->canWrite('webp') ? ', WebP twins' : ', WebP not supported by '.$driver->name()) : '';
            $this->pass('Image sizes', implode(', ', array_keys($sizes)).' with '.$driver->name().$webp.' (existing media: php artisan commerce:images:generate --missing).');
        }
    }

    protected function theme(): void
    {
        $slug = (string) config('commerce.theme', 'default');
        $managerClass = 'Pine\\Commerce\\Theme\\ThemeManager';

        if (! class_exists($managerClass)) {
            $this->info('Active theme', "\"{$slug}\" (the theme system is not part of this package version yet – storefront views come from resources/views).");

            return;
        }

        try {
            $manager = app($managerClass);
            $theme = $manager->active();
            $this->pass('Active theme', ($theme->name ?? $slug).' ('.($theme->slug ?? $slug).(isset($theme->version) ? ' '.$theme->version : '').')');
        } catch (Throwable $e) {
            $this->fail('Active theme', "\"{$slug}\" cannot be loaded: ".$e->getMessage(), 'Set COMMERCE_THEME to an installed theme (themes/{slug}/theme.json), or php artisan commerce:theme:make '.$slug);

            return;
        }

        $contract = 'Pine\\Commerce\\Theme\\ThemeContract';
        if (class_exists($contract) && method_exists($contract, 'check')) {
            $problems = $contract::check($manager, $theme->slug ?? $slug);
            $problems
                ? $this->fail('Theme contract', count($problems).' problem(s): '.implode('; ', array_slice($problems, 0, 3)).(count($problems) > 3 ? ' …' : ''), 'php artisan commerce:theme:check '.($theme->slug ?? $slug))
                : $this->pass('Theme contract', 'Every contract view resolves (commerce:theme:check).');
        }

        // one-off overrides in resources/views shadow the theme (risk R7) - fine when intended, but list them
        if (method_exists($theme, 'viewsPath') && is_dir(resource_path('views'))) {
            $shadowed = [];
            foreach ($manager->chain() as $link) {
                $dir = $link->viewsPath();
                if (! is_dir($dir)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/');
                    if (is_file(resource_path('views/'.$relative))) {
                        $shadowed[$relative] = true;
                    }
                }
            }
            $shadowed
                ? $this->warn('View overrides', count($shadowed).' file(s) in resources/views shadow the theme: '.implode(', ', array_slice(array_keys($shadowed), 0, 5)).(count($shadowed) > 5 ? ' …' : ''), 'Move intended changes into the theme and delete the copies from resources/views.')
                : $this->pass('View overrides', 'No file in resources/views shadows the theme.');
        }

        if (method_exists($theme, 'supports')) {
            $viewFeatures = ['wishlist' => 'wishlist', 'reviews' => 'reviews', 'stock_alerts' => 'stock-alerts', 'newsletter' => 'newsletter',
                'blog' => 'blog', 'order_tracking' => 'order-tracking', 'contact_form' => 'contact-form', 'quick_view' => 'quick-view'];
            $unsupported = [];
            foreach ($viewFeatures as $flag => $support) {
                if (config("commerce.features.{$flag}") && ! $theme->supports($support)) {
                    $unsupported[] = $flag;
                }
            }
            $unsupported
                ? $this->warn('Features vs theme', 'Switched on but not supported by the theme: '.implode(', ', $unsupported).'.', 'Switch them off in config/commerce.php (features) or add the views to the theme and list them in theme.json "supports".')
                : $this->pass('Features vs theme', 'Every switched-on storefront feature is supported by the theme.');
        }
    }

    protected function mail(): void
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            app()->environment('production')
                ? $this->warn('Mailer', "MAIL_MAILER={$mailer}: order and password emails are NOT sent.", 'Set MAIL_MAILER=smtp (or ses/postmark/resend) with its credentials in .env.')
                : $this->info('Mailer', "MAIL_MAILER={$mailer} (emails are written to the log).");
        } else {
            $this->pass('Mailer', $mailer);
        }

        $from = (string) (setting('emails.from_address') ?: config('mail.from.address'));
        if ($from === '' || preg_match('/@(example\.(com|org|net|test)|localhost)$/i', $from)) {
            $this->warn('Sender address', "\"{$from}\" is a placeholder.", 'Set MAIL_FROM_ADDRESS in .env or Admin › Settings › Emails › Sender email address.');
        } else {
            $this->pass('Sender address', $from);
        }

        $admin = (string) (setting('emails.admin_address') ?: setting('emails.admin_recipient') ?: setting('store.email'));
        $admin !== ''
            ? $this->pass('Order notifications', 'Sent to '.$admin)
            : $this->warn('Order notifications', 'No address receives new-order emails.', 'Admin › Settings › Emails › Send order notifications to.');
    }

    protected function payments(): void
    {
        $available = 0;
        foreach (app(PaymentManager::class)->all() as $code => $gateway) {
            if (! $gateway->isEnabled()) {
                continue;
            }
            if (! $gateway->isConfigured()) {
                $this->fail($gateway->title(), "Enabled but not fully configured ({$code}) – hidden at checkout.", 'Admin › Settings › Payments: enter the keys (or switch it off).');

                continue;
            }
            $available++;
            $notes = [];
            if (method_exists($gateway, 'isTestMode') && $gateway->isTestMode()) {
                $notes[] = 'TEST mode';
            }
            if ($code === 'stripe' && ! setting('payments.stripe.webhook_secret')) {
                $this->warn($gateway->title(), 'No webhook signing secret – payments confirmed only when the customer returns to the site.', 'Stripe dashboard › Webhooks: add '.route('webhooks.payment', 'stripe').' (events payment_intent.succeeded + payment_intent.payment_failed) and paste the signing secret into Admin › Settings › Payments.');

                continue;
            }
            $notes
                ? $this->warn($gateway->title(), 'Enabled and configured, in '.implode(', ', $notes).'.', 'Switch to live keys at go-live.')
                : $this->pass($gateway->title(), 'Enabled and configured.');
        }
        if ($available === 0) {
            $this->warn('Payment methods', 'No payment method is available – customers cannot pay.', 'Admin › Settings › Payments.');
        }
    }

    protected function server(): void
    {
        config('queue.default') === 'sync'
            ? $this->pass('Queue', 'sync – emails and stock updates run inside the request (no worker needed).')
            : $this->warn('Queue', 'QUEUE_CONNECTION='.config('queue.default').': queued jobs need a running worker (php artisan queue:work).', 'Use QUEUE_CONNECTION=sync unless a supervised worker runs on the server.');

        $this->scheduler();

        $proxies = config('trustedproxy.proxies');
        $this->info('Trusted proxies', $proxies ? 'TRUSTED_PROXIES='.(is_array($proxies) ? implode(',', $proxies) : $proxies) : 'None (correct unless the site sits behind a CDN / load balancer – then list its IPs in TRUSTED_PROXIES).');

        foreach (['pdo_mysql', 'mbstring', 'intl', 'gd', 'curl', 'zip', 'dom'] as $ext) {
            if (! extension_loaded($ext)) {
                $this->{in_array($ext, ['pdo_mysql', 'mbstring', 'curl', 'dom'], true) ? 'fail' : 'warn'}('PHP extension '.$ext, 'Not loaded.', "Enable the {$ext} extension for this PHP version.");
            }
        }
    }

    /** Cron heartbeat (setting scheduler.last_run, written by every schedule:run) and what happens without it. */
    protected function scheduler(): void
    {
        $cron = '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
        if (! filter_var(config('commerce.scheduler.enabled', true), FILTER_VALIDATE_BOOL)) {
            $this->info('Scheduler', 'Core scheduled tasks are switched off (config commerce.scheduler.enabled).');

            return;
        }
        $beat = \Pine\Commerce\Scheduling\Scheduler::lastHeartbeat();
        if (\Pine\Commerce\Scheduling\Scheduler::cronRunning()) {
            $this->pass('Scheduler', 'Cron is running (last heartbeat '.$beat->diffForHumans().'). Details: php artisan commerce:schedule:status');

            return;
        }
        $fallback = filter_var(config('commerce.scheduler.web_fallback', false), FILTER_VALIDATE_BOOL);
        $without = 'unpaid orders are still cancelled when the checkout is visited'
            .($fallback ? ' and other due tasks run after storefront requests (web fallback)' : '; abandoned-cart reminders, scheduled sale prices, back-in-stock sweeps, tidy-up and the low-stock email wait for cron');
        $message = ($beat ? 'Cron is not running (last heartbeat '.$beat->diffForHumans().')' : 'No cron detected (schedule:run has never run)').': '.$without.'.';
        $fallback
            ? $this->info('Scheduler', $message.' Recommended cron: '.$cron)
            : $this->warn('Scheduler', $message, 'Add the cron job (cPanel › Cron Jobs, every minute): '.$cron);
    }

    protected function importer(): void
    {
        $wp = config('database.connections.wordpress');
        if (! $wp || empty($wp['database'])) {
            $this->info('WordPress source', 'Not configured (only needed while importing – WP_DB_* in .env).');
        } else {
            $init = (string) ($wp['options'][\PDO::MYSQL_ATTR_INIT_COMMAND] ?? ($wp['options'][1002] ?? ''));
            str_contains(strtoupper($init), 'READ ONLY')
                ? $this->pass('WordPress source', "Connection \"wordpress\" ({$wp['database']}) is read-only.")
                : $this->fail('WordPress source', 'The "wordpress" connection is not forced read-only.', "Add PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY' to its options (config/database.php).");
        }

        $report = static::lastImportReport();
        $report
            ? $this->info('Last import', ($report['date'] ?? 'unknown date').' – '.count($report['summary'] ?? []).' entities, '.count($report['warnings'] ?? []).' warning(s).')
            : $this->info('Last import', 'No WordPress import has been run on this install.');
    }

    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Compare the package admin assets with the published copy.
     *
     * @return array{status:string, files:int, stale:list<string>}
     */
    public static function adminAssetsState(): array
    {
        $source = dirname(__DIR__, 2).'/resources/assets/admin';
        $target = public_path(trim((string) config('commerce.admin.assets_url', 'vendor/commerce/admin'), '/'));
        if (is_link($target)) {
            return ['status' => 'symlink', 'files' => 0, 'stale' => []];
        }
        if (! is_dir($target)) {
            return ['status' => 'missing', 'files' => 0, 'stale' => []];
        }
        $stale = [];
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $count++;
            $relative = ltrim(substr($file->getPathname(), strlen($source)), '/');
            $published = $target.'/'.$relative;
            if (! is_file($published) || filesize($published) !== $file->getSize() || md5_file($published) !== md5_file($file->getPathname())) {
                $stale[] = $relative;
            }
        }

        return ['status' => $stale ? 'stale' : 'ok', 'files' => $count, 'stale' => $stale];
    }

    /**
     * The report the WordPress importer writes after every run (storage/logs/import-wordpress.log: a "[date]" line and
     * a JSON object with "summary" and "warnings").
     *
     * @return array{date:?string, summary:array, warnings:array}|null
     */
    public static function lastImportReport(): ?array
    {
        $file = storage_path('logs/import-wordpress.log');
        if (! is_file($file) || ! ($raw = @file_get_contents($file))) {
            return null;
        }
        $date = preg_match('/^\[([^\]]+)\]/', $raw, $m) ? $m[1] : null;
        $json = json_decode(trim(preg_replace('/^\[[^\]]+\]\s*/', '', $raw)), true);
        if (! is_array($json)) {
            return ['date' => $date, 'summary' => [], 'warnings' => []];
        }

        return ['date' => $date, 'summary' => (array) ($json['summary'] ?? []), 'warnings' => array_values((array) ($json['warnings'] ?? []))];
    }

    protected function add(string $status, string $title, string $message, ?string $fix = null): void
    {
        $this->results[] = ['status' => $status, 'group' => $this->group, 'title' => $title, 'message' => $message, 'fix' => $fix];
    }

    protected function pass(string $title, string $message): void
    {
        $this->add(self::PASS, $title, $message);
    }

    protected function warn(string $title, string $message, ?string $fix = null): void
    {
        $this->add(self::WARN, $title, $message, $fix);
    }

    protected function fail(string $title, string $message, ?string $fix = null): void
    {
        $this->add(self::FAIL, $title, $message, $fix);
    }

    protected function info(string $title, string $message): void
    {
        $this->add(self::INFO, $title, $message);
    }
}
