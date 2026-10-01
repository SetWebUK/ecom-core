<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Commerce;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;

/**
 * Worked examples of the Pine Commerce extension API (pine/commerce docs/EXTENDING.md). NOT called: copy what you need
 * into App\Providers\ClientServiceProvider::boot() and delete this file when the project is set up.
 *
 * The App\… classes named below are placeholders for your own code (see EXTENDING.md for a full example of each).
 * Pine Commerce's own tests use every extension point: vendor/pine/commerce/tests/Feature/ExtensionApiTest.php.
 */
final class ExtensionExamples
{
    public static function boot(): void
    {
        // ------------------------------------------------------------ payments / shipping
        // A gateway class extends Pine\Commerce\Services\Payments\Gateway (implements Contracts\PaymentGateway);
        // its adminSettings() declares the card and fields shown in Admin › Settings › Payments.
        Commerce::gateway('klarna', \App\Payments\KlarnaGateway::class);
        // Commerce::gateway('bacs', null);                          // remove a built-in gateway

        // Price delivery options whose code matches (fnmatch); return null to hide an option for this basket.
        Commerce::shippingCalculator('weight_*', \App\Shipping\WeightBands::class);
        Commerce::shippingCalculator('pallet', fn ($method, float $cost, $lines, array $context) => $context['subtotal'] >= 500 ? 0.0 : null);

        // ------------------------------------------------------------ back office
        Commerce::adminRoutes(function () {                     // prefix /admin, names admin.*, staff only
            Route::get('trade-accounts', [\App\Http\Controllers\Admin\TradeAccountController::class, 'index'])->name('trade.index');
        });
        Commerce::adminMenu()->add('Trade accounts', 'briefcase', 'admin.trade.index', ['admin.trade.*'], after: 'admin.customers.index');

        Commerce::settings('trade', ['label' => 'Trade accounts', 'icon' => 'briefcase', 'description' => 'Trade discount and minimum order.'], [
            ['title' => 'Trade terms', 'fields' => [
                ['key' => 'trade.discount', 'label' => 'Trade discount (%)', 'type' => 'decimal', 'default' => 10, 'max' => 50],
                ['key' => 'trade.min_order', 'label' => 'Minimum order', 'type' => 'money', 'default' => 100],
            ]],
        ]);
        Commerce::settingsFields('checkout', ['title' => 'Gift messages', 'fields' => [
            ['key' => 'gifts.enabled', 'label' => 'Offer a gift message at checkout', 'type' => 'bool', 'default' => false],
        ]]);

        Commerce::dashboardWidget('trade', [
            'title' => 'Trade accounts', 'view' => 'admin.widgets.trade', 'sort' => 50,
            'data' => fn (Request $request) => ['pending' => 0 /* \App\Models\TradeAccount::pending()->count() */],
        ]);

        // ------------------------------------------------------------ content
        Commerce::pageTemplate('landing', ['label' => 'Landing page', 'help' => 'Campaign page', 'view' => 'pages.landing'],
            ['headline' => 'text', 'hero_image' => 'image', 'products' => 'ids']);
        Commerce::shortcode('store_hours', fn (array $atts) => view('partials.store-hours', $atts)->render());
        Commerce::shortcodeAlias('opening_times', 'store_hours');     // an old WordPress shortcode name
        Commerce::menuLocation('top_bar', 'Top bar links');

        // ------------------------------------------------------------ catalogue
        // Commerce::presenter(\App\Catalog\ClientProductPresenter::class);     // subclass of ProductPresenter
        Commerce::presenterMethod('deliveryPromise', fn (Product $product) => $product->stock_status === 'instock' ? 'Order by 3pm for next-day delivery' : null);
        // Commerce::facetSorter(\App\Catalog\SizeFacetSorter::class);

        // ------------------------------------------------------------ orders
        Commerce::onOrderStatus('completed', function (Order $order, ?string $from, string $to) {
            Http::timeout(5)->post((string) config('services.erp.url'), ['order' => $order->number, 'total' => $order->total]);
        });
        Commerce::orderEmail('customer_completed', \App\Mail\CompletedWithReviewInvite::class);

        // ------------------------------------------------------------ WordPress importer
        Commerce::importAdapter(\App\Import\Client\ClientCatalogAdapter::class);
        Commerce::importStep(\App\Import\Client\LoyaltyPointsStep::class);

        // ------------------------------------------------------------ scheduled jobs (since 1.2)
        // Run by cron (or the web fallback), shown in commerce:schedule:status and Settings › Scheduled tasks.
        Commerce::scheduledTask('client.erp-stock', [
            'label' => 'ERP stock sync', 'description' => 'Pulls stock levels from the ERP.',
            'schedule' => 'everyFifteenMinutes',                            // or '*/15 * * * *', 'dailyAt:02:30', 'weekdays|hourly'
            'call' => fn () => Http::timeout(10)->get((string) config('services.erp.url').'/stock')->successful() ? 'Stock synced' : 'ERP unreachable',
            'setting' => 'erp.sync_enabled',                               // skipped while this owner setting is off
        ]);
        Commerce::scheduledTask('client.feed-export', ['schedule' => 'dailyAt:04:15', 'command' => 'client:export-feed']);
        Commerce::scheduledTask('client.reviews-digest', ['schedule' => 'mondays|dailyAt:08:00', 'feature' => 'reviews',
            'task' => \App\Scheduling\ReviewsDigest::class]);           // extends Pine\Commerce\Scheduling\Task
    }
}
