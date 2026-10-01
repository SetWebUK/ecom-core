<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Pine\Commerce\Commerce;
use Pine\Commerce\Models\Menu;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Support\Doctor;

/**
 * First-time (and repeatable) set-up of a pine/commerce store:
 *
 *   migrations → first administrator → default settings → free delivery → core pages (home, contact, about, terms,
 *   privacy) → menus → public/storage (real directory + hardening .htaccess) → admin + theme assets → optimize.
 *
 * Idempotent: everything is created only when absent – existing settings, pages, menus, delivery options and users are
 * never changed (except the admin named with --admin-email together with --force-admin). Safe to re-run after a
 * WordPress import. --connection runs the database part against another connection (e.g. the zz_ "scratch" test
 * connection); files, .env and caches are then left alone.
 */
class InstallCommand extends Command
{
    protected $signature = 'commerce:install
        {--connection= : Database connection to install into (default: database.default)}
        {--admin-email= : Email of the first administrator}
        {--admin-name= : Name of the first administrator}
        {--admin-password= : Password of the first administrator (generated and printed when omitted in non-interactive mode)}
        {--force-admin : Also create/promote the administrator when one already exists (resets that user\'s password)}
        {--store-name= : Store name (default: APP_NAME)}
        {--store-email= : Store contact email (default: the administrator email)}
        {--theme= : Storefront theme slug – written to COMMERCE_THEME in .env}
        {--order-start=1000 : Lowest order number for new orders}
        {--skip-publish : Do not publish admin/theme assets}
        {--no-optimize : Do not run php artisan optimize at the end}';

    protected $description = 'Install pine/commerce: migrate, create the first admin, seed default settings/pages/menus/shipping zone/tax rates, publish assets';

    protected string $connection;

    protected bool $defaultConnection;

    /** @var list<array{string,string}> what was done: [status, message] */
    protected array $log = [];

    public function handle(): int
    {
        $this->connection = (string) ($this->option('connection') ?: config('database.default'));
        $this->defaultConnection = $this->connection === config('database.default');
        if (! config("database.connections.{$this->connection}")) {
            $this->error("Unknown database connection '{$this->connection}'.");

            return self::FAILURE;
        }

        $this->components->info('Installing pine/commerce '.Commerce::VERSION.($this->defaultConnection ? '' : " into connection '{$this->connection}'"));

        if (! config('app.key')) {
            $this->components->warn('APP_KEY is empty – run `php artisan key:generate` (sessions and encrypted payment keys need it).');
        }

        if ($this->option('theme') && ! $this->theme((string) $this->option('theme'))) {
            return self::FAILURE;
        }

        // 1. schema
        $code = $this->call('migrate', ['--database' => $this->connection, '--force' => true]);
        if ($code !== 0) {
            $this->error('Migrations failed – nothing else was changed.');

            return self::FAILURE;
        }

        // 2-6. data (models use the default connection: point it at the target for the rest of this command)
        $restore = $this->useConnection();
        try {
            $admin = $this->admin();
            if ($admin === false) {
                return self::FAILURE;
            }
            DB::transaction(function () use ($admin) {
                $this->settings($admin);
                $this->shipping();
                $this->taxRates();
                $this->pages();
                $this->menus();
            });
        } finally {
            $restore();
        }

        // 7-9. files (never for a side connection: tests must not touch the served site)
        if ($this->defaultConnection) {
            $this->storageDirectory();
            if (! $this->option('skip-publish')) {
                $this->publish();
            }
            if (! $this->option('no-optimize')) {
                $this->callSilently('optimize:clear');
                $this->call('optimize');
            }
        } else {
            $this->line('  Side connection: public/storage, asset publishing, .env and caches were left alone.');
        }

        $this->report();

        return self::SUCCESS;
    }

    /** Point the default DB connection (and an in-memory cache) at the target; returns the undo closure. */
    protected function useConnection(): \Closure
    {
        if ($this->defaultConnection) {
            return function () {
                Setting::flushMemo();
            };
        }
        $previous = config('database.default');
        $previousCache = app('cache')->getDefaultDriver();
        DB::setDefaultConnection($this->connection);
        app('cache')->setDefaultDriver('array'); // never touch the served site's settings/menu cache
        Setting::flushMemo();

        return function () use ($previous, $previousCache) {
            DB::setDefaultConnection($previous);
            app('cache')->setDefaultDriver($previousCache);
            Setting::flushMemo();
        };
    }

    /** @return \Pine\Commerce\Models\User|null|false  false = abort */
    protected function admin(): mixed
    {
        $model = Commerce::userModel();
        $email = trim((string) $this->option('admin-email'));
        $hasAdmin = $model::query()->where('role', 'admin')->where('is_active', true)->exists();

        if ($email === '' && $hasAdmin) {
            $this->done('skip', 'Administrator: an active administrator already exists.');

            return $model::query()->where('role', 'admin')->where('is_active', true)->orderBy('id')->first();
        }
        if ($email === '' && $this->input->isInteractive()) {
            $email = trim((string) $this->ask('Administrator email'));
        }
        if ($email === '') {
            $this->done('warn', 'Administrator: none created (pass --admin-email=… or run interactively).');

            return null;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$email}' is not a valid email address.");

            return false;
        }

        $existing = $model::query()->where('email', $email)->first();
        if ($existing && $existing->role === 'admin' && ! $this->option('force-admin')) {
            $this->done('skip', "Administrator: {$email} already is an administrator (use --force-admin to reset the password).");

            return $existing;
        }
        if (($existing || $hasAdmin) && ! $this->option('force-admin')) {
            $this->done('skip', $existing
                ? "Administrator: {$email} exists as a {$existing->role}; not promoted (use --force-admin)."
                : "Administrator: one already exists; {$email} not created (use --force-admin).");

            return $model::query()->where('role', 'admin')->where('is_active', true)->orderBy('id')->first() ?? $existing;
        }

        $name = trim((string) $this->option('admin-name'));
        if ($name === '' && $this->input->isInteractive()) {
            $name = trim((string) $this->ask('Administrator name', $existing->name ?? Str::headline(Str::before($email, '@'))));
        }
        $name = $name !== '' ? $name : ($existing->name ?? Str::headline(Str::before($email, '@')));

        $password = (string) $this->option('admin-password');
        $generated = false;
        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Administrator password (min. 10 characters, empty = generate one)');
        }
        if ($password === '') {
            $password = Str::password(20, symbols: false);
            $generated = true;
        }
        if (mb_strlen($password) < 10) {
            $this->error('The administrator password must be at least 10 characters.');

            return false;
        }

        [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);
        $attributes = ['name' => $name, 'first_name' => $first, 'last_name' => $last, 'role' => 'admin', 'is_active' => true,
            'password' => Hash::make($password), 'email_verified_at' => now()];

        $user = $existing ?? new $model(['email' => $email]);
        $user->forceFill($attributes)->save();
        $this->done('ok', "Administrator: {$email} ".($existing ? 'promoted/updated' : 'created').'.');
        if ($generated) {
            $this->newLine();
            $this->components->warn("Generated password for {$email}: {$password}  (shown once – change it under Admin › your profile)");
        }

        return $user;
    }

