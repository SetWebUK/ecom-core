<?php

namespace Pine\Commerce;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Pagination\Paginator;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Pine\Commerce\Console\ImportWordPressCommand;
use Pine\Commerce\Console\PublishCommand;
use Pine\Commerce\Console\ThemeCacheCommand;
use Pine\Commerce\Console\ThemeCheckCommand;
use Pine\Commerce\Console\ThemeClearCommand;
use Pine\Commerce\Console\ThemeMakeCommand;
use Pine\Commerce\Console\ThemePublishCommand;
use Pine\Commerce\Events\OrderPlaced;
use Pine\Commerce\Http\Controllers\BlogController;
use Pine\Commerce\Http\Controllers\CartController;
use Pine\Commerce\Events\OrderStatusChanged;
use Pine\Commerce\Http\Middleware\HandleAddToCartQuery;
use Pine\Commerce\Http\Middleware\PreviewTheme;
use Pine\Commerce\Listeners\SaveCheckoutCustomer;
use Pine\Commerce\Listeners\SendOrderStatusEmails;
use Pine\Commerce\Listeners\SyncOrderStock;
use Pine\Commerce\Providers\StoreMailServiceProvider;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\Attribution;
use Pine\Commerce\Scheduling\Scheduler;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Support\SlashUrlGenerator;
use Pine\Commerce\Theme\ThemeManager;
use Pine\Commerce\View\Components\Admin\Sidebar;
use Pine\Commerce\View\Components\FooterMenu;
use Pine\Commerce\View\Components\MegaMenu;
use Pine\Commerce\View\Components\MobileMenu;
use Pine\Commerce\View\Components\MediaImage;
use Pine\Commerce\View\Components\PageContent;
use Pine\Commerce\View\Components\Seo;

/**
 * Wires the pine/commerce package into the host application (auto-discovered via composer "extra.laravel").
 * See docs/ARCHITECTURE.md §4.2 for the full list of responsibilities.
 */
