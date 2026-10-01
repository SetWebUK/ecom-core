<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Pine\Commerce\Events\OrderPlaced;

/**
 * {{CLIENT_NAME}} - the client's extension point on top of pine/commerce (registered in bootstrap/providers.php).
 *
 * Rule of thumb (pine/commerce docs/EXTENDING.md): never edit vendor/pine/commerce. Change behaviour with config
 * (config/commerce.php), the theme (themes/{{CLIENT_SLUG}}), events, view composers and the Commerce extension API.
 * Everything below is an EXAMPLE - delete what you do not need.
 */
class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Container bindings, e.g. a client product presenter (also possible via config commerce.catalog.presenter):
        // $this->app->bind(\Pine\Commerce\Services\Catalog\ProductPresenter::class, \App\Catalog\ClientProductPresenter::class);
    }

    public function boot(): void
    {
        // DATA SAFETY - keep this: the shop database holds live client data. Refuse migrate:fresh / migrate:refresh /
        // migrate:reset / db:wipe (and RefreshDatabase in tests) on MySQL/MariaDB. Tests use in-memory SQLite.
        if (in_array(config('database.connections.'.config('database.default').'.driver'), ['mysql', 'mariadb'], true)) {
            \Illuminate\Support\Facades\DB::prohibitDestructiveCommands();
        }

        // React to store events (sync queue: keep listeners fast, never call slow APIs without a timeout).
        Event::listen(OrderPlaced::class, function (OrderPlaced $event) {
            // e.g. push the order to the client's ERP / accounting system
            // \App\Integrations\Erp::pushOrder($event->order);
        });

        // Extra data for storefront views (the theme's views resolve as theme::*, bare names work too).
        View::composer('layouts.app', function ($view) {
            // $view->with('clientBanner', setting('client.banner_text'));
        });

        // Extension API (pine/commerce docs/EXTENDING.md; worked examples in ExtensionExamples.php):
        // \Pine\Commerce\Commerce::shortcode('opening_hours', fn (array $attrs) => view('partials.opening-hours')->render());
        // \Pine\Commerce\Commerce::adminMenu()->add('Trade accounts', 'briefcase', 'admin.trade.index', ['admin.trade.*']);
        // \Pine\Commerce\Commerce::gateway('klarna', \App\Payments\KlarnaGateway::class);

        // Scheduled jobs: run by the one cron line (or the web fallback without cron), listed by
        // `php artisan commerce:schedule:status` and Admin › Settings › Scheduled tasks with their last result.
        // \Pine\Commerce\Commerce::scheduledTask('{{CLIENT_SLUG}}.erp-stock', [
        //     'label' => 'ERP stock sync',
        //     'schedule' => 'everyFifteenMinutes',          // or a cron expression '*/15 * * * *', 'dailyAt:02:30', 'weekdays|hourly'
        //     'call' => fn () => \App\Integrations\Erp::syncStock().' products updated',  // or 'command' => 'erp:sync', 'task' => Task class
        //     'setting' => 'erp.sync_enabled',              // optional guards: owner setting / 'feature' => 'reviews'
        // ]);
    }
}