    protected function settings(mixed $admin): void
    {
        $storeName = trim((string) $this->option('store-name')) ?: (string) config('app.name', 'Store');
        $storeEmail = trim((string) $this->option('store-email')) ?: ($admin->email ?? (string) config('mail.from.address'));

        $defaults = array_merge((array) config('commerce.settings.defaults', []), [
            'store.name' => $storeName,
            'store.email' => $storeEmail,
            'seo.site_name' => $storeName,
            'seo.title_suffix' => '| '.$storeName,
            'emails.from_name' => $storeName,
            'orders.starting_number' => (string) max(1, (int) $this->option('order-start')),
            'checkout.terms_page' => 'terms-conditions',
            'checkout.privacy_page' => 'privacy-policy',
            // new installs start hidden from search engines; untick in Settings › SEO at go-live (docs/PLAYBOOK.md go-live checklist)
            'seo.discourage_search_engines' => true,
        ]);

        $existing = Setting::query()->pluck('key')->flip();
        $added = 0;
        foreach ($defaults as $key => $value) {
            if ($existing->has($key)) {
                continue;
            }
            Setting::set($key, $value);
            $added++;
        }
        $this->done($added ? 'ok' : 'skip', "Settings: {$added} default(s) added, ".(count($defaults) - $added).' already set.');
    }