class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce.php', 'commerce');
        $this->mergeConfigFrom(__DIR__.'/../config/commerce-import.php', 'commerce-import');
        // feature switches merge key by key: a client's features block that predates a switch keeps the package default
        if (! ($this->app instanceof \Illuminate\Contracts\Foundation\CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app['config'];
            $config->set('commerce.features', array_replace(Features::defaults(), (array) $config->get('commerce.features', [])));
        }

        // SQLite (development/CI/rehearsals): the MySQL functions the package's raw SQL uses – Support\SqliteFunctions
        $this->app['events']->listen(
            \Illuminate\Database\Events\ConnectionEstablished::class,
            fn ($event) => \Pine\Commerce\Support\SqliteFunctions::attach($event->connection),
        );

        $this->app->singleton(Cart::class);
        // Admin › Updates: external programs (composer, git, mysqldump, artisan) – tests bind a fake runner
        $this->app->bindIf(Updater\ProcessRunner::class, Updater\SystemProcessRunner::class);
        // extension API state (Commerce::gateway(), ::adminMenu(), ::settings() …) – one per application instance
        $this->app->singleton(Extensions\ExtensionRegistry::class);
        $this->app->singleton(Extensions\AdminMenu::class);
        $this->app->singleton(Content\Shortcodes::class, function () {
            $shortcodes = new Content\Shortcodes;
            static::registerCoreShortcodes($shortcodes);

            return $shortcodes;
        });

        // storefront themes (ARCHITECTURE §7): view-path chain + "theme::" namespace must exist before the exception
        // handler builds its error-view paths, so this happens in register()
        $this->app->singleton(ThemeManager::class);
        $themes = $this->app->make(ThemeManager::class);
        $themes->registerViewPaths();
        $themes->registerDefinitions();

        // Swap in the trailing-slash URL generator (resolvers from RoutingServiceProvider's extend() still apply)
        if ($this->app['config']->get('commerce.urls.trailing_slash', true)) {
            $this->app->singleton('url', function ($app) {
                $routes = $app['router']->getRoutes();
                $app->instance('routes', $routes);

                return new SlashUrlGenerator($routes, $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }), $app['config']['app.asset_url']);
            });
        }

        // admin Settings › Emails sender name/address
        $this->app->register(StoreMailServiceProvider::class);
    }

    public function boot(): void
    {
        // store schema (table and migration file names are unchanged, so existing installs see nothing to run)
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerEvents();
        $this->registerSchedule();

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Paginator::defaultView('partials.pagination');

        // theme helpers as Blade directives: @themeAsset('css/site.css'), @themeSetting('primary_color', '#000')
        Blade::directive('themeAsset', fn ($expression) => "<?php echo e(theme_asset({$expression})); ?>");
        Blade::directive('themeSetting', fn ($expression) => "<?php echo e(theme_setting({$expression})); ?>");

        // the admin's theme choice (setting theme.active) and the active chain's Theme.php boot() hooks
        $this->app->booted(function () {
            $themes = $this->app->make(ThemeManager::class);
            $themes->applyStoredChoice();
            $themes->bootDefinitions();
        });

        // staff-only ?preview_theme={slug} (never changes what customers see)
        // (through the HTTP kernel: it re-syncs the router's groups when it is built after boot, e.g. in tests)
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('web', PreviewTheme::class);
        } else {
            $this->app['router']->pushMiddlewareToGroup('web', PreviewTheme::class);
        }

        // back office views: commerce::admin.* (a client can override one in resources/views/vendor/commerce/admin/…)
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'commerce');
        // <x-admin.button> etc.: anonymous components in resources/views/components/admin (no prefix, same tags as before)
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components');
        Blade::component('admin.sidebar', Sidebar::class);

        // storefront components (same tag names as when they lived in App\View\Components)
        Blade::component('mega-menu', MegaMenu::class);
        Blade::component('footer-menu', FooterMenu::class);
        Blade::component('mobile-menu', MobileMenu::class);
        Blade::component('page-content', PageContent::class);
        Blade::component('seo', Seo::class);
        // <x-media-image :path="…" size="card" sizes="…" /> – responsive upload image (Services\Media\Images)
        Blade::component('media-image', MediaImage::class);

        $this->registerRoutes();
        // client back-office routes (Commerce::adminRoutes()) registered by providers that boot after this one
        $this->app->booted(fn () => $this->app->make(Extensions\ExtensionRegistry::class)->flushAdminRoutes());

        // Basket / checkout: cookies written by the storefront JS (cart.js) stay plain
        EncryptCookies::except([Attribution::cookieName(), CartController::openCookie()]);
        // maintenance-mode bypass cookie set by Admin › Updates for the approving administrator (read raw by Laravel's
        // PreventRequestsDuringMaintenance before the web group decrypts cookies)
        EncryptCookies::except(['laravel_maintenance']);

        // legacy ?add-to-cart= links work everywhere
        if (Features::enabled('add_to_cart_query', false)) {
            $this->app['router']->pushMiddlewareToGroup('web', HandleAddToCartQuery::class);
        }

        // Registered for web requests too (commands() only hooks Artisan::starting, so this costs nothing until
        // Artisan runs): Admin › Settings › Theme calls Artisan::call('commerce:theme:publish') from a request.
        $this->commands([PublishCommand::class, ImportWordPressCommand::class, ThemeMakeCommand::class,
            ThemePublishCommand::class, ThemeCheckCommand::class, ThemeCacheCommand::class, ThemeClearCommand::class]);
        // platform tooling: install / health check / scratch clean-up / new client project / URL parity
        $this->commands([Console\InstallCommand::class, Console\DoctorCommand::class, Console\ScratchDropCommand::class,
            Console\NewClientCommand::class, Console\VerifyUrlsCommand::class]);
        // image sizes: (re)build the size variants of existing uploads
        $this->commands([Console\ImagesGenerateCommand::class]);
        // full product CSV (feature switch product_csv – the commands refuse to run while it is off)
        $this->commands([Console\ProductsExportCommand::class, Console\ProductsImportCommand::class]);
        // scheduled tasks: status table + run one task by hand
        $this->commands([Console\ScheduleStatusCommand::class, Console\ScheduleTaskCommand::class]);
        // Admin › Updates from the command line: check, run an approved update, skeleton baseline / comparison
        $this->commands([Console\UpdateCheckCommand::class, Console\UpdateRunCommand::class,
            Console\SkeletonBaselineCommand::class, Console\SkeletonCheckCommand::class]);

        if ($this->app->runningInConsole()) {
            $this->optimizes(optimize: 'commerce:theme:cache', clear: 'commerce:theme:clear', key: 'commerce-themes');
            $this->publishes([
                __DIR__.'/../config/commerce.php' => config_path('commerce.php'),
                __DIR__.'/../config/commerce-import.php' => config_path('commerce-import.php'),
            ], 'commerce-config');
            $this->publishes([__DIR__.'/../resources/views/admin' => resource_path('views/vendor/commerce/admin')], 'commerce-admin-views');
            // for reference only – always publish with `php artisan commerce:publish` (copies, never symlinks)
            $this->publishes([__DIR__.'/../resources/assets/admin' => public_path(config('commerce.admin.assets_url', 'vendor/commerce/admin'))], 'commerce-admin-assets');
        }
    }

    /**
     * The generic content shortcodes (ARCHITECTURE §8.2 / §11), in processing order. Clients and themes add their own
     * (or aliases of these) with Commerce::shortcode() / Commerce::shortcodeAlias() / commerce.content.shortcode_aliases.
     */
    public static function registerCoreShortcodes(Content\Shortcodes $shortcodes): void
    {
        // [contact_form] – the enquiry form (partials.contact-form)
        // (a switched-off feature's shortcode renders nothing: flags contact_form, blog, order_tracking)
        $shortcodes->register('contact_form', fn () => Features::enabled('contact_form') ? view('partials.contact-form')->render() : '');

        // [blog_index posts_per_page=9] – the blog post grid with WordPress-style pagination
        $shortcodes->register('blog_index', function (array $atts, array $context) {
            if (! Features::enabled('blog')) {
                return '';
            }
            $perPage = max(1, min(48, (int) ($atts['posts_per_page'] ?? 9)));

            return app(BlogController::class)->renderIndexGrid($perPage, (int) ($context['blog_page'] ?? 1), $context['blog_category'] ?? null);
        });

        // [sitemap] (WordPress: [wp_sitemap_page]) – HTML sitemap
        $shortcodes->register('sitemap', fn () => view('partials.html-sitemap')->render());
        $shortcodes->alias('wp_sitemap_page', 'sitemap');

        // [order_tracking] (WooCommerce: [woocommerce_order_tracking], or the form WordPress rendered in its place)
        $shortcodes->register('order_tracking', fn () => Features::enabled('order_tracking') ? view('partials.order-tracking')->render() : '', [
            '#<div class="woocommerce">\s*<form[^>]*woocommerce-form-track-order[^>]*>.*?</form>\s*</div>#s',
            '#<form[^>]*woocommerce-form-track-order[^>]*>.*?</form>#s',
        ], unwrap: false);
        $shortcodes->alias('woocommerce_order_tracking', 'order_tracking');
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }
        // 1. back office first: the router matches in registration order, so a broad theme/storefront/client pattern
        //    registered later can never answer an /admin URL (route names stay admin.*)
        if (config('commerce.routes.admin', true)) {
            Route::middleware('web')->prefix(trim((string) config('commerce.admin.path', 'admin'), '/'))->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');
        }
        // 2. theme routes (ThemeDefinition::routes()), 3. storefront. The storefront catch-all is a fallback route,
        // so it always matches last whatever registered after it (client routes/web.php included).
        Route::middleware('web')->group(fn () => $this->app->make(ThemeManager::class)->loadRoutes());
        if (config('commerce.routes.storefront', true)) {
            Route::middleware('web')->group(__DIR__.'/../routes/storefront.php');
        }
    }

    /** Exactly the listeners event discovery found in app/Listeners before the package existed (order matters). */
    protected function registerEvents(): void
    {
        Event::listen(OrderPlaced::class, [SaveCheckoutCustomer::class, 'handle']);
        Event::listen(OrderStatusChanged::class, [\Pine\Commerce\Listeners\AssignInvoiceNumber::class, 'handle']); // before the emails (invoice attachment)
        Event::listen(OrderStatusChanged::class, [SendOrderStatusEmails::class, 'handle']);
        Event::listen(OrderStatusChanged::class, [SyncOrderStock::class, 'handle']);
        // abandoned-cart recovery: an order won back by a reminder (no effect until a reminder was ever sent)
        Event::listen(OrderPlaced::class, [\Pine\Commerce\Listeners\MarkRecoveredCart::class, 'handle']);
    }

    /**
     * Core scheduled tasks (Scheduling\Scheduler): one cron line `* * * * * php artisan schedule:run` runs them all.
     * Without cron: the checkout-visit fallback for unpaid orders, and (config commerce.scheduler.web_fallback) due
     * tasks after a storefront response.
     */
    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => Scheduler::register($schedule));

        if (! $this->app->runningInConsole() && config('commerce.scheduler.web_fallback', false)) {
            $this->app->terminating(fn () => Scheduler::webFallback());
        }
    }
}
