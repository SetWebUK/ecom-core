<?php

namespace Pine\Commerce;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Pine\Commerce\Http\Middleware\EnsureStaff;
use Pine\Commerce\Http\Middleware\SecurityHeaders;
use Pine\Commerce\Http\Middleware\TrailingSlash;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Static entry point of the pine/commerce package: bootstrap delegations (middleware / exceptions, called from the
 * client's bootstrap/app.php) and the extension registries clients and themes use (docs/ARCHITECTURE.md §11).
 *
 *   ->withMiddleware(fn (Middleware $m) => \Pine\Commerce\Commerce::middleware($m))
 *   ->withExceptions(fn (Exceptions $e) => \Pine\Commerce\Commerce::exceptions($e))
 */
class Commerce
{
    /** Package version (SemVer; the theme contract, config keys and extension API are the public API). */
    public const VERSION = '1.3.0';

    /** Session fields never flashed back as "old input" when validation fails. */
    public const DONT_FLASH = ['password_current', 'password_1', 'password_2', 'account_password', 'pass1', 'pass2',
        // back office: payment gateway secrets (Settings › Payments) never go into the session either
        'payments.stripe.secret_key', 'payments.stripe.webhook_secret', 'payments.paypal.secret'];

    /**
     * The application's user model (config auth.providers.users.model – the client's App\Models\User, a thin subclass of
     * Pine\Commerce\Models\User). Core relations to users use this so client additions are always returned.
     *
     * @return class-string<\Pine\Commerce\Models\User>
     */
    public static function userModel(): string
    {
        return config('auth.providers.users.model') ?: Models\User::class;
    }

    /** HTTP middleware the store needs. A client adds its own after this call. */
    public static function middleware(Middleware $middleware): Middleware
    {
        $middleware->web(prepend: [TrailingSlash::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'basket/unsubscribe/*']); // + one-click unsubscribe (RFC 8058)
        $middleware->redirectGuestsTo(fn () => route('account'));
        // trusted proxies: config/trustedproxy.php (TRUSTED_PROXIES) - none by default, so X-Forwarded-For cannot spoof client IPs
        $middleware->append(SecurityHeaders::class); // X-Frame-Options / nosniff / Referrer-Policy + HTTP -> HTTPS
        $middleware->alias(['admin' => EnsureStaff::class]); // back office: staff only ('admin:admin' = administrators only)
        // Check staff access before route-model binding, so guests are sent to the login page and missing records get the admin 404
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureStaff::class);

        return $middleware;
    }

    /** Exception rendering rules of the store. */
    public static function exceptions(Exceptions $exceptions): Exceptions
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // never copy passwords into the (database) session as "old input" when a form fails validation
        $exceptions->dontFlash(self::DONT_FLASH);
        // back office "page expired" (stale CSRF token): back to the form with a friendly toast instead of the bare 419 page
        $exceptions->render(fn (HttpException $e, Request $request) => EnsureStaff::expiredSession($e, $request));
        // storefront "page expired" (e.g. checkout left open, then submitted/refreshed): back to the page the form was
        // on – same-site only – keeping what was typed (minus passwords), with a message instead of the bare 419 page
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson() || $request->is('admin', 'admin/*')) {
                return null;
            }
            $back = (string) $request->headers->get('referer');
            $sameSite = $back !== '' && parse_url($back, PHP_URL_HOST) === $request->getHost();
            $target = $sameSite ? $back : ($request->is('checkout', 'checkout/*') ? url('checkout') : url('/'));

            return redirect()->to($target)
                ->withInput($request->except(array_merge(self::DONT_FLASH, ['_token'])))
                ->withErrors(['session' => 'Your session timed out, so nothing was submitted. Please check your details and try again.']);
        });

        return $exceptions;
    }

    // ------------------------------------------------------------------ extension API (ARCHITECTURE §11, EXTENDING.md)
    //
    // Call these from a client service provider's boot() (App\Providers\ClientServiceProvider) or a theme's
    // ThemeDefinition::boot(). Registrations live in the ExtensionRegistry container singleton.

    public static function registry(): Extensions\ExtensionRegistry
    {
        return app(Extensions\ExtensionRegistry::class);
    }

    /** Is a feature switch on (config commerce.features.*)? $theme = also require the active theme to support it. */
    public static function feature(string $key, bool $theme = true): bool
    {
        return Support\Features::enabled($key, $theme);
    }

    // ---------------------------------------------------------------- payments / shipping

    /**
     * Add or replace a checkout payment gateway (null removes one). The class implements Contracts\PaymentGateway –
     * usually by extending Services\Payments\Gateway. Its admin fields come from adminSettings().
     *
     *   Commerce::gateway('klarna', \App\Payments\KlarnaGateway::class);
     *
     * @param  class-string<Contracts\PaymentGateway>|Contracts\PaymentGateway|null  $gateway
     */
    public static function gateway(string $code, string|Contracts\PaymentGateway|null $gateway): void
    {
        if (is_string($gateway) && ! is_a($gateway, Contracts\PaymentGateway::class, true)) {
            throw new \InvalidArgumentException("Payment gateway [{$gateway}] must implement ".Contracts\PaymentGateway::class.'.');
        }
        static::registry()->addGateway($code, $gateway);
    }

    public static function payments(): Services\Payments\PaymentManager
    {
        return app(Services\Payments\PaymentManager::class);
    }

    /**
     * Price the delivery options whose code matches $pattern (fnmatch: 'dpd_*', 'weight', '*') with a calculator
     * instead of their flat admin cost; the first matching registration wins. Return null from it to hide the option.
     *
     *   Commerce::shippingCalculator('weight_*', \App\Shipping\WeightCalculator::class);
     *   Commerce::shippingCalculator('pallet', fn ($method, $cost, $lines, $ctx) => $lines->sum('quantity') > 10 ? 95.0 : null);
     *
     * @param  class-string<Contracts\ShippingCalculator>|Contracts\ShippingCalculator|\Closure  $calculator
     */
    public static function shippingCalculator(string $pattern, string|Contracts\ShippingCalculator|\Closure $calculator): void
    {
        if (is_string($calculator) && ! is_a($calculator, Contracts\ShippingCalculator::class, true)) {
            throw new \InvalidArgumentException("Shipping calculator [{$calculator}] must implement ".Contracts\ShippingCalculator::class.'.');
        }
        static::registry()->addShippingCalculator($pattern, $calculator);
    }

    // ---------------------------------------------------------------- back office

    /**
     * The admin sidebar: ->add(label, icon, route, active, after, children, feature, admin), ->child(parentRoute, …),
     * ->remove(route). Entries can also come from config commerce.admin.menu.
     */
    public static function adminMenu(): Extensions\AdminMenu
    {
        return app(Extensions\AdminMenu::class);
    }

    /**
     * Back-office routes of the client, registered like the package's own: middleware web + admin (staff), URL prefix
     * commerce.admin.path, route names prefixed "admin.". Controllers render views extending commerce::admin.layouts.app.
     *
     *   Commerce::adminRoutes(function () {
     *       Route::get('trade-accounts', [TradeAccountController::class, 'index'])->name('trade.index'); // admin.trade.index
     *   });
     */
    public static function adminRoutes(\Closure $routes): void
    {
        static::registry()->addAdminRoutes($routes);
    }

    /**
     * A new settings screen in Admin › Settings (rendered, validated and saved by the generic settings form; values
     * are read with setting('key')). Field shape: see Services\Admin\StoreSettings.
     *
     *   Commerce::settings('trade', ['label' => 'Trade accounts', 'icon' => 'briefcase', 'description' => '…'], [
     *       ['title' => 'Discounts', 'fields' => [
     *           ['key' => 'trade.discount', 'label' => 'Trade discount (%)', 'type' => 'decimal', 'default' => 10, 'max' => 50],
     *       ]],
     *   ]);
     *
     * @param  array{label?:string, icon?:string, description?:string, admin?:bool, feature?:string}  $group
     * @param  list<array{title:string, description?:string, fields:list<array>}>  $sections
     */
    public static function settings(string $key, array $group, array $sections): void
    {
        static::registry()->addSettingsGroup($key, $group, $sections);
    }

    /** Alias of settings() (ARCHITECTURE §11 name). */
    public static function settingsGroup(string $key, array $group, array $sections): void
    {
        static::settings($key, $group, $sections);
    }

    /**
     * An extra card of fields on an existing settings screen (general, checkout, emails, seo or a client group).
     *
     *   Commerce::settingsFields('checkout', ['title' => 'Trade', 'fields' => [['key' => 'trade.min_order', 'label' => 'Minimum order', 'type' => 'money']]]);
     */
    public static function settingsFields(string $group, array $section): void
    {
        static::registry()->addSettingsSection($group, $section);
    }

    /**
     * A card on the back-office dashboard.
     *
     *   Commerce::dashboardWidget('trade', ['title' => 'Trade orders', 'view' => 'admin.widgets.trade',
     *       'data' => fn (Request $request) => ['orders' => …], 'sort' => 50, 'wide' => false, 'admin' => false, 'feature' => null]);
     *   Commerce::dashboardWidget('trade', \App\Admin\TradeWidget::class);   // implements Contracts\DashboardWidget
     */
    public static function dashboardWidget(string $key, array|string|Contracts\DashboardWidget $widget): void
    {
        if (is_string($widget) && ! is_a($widget, Contracts\DashboardWidget::class, true)) {
            throw new \InvalidArgumentException("Dashboard widget [{$widget}] must implement ".Contracts\DashboardWidget::class.'.');
        }
        static::registry()->addDashboardWidget($key, $widget);
    }

    public static function removeDashboardWidget(string $key): void
    {
        static::registry()->removeDashboardWidget($key);
    }

    // ---------------------------------------------------------------- content

    /**
     * A page template offered in Admin › Pages, with a block schema for the page builder (types: see PageBlocks).
     * The storefront renders $meta['view'] when given, else the theme view pages.{key}; both receive $page and $blocks.
     *
     *   Commerce::pageTemplate('landing', ['label' => 'Landing page', 'help' => '…', 'view' => 'pages.landing'],
     *       ['hero_title' => 'text', 'hero_image' => 'image', 'products' => 'ids']);
     */
    public static function pageTemplate(string $key, array $meta, array $schema = []): void
    {
        static::registry()->addPageTemplate($key, $meta, $schema);
    }

    /** A menu location offered in Admin › Menus (render it with a menu component in the theme). */
    public static function menuLocation(string $key, string $label): void
    {
        static::registry()->addMenuLocation($key, $label);
    }

    /**
     * Register a content shortcode processed by PageContent (pages, posts, the blog page):
     *   Commerce::shortcode('store_hours', fn (array $atts, array $context) => view('partials.store-hours')->render());
     *
     * @param  callable(array $atts, array $context): string  $render
     * @param  list<string>  $patterns  extra raw-HTML regexes the shortcode also replaces
     */
    public static function shortcode(string $name, callable $render, array $patterns = [], bool $unwrap = true): void
    {
        static::shortcodes()->register($name, $render, $patterns, $unwrap);
    }

    /** Make [$alias …] render the shortcode $name (e.g. a legacy WordPress shortcode name). */
    public static function shortcodeAlias(string $alias, string $name): void
    {
        static::shortcodes()->alias($alias, $name);
    }

    public static function shortcodes(): Content\Shortcodes
    {
        return app(Content\Shortcodes::class);
    }

    // ---------------------------------------------------------------- catalogue

    /**
     * Use $class (a subclass of Services\Catalog\ProductPresenter) as the product presenter – the same as setting
     * config commerce.catalog.presenter. Themes call the presenter through commerce_presenter().
     *
     * @param  class-string<Services\Catalog\ProductPresenter>  $class
     */
    public static function presenter(string $class): void
    {
        if (! is_a($class, Services\Catalog\ProductPresenter::class, true)) {
            throw new \InvalidArgumentException("Product presenter [{$class}] must extend ".Services\Catalog\ProductPresenter::class.'.');
        }
        config(['commerce.catalog.presenter' => $class]);
    }

    /**
     * Add a method to the product presenter without subclassing it (works on the configured presenter class too):
     *
     *   Commerce::presenterMethod('deliveryPromise', fn (Product $product) => $product->inStock() ? 'Next-day delivery' : null);
     *   {{ commerce_presenter()::deliveryPromise($product) }}   // in a theme view
     */
    public static function presenterMethod(string $name, callable $method): void
    {
        Services\Catalog\ProductPresenter::macro($name, $method);
    }

    /**
     * Order the options of the shop filters (facets) with $class (implements Services\Catalog\FacetSorter) – the same
     * as config commerce.catalog.facet_sorter.
     *
     * @param  class-string<Services\Catalog\FacetSorter>  $class
     */
    public static function facetSorter(string $class): void
    {
        if (! is_a($class, Services\Catalog\FacetSorter::class, true)) {
            throw new \InvalidArgumentException("Facet sorter [{$class}] must implement ".Services\Catalog\FacetSorter::class.'.');
        }
        config(['commerce.catalog.facet_sorter' => $class]);
    }

    // ---------------------------------------------------------------- orders

    /**
     * Run $callback when an order moves to one of $statuses ('*' = any change) – a shortcut for listening to
     * Events\OrderStatusChanged. Listeners run in the customer's request (queue = sync): keep them fast.
     *
     *   Commerce::onOrderStatus('completed', fn (Order $order, ?string $from) => Http::timeout(5)->post(…));
     *
     * @param  string|list<string>  $statuses
     * @param  callable(Models\Order $order, ?string $from, string $to): void  $callback
     */
    public static function onOrderStatus(string|array $statuses, callable $callback): void
    {
        $statuses = (array) $statuses;
        \Illuminate\Support\Facades\Event::listen(Events\OrderStatusChanged::class, function (Events\OrderStatusChanged $event) use ($statuses, $callback) {
            if (in_array('*', $statuses, true) || in_array($event->to, $statuses, true)) {
                $callback($event->order, $event->from, $event->to);
            }
        });
    }

    /** Run $callback when checkout has created an order (before payment) – a shortcut for Events\OrderPlaced. */
    public static function onOrderPlaced(callable $callback): void
    {
        \Illuminate\Support\Facades\Event::listen(Events\OrderPlaced::class, fn (Events\OrderPlaced $event) => $callback($event->order));
    }

    /**
     * Replace one of the order emails sent by Listeners\SendOrderStatusEmails (keys: Services\Admin\StoreSettings::
     * ORDER_EMAILS – new_order, customer_processing, customer_completed …) with your own Mailable. Its constructor
     * receives the Order, like the built-in ones.
     *
     * @param  class-string<\Illuminate\Mail\Mailable>  $mailable
     */
    public static function orderEmail(string $key, string $mailable): void
    {
        if (! array_key_exists($key, Services\Admin\StoreSettings::ORDER_EMAILS)) {
            throw new \InvalidArgumentException("Unknown order email [{$key}]; use one of ".implode(', ', array_keys(Services\Admin\StoreSettings::ORDER_EMAILS)).'.');
        }
        if (! is_a($mailable, \Illuminate\Mail\Mailable::class, true)) {
            throw new \InvalidArgumentException("Order email [{$mailable}] must extend ".\Illuminate\Mail\Mailable::class.'.');
        }
        static::registry()->setOrderEmail($key, $mailable);
    }

    // ---------------------------------------------------------------- scheduled tasks

    /**
     * A client scheduled task. It runs like the core ones: Laravel's scheduler (one cron line), the web fallback on
     * sites without cron, `commerce:schedule:task {key}`; its last result is recorded and shown by
     * `commerce:schedule:status` and Admin › Settings › Scheduled tasks. Switch it off per key in
     * config commerce.scheduler.tasks like a core task. Registering the same key again replaces it.
     *
     *   Commerce::scheduledTask('erp.stock-sync', [
     *       'label' => 'ERP stock sync', 'description' => 'Pulls stock levels from the ERP.',
     *       'schedule' => 'everyFifteenMinutes',                 // or '*\/15 * * * *', 'dailyAt:02:30', 'weekdays|hourly'
     *       'call' => fn (\App\Erp\Client $erp) => $erp->syncStock().' products updated',   // or 'task' => Task class, 'command' => 'erp:sync'
     *       'feature' => null, 'setting' => 'erp.sync_enabled',  // optional guards: skipped while off
     *   ]);
     *
     * @param  array{schedule:string|array, label?:string, description?:string, task?:class-string<Scheduling\Task>, call?:callable|class-string, command?:string, feature?:string, setting?:string, setting_default?:mixed, skip?:callable}  $options
     */
    public static function scheduledTask(string $key, array $options): void
    {
        static::registry()->addScheduledTask(Scheduling\ClientTask::define($key, $options));
    }

    // ---------------------------------------------------------------- WordPress importer

    /**
     * An importer adapter (implements Import\Contracts\Adapter) – the same as config commerce-import.adapters.extra.
     *
     * @param  class-string<Import\Contracts\Adapter>|Import\Contracts\Adapter  $adapter
     */
    public static function importAdapter(string|Import\Contracts\Adapter $adapter): void
    {
        if (is_string($adapter) && ! is_a($adapter, Import\Contracts\Adapter::class, true)) {
            throw new \InvalidArgumentException("Import adapter [{$adapter}] must implement ".Import\Contracts\Adapter::class.'.');
        }
        static::registry()->addImportAdapter($adapter);
    }

    /**
     * An extra importer step (implements Import\Contracts\Step) that always runs – no adapter or detection needed.
     *
     * @param  class-string<Import\Contracts\Step>|Import\Contracts\Step  $step
     */
    public static function importStep(string|Import\Contracts\Step $step): void
    {
        if (is_string($step) && ! is_a($step, Import\Contracts\Step::class, true)) {
            throw new \InvalidArgumentException("Import step [{$step}] must implement ".Import\Contracts\Step::class.'.');
        }
        static::registry()->addImportStep($step);
    }
}