    protected function shipping(): void
    {
        if (ShippingMethod::query()->exists()) {
            $this->done('skip', 'Shipping: delivery options already exist.');

            return;
        }
        $country = (string) config('commerce.store.country', 'GB');
        if (! config('commerce.shipping.install_zones', true)) {
            ShippingMethod::forceCreate([
                'name' => 'Free delivery', 'code' => 'free_shipping', 'description' => null, 'cost' => 0, 'min_order_amount' => null,
                'countries' => [$country], 'is_active' => true, 'sort_order' => 0,
            ]);
            $this->done('ok', 'Shipping: "Free delivery" created (edit prices in Admin › Settings › Shipping).');

            return;
        }
        $names = (array) config('commerce.store.countries', []);
        $zone = ShippingZone::forceCreate(['name' => $names[$country] ?? $country, 'regions' => [$country], 'sort_order' => 1]);
        ShippingMethod::forceCreate([
            'shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'settings' => ['requires' => ''],
            'name' => 'Free delivery', 'code' => 'free_shipping', 'description' => null, 'cost' => 0, 'min_order_amount' => null,
            'countries' => null, 'is_active' => true, 'sort_order' => 0, 'tax_status' => 'taxable',
        ]);
        $this->done('ok', 'Shipping: zone "'.$zone->name.'" with "Free delivery" created (edit zones and prices in Admin › Settings › Shipping).');
    }

    /** Standard UK (and/or EU) VAT rates for a new store: config commerce.tax.install_rates = uk|eu|uk+eu|none. */
    protected function taxRates(): void
    {
        $which = (string) config('commerce.tax.install_rates', 'uk');
        if ($which === 'none' || $which === '') {
            return;
        }
        if (TaxRate::query()->exists()) {
            $this->done('skip', 'Tax: rates already exist.');

            return;
        }
        $rows = [];
        if (str_contains($which, 'uk')) {
            foreach (['GB', 'IM'] as $country) {
                $rows[] = ['tax_class' => 'standard', 'country' => $country, 'rate' => 20, 'name' => 'VAT'];
                $rows[] = ['tax_class' => 'reduced-rate', 'country' => $country, 'rate' => 5, 'name' => 'VAT'];
                $rows[] = ['tax_class' => 'zero-rate', 'country' => $country, 'rate' => 0, 'name' => 'VAT'];
            }
        }
        if (str_contains($which, 'eu')) {
            foreach (self::EU_STANDARD_RATES as $country => $rate) {
                $rows[] = ['tax_class' => 'standard', 'country' => $country, 'rate' => $rate, 'name' => 'VAT'];
            }
        }
        foreach ($rows as $i => $row) {
            TaxRate::forceCreate($row + ['state' => '', 'priority' => 1, 'compound' => false, 'shipping' => true, 'sort_order' => $i]);
        }
        $this->done('ok', 'Tax: '.count($rows).' VAT rates added ('.$which.'; check them in Admin › Settings › Tax before going live).');
    }

    /** EU standard VAT rates (2026) seeded with install_rates "eu" – check them before selling to EU consumers (OSS). */
    public const EU_STANDARD_RATES = [
        'AT' => 20, 'BE' => 21, 'BG' => 20, 'HR' => 25, 'CY' => 19, 'CZ' => 21, 'DK' => 25, 'EE' => 24, 'FI' => 25.5,
        'FR' => 20, 'DE' => 19, 'GR' => 24, 'HU' => 27, 'IE' => 23, 'IT' => 22, 'LV' => 21, 'LT' => 21, 'LU' => 17,
        'MT' => 18, 'NL' => 21, 'PL' => 23, 'PT' => 23, 'RO' => 21, 'SK' => 23, 'SI' => 22, 'ES' => 21, 'SE' => 25,
    ];

