# Extending Pine Commerce in a client project

The rule: **never edit `vendor/pine/commerce`**. A client
changes behaviour through, in this order of preference:

1. **Config** – `config/commerce.php`, `config/commerce-import.php` (feature switches, cookie names, currency,
   catalogue filters, importer mappings).
2. **Settings** – everything the shop owner edits in Admin › Settings (store details, checkout, emails, SEO,
   payments, delivery).
3. **The theme** – `themes/{client}` views, CSS/JS, theme config and `Theme.php` hooks ([THEMES.md](THEMES.md)).
4. **Client code** – `App\Providers\ClientServiceProvider` (registered in `bootstrap/providers.php`) using the
   **extension API** on `Pine\Commerce\Commerce` (below), client routes in `routes/web.php`, client
   models/tables/migrations, importer adapters in `app/Import`.

If none of these can do it, the change belongs in the package: make it generic (a config key, a feature switch, an
event or an extension point), keep every existing client's output identical, and follow [UPGRADING.md](UPGRADING.md).

Binding reference: [ARCHITECTURE.md](ARCHITECTURE.md) §9–§11 and §18.8.

**Typical client:** `app/Providers/ClientServiceProvider.php` registers its product presenter, facet sorter, legacy
shortcode aliases and importer adapters through this API; `themes/{client}/Theme.php` adds its body classes. The
package's own tests show every extension point in use: `tests/Feature/ExtensionApiTest.php` (fixtures in
`tests/Fixtures`).

---

## Contents