    /** Core pages, only when their path is free. The home page takes the active theme's default blocks. */
    protected function pages(): void
    {
        $store = e((string) setting('store.name', config('app.name')));
        $email = e((string) setting('store.email', ''));
        $themeBlocks = function (string $key): ?array {
            if (! function_exists('theme_config')) {
                return null;
            }
            try {
                $blocks = theme_config($key);
            } catch (\Throwable) {
                return null;
            }

            return is_array($blocks) && $blocks ? $blocks : null;
        };

        $pages = [
            ['title' => 'Home', 'slug' => 'home', 'path' => '', 'template' => 'home', 'blocks' => $themeBlocks('home.defaults'), 'content' => null, 'sort_order' => 0],
            ['title' => 'About us', 'slug' => 'about', 'path' => 'about', 'template' => 'default', 'sort_order' => 10,
                'content' => "<p>{$store} is an independent online shop. Tell your customers who you are, what you sell and why they can trust you.</p>"],
            ['title' => 'Contact us', 'slug' => 'contact', 'path' => 'contact', 'template' => 'contact', 'sort_order' => 20,
                'content' => '<p>Questions about an order or a product? Send us a message and we will get back to you'.($email ? " – or email <a href=\"mailto:{$email}\">{$email}</a>" : '').'.</p>'],
            ['title' => 'Terms and conditions', 'slug' => 'terms-conditions', 'path' => 'terms-conditions', 'template' => 'default', 'sort_order' => 30,
                'content' => "<p>These terms apply to every order placed with {$store}. Replace this text with your own terms of sale (ordering, prices, delivery, returns, warranty, liability, governing law).</p>"],
            ['title' => 'Privacy policy', 'slug' => 'privacy-policy', 'path' => 'privacy-policy', 'template' => 'default', 'sort_order' => 40,
                'content' => "<p>This policy explains how {$store} collects and uses personal data. Replace this text with your own privacy policy (data controller, what is collected, cookies, legal basis, retention, your rights).</p>"],
        ];

        $created = [];
        foreach ($pages as $page) {
            if (Page::query()->where('path', $page['path'])->exists()
                || ($page['template'] === 'home' && Page::query()->where('template', 'home')->exists())) {
                continue;
            }
            Page::create($page + ['status' => 'published', 'blocks' => null]);
            $created[] = $page['title'];
        }
        $this->done($created ? 'ok' : 'skip', 'Pages: '.($created ? implode(', ', $created).' created.' : 'all core pages already exist.'));
    }

    /** Menu locations of the theme (or the core list), each created with sensible links when it does not exist. */
    protected function menus(): void
    {
        $locations = [];
        if (function_exists('theme_config')) {
            try {
                $locations = (array) theme_config('menus.locations', []);
            } catch (\Throwable) {
                $locations = [];
            }
        }
        $locations = $locations ?: [
            'main' => 'Main menu',
            'mobile_nav' => 'Mobile menu',
            'footer_shop' => 'Shop',
            'footer_company' => 'Company',
            'footer_legal' => 'Legal',
        ];

        $url = fn (string $path) => '/'.($path === '' ? '' : trim($path, '/').'/');
        $main = [['Home', $url('')], ['Shop', $url('shop')], ['About us', $url('about')], ['Contact us', $url('contact')]];
        $sets = [
            'legal' => [['Terms and conditions', $url('terms-conditions')], ['Privacy policy', $url('privacy-policy')]],
            'company' => [['About us', $url('about')], ['Contact us', $url('contact')], ['My account', $url('my-account')]],
            'shop' => [['Shop all', $url('shop')], ['Basket', $url('basket')]],
        ];

        $created = [];
        foreach ($locations as $location => $label) {
            $location = (string) $location;
            if (! preg_match('/^[a-z0-9_]+$/', $location) || Menu::query()->where('location', $location)->exists()) {
                continue;
            }
            $items = match (true) {
                str_contains($location, 'legal') => $sets['legal'],
                str_contains($location, 'company'), str_contains($location, 'information') => $sets['company'],
                str_starts_with($location, 'footer') => $sets['shop'],
                in_array($location, ['main', 'mega', 'mobile', 'mobile_nav', 'header'], true) => $main,
                default => [],
            };
            $name = is_array($label) ? (string) ($label['label'] ?? $location) : (string) $label;
            $menu = Menu::create(['name' => $name ?: Str::headline($location), 'location' => $location]);
            foreach ($items as $i => [$text, $link]) {
                $menu->items()->create(['label' => $text, 'url' => $link, 'sort_order' => $i]);
            }
            $created[] = $location;
        }
        $this->done($created ? 'ok' : 'skip', 'Menus: '.($created ? implode(', ', $created).' created.' : 'all menu locations already exist.'));
    }

    /** public/storage = the public disk root: a REAL directory (LiteSpeed does not follow symlinks) with hardening rules. */
    protected function storageDirectory(): void
    {
        $dir = public_path('storage');
        if (is_link($dir)) {
            $this->done('fail', 'public/storage is a symlink – LiteSpeed will not serve it. Replace it with a real directory: '
                .'`rm public/storage && mkdir public/storage && cp -a storage/app/public/. public/storage/`, then re-run.');

            return;
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
            $this->done('ok', 'public/storage created (real directory).');
        }
        if (! is_file($dir.'/.htaccess')) {
            copy(dirname(__DIR__, 2).'/stubs/public-storage.htaccess', $dir.'/.htaccess');
            $this->done('ok', 'public/storage/.htaccess written (no script execution, media only).');
        } else {
            $this->done('skip', 'public/storage: real directory with .htaccess already.');
        }
    }

    protected function publish(): void
    {
        $this->call('commerce:publish');
        if (array_key_exists('commerce:theme:publish', Artisan::all())) {
            $this->call('commerce:theme:publish');
        }
    }

    /** Validate the theme slug (when the theme system is present) and write COMMERCE_THEME to .env. */
    protected function theme(string $slug): bool
    {
        if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
            $this->error("Invalid theme slug '{$slug}' (lowercase letters, digits and dashes).");

            return false;
        }
        $managerClass = 'Pine\\Commerce\\Theme\\ThemeManager';
        if (class_exists($managerClass) && method_exists($managerClass, 'get')) {
            try {
                app($managerClass)->get($slug);
            } catch (\Throwable $e) {
                $this->error("Theme '{$slug}' not found: ".$e->getMessage().' – create it with `php artisan commerce:theme:make '.$slug.'`.');

                return false;
            }
        }
        config(['commerce.theme' => $slug]);

        if (! $this->defaultConnection) {
            $this->done('skip', "Theme: {$slug} (side connection – .env not changed).");

            return true;
        }
        $env = base_path('.env');
        if (! is_file($env) || ! is_writable($env)) {
            $this->done('warn', "Theme: set COMMERCE_THEME={$slug} in .env yourself (.env not writable).");

            return true;
        }
        $contents = (string) file_get_contents($env);
        $line = 'COMMERCE_THEME='.$slug;
        $contents = preg_match('/^COMMERCE_THEME=.*$/m', $contents)
            ? preg_replace('/^COMMERCE_THEME=.*$/m', $line, $contents)
            : rtrim($contents, "\n")."\n\n{$line}\n";
        file_put_contents($env, $contents);
        $this->done('ok', "Theme: COMMERCE_THEME={$slug} written to .env.");

        return true;
    }

    protected function done(string $status, string $message): void
    {
        $this->log[] = [$status, $message];
    }

    protected function report(): void
    {
        $this->newLine();
        $marks = ['ok' => '<fg=green>✔</>', 'skip' => '<fg=gray>–</>', 'warn' => '<fg=yellow>!</>', 'fail' => '<fg=red>✘</>'];
        foreach ($this->log as [$status, $message]) {
            $this->line('  '.$marks[$status].' '.$message);
        }

        if ($this->defaultConnection) {
            $problems = array_filter((new Doctor)->run(), fn ($r) => $r['status'] === Doctor::FAIL);
            if ($problems) {
                $this->newLine();
                $this->components->warn('commerce:doctor reports '.count($problems).' failing check(s): '.implode(', ', array_column($problems, 'title')).'.');
            }
        }

        $admin = url(trim((string) config('commerce.admin.path', 'admin'), '/'));
        $this->newLine();
        $this->line('<options=bold>Next steps</>');
        foreach ([
            "Sign in to the back office: {$admin}",
            'Store details, emails, payments (Stripe / PayPal / bank transfer) and delivery prices: Admin › Settings',
            'Import from WordPress/WooCommerce: set WP_DB_* and WP_PATH in .env, then php artisan commerce:import-wordpress (pine/commerce docs/PLAYBOOK.md, part 2)',
            'Theme: php artisan commerce:theme:make {client} --parent=default, set COMMERCE_THEME, php artisan commerce:theme:publish',
            'Check everything: php artisan commerce:doctor – playbook: pine/commerce docs/PLAYBOOK.md',
        ] as $i => $step) {
            $this->line('  '.($i + 1).'. '.$step);
        }
    }
}