- [Config overrides](#config-overrides)
- [Feature switches](#feature-switches)
- [The extension API at a glance](#the-extension-api-at-a-glance)
- [Payment gateways](#payment-gateways) · [Shipping calculators](#shipping-calculators)
- [Admin pages and the sidebar](#admin-pages-and-the-sidebar) · [Settings screens and fields](#settings-screens-and-fields) · [Dashboard widgets](#dashboard-widgets)
- [Page templates](#page-templates) · [Shortcodes](#shortcodes) · [Menu locations](#menu-locations)
- [Product presentation](#product-presentation) · [Facet sorter](#facet-sorter)
- [Order events, hooks and emails](#order-events-hooks-and-emails)
- [Views and view composers](#views-and-view-composers) · [Client routes](#client-routes)
- [Scheduled tasks](#scheduled-tasks) · [Updates](#updates) · [Importer adapters and steps](#importer-adapters-and-steps)
- [Client data](#client-data) · [Checklist](#checklist-for-client-code)

---

## Config overrides

`config/commerce.php` in the client **replaces whole top-level keys** (Laravel's `mergeConfigFrom` is shallow): to
change one catalogue key, copy the complete `catalog` block from `vendor/pine/commerce/config/commerce.php`. Keys you
leave out keep the package default. **Exception: `features`** is merged key by key – a client block that predates a
new switch keeps that switch's package default.

```php
// config/commerce.php (client)
return [
    'theme' => env('COMMERCE_THEME', 'acme'),
    'features' => ['blog' => false, 'reviews' => env('COMMERCE_REVIEWS', true)],   // the rest keep their defaults
    'catalog' => [/* all catalog keys */ 'per_page' => 36, 'brand_attribute' => 'brand', /* … */],
    'orders' => ['reference_prefix' => 'AC'],
];
```

Never change cookie/header names (`cart.cookie`, `cart.open_cookie`, `checkout.attribution_cookie`,
`catalog.ajax_header`) after go-live – live baskets would be lost.

## Feature switches

`config('commerce.features.*')`, read with `commerce_feature('key')` (Blade/PHP) or `Commerce::feature('key')`.
Switches are **per site, in config** (optionally from `.env` via `env()` in the client's config file) – the shop
owner sees them read-only in **Admin › Settings › System** (state, package default, what each one gates). After
changing one on a server: `php artisan optimize:clear && php artisan optimize`.

A switched-off feature is off everywhere:

- **routes** keep their names (themes can still call `route()`, cached routes stay valid) but answer **404**
  (`Pine\Commerce\Http\Middleware\RequireFeature`; add it to client routes with `->middleware(RequireFeature::for('blog'))`);
- **admin**: sidebar entries, admin routes (404), cross-links and dashboard cards disappear;
- **storefront**: both shipped themes hide the entry points (links, buttons, forms) – custom themes must wrap theirs
  in `@if (commerce_feature('key'))`;
- **sitemap**, shortcodes (`[contact_form]`, `[order_tracking]`, `[blog_index]` render nothing), **emails** and
  **importer steps** follow the switch.

View-bearing switches (`blog`, `wishlist`, `reviews`, `stock_alerts`, `newsletter`, `contact_form`, `order_tracking`,
`quick_view`) are also off on the storefront when the active theme does not list them in `theme.json` `supports`
(`commerce_feature('wishlist', false)` checks the switch only, as the back office does).

| Switch | Default | Gates |
|---|---|---|
| `blog` | on | blog index/posts/categories + RSS routes, RSS `<link>`, admin Blog posts/categories, sitemap posts + /blog, `[blog_index]`, importer `content.posts` |
| `wishlist` | on | wishlist toggle + My account › Wishlist routes, heart buttons/nav entries, importer `extras.wishlists` |
| `reviews` | on | review route + form, star ratings, review JSON-LD, admin Reviews, importer `extras.reviews` |
| `stock_alerts` | on | back-in-stock route + form, back-in-stock emails, admin Stock alerts, importer `extras.stock-alerts` |
| `newsletter` | on | sign-up route + footer form, admin Inbox › Newsletter |
| `contact_form` | on | contact route, `[contact_form]`, contact-page form, admin Inbox › Form submissions + dashboard messages, importer `extras.forms` |
| `order_tracking` | on | order-tracking route + `[order_tracking]` |
| `quick_view` | on | quick-view route + buttons |
| `google_feed` | on | `feeds/google-shopping.xml` + the feed card in Settings › SEO |
| `abandoned_carts` | on | admin Orders › Abandoned checkouts, Settings › Abandoned carts, reminder emails (also need the owner's setting, off by default) and their restore/unsubscribe links |
| `coupons` | on | coupon routes, discount-code field (basket/checkout), stored codes ignored when off, admin Discounts, importer `extras.coupons` |
| `guest_checkout` | on | off: guests are sent to My account to sign in/register before checkout |
| `registration` | on | register route + forms, "create an account" at checkout (AND Settings › Checkout › "Customers can create an account") |
| `reports` | on | admin Analytics (reports + CSV export) |
| `redirects` | on | redirect rules on old URLs, admin Content › Redirects, importer `redirects` |
| `multi_shipping` | on | off: only the first available delivery option is offered |
| `product_condition` | off | condition field/filter/CSV, condition facet, `itemCondition` in JSON-LD + feed |
| `product_brand` | on | brand panel/filter, brand facet + logos |
| `spec_highlights` | off | presenter `highlights()` / `cardSpecs()` / `specLine()` |
| `pay_in_3` | off | instalment line on product pages |
| `legacy_content` | on | Elementor per-page CSS, WPBakery toggles, Font Awesome icons in imported WordPress content |
| `wp_404_guess` | on | old WordPress URLs: guess the product/category by slug before the 404 |
| `add_to_cart_query` | on | old `?add-to-cart={id}` links |
| `product_csv` | on | admin Products › Import / Export (full product CSV, WooCommerce exports accepted), `commerce:products:export` / `commerce:products:import` ([PRODUCT-CSV.md](PRODUCT-CSV.md)) |
| `updater` | on | admin Updates (administrators only): daily update check, dashboard notice + sidebar badge, approved pine/commerce updates, skeleton file updates; `commerce:update:*`, `commerce:skeleton:*`, scheduler task `updates.check` ([Updates](#updates)) |

Tests: `tests/Feature/FeatureFlagsTest.php` turns each one on and off.

---

## The extension API at a glance

Call these from `App\Providers\ClientServiceProvider::boot()` (or a theme's `ThemeDefinition::boot()`):

```php
use Pine\Commerce\Commerce;
```

| API | Purpose | Config alternative |
|---|---|---|
| `Commerce::gateway($code, $class\|null)` | add / replace / remove a payment gateway | `commerce.payments.gateways` |
| `Commerce::shippingCalculator($pattern, $calculator)` | price or hide delivery options by code | – |
| `Commerce::adminRoutes(fn () => …)` | client back-office routes (prefix, `admin.` names, staff middleware) | – |
| `Commerce::adminMenu()->add()/child()/remove()` | admin sidebar entries | `commerce.admin.menu` |
| `Commerce::settings($key, $group, $sections)` (alias `settingsGroup`) | a new Admin › Settings screen | – |
| `Commerce::settingsFields($group, $section)` | an extra card of fields on a settings screen | – |
| `Commerce::dashboardWidget($key, $widget)` / `removeDashboardWidget()` | back-office dashboard cards | – |
| `Commerce::pageTemplate($key, $meta, $schema)` | page templates with a block schema | theme `config/blocks.php` |
| `Commerce::shortcode($name, $render)` / `shortcodeAlias($alias, $name)` | content shortcodes | `commerce.content.shortcode_aliases` |
| `Commerce::menuLocation($key, $label)` | menu locations in Admin › Menus | theme `config/menus.php` |
| `Commerce::presenter($class)` / `presenterMethod($name, $fn)` | product presenter / extra presenter methods | `commerce.catalog.presenter` |
| `Commerce::facetSorter($class)` | shop filter option order | `commerce.catalog.facet_sorter` |
| `Commerce::onOrderStatus($statuses, $fn)` / `onOrderPlaced($fn)` | order hooks (events) | ordinary `Event::listen` |
| `Commerce::orderEmail($key, $mailable)` | replace an order email | – |
| `Commerce::importAdapter($class)` / `importStep($class)` | WordPress importer adapters / steps | `commerce-import.adapters.extra` |
| `Commerce::scheduledTask($key, $options)` | a client scheduled job (cron + web fallback, status, last result) | `commerce.scheduler.tasks.{key}` switches it off |
| `Commerce::feature($key)` | read a feature switch | `commerce_feature()` |
| `ThemeDefinition::composers()` | view composers of a theme | – |

Also: `Commerce::middleware()` / `Commerce::exceptions()` (bootstrap delegation – add client middleware after the
call), `Commerce::userModel()`, `Commerce::payments()` (the `PaymentManager`), `Commerce::shortcodes()`.

Contracts a client implements live in `Pine\Commerce\Contracts`: `PaymentGateway`, `ShippingCalculator`,
`DashboardWidget`; importer contracts in `Pine\Commerce\Import\Contracts` ([IMPORTER.md](IMPORTER.md)).

A complete client provider (worked examples for a new project:
`stubs/client-skeleton/app/Providers/ExtensionExamples.php`, copied by `commerce:new-client`):

```php
namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pine\Commerce\Commerce;

class ClientServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Commerce::gateway('klarna', \App\Payments\KlarnaGateway::class);
        Commerce::shippingCalculator('dpd_*', \App\Shipping\DpdZones::class);

        Commerce::adminRoutes(function () {
            Route::get('trade-accounts', [\App\Http\Controllers\Admin\TradeAccountController::class, 'index'])->name('trade.index');
        });
        Commerce::adminMenu()->add('Trade accounts', 'briefcase', 'admin.trade.index', after: 'admin.customers.index');
        Commerce::settings('trade', ['label' => 'Trade accounts', 'icon' => 'briefcase', 'description' => 'Trade discount and minimum order.'], [
            ['title' => 'Trade terms', 'fields' => [
                ['key' => 'trade.discount', 'label' => 'Trade discount (%)', 'type' => 'decimal', 'default' => 10, 'max' => 50],
            ]],
        ]);
        Commerce::dashboardWidget('trade', ['title' => 'Trade orders', 'view' => 'admin.widgets.trade',
            'data' => fn ($request) => ['count' => \App\Models\TradeAccount::count()]]);

        Commerce::pageTemplate('landing', ['label' => 'Landing page', 'view' => 'pages.landing'], ['headline' => 'text', 'products' => 'ids']);
        Commerce::shortcode('store_hours', fn (array $atts) => view('partials.store-hours', $atts)->render());
        Commerce::presenterMethod('deliveryPromise', fn ($product) => $product->stock_status === 'instock' ? 'Next-day delivery' : null);

        Commerce::onOrderStatus('completed', fn ($order) => \App\Erp::push($order));
        Commerce::importAdapter(\App\Import\Acme\AcmeCatalogAdapter::class);
        Commerce::scheduledTask('acme.erp-stock', ['label' => 'ERP stock sync', 'schedule' => 'everyFifteenMinutes', 'command' => 'acme:erp-stock']);
    }
}
```

---

## Payment gateways

Every gateway implements `Pine\Commerce\Contracts\PaymentGateway`; the built-in `StripeGateway`, `PaypalGateway` and
`BacsGateway` do so through the abstract `Pine\Commerce\Services\Payments\Gateway`, which is the easiest base for a
client gateway. Register it with `Commerce::gateway()` (or `commerce.payments.gateways`, code => class, which also
sets the checkout order; `Commerce::gateway('bacs', null)` removes one).

```php
namespace App\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Validator;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\Gateway;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Services\Payments\PaymentResult;
use Symfony\Component\HttpFoundation\Response;

class KlarnaGateway extends Gateway
{
    public function code(): string { return 'klarna'; }            // orders.payment_method, webhooks/klarna
    protected function defaultTitle(): string { return 'Pay later with Klarna'; }
    public function isConfigured(): bool { return $this->secret('api_key') !== null && filled($this->setting('merchant_id')); }

    /** The card in Admin › Settings › Payments – rendered, validated and saved by the package. */
    public function adminSettings(): array
    {
        return [
            'label' => 'Klarna', 'icon' => 'credit-card',
            'description' => 'Pay in 3 / pay later. Keys: Klarna merchant portal › Settings › API credentials.',
            'fields' => static::baseSettingsFields($this->defaultTitle()) + [   // enabled, title, description
                'test_mode' => ['type' => 'bool', 'label' => 'Test mode'],
                'merchant_id' => ['type' => 'text', 'label' => 'Merchant ID', 'mono' => true],
                'api_key' => ['type' => 'secret', 'label' => 'API key', 'pattern' => '/^klarna_(live|test)_\w+$/',
                    'message' => 'A Klarna key starts with klarna_live_ or klarna_test_.'],
            ],
            'webhook' => ['provider' => 'Klarna', 'where' => 'Klarna › Settings › Webhooks'],  // shows webhooks/klarna
        ];
    }

    public function validateSettings(array $values, Validator $validator): void
    {
        if (($values['api_key'] ?? '') !== '' && str_contains($values['api_key'], '_test_') !== filter_var($values['test_mode'] ?? false, FILTER_VALIDATE_BOOL)) {
            $validator->errors()->add('payments.klarna.api_key', 'Test mode and the key type must match.');
        }
    }

    /** Called by POST /checkout/ after the order was created (status pending). */
    public function process(Order $order, Request $request): PaymentResult
    {
        $session = Http::timeout(10)->withToken($this->secret('api_key'))->post('https://api.klarna.test/sessions', [
            'amount' => $this->pence((float) $order->total), 'reference' => $order->number,
            'return_url' => route('checkout.payment.return', ['gateway' => $this->code(), 'order' => $order->number, 'key' => $order->order_key]),
        ])->throw()->json();

        return PaymentResult::redirect($session['redirect_url']);        // or success($url) / action([...]) / failure($msg)
    }

    /** GET checkout/payment/klarna/return?order=…&key=… (the order is looked up and checked by the package). */
    public function handleReturn(Order $order, Request $request): PaymentResult
    {
        // confirm with the provider, then book the money – idempotent, also used by the webhook
        PaymentManager::complete($order, $this->code(), $request->query('transaction'), (float) $order->total);

        return PaymentResult::success($order->view_url);
    }

    public function handleWebhook(Request $request): Response { /* verify signature, PaymentManager::complete()/fail() */ return response('ok'); }
}
```

- Field types: `bool`, `text`, `textarea`, `secret` (encrypted with `Crypt`, write-only in the form, never flashed
  back on a validation error), plus `help`, `default`, `placeholder`, `pattern` + `message`, `mono`, `optional`, `wide`.
- Read settings with `$this->setting('key')`, secrets with `$this->secret('key')` (stored as `payments.{code}.{key}`).
- `icons()` (logos next to the name) and `checkoutHtml()` (trusted HTML inside the method's panel – both shipped
  themes render it) are optional; `supportsRefunds()` + `refund()` enable refunds from the order screen.
- Bookkeeping: `PaymentManager::complete($order, $code, $transactionId, $amount)` marks the order paid
  (pending → processing, notes, emails); `PaymentManager::fail(...)` records a failed attempt.
- `commerce:doctor` lists every gateway's enabled/configured state.

## Shipping calculators

Delivery options are the methods of the shipping zones in Admin › Settings › Shipping (flat rate, free shipping,
weight/price bands, local pickup – [TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md)). Most shops need no code. A calculator
prices the options whose **code** matches a pattern (`fnmatch`: `dpd_*`, `pallet`, `*`) **after** the method's own
type has priced it (`$cost`); the first matching registration wins. Return the cost (as entered – with or without tax
like Settings › Tax says), or `null` to hide the option for this basket.

```php
use Illuminate\Support\Collection;
use Pine\Commerce\Contracts\ShippingCalculator;
use Pine\Commerce\Models\ShippingMethod;

class WeightBands implements ShippingCalculator
{
    public function cost(ShippingMethod $method, float $cost, Collection $lines, array $context): ?float
    {
        $kg = $lines->sum(fn ($line) => (float) ($line->product->weight ?? 0) * $line->quantity);

        return $kg > 30 ? null : $cost + max(0, ceil($kg - 2)) * 1.50;   // flat cost + £1.50 per kg over 2 kg
    }
}

Commerce::shippingCalculator('weight_*', WeightBands::class);
Commerce::shippingCalculator('pallet', fn ($method, $cost, $lines, $context) => $context['subtotal'] >= 500 ? 0.0 : null);
```

`$context`: `subtotal`, `discount`, `country`, `postcode`, `zone` (the matched `ShippingZone` or null), `free_shipping`
(a coupon grants it), `lines`. The zone, country list and minimum-order rules apply first. With the `multi_shipping`
switch off only the first available option is offered. Tax on the result follows the method's tax status and
Settings › Tax.

---

## Admin pages and the sidebar

Client back-office pages are ordinary controllers + Blade views using the package layout and components
([ADMIN_UI.md](ADMIN_UI.md)) – no admin framework. `Commerce::adminRoutes()` registers them exactly like the
package's own routes: middleware `web` + `admin` (staff), URL prefix `commerce.admin.path`, route names prefixed
`admin.`. Add `->middleware('admin:admin')` for administrators only. Don't put client pages under `settings/` (that
path belongs to the settings screens).

```php
Commerce::adminRoutes(function () {
    Route::get('trade-accounts', [TradeAccountController::class, 'index'])->name('trade.index');          // admin.trade.index
    Route::post('trade-accounts/{account}/approve', [TradeAccountController::class, 'approve'])->name('trade.approve');
});

Commerce::adminMenu()
    ->add('Trade accounts', 'briefcase', 'admin.trade.index', active: ['admin.trade.*'], after: 'admin.customers.index',
        feature: null, admin: false, children: [['label' => 'Applications', 'route' => 'admin.trade.applications']])
    ->child('admin.orders.index', 'Trade orders', 'admin.trade.orders')     // a sub-item under a core section
    ->remove('admin.reports.index');                                         // hide a core entry
```

```blade
{{-- resources/views/admin/trade/index.blade.php --}}
@extends('commerce::admin.layouts.app')
@section('title', 'Trade accounts')
@section('content')
    <x-admin.page-header title="Trade accounts" />
    <x-admin.card flush>…<x-admin.table>…</x-admin.table></x-admin.card>
@endsection
```

Entry keys: `label`, `icon` (admin icon name), `route` (route **name**), `active` (route-name patterns that highlight
it; default the route and `route.*`), `after` (route of the top-level entry to insert after; default: end),
`children`, `feature` (switch that must be on), `admin` (administrators only), `count` (int or closure – the badge).
Config alternative: `commerce.admin.menu` (a list of the same arrays). Core entries of switched-off features are
hidden automatically.

## Settings screens and fields

A client settings screen is rendered, validated and saved by the package's generic settings form (no controller or
view needed) and appears in Admin › Settings before *System*. Values are read with `setting('key', $default)`.

```php
Commerce::settings('loyalty', ['label' => 'Loyalty points', 'icon' => 'star', 'description' => 'Points per order.',
    'admin' => false /* true = administrators only */, 'feature' => null], [
    ['title' => 'Earning', 'description' => 'Shown on the product page.', 'fields' => [
        ['key' => 'loyalty.points_per_pound', 'label' => 'Points per £1', 'type' => 'int', 'default' => 1, 'max' => 100,
            'drives' => 'Product page badge and the order confirmation.'],
        ['key' => 'loyalty.terms_page', 'label' => 'Terms page', 'type' => 'select', 'options' => 'pages'],
    ]],
]);

// an extra card on an existing screen (general, checkout, emails, seo or a client screen)
Commerce::settingsFields('checkout', ['title' => 'Trade', 'fields' => [
    ['key' => 'trade.min_order', 'label' => 'Trade minimum order', 'type' => 'money'],
]]);
```

Field types (see `Pine\Commerce\Services\Admin\StoreSettings`): `text`, `textarea`, `email`, `emails`, `url`, `link`,
`image`, `bool`, `int`, `decimal`, `money`, `select` (`options` array or `'pages'`), `list`; options `default`, `help`,
`drives`, `required`, `min`, `max`, `pattern`, `placeholder`. A section with `'feature' => 'key'` only shows while that
switch is on. The URL is `/admin/settings/{key}` (route `admin.settings.edit`).

## Dashboard widgets

```php
Commerce::dashboardWidget('trade', [
    'title' => 'Trade orders', 'subtitle' => 'Last 7 days',
    'view' => 'admin.widgets.trade',                       // client resources/views/admin/widgets/trade.blade.php
    'data' => fn (Request $request) => ['orders' => TradeOrder::recent()->get()],   // only runs when shown
    'wide' => false,        // true = main column, false = side column
    'sort' => 50, 'admin' => false, 'feature' => null,
]);
Commerce::dashboardWidget('stock-value', \App\Admin\StockValueWidget::class);   // implements Contracts\DashboardWidget
Commerce::removeDashboardWidget('trade');
```

`'title' => null` renders the view without a card around it. A widget that throws is reported to the log and left
out, so it never breaks the dashboard.

---

## Page templates

A template appears in Admin › Pages › Template, the page builder shows its block fields, and the storefront renders
`$meta['view']` (any view) or, without it, the theme view `pages.{key}`. The view receives `$page`, `$blocks`,
`$contentHtml`, `$seo`, `$bodyClass`.

```php
Commerce::pageTemplate('landing', ['label' => 'Landing page', 'help' => 'Campaign page with hero and products', 'view' => 'pages.landing'], [
    'headline' => 'text',
    'hero' => ['object', ['image' => 'image', 'button_text' => 'text', 'button_link' => 'link']],
    'products' => 'ids',
    'faq' => ['list', ['question' => 'text', 'answer' => 'html'], ['label' => 'Questions', 'item' => 'question', 'max' => 20]],
]);
```

Block types: `text`, `multiline`, `textarea`, `html`, `link`, `image`, `int`, `bool`, `ids` (product ids),
`['list', fields, meta]`, `['object', fields, meta]` (see `Pine\Commerce\Services\Admin\PageBlocks`). A theme can
declare templates in `config/blocks.php` instead ([THEMES.md](THEMES.md)).

## Shortcodes

Processed by `PageContent` in pages, posts and the blog page, in registration order.

```php
Commerce::shortcode('store_hours', fn (array $atts, array $context) => view('partials.store-hours', ['days' => $atts['days'] ?? 'Mon–Fri'])->render());
Commerce::shortcodeAlias('opening_times', 'store_hours');           // an old WordPress name
```

Core shortcodes: `[contact_form]`, `[blog_index posts_per_page=9]`, `[sitemap]` (alias `wp_sitemap_page`),
`[order_tracking]` (alias `woocommerce_order_tracking`); they render nothing while their feature is off.

## Menu locations

```php
Commerce::menuLocation('top_bar', 'Top bar links');
```

The location is offered in Admin › Menus; render it in the theme with a menu component (`<x-footer-menu location="top_bar" />`)
or `Menu::where('location', 'top_bar')`.

Menu labels and links may contain **store-setting tokens** (1.1): `{store.phone}`, `{store.email}`, `{store.name}` –
any `store.*` setting (Settings › Store). They are expanded on every request after the menu cache (`MenuComponent::
expandTokens()`, also for `menu_tree()` and theme fallback menus), so a changed setting shows at once; in a `tel:` link
only digits and `+` are kept (`tel:{store.phone}`). Other setting groups are never expanded.

---

## Product presentation

`commerce.catalog.presenter` (or `Commerce::presenter($class)` in a service provider) names the product presenter
class (static API used by theme views via `commerce_presenter()`, and by core itself, so an override applies
everywhere). A client subclasses `Pine\Commerce\Services\Catalog\ProductPresenter` and overrides what differs – every
method is called through `static::`, so one override changes all callers (e.g. `App\Acme\AcmeProductPresenter`).

The core presenter is neutral and config-driven; the hooks meant for overriding:

| Method | Core behaviour | Override for… |
|---|---|---|
| `noun($product)` | `'product'` | a noun for copy ("shirt", "sofa") |
| `conditions($product)` / `condition()` | condition attribute terms (flag `product_condition`), descriptions from theme config `product.condition_descriptions` | grade badges, per-grade copy |
| `itemCondition($product)` | `commerce.catalog.condition_schema_map` | custom schema.org mapping |
| `brandName()` / `brandFromTitle()` / `brandLogos()` | `commerce.catalog.brand_attribute`, `known_brands`, theme config `product.brand_logos` | brand rules |
| `highlights()` / `cardSpecs()` / `specLine()` / `specValue()` | attributes in `commerce.catalog.spec_attributes` (flag `spec_highlights`) | spec cards, units, title parsing |
| `specRows($product)` | the `product_specs` rows (neutral specifications table) | – |
| `payIn3($price)` | `commerce.pay_in_3.{min,max,instalments}` (flag `pay_in_3`) | another instalment rule |
| `cardPriceClass()` | `'card-price'` (CSS block of `cardPriceHtml()`) | the theme's own class names |
| `amount()` / `priceHtml()` / `saveBadge()` | WooCommerce price markup, symbol from `commerce.currency.symbol` | – |

**Extra presenter methods without a subclass** (they work on whichever presenter class is configured):

```php
Commerce::presenterMethod('deliveryPromise', fn (Product $product) => $product->stock_status === 'instock' ? 'Order by 3pm for next-day delivery' : null);
```

```blade
@php $P = commerce_presenter(); @endphp
@if ($promise = $P::deliveryPromise($product))<p class="delivery-promise">{{ $promise }}</p>@endif
```

Client-only presentation methods (e.g. a `why()`, `process()` or `hasWarranty()` of the client's own) simply live on the subclass
and are called by the client theme's views; core and the default theme never call them.

Other client-layer hooks with neutral package defaults (set them in the client's `config/commerce.php`):
`content.legacy_class_prefix` (classes of generated legacy WordPress markup, default `wp-`), `content.shortcode_aliases`,
`catalog.legacy_page_params`, `catalog.ajax_header`, `cart.cookie` / `open_cookie`, `checkout.attribution_cookie`,
`forms.contact.prefix`, `feeds.google.condition_map`, `documents.logo`, `settings.defaults`, `admin.brand.*`.
Theme config: `product.notify_anchor` (id of the back-in-stock form), theme views `checkout.partials.paypal-icon` /
`card-icons`, `Theme::bodyClass()` for extra body classes.

## Facet sorter

```php
Commerce::facetSorter(\App\Catalog\SizeFacetSorter::class);   // implements Pine\Commerce\Services\Catalog\FacetSorter
```

Orders the options of each shop filter group (core: natural sort). A client sorter can, for example, order sizes
(`S, M, L, XL`) or capacities (`8GB, 16GB, 1TB`) numerically.

---

## Order events, hooks and emails

Core events are ordinary Laravel events. The queue is `sync` on our servers, so listeners run inside the customer's
request: keep them fast and give HTTP calls a timeout.

| Event | When | Payload |
|---|---|---|
| `Pine\Commerce\Events\OrderPlaced` | checkout created the order (before payment) | `$event->order` |
| `Pine\Commerce\Events\OrderStatusChanged` | any status change | `$event->order`, `$event->from` (?string), `$event->to` |

Statuses: `pending` (awaiting payment) → `processing` (paid) → `completed`; `on-hold` (bank transfer / underpaid),
`failed`, `cancelled`, `refunded`. Core listeners, in order: `SaveCheckoutCustomer` (OrderPlaced),
`AssignInvoiceNumber`, `SendOrderStatusEmails` then `SyncOrderStock` (OrderStatusChanged); `php artisan event:list`
shows the map.

```php
Commerce::onOrderStatus('completed', fn (Order $order, ?string $from, string $to) => Http::timeout(5)->post(config('services.erp.url'), ['order' => $order->number]));
Commerce::onOrderStatus(['cancelled', 'refunded'], fn (Order $order) => \App\Loyalty::revoke($order));
Commerce::onOrderStatus('*', fn (Order $order, ?string $from, string $to) => Log::info("{$order->number}: {$from} → {$to}"));
Commerce::onOrderPlaced(fn (Order $order) => \App\Attribution::record($order));

// the same with plain Laravel
Event::listen(OrderStatusChanged::class, fn (OrderStatusChanged $e) => …);
```

**Order emails** (keys in `StoreSettings::ORDER_EMAILS`: `new_order`, `cancelled_order`, `failed_order`,
`customer_processing`, `customer_on_hold`, `customer_completed`, `customer_refunded`) can be switched off by the shop
owner (Settings › Emails) and replaced by a client Mailable whose constructor takes the `Order`:

```php
Commerce::orderEmail('customer_completed', \App\Mail\CompletedWithReviewInvite::class);
```

To restyle rather than replace, override the email views in the theme (`views/emails/orders/…`).

**Invoices.** The "order received" and "order completed" emails can carry the invoice PDF (Settings › Invoices). A
replacement Mailable that extends the core class keeps the attachment; one extending `OrderEmail` directly returns
its key from `invoiceEmailKey()`. The PDF templates are theme views: `views/pdf/invoice.blade.php` and
`views/pdf/packing-slip.blade.php` in the client theme replace the core ones – see [INVOICES.md](INVOICES.md).

## Views and view composers

- **Storefront**: views resolve through the theme chain (`themes/{active}` → parents → package `default`). Override
  a file by creating it at the same relative path in the client theme. A one-off override without touching the
  theme: `resources/views/{same path}` (checked first – keep it rare, `commerce:doctor` lists shadowed files).
- **Back office**: `commerce::admin.*` views can be overridden in `resources/views/vendor/commerce/admin/…` (standard
  Laravel package view override). Prefer adding a page over overriding one – overrides must be re-checked on every
  package upgrade.
- **Emails**: bare names (`emails.orders.customer-processing-order`, `emails.layouts.base`) resolve through the theme
  chain, so a theme restyles emails by shipping `views/emails/…`.
- **View composers per theme** – in the theme's `Theme.php`; they only run while that theme is in the active chain
  (a staff theme preview or an admin theme switch never leaks them), and a bare name also matches the `theme::` form
  core renders:

  ```php
  class Theme extends \Pine\Commerce\Theme\ThemeDefinition
  {
      public function composers(): array
      {
          return [
              'product.show' => fn ($view) => $view->with('deliveryPromise', setting('client.delivery_promise')),
              'partials.header, partials.footer' => \Themes\Acme\Composers\StoreDetails::class,   // class with compose(View $view)
          ];
      }
  }
  ```

  Client-wide composers (any theme) are ordinary `View::composer()` calls in `ClientServiceProvider::boot()`.

## Client routes

Add storefront routes in the client's `routes/web.php`. The storefront catch-all (pages/products/categories/redirects)
is a **fallback** route, so client routes always win without ordering tricks. Theme routes go in `Theme::routes()`.
Gate a client route by a feature switch with `RequireFeature::for('key')`.

```php
Route::get('trade-account', [\App\Http\Controllers\TradeAccountController::class, 'show'])->name('trade.show');
```

## Scheduled tasks

*Since 1.2.* A client job registered with `Commerce::scheduledTask()` is treated exactly like the core jobs
(`Pine\Commerce\Scheduling\Scheduler`): it is added to Laravel's scheduler (the one cron line
`* * * * * cd /path && php artisan schedule:run`), runs from the **web fallback** on sites without cron (while
`commerce.scheduler.web_fallback` is on), can be run by hand with `php artisan commerce:schedule:task {key}`, records
its last result (setting `scheduler.task.{key}`: when, ok/skipped/failed, summary, duration, cron/web/manual) and is
listed by `php artisan commerce:schedule:status` (marked `(client)`, `"source": "client"` in `--json`) and in
Admin › Settings › Scheduled tasks (badge "Custom").

```php
use Pine\Commerce\Commerce;

// in ClientServiceProvider::boot()
Commerce::scheduledTask('acme.erp-stock', [
    'label' => 'ERP stock sync',                           // shown in the status table and the admin screen
    'description' => 'Pulls stock levels from the ERP.',
    'schedule' => 'everyFifteenMinutes',                   // see "Schedules" below
    'call' => fn (\App\Erp\Client $erp) => $erp->syncStock().' products updated',   // the returned string is the summary
    'setting' => 'acme.erp_sync',                          // optional: skipped while this owner setting is off …
    'setting_default' => true,                             // … (value when it was never saved; default false)
]);

Commerce::scheduledTask('acme.feed', ['schedule' => 'dailyAt:04:15', 'command' => 'acme:export-feed --quiet']);
Commerce::scheduledTask('acme.reviews-digest', ['schedule' => 'weekdays|dailyAt:09:00', 'feature' => 'reviews',
    'task' => \App\Scheduling\ReviewsDigest::class]);   // extends Pine\Commerce\Scheduling\Task
```

| Option | |
|---|---|
| `schedule` (required) | a cron expression (`'*/15 * * * *'`), a Laravel frequency method (`'hourly'`, `'everyFifteenMinutes'`, `'daily'`), a method with arguments after `:` (`'dailyAt:02:30'`, `'weeklyOn:1,08:00'`, or `['dailyAt', '02:30']`), or several chained with `\|` (`'weekdays\|dailyAt:09:00'`). Times are in the store timezone (`commerce.store.timezone`). Sub-minute frequencies and `between()` are not accepted. |
| exactly one of `call` / `command` / `task` | `call`: a closure, `[Class::class, 'method']` or invokable class, called through the container (type-hint what you need); `command`: an artisan command line – a non-zero exit code counts as failed and the last output line becomes the summary; `task`: a class extending `Pine\Commerce\Scheduling\Task` (`skipReason()` + `handle(): string`, like the core tasks) |
| `label`, `description` | for the status table and Settings › Scheduled tasks (default: the key) |
| `feature` | a feature switch (`commerce.features.*`) – skipped while it is off |
| `setting`, `setting_default` | an owner setting that must be on – skipped while it is off (add the switch with `Commerce::settingsFields('automation', …)`) |
| `skip` | a callable returning a reason to skip this run (string) or `null` |

Rules:
- Keys: `a-z 0-9 . _ -`; prefix them with the client (`acme.…`). A core key is refused; registering the same key again
  replaces the earlier registration.
- Switch a task off without code changes: `'scheduler' => ['tasks' => ['acme.feed' => false]]` in
  `config/commerce.php` (like a core task). `commerce.scheduler.enabled` false switches off every task.
- Like the core tasks, a client task must be **safe to run late, twice or from the web fallback** (after a shop page
  has been sent) – keep it short, give HTTP calls a timeout, and do not depend on it for checkout. An exception never
  breaks the run: the task is recorded as failed with the message and logged.
- `Schedule::command(...)` in `routes/console.php` still works for jobs that need Laravel's full scheduling API
  (`between()`, `onOneServer()`, sub-minute), but those jobs have no web fallback and do not appear in the status.

## Updates

*Since 1.3.* Admin › Updates finds new pine/commerce releases and installs one only after an administrator approves
it (PLAYBOOK part 3.6). Nothing to register in client code; the `updater` block of `config/commerce.php` adapts it to a
server. A client config without the block gets the package defaults (top-level keys merge shallowly: copy the whole
block to change one key).

| `commerce.updater.*` | Default | |
|---|---|---|
| `repository` | `env('COMMERCE_UPDATER_REPOSITORY')` | where to look for releases; null = the `vcs`/`git` repository of `pine/commerce` in `composer.json` (a `path` repository = development checkout: the admin refuses to update) |
| `default_repository` | `https://github.com/SetWebUK/ecom-core.git` | when `composer.json` names no repository (installed from a registry) |
| `skeleton_repository` | `https://github.com/SetWebUK/ecom-skeleton.git` | skeleton file updates (a project's `.commerce-skeleton.json` may name another) |
| `github_token` | `env('COMMERCE_UPDATER_GITHUB_TOKEN')` | optional: private repositories / GitHub API rate limit (anonymous: 60 requests an hour; the checker falls back to `git ls-remote`) |
| `check` | `true` | the daily scheduled check (task `updates.check`; or switch the task off with `'scheduler' => ['tasks' => ['updates.check' => false]]`) |
| `php_binary` | `env('COMMERCE_PHP_BINARY')` | the PHP **CLI** for `php artisan …`; null = detected (a web request's `PHP_BINARY` is `lsphp`/`php-fpm`, so `PHP_BINDIR/php` and `php` in PATH are tried) |
| `composer_binary` | `env('COMMERCE_COMPOSER_BINARY')` | null = `composer` / `composer.phar` in PATH, `~/bin`, the project |
| `mysqldump_binary`, `git_binary` | null | null = found in PATH (`mysqldump` or `mariadb-dump`; `git`) |
| `home` | `env('COMMERCE_UPDATER_HOME')` | HOME for composer and git started from the web (null = `$HOME`, else the account's home directory) – composer's cache, auth.json and SSH keys live there |
| `composer_home` | `env('COMMERCE_COMPOSER_HOME')` | null = `$COMPOSER_HOME`, `~/.config/composer` (if it exists) or `~/.composer` |
| `path` | null | working directory (lock, run logs, skeleton checkouts); null = `storage/app/private/updater` |
| `backup` | `true` | database backup before every update (`mysqldump` + gzip, or a copy of the SQLite file); false only if you back up yourself |
| `backup_path` | null | null = `storage/app/private/updater/backups`; refused when under `public/` |
| `keep_backups` | `5` | newest N backups kept |
| `min_free_mb` | `1024` | free disk space needed to start |
| `timeout` | `900` | seconds per composer / mysqldump step |
| `http_timeout`, `git_timeout` | `10`, `120` | seconds per GitHub request / git command |
| `project_path` | null | the project root (tests only) |

Audit log: table `platform_updates` (model `Pine\Commerce\Models\PlatformUpdate`, additive migration
`2026_10_02_000100_create_platform_updates_table`). Process execution goes through the `Pine\Commerce\Updater\ProcessRunner`
interface (bound to `SystemProcessRunner`; tests bind `Tests\Fixtures\FakeProcessRunner`).

## Importer adapters and steps

Client-specific import logic lives in `app/Import/{Client}/…` ([IMPORTER.md](IMPORTER.md), NEW-CLIENT.md step 6):

```php
Commerce::importAdapter(\App\Import\Acme\AcmeCatalogAdapter::class);   // implements Import\Contracts\Adapter (detect(), capabilities, steps())
Commerce::importStep(\App\Import\Acme\LoyaltyPointsStep::class);       // implements Import\Contracts\Step – always runs, no detection
```

`config/commerce-import.php` `adapters.extra` still works (config entries come first). Steps of switched-off features
are skipped with a warning (`content.posts` without `blog`, `extras.reviews` without `reviews`, …); a client step can
declare its own switch with a `feature(): ?string` method. `commerce:import-wordpress --detect` lists every adapter
(core/client) and the step order.

## Client data

Core models are not swappable (except the user model: `App\Models\User extends Pine\Commerce\Models\User`). For
client data use **your own tables** (migrations in the client's `database/migrations`) and attach them with
`Model::resolveRelationUsing()`, observers or macros:

```php
use Pine\Commerce\Models\Product;

Product::resolveRelationUsing('tradePrices', fn (Product $p) => $p->hasMany(\App\Models\TradePrice::class));
```

Never add columns to core tables from a client migration – a later package migration could collide with them.

## What 1.1 added for client code

- **Images** – `media_url($path, $size)` (a size name from `commerce.images.sizes` or a pixel width; without `$size`
  unchanged), `image_srcset($path, $size, $format = null)`, `$media->sizeUrl()` / `->srcset()` / `->variants()`, the
  Blade component `<x-media-image :path="$path" size="card" sizes="(max-width: 600px) 50vw, 300px" />`. Client code
  that stores uploads itself calls `app(Pine\Commerce\Services\Media\ImageGenerator::class)` so the sizes exist
  ([THEMES.md](THEMES.md) "Images").
- **Tax and shipping** – shipping calculators receive `postcode` and `zone` (above); rates, zones and methods are
  data (Admin › Settings › Tax / Shipping), not code ([TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md)).
- **Invoices** – `OrderEmail::invoiceEmailKey()` lets a replacement order email carry the PDF; templates
  `pdf/invoice` and `pdf/packing-slip` are overridable in the theme or `resources/views/pdf/` ([INVOICES.md](INVOICES.md)).
- **Scheduled tasks** – the core registers its jobs with Laravel's scheduler; switch core jobs per key in
  `commerce.scheduler.tasks`. Since 1.2 a client registers its own with `Commerce::scheduledTask()`
  ([Scheduled tasks](#scheduled-tasks)).
- **Order access** – `Pine\Commerce\Services\Checkout\OrderAccess` decides who may see an order-received page or a
  guest invoice; reuse it in client routes that show an order.
- **Raw SQL** – `Pine\Commerce\Support\Sql::table()` / `col()` / `qualify()` keep queries working on a
  table-prefixed connection.
- **Importer** – `Import\Contracts\BreadcrumbTermProvider` (primary category of a product; the Rank Math adapter
  implements it); new steps `extras.tax` and zones in `extras.shipping`.
- **Product CSV** – `commerce:products:export` / `commerce:products:import` for scripted catalogue updates
  ([PRODUCT-CSV.md](PRODUCT-CSV.md)).

## Checklist for client code

- [ ] Lives in the client project (never in `vendor/`).
- [ ] Uses the extension API / config / theme – no copied package classes.
- [ ] Works with the queue on `sync` and without cron (core scheduled tasks: ARCHITECTURE.md §13.2).
- [ ] No admin/e-commerce framework packages; plain Blade + Alpine for UI.
- [ ] Client tables only – no columns added to core tables.
- [ ] Theme entry points of optional features wrapped in `commerce_feature()`.
- [ ] Covered by a test in the client's `tests/`, and `php artisan commerce:doctor` stays clean.
- [ ] Raw SQL (`selectRaw`, `whereRaw`, `DB::raw`, `DB::select`) names tables through `Pine\Commerce\Support\Sql`
      (`Sql::table('orders')`, `Sql::col('orders.total')`, `Sql::qualify($sql, ['orders'])`) – bare table names break on a
      connection with a table prefix (the package's own queries do this since 1.1; `TablePrefixTest` checks the back office).
