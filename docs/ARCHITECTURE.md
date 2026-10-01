# Pine Commerce – platform architecture (binding contract)

Status: **binding**. Every change to `pine/commerce` builds what is written here. If something here turns out to be
wrong or impossible, write down why in §18 “Open questions and implementation notes” of this file and decide it
explicitly – do not improvise a different design.

The platform has three layers:

1. **`pine/commerce`** – a client-agnostic core package (models, migrations, services, storefront + admin
   controllers, admin UI, routes, WordPress/WooCommerce importer, tooling);
2. **themes** – `themes/{slug}/` storefront Blade views + assets, selected per install;
3. **the client app** – one Laravel app per client: `.env`, config overrides, the active theme, a client extension
   provider and client-specific importer adapters.

The platform was extracted from a running WooCommerce rebuild (the first client project), which had to look and
behave **identically** after every step. That rule still applies to every client: a platform change never changes a
client's output unless the client opts in.

Hard constraints (they shape the design): no Filament / Livewire / Lunar / Nova / Backpack / Bagisto or any admin or
e-commerce framework package; plain Laravel + Blade + Alpine + hand-written CSS/JS; no Node on the server; LiteSpeed
does not follow symlinks out of `public/` so every published asset is a **copy**; queue is `sync`, and nothing depends
on cron (it is recommended since v1.1 – §13.2); a WordPress install sharing the web root is never touched.

---

## 1. Principles

- **Mechanical first, clever later.** Moves and renames are mechanical commits; behaviour changes (config-driven
  values, feature flags) are separate, small commits.
- **Client defaults live with the client.** Every client-specific value (copy, URLs, attribute slugs, cookie names,
  colours, legacy WordPress ids) lives in the client app config, the client's theme or the client importer adapters.
  The package default for each such value is neutral; a client sets its own value explicitly so its output stays
  byte-identical.
- **Existing identifiers are kept via config, not renamed.** Cookie names (`commerce.cart.cookie`, `open_cookie`,
  `checkout.attribution_cookie`), the catalogue AJAX header, contact form field names (`forms.contact.prefix`), CSS
  classes emitted by core (`content.legacy_class_prefix`, `ProductPresenter::cardPriceClass()`), asset URLs
  (`public_path`, `admin.assets_url`) are configurable, so a migrated store keeps the names its old site used. Changing
  any of them on a live site breaks carts, cached pages or sent emails.
- **Client databases are untouchable.** Table names, column names and migration file names never change; package
  migrations are additive only.
- **Themes never need controller changes.** Everything a theme can vary is either a view, theme config, a theme
  setting, or a hook in the theme's optional `Theme.php`.

---

## 2. Layers and ownership

| Layer | Location | Owns | Changes per client? |
|---|---|---|---|
| Core package | `vendor/pine/commerce` (`Pine\Commerce\`) | schema, models, services, storefront + admin controllers and routes, admin UI (views, components, assets), mail classes, importer framework + generic WooCommerce/WordPress steps + plugin adapters, artisan tooling, `default` theme | never (upgraded as a whole, §15) |
| Theme | `themes/{slug}` | storefront views (layout, partials, pages, emails), storefront CSS/JS/images/fonts, theme config (menu fallbacks, page-template block schemas + defaults, SEO copy defaults, body-class suffixes), optional `Theme.php` | yes (one per client, may extend a parent) |
| Client app | repo root (`App\`) | `.env`, `config/commerce.php` + `config/commerce-import.php` overrides, `App\Providers\ClientServiceProvider`, client domain helpers (e.g. `app/Acme/*`), client importer adapters (`app/Import/*`), client tests, `App\Models\User` (thin subclass) | yes |

Dependency rule: core → nothing client-specific. Theme → core (and, for a client-specific theme only, that client's
`App\` helpers). Client → core + theme. The `default` theme depends on core only.

---

## 3. Client project layout

```
acme-app/                                      (Laravel app root of one client – made from the client skeleton)
├── app/
│   ├── Import/Acme/                           (client importer adapters, namespace App\Import\Acme – §12.7)
│   ├── Acme/                                  (client domain helpers, e.g. a ProductPresenter subclass, a FacetSorter)
│   ├── Models/User.php                        (class User extends \Pine\Commerce\Models\User – auth config + factory keep working)
│   └── Providers/
│       ├── AppServiceProvider.php             (DB::prohibitDestructiveCommands() guard)
│       └── ClientServiceProvider.php          (client extension point, §11)
├── bootstrap/app.php                          (delegates middleware/exceptions to Pine\Commerce\Commerce, §4.3)
├── config/
│   ├── commerce.php                           (client overrides of package config)
│   └── commerce-import.php                    (client importer config: source, mappings, client adapters)
├── database/migrations/                       (Laravel base migrations + client-only tables; store schema comes from the package)
├── public/
│   ├── themes/{slug}/                         (PUBLISHED COPY of a theme's assets – or the theme's public_path, §7.6)
│   ├── vendor/commerce/admin/                 (PUBLISHED COPY of the package's resources/assets/admin)
│   ├── images/placeholder.png                 (published by commerce:install / commerce:publish)
│   └── storage/                               (REAL directory – public disk root; never a symlink)
├── resources/views/                           (normally empty: client one-off overrides; vendor/commerce/admin/* overrides allowed)
├── routes/{web.php, console.php}              (client-only routes – the storefront comes from the package)
├── tests/                                     (client tests)
├── themes/acme/                               (theme.json, Theme.php, config/, views/, assets/ – §7)
└── vendor/pine/commerce/                      (the package, installed by composer from its VCS repository)
```

The package itself (repository `ecom-core`):

```
pine/commerce
├── composer.json  VERSION  CHANGELOG.md  LICENSE  phpunit.xml.dist  README.md
├── bin/export-client-skeleton.sh             (renders stubs/client-skeleton into the skeleton repository)
├── config/{commerce.php, commerce-import.php}
├── database/migrations/                       (store schema, additive only)
├── docs/                                      (this file, PLAYBOOK, THEMES, IMPORTER, EXTENDING, UPGRADING, ADMIN_UI …)
├── resources/
│   ├── assets/admin/                          (source of public/vendor/commerce/admin: css/ js/ img/ vendor/)
│   ├── assets/core/images/placeholder.png
│   ├── themes/default/                        (complete neutral theme – implements the full contract)
│   └── views/{admin, components/admin}        (commerce::admin.*, <x-admin.*> anonymous components)
├── routes/{storefront.php, admin.php, admin/*.php}
├── src/                                       (Pine\Commerce\…: Commerce.php, CommerceServiceProvider.php, helpers.php,
│                                               Console, Http, Models, Services, Theme, Import, Scheduling, Support, View …)
├── stubs/client-skeleton/                     (new client project template, commerce:new-client)
└── tests/                                     (Pine\Commerce\Tests – composer test, §13.1)
```

---

## 4. Package wiring

### 4.1 Composer

Client `composer.json` (VCS mode – every committed client):

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/SetWebUK/ecom-core.git" }],
"require": { "pine/commerce": "^1.2" }
```

For local development of the package together with a client, a **path** repository may point at a checkout of the
package (`{ "type": "path", "url": "../ecom-core", "options": { "symlink": true } }`, `"pine/commerce": "*@dev"`) –
never committed (PLAYBOOK Part 3.1). The vendor symlink lives in `vendor/`, which is never served, so LiteSpeed's
symlink rule does not apply. Package `composer.json` (abridged):

```json
{
  "name": "pine/commerce",
  "type": "library",
  "require": { "php": "^8.3", "laravel/framework": "^13.17", "stripe/stripe-php": "^21.3", "dompdf/dompdf": "^3.1" },
  "autoload": { "psr-4": { "Pine\\Commerce\\": "src/" }, "files": ["src/helpers.php"] },
  "autoload-dev": { "psr-4": { "Pine\\Commerce\\Tests\\": "tests/" } },
  "extra": { "laravel": { "providers": ["Pine\\Commerce\\CommerceServiceProvider"] } }
}
```

Clients update with `composer update pine/commerce` (never a blanket `composer update` on a live site).

### 4.2 `CommerceServiceProvider` responsibilities

`register()`:
- `mergeConfigFrom` `config/commerce.php` → `commerce`, `config/commerce-import.php` → `commerce-import`.
- singletons: `Services\Cart`, `Theme\ThemeManager`, `Services\Payments\PaymentManager`, `Import\AdapterRegistry`.
- swap the `url` singleton for `Support\SlashUrlGenerator` when `commerce.urls.trailing_slash` is true (moved verbatim
  from `AppServiceProvider::register()`).
- `ThemeManager::registerViewPaths()` – see §7.3 (must happen in `register()` so the exception handler's error-view
  paths see it).
- `LegacyAliases::register()` when `commerce.legacy_aliases` is true (§5.4).

`boot()`:
- `loadMigrationsFrom(__DIR__.'/../database/migrations')`.
- `loadViewsFrom(__DIR__.'/../resources/views', 'commerce')` (overridable in `resources/views/vendor/commerce/…`).
- `Blade::anonymousComponentPath(__DIR__.'/../resources/views/components')` **without prefix**, so `<x-admin.button>`
  keeps resolving `components/admin/button.blade.php`. Class components registered explicitly:
  `Blade::component('admin.sidebar', Sidebar::class)`, `'admin.icon-set'` (IconSet) and the storefront
  `mega-menu`, `footer-menu`, `mobile-menu`, `page-content`, `seo` components (same tag names as today).
- routes (§4.4), events (§4.5), `Paginator::defaultView('partials.pagination')`, `URL::forceScheme('https')` when
  `APP_URL` is https, `EncryptCookies::except([config cart cookie, attribution cookie, open-cart cookie])`,
  push `HandleAddToCartQuery` to the `web` group when `commerce.features.add_to_cart_query`.
- register `Providers\StoreMailServiceProvider` (moved as-is).
- scheduled tasks (§13.2): `callAfterResolving(Schedule::class, Scheduler::register(...))`, and on web requests with
  `commerce.scheduler.web_fallback` a terminating callback `Scheduler::webFallback()`.
- commands (§13); `$this->optimizes(optimize: 'commerce:theme:cache', clear: 'commerce:theme:clear')` so
  `php artisan optimize` / `optimize:clear` cover the theme manifest.
- publishable groups: `commerce-config`, `commerce-admin-assets`, `commerce-theme-default` (used by the commands; they
  copy – never `vendor:publish` with symlinks).
- the active theme's `ThemeDefinition::boot()` (§7.5).

### 4.3 `bootstrap/app.php`

Only the routing block and two delegations stay in the app:

```php
->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
->withMiddleware(fn (Middleware $m) => \Pine\Commerce\Commerce::middleware($m))
->withExceptions(fn (Exceptions $e) => \Pine\Commerce\Commerce::exceptions($e))
```

`Commerce::middleware()` contains today's body verbatim: prepend `TrailingSlash` to `web` (when
`commerce.urls.trailing_slash`), `validateCsrfTokens(except: ['webhooks/*'])`, `redirectGuestsTo(route('account'))`,
append `SecurityHeaders`, alias `admin` → `EnsureStaff`, `prependToPriorityList(SubstituteBindings, EnsureStaff)`.
`Commerce::exceptions()` contains today's JSON rule, `dontFlash` list and the admin expired-session renderer. A client
adds its own middleware after the call (`fn ($m) => tap($m, fn ($m) => Commerce::middleware($m))->append(...)`).

### 4.4 Routes

- `routes/storefront.php` (ex `routes/web.php`, unchanged content, controller imports re-pointed) is loaded by the
  provider inside `Route::middleware('web')`, when `commerce.routes.storefront` is true.
- `routes/admin.php` + `routes/admin/*.php` are loaded with `middleware('web')->prefix(config('commerce.admin.path',
  'admin'))->name('admin.')`, when `commerce.routes.admin` is true.
- Optional features switch their routes off: `blog` (blog.*, feed), `wishlist` (wishlist.toggle, account.wishlist),
  `reviews` (product.review), `stock_alerts` (product.notify), `newsletter`, `contact_form`, `order_tracking`,
  `quick_view`, `google_feed`. Route **names never change**.
- **Catch-all stays last – guaranteed by the router, not by load order.** The resolve route is declared as
  ```php
  Route::get('{path}', [ResolveController::class, 'resolve'])
      ->where('path', '^(?!admin|storage|css|js|build|vendor|themes).*$')->name('resolve')->fallback();
  ```
  `->fallback()` marks it `isFallback`, and `RouteCollection` always matches fallback routes after every normal route,
  whatever file or provider registered them first (package routes register before the app's `routes/web.php`; without
  the fallback flag the catch-all would swallow client routes). The admin's own `Route::fallback()` (prefix `admin/`)
  and this one never overlap because of the `(?!admin…)` guard. The guard list adds `vendor|themes` (published asset
  dirs); if `commerce.admin.path` is changed, the guard uses that value instead of `admin`. Theme routes
  (`ThemeDefinition::routes()`) and client routes need no ordering care.
- Webhook route stays `webhooks/{gateway}` (CSRF-exempt).

### 4.5 Events

Event discovery currently finds `app/Listeners/*` (see `bootstrap/cache/events.php`). After the move the provider
registers exactly today's map explicitly:
`OrderPlaced` → `SaveCheckoutCustomer@handle`; `OrderStatusChanged` → (since 1.1 `AssignInvoiceNumber@handle`,) `SendOrderStatusEmails@handle`,
`SyncOrderStock@handle` (in that order). Run `php artisan event:list` before and after; the output must list the same
listeners (namespaces changed only). Client listeners are registered by the client provider as usual.

---

## 5. Namespaces

Everything in the package lives under `Pine\Commerce\` (`src/`), mirroring a Laravel app: `Console`, `Events`,
`Exports`, `Http\{Controllers,Controllers\Admin,Controllers\Auth,Middleware,Requests}`, `Import`, `Listeners`, `Mail`,
`Models`, `Notifications`, `Providers`, `Scheduling`, `Services\{Admin,Catalog,Checkout,Payments,…}`, `Support`, `Theme`,
`View\Components`. A client keeps only its own code in `App\`:

- `App\Models\User` is a thin subclass (`class User extends \Pine\Commerce\Models\User {}`), so `config/auth.php`,
  `UserFactory`, seeders and tests keep working. Core relations to users use `Commerce::userModel()`
  (= `config('auth.providers.users.model')`).
- `App\Providers\ClientServiceProvider` (extension API, §11), client domain helpers, client importer adapters,
  `Database\Factories\UserFactory`, `Database\Seeders\DatabaseSeeder`, `Tests\*`.

### 5.4 Transitional aliases (`Pine\Commerce\Support\LegacyAliases`)

For a client migrating from an app that had these classes under `App\`, `LegacyAliases::register()` can push an
**append** autoloader (after Composer's) that maps an old `App\…` class name whose file no longer exists to its
`Pine\Commerce\…` class with `class_alias()` (an alias is the same class, so `instanceof`, route-model binding and
`::class` comparisons keep working). Enabled by `commerce.legacy_aliases` (default `false`; see §18.4 – the first
client did not need it).

---

## 6. Views outside the theme (admin, components, emails)

| Location | Referenced as |
|---|---|
| package `resources/views/admin/**` | `commerce::admin.*` everywhere (controllers, `@extends`, `@include`, `->links('commerce::admin.partials.pagination')`, `view('commerce::admin.partials.sidebar')`); route names stay `admin.*` |
| package `resources/views/components/admin/*.blade.php` | `<x-admin.*>` (anonymous path without prefix, §4.2) |
| package `resources/views/admin/emails/{back-in-stock,customer-invoice,customer-invoice-plain}` | `commerce::admin.emails.*`; they `@extends('emails.layouts.base')` and include `emails.partials.*` – those are **theme** views (theme contract §8, emails), resolved bare through the view-path chain |
| package `resources/assets/admin/**`, published by copy to `public/vendor/commerce/admin/**` | `commerce_admin_asset('js/admin.js')` → `asset(config('commerce.admin.assets_url').'/js/admin.js').'?v='.filemtime`. `commerce.admin.assets_url` default `vendor/commerce/admin` (a client may keep an older URL such as `admin-assets`). JS that needs the asset base (icons sprite, TinyMCE base in `admin.js`) reads `window.Admin.assetBase`, set from `<meta name="admin-asset-base">` in `commerce::admin.layouts.app`. |
| admin branding | `commerce.admin.brand.{name,logo,logo_light,mark,favicon}` (paths relative to `public/`; defaults point at the published neutral images; a client points at its own copies, e.g. `public/brand/admin/`) – admin layouts/topbar/auth use the config, never a literal path |

Editor preview CSS (`Admin\PageController::editorCss()`, `Admin\PostController` `contentCss`, `<x-admin.rich-editor
content-css>` default) = `theme()->editorCss()` = the theme.json `editor_css` list turned into `theme_asset()` URLs,
plus the legacy `css/elementor/post-{wp_id}.css` when `features.legacy_content` and the file exists.

---

## 7. Theme system

### 7.1 `theme.json`

```json
{
  "name": "Acme",
  "slug": "acme",
  "parent": "default",
  "version": "1.0.0",
  "requires": "pine/commerce ^1.0",
  "public_path": "themes/acme",
  "class": "Themes\\Acme\\Theme",
  "autoload": { "Themes\\Acme\\": "src/" },
  "supports": ["side-cart", "ajax-catalog", "quick-view", "mega-menu", "mobile-menu", "wishlist", "reviews",
               "stock-alerts", "newsletter", "cookie-banner", "blog", "order-tracking", "contact-form", "emails"],
  "editor_css": ["css/site.css"],
  "settings": {
    "header.show_top_bar": { "type": "bool", "default": true, "label": "Show the top bar" }
  }
}
```

| Key | Required | Meaning |
|---|---|---|
| `name`, `slug`, `version` | yes | `slug` = directory name, `[a-z0-9-]+` |
| `parent` | no | parent theme slug; chain resolves recursively, cycles are an error; `default` is always appended last even if omitted |
| `requires` | no | semver constraint on the package version; `commerce:doctor` warns on mismatch |
| `public_path` | no | where `commerce:theme:publish` copies `assets/` to, relative to `public/`. Default `themes/{slug}`. A like-for-like client may use its old asset directory (e.g. `assets`) so every existing URL (`/assets/css/site.css`, logo URLs in sent emails, imported `/assets/css/elementor/post-*.css`) is unchanged. Forbidden values: `admin-assets`, `vendor`, `storage`, `build`. |
| `class` | no | FQCN of a `Pine\Commerce\Theme\ThemeDefinition` subclass in `themes/{slug}/Theme.php` (required once by the manager) |
| `autoload` | no | PSR-4 map for `themes/{slug}/src`, registered at runtime by the manager (no composer change) |
| `supports` | yes | feature keys the theme implements (§7.8); `commerce:theme:check` validates the matching optional views exist |
| `editor_css` | no | theme asset paths loaded inside the admin rich-text editors |
| `settings` | no | theme settings schema (`type` text/textarea/bool/int/image/link/color/select, `default`, `label`, `help`, `options`); rendered on a “Theme” card under Admin › Settings › Store details; stored as `settings` rows `theme.{slug}.{key}` |

### 7.2 Active theme

`config('commerce.theme')` ← `env('COMMERCE_THEME', 'default')`, e.g. `COMMERCE_THEME=acme` in the client's `.env`.
An unknown slug is a boot-time exception in `local`, and a logged error + fallback to `default` in production
(`commerce:doctor` reports it).

### 7.3 View resolution

`ThemeManager::registerViewPaths()` (provider `register()`):

```
config('view.paths') = [ resource_path('views'),           // client one-off overrides (normally empty)
                         base_path('themes/{active}/views'),
                         base_path('themes/{parent}/views'), …,
                         vendor/pine/commerce/resources/themes/default/views ]
View namespace 'theme' = the same list minus resource_path('views')
```

Consequences (all intended):
- Controllers render `theme::…` names (§8). Inside themes, bare names (`@extends('layouts.app')`,
  `@include('partials.header')`, `emails.orders.*`) resolve through the same chain, so the existing views move
  **without edits**.
- Laravel's error pages (`errors::404`, built from `config('view.paths')` at render time) pick up the theme's
  `errors/404.blade.php`, `errors/500.blade.php`.
- Mailables keep bare names (`emails.orders.customer-processing-order`) → themes can restyle emails; the default
  theme ships a complete neutral set.
- A child theme overrides a single file by creating it at the same relative path.

`ThemeManager` API: `active(): Theme`, `chain(): Theme[]`, `get(string $slug): Theme`, `all(): Theme[]`,
`find(string $view): ?string` (debug). `Theme` value object: `slug`, `name`, `version`, `path`, `viewsPath()`,
`assetsPath()`, `publicPath()`, `supports(string $feature): bool`, `config(string $key, $default = null)`,
`definition(): ?ThemeDefinition`, `editorCss(): array`, `settingsSchema(): array`.

The manifest (merged theme.json files + config files of the chain) is cached in `bootstrap/cache/commerce-themes.php`
by `commerce:theme:cache` (hooked into `php artisan optimize`) and deleted by `commerce:theme:clear`
(`optimize:clear`). Without a cache the JSON is read per request (cheap).

### 7.4 Theme helpers (in `src/helpers.php` unless noted)

| Helper | Returns |
|---|---|
| `theme(): Theme` | active theme |
| `theme_asset(string $path): string` | `asset({publicPath}/{path}).'?v='.filemtime(public file)`; walks the chain and uses the first theme whose **published** file exists; missing everywhere → URL of the active theme without `?v` + a `Log::warning` once per request. |
| `theme_config(string $key, $default = null)` | value from `themes/*/config/{file}.php` (`'menus.mega'` = key `mega` of `config/menus.php`), child overriding parent per top-level key |
| `theme_setting(string $key, $default = null)` | `setting('theme.{slug}.'.$key)` ?? theme.json default ?? `$default` |
| `@themeSetting('key', 'default')` | Blade directive → `{{ theme_setting(...) }}` (escaped) |
| `@themeAsset('css/site.css')` | Blade directive → `{{ theme_asset(...) }}` |
| `menu_tree(string|array $locations, array $fallback = []): array` | normalised menu tree (`MenuComponent::load()` logic, cached per menu stamp): `[{label, url, badge, icon, class, new_tab, children}]` |
| `commerce_presenter(): string` | class-string of the configured product presenter (`commerce.catalog.presenter`) |
| `money($amount, bool $symbol = true)` | unchanged signature; symbol/decimals/separators from `commerce.currency` |
| `setting()`, `media_url()` | unchanged; `media_url(null)` → `asset(config('commerce.media.placeholder', 'images/placeholder.png'))` |

### 7.5 `ThemeDefinition` (optional `themes/{slug}/Theme.php`)

```php
namespace Pine\Commerce\Theme;

abstract class ThemeDefinition
{
    public function __construct(protected Theme $theme) {}

    /** Container bindings (runs in the package provider's register phase, after theme paths are set). */
    public function register(\Illuminate\Contracts\Foundation\Application $app): void {}

    /** View composers, Blade directives, shortcodes (Commerce::shortcode), presenters, menu locations. */
    public function boot(): void {}

    /** Extra storefront routes; loaded inside Route::middleware('web'). Never needs ordering care (catch-all is a fallback). */
    public function routes(): void {}

    /**
     * Body classes for a core-rendered storefront view. $key is the contract key from §8 (e.g. 'catalog.category');
     * $classes is what core computed. Called BEFORE core appends ' paged paged-N'.
     */
    public function bodyClass(string $key, string $classes, array $data): string { return $classes; }
}
```

A client theme's `bodyClass()` appends what its old site printed, e.g. a page-builder template class:

| key | core computes | a client theme may append |
|---|---|---|
| `catalog.shop` | `archive post-type-archive post-type-archive-product woocommerce-shop woocommerce woocommerce-page woocommerce-no-js` | ` elementor-page-{id} elementor-template-full-width` |
| `catalog.category` | `archive tax-product_cat term-{slug} term-{wp_id ?: id} woocommerce woocommerce-page woocommerce-no-js` | (same) |
| `catalog.search` | `archive search search-results post-type-archive post-type-archive-product woocommerce-shop woocommerce woocommerce-page woocommerce-no-js` | (same) |
| `product.show` | `wp-singular product-template-default single single-product postid-{id} woocommerce woocommerce-page woocommerce-no-js` | ` elementor-template-full-width elementor-page-{id}` |

Page body classes (`page-template-default page page-id-… elementor-page …`) stay computed by core `PageController`
because they depend on the imported content (legacy_content feature). A theme's `boot()` may also register shortcode
aliases for the old site's shortcode names (e.g. `[acme_contact_form]` → `contact_form`, §11).

### 7.6 Assets and publishing

- Source: `themes/{slug}/assets/**` (css, js, images, fonts, vendor libs such as Alpine/Swiper).
- `php artisan commerce:theme:publish [slug] [--all]` copies (PHP `File::copyDirectory` semantics, never symlinks) the
  chain's assets to each theme's `public_path`, parents first, deleting files in the target that no longer exist in
  the source **only** when `--prune` is passed. It writes `public/{public_path}/.commerce-theme` (slug + version +
  source hash) and refuses to publish into a directory holding a different theme's marker.
- Published copies are build output: `public/themes/`, `public/vendor/commerce/` (and a custom `public_path`) are
  git-ignored.
- Every deploy (and `commerce:install`) runs `commerce:publish` + `commerce:theme:publish`.

### 7.7 Theme config files

| File | Keys | Used by |
|---|---|---|
| `config/home.php` | `defaults` (home page blocks shown until the owner saves the home page), `best_sellers.legacy_wp_ids` (optional fixed list of imported product ids), `seo.description` | `HomeController` |
| `config/blocks.php` | `templates` (extra/overridden page templates: key → `{label, help, view?}`), `schemas` (template key → block schema in `PageBlocks` notation, incl. `home`) | `PageBlocks`, the admin page builder |
| `config/menus.php` | `fallbacks.mega`, `fallbacks.mobile_nav`, `fallbacks.footer_shop`, `fallbacks.footer_company`, `fallbacks.footer_legal`, …; `locations` (location key → admin label) | `MegaMenu`, `MobileMenu`, `FooterMenu`, the admin menu screen |
| `config/product.php` | `condition_descriptions`, `brand_logos`, `notify_anchor`, client copy blocks used by a client presenter | `ProductPresenter` (and subclasses) |
| `config/seo.php` | `defaults.shop_description`, `defaults.blog_description`, `defaults.blog_category_description` (pattern with `:category` and `:site`) | Catalog/Blog controllers |

Core reads these through `theme_config()` with neutral fallbacks (empty menus, no home defaults, the store's
`seo.default_description` setting).

### 7.8 Theme feature keys (`supports`)

`side-cart` (needs `cart.side-cart`), `ajax-catalog` (needs `catalog.partials.results` + `partials.filters`; core only
answers the JSON fragment request when supported), `quick-view` (`product.quick-view`), `mega-menu`
(`partials.mega-menu`), `mobile-menu` (`partials.mobile-menu`), `wishlist` (`account.wishlist`), `reviews`,
`stock-alerts`, `newsletter`, `cookie-banner`, `blog` (`blog.*`), `order-tracking` (`partials.order-tracking`),
`contact-form` (`partials.contact-form`), `emails` (theme ships its own `emails/*`; otherwise the default theme's are
used through the chain). A feature switched on in `commerce.features` but not supported by the theme is reported by
`commerce:doctor`.

---

## 8. Theme contract (views rendered by core)

Controllers render the **first existing** name of each row via `View::first([...])` with the `theme::` prefix
(`theme::catalog.category`, then `theme::catalog.archive`). A theme must provide at least one name per required row;
the default theme provides all. “Base data” below is merged into every storefront view by a core view composer on
`theme::*`: `$store` (array: name, phone, email, address — from settings), `$cartCount` (int, lazily computed),
`$theme` (Theme). Types: `Paginator` = `Illuminate\Pagination\LengthAwarePaginator`, models are
`Pine\Commerce\Models\*`, `Seo` = `array{title:string, description?:?string, canonical?:string, type?:string,
image?:?string, noindex?:bool, robots?:?string, modified_time?:?string, published_time?:?string}` (rendered by the
theme's own `partials.seo`).

### 8.1 Pages rendered by controllers

| Key | View names (first found) | Rendered by | Variables |
|---|---|---|---|
| `home` | `home`, `pages.home` | `HomeController@index` | `page: ?Page`, `b: array` (home blocks = stored `page.blocks` merged over `theme_config('home.defaults')`, lists replaced whole), `bestSellers: Collection<Product>` (eager: images, primaryCategory, categories), `seo: Seo` |
| `page` | `pages.{template}`, `pages.default` | `PageController@show` | `page: Page`, `contentHtml: string` (processed, trusted HTML), `isElementor: bool`, `showTitle: bool`, `pageCss: ?string` (URL), `seo: Seo`, `bodyClass: string`; template `faq` adds `faqs: list<array{question:string, answer:string(html)}>`; template `contact` adds `hasForm: bool`. Core templates: `default`, `full-width`, `contact`, `faq` (+ `home`/`blog` delegate). A theme template from `config/blocks.php` renders `pages.{key}` with the same variables + `blocks: array` |
| `catalog.shop` | `catalog.shop`, `catalog.archive` | `CatalogController@shop` | **Archive data** (below) with `context = 'shop'` |
| `catalog.category` | `catalog.category`, `catalog.archive` | `CatalogController@category` | Archive data, `context = 'category'`, `category: Category` |
| `catalog.search` | `search.results`, `catalog.archive` | `SearchController@index` | Archive data, `context = 'search'`, `searchTerm: string` |
| `catalog.results` (AJAX, `ajax-catalog`) | `catalog.partials.results` | `CatalogController@render` | Archive data |
| `catalog.filters` (AJAX, `ajax-catalog`) | `partials.filters` | `CatalogController@render` | Archive data |
| `product.show` | `product.show` | `ProductController@show` | `product: Product` (loaded: images, primaryCategory, categories, attributeValues.attribute, specs, variations, productAttributes.attribute, approved reviews), `category: ?Category`, `crumbs: list<array{label:string,url:string}>`, `variationData: list<array>` (WooCommerce `data-product_variations` shape), `variationAttributes: list<array{slug,name,options:list<array{slug,label}>}>`, `related: Collection<Product>`, `reviews: Collection<ProductReview>`, `bodyClass: string`, `schema: array` (JSON-LD), `seo: Seo` |
| `product.quick-view` (`quick-view`) | `product.quick-view` | `ProductController@quickView` | `product: Product` |
| `blog.index` (`blog`) | `blog.index` | `BlogController@index` | `blogPage: ?Page`, `contentHtml: ?string` (set when the blog page has content with the blog-index shortcode), `posts: ?Paginator<Post>` (absent when `contentHtml` is set), `pageNumber: int`, `seo: Seo` |
| `blog.category` | `blog.category` | `BlogController@category` | `category: PostCategory`, `posts: Paginator<Post>`, `pageNumber: int`, `seo: Seo` |
| `blog.show` | `blog.show` | `BlogController@show` | `post: Post`, `contentHtml: string`, `previous: ?Post`, `next: ?Post`, `related: Collection<Post>`, `seo: Seo` |
| `blog.grid` | `blog.partials.grid` | `BlogController::renderIndexGrid()` (blog-index shortcode) | `posts: Paginator<Post>`, `featureFirst: bool` |
| `cart.side-cart` (`side-cart`) | `cart.side-cart` | `CheckoutFragments::cart()` | `cart: Services\Cart`, `totals: array` (Cart::totals()), `notices: list<string>`, `error: ?string` |
| `checkout.show` | `checkout.show` | `CheckoutController@show` | `cart: Cart`, `totals: array`, `gateways: array<string,Gateway>`, `values: array<string,scalar>` (prefill + old input), `termsUrl: ?string`, `privacyUrl: string`, `notices: list<string>`, `error: ?string`, `stripe: ?array` (publishable key, amount, currency, client config), `user: ?User`, `noGatewaysMessage: string` |
| `checkout.summary` | `checkout.partials.summary` | `CheckoutFragments::checkout()` | `cart: Cart`, `totals: array` |
| `checkout.shipping-methods` | `checkout.partials.shipping-methods` | `CheckoutFragments::checkout()` | `totals: array` |
| `checkout.card-icons` | `checkout.partials.card-icons` | `StripeGateway::icons()` | – |
| `checkout.thankyou` | `checkout.thankyou` | `CheckoutController@thankYou` | `order: Order` (loaded items.product.images, items.variation, customer notes), `bacs: ?BacsGateway`, `error: ?string`, `canPay: bool` |
| `checkout.pay` | `checkout.pay` | `CheckoutController@pay` | `order: Order`, `gateways: array<string,Gateway>`, `problem: ?string`, `error: ?string`, `termsUrl: ?string`, `stripe: ?array`, `noGatewaysMessage: string` |
| `checkout.verify-email` (1.1) | `checkout.verify-email` | `CheckoutController@thankYou` when the viewer may not see the order yet | `order: Order` (show its number only), `action: string` (POST URL), `key: string` (hidden field), `error: ?string`; the form posts `key` + `email` |
| `auth.login` | `auth.login` | `AccountController@dashboard` (guest) | `registration: bool`, `redirect: string` |
| `auth.lost-password` | `auth.lost-password` | `AuthController@showForgot` | `sent: bool` |
| `auth.reset-password` | `auth.reset-password` | `AuthController@showReset` | `token: string`, `email: string` |
| `account.logout-confirm` | `account.logout-confirm` | `AuthController@logout` (GET without token) | – |
| `account.*` | `account.{dashboard,orders,view-order,invalid-order,addresses,edit-address,edit-account,wishlist,downloads}` | `AccountController` | always: `endpoint: string` (WooCommerce endpoint slug), `user: User`, `message: ?string`; plus `orders: Paginator<Order>` (withCount items, withSum quantity) · `order: Order` · `addresses: Collection<string,Address>` keyed billing/shipping · `type: 'billing'|'shipping'`, `address: ?Address`, `values: array` · `items: Collection<WishlistItem>` (product.images, product.primaryCategory) |
| `errors.404` / `errors.500` | `errors.404`, `errors.500` | framework error handler | Laravel's `$exception`; the theme may call `Page`/`PageContent` itself (e.g. to show an imported 404 page, `content.404_source`) |
| `errors.410` (1.1) | `errors.410`, else `errors.404` | `ResolveController` (redirect rule of type 410) | `exception: HttpException(410)`; status 410 |

**Archive data** (`CatalogController::render`): `context: 'shop'|'category'|'search'`, `category?: Category`,
`baseUrl: string`, `heading: string`, `title: string`, `description: ?string`, `bodyClass: string`,
`breadcrumbs: list<array{label:string, url?:string}>`, `noindex: bool`, `canonical?: string`, `searchTerm: ?string`,
`seoContent: ?string` (html), `listing: ProductListing`, `products: Paginator<Product>`, `facets: array` (Facets::build),
`activeFilters: array`, `sorts: array<string,string>`, `pageNumber: int`, `formAction: string`, `prevUrl: ?string`,
`nextUrl: ?string`, `seo: Seo`. (`pagedTitle` is a closure used by core only; themes must not rely on it.)

### 8.2 Partials rendered by core components/services

| Key | View | Rendered by | Variables |
|---|---|---|---|
| `partials.mega-menu` (`mega-menu`) | `partials.mega-menu` | `<x-mega-menu>` | component public: `items: array` (menu_tree `['mega','main']`, fallback `theme_config('menus.fallbacks.mega')`), static `MegaMenu::panel($item)` |
| `partials.mobile-menu` | `partials.mobile-menu` | `<x-mobile-menu>` | `items: array` |
| `partials.footer-menu` | `partials.footer-menu` | `<x-footer-menu location variant>` | `items: array`, `location: string`, `variant: string` |
| `partials.page-content` | `partials.page-content` | `<x-page-content>` | component: `html`, `page`, `post`, `context` |
| `partials.contact-form` (`contact-form`) | `partials.contact-form` | shortcode `contact_form` | – (reads `old()`, `$errors->contact`, `session('contact_status')`) |
| `partials.html-sitemap` | `partials.html-sitemap` | shortcode `sitemap` | – |
| `partials.order-tracking` (`order-tracking`) | `partials.order-tracking` | shortcode `order_tracking` | – (`session('tracked_order_id')`, `$errors->tracking`) |
| `partials.pagination` | `partials.pagination` | default paginator view | Laravel paginator view data |
| `partials.product-card` | `partials.product-card` | theme-internal; core never renders it | convention: `product: Product` |

### 8.3 Emails (resolved bare through the chain; the default theme provides all)

`emails.layouts.base` (sections: `heading`, `content`; var `storeName`), `emails.partials.order-details` (`order`),
`emails.partials.addresses` (`order`), `emails.orders.{admin-new-order, admin-cancelled-order, admin-failed-order,
customer-processing-order, customer-on-hold-order, customer-completed-order, customer-refunded-order, customer-note}`
(`order: Order`, `heading: string`, `storeName: string` + per-mail extras: note text for customer-note, refund for
refunded, tracking for completed), `emails.account.new-account` (`user, heading, storeName`),
`emails.account.reset-password` (`user, url, minutes, heading, storeName`), `emails.contact-submitted` (**text**
view; `submission: FormSubmission`).

### 8.4 Assets and JS the core relies on

Core controllers answer a few requests the theme's JS makes; names are config so a client keeps its old ones:
`commerce.catalog.ajax_header` (default `X-Commerce-Catalog`), `commerce.cart.cookie` (`commerce_cart`),
`commerce.cart.open_cookie` (`commerce_open_cart`), `commerce.checkout.attribution_cookie` (`commerce_attr`),
`commerce.forms.contact.prefix` (`cf_`, fields `{prefix}name|email|phone|subject|message`, honeypot `{prefix}company`),
`commerce.catalog.legacy_page_params` (page-builder pagination parameters of the old site). Classes emitted inside
core-generated HTML (theme CSS must style them) use `commerce.content.legacy_class_prefix` (default `wp-`:
`{p}link` (NoteFormatter), `{p}fa` (legacy Font-Awesome SVG), `{p}legacy-toggle*` (WPBakery toggles)), the PayPal icon
partial `checkout.partials.paypal-icon`, and WooCommerce price markup `woocommerce-Price-amount amount` / `<bdi>`
(ProductPresenter::amount).

---

## 9. Client-specific logic: where it goes

Anything a single client needs lives in the client app, the client's theme or behind a flag. “Flag” =
`commerce.features.*` (§9.2). A client's `config/commerce.php` sets every value it needs so its output is unchanged
by platform releases.

### 9.1 Destinations

| Kind of client-specific content | Destination |
|---|---|
| home copy, best-seller lists, page-builder body classes, SEO description copy | theme config (`config/home.php`, `config/seo.php`), `Theme::bodyClass()` |
| navigation that used to be hard-coded | theme `config/menus.php` (fallbacks) or imported menus |
| cookie / header / form field / CSS class names, currency, countries, timezone, carriers, order reference prefix, legacy hosts, catalogue filters, settings defaults | `config/commerce.php` (§10.1) |
| condition/grade logic, spec formatting, client copy blocks | a `ProductPresenter` subclass (§9.3) + theme config `product.*` |
| filter option ordering | a `FacetSorter` class (`commerce.catalog.facet_sorter`) |
| old shortcode names | `Commerce::shortcodeAlias()` / `content.shortcode_aliases` |
| storefront views and assets | the client theme |
| admin branding | `commerce.admin.brand.*` + client-owned images |
| import rules the generic importer does not know | `config/commerce-import.php` + client adapters (§12.7) |

### 9.2 Feature flags (`config('commerce.features.*')`)

| Flag | Package default | Gates |
|---|---|---|
| `blog` | true | blog routes, RSS feed, admin Posts/Blog categories menu items, sitemap posts |
| `wishlist` | true | wishlist routes, account endpoint, heart buttons (theme checks `commerce_feature('wishlist')`) |
| `reviews` | true | review route, admin Reviews, JSON-LD ratings |
| `stock_alerts` | true | notify route, admin Stock alerts, back-in-stock mails on restock |
| `newsletter` | true | newsletter route, admin Newsletter |
| `contact_form` | true | contact route + shortcode |
| `order_tracking` | true | order-tracking route + shortcode |
| `quick_view` | true | quick-view route |
| `google_feed` | true | `feeds/google-shopping.xml` + SEO settings fields |
| `abandoned_carts` | true | admin Abandoned checkouts |
| `coupons` | true | coupon routes, discount-code field in basket/checkout, stored codes ignored, admin Discounts, importer `extras.coupons` (added in §18.8) |
| `guest_checkout` | true | off: guests sign in/register before checkout (§18.8) |
| `registration` | true | register route/forms + "create an account" at checkout, AND setting `account.registration` (§18.8) |
| `reports` | true | admin Analytics (§18.8) |
| `redirects` | true | redirect rules on old URLs, admin Redirects, importer `redirects` (§18.8) |
| `multi_shipping` | true | off: only the first available delivery option (§18.8) |
| `product_condition` | false | condition column in admin (side panel select, list filter, CSV), condition facet, `itemCondition` in JSON-LD + feed, presenter `condition()` |
| `product_brand` | true | brand side panel/filter, brand facet image, presenter `brandName()`/`brandLogo()` |
| `spec_highlights` | false | presenter `highlights()` / `cardSpecs()` / `specLine()` (core versions are attribute-driven via `commerce.catalog.spec_attributes`) |
| `pay_in_3` | false | presenter `payIn3()` using `commerce.pay_in_3.{min,max,instalments}` (package 30/2000/3) |
| `legacy_content` | true | WordPress content processing: Elementor detection + per-page CSS, WPBakery toggles, Font-Awesome brand SVGs, `[woocommerce_order_tracking]` form replacement |
| `wp_404_guess` | true | ResolveController step 5 |
| `add_to_cart_query` | true | `HandleAddToCartQuery` middleware |
| `product_csv` | true | admin Products › Import / Export + `commerce:products:*` commands (back office only; 1.1, [PRODUCT-CSV.md](PRODUCT-CSV.md)) |

Client-only sections (for example product-condition explainers, process steps, mega-menu tiles, bespoke home
sections) are **not** flags – they are client theme views + theme config and simply do not exist in other themes.
Helper `commerce_feature(string $key): bool` reads the flags (and returns false when the active theme does not
`support` a view-bearing feature).

### 9.3 ProductPresenter

`Pine\Commerce\Services\Catalog\ProductPresenter` (core, static API): `CARD_RELATIONS`, `attrNames`, `attr`,
`brandName` (attribute `commerce.catalog.brand_attribute`, then `commerce.catalog.known_brands` title match), `brandLogo`
(logos from `theme_config('product.brand_logos', [])`), `condition`/`conditions` (generic: condition attribute terms,
description from `theme_config('product.condition_descriptions')`, label strip list), `specRows`, `amount`, `price`,
`sale`, `cardPriceHtml`, `saveShort`, `saveBadge`, `priceHtml`, `delIns`, `payIn3` (flag), `activeVariations`,
`stockDelivery`, `inStock`, `sized`, `highlights`/`cardSpecs`/`specLine` (generic, attribute-driven, flag).

A client subclasses it (`App\Acme\AcmeProductPresenter extends ProductPresenter`) and overrides what differs – every
method is called through `static::`. Config: `commerce.catalog.presenter = App\Acme\AcmeProductPresenter::class` (or
`Commerce::presenter()`); theme views use `$P = commerce_presenter();` ([EXTENDING.md](EXTENDING.md) "Product
presentation").

---

## 10. Configuration

### 10.1 `config/commerce.php` (package defaults; the client file overrides keys)

```php
return [
    'theme' => env('COMMERCE_THEME', 'default'),
    'legacy_aliases' => false,
    'legacy_hosts' => [],                          // e.g. ['staging.acme.example', 'acme.example'] – old hosts rewritten to the current one
    'routes' => ['storefront' => true, 'admin' => true],
    'urls' => ['trailing_slash' => true],
    'admin' => [
        'path' => 'admin',
        'assets_url' => 'vendor/commerce/admin',
        'brand' => ['name' => null, 'logo' => null, 'logo_light' => null, 'mark' => null, 'favicon' => null],
        'menu' => [],                              // extra sidebar items (see Commerce::adminMenu())
    ],
    'store' => [
        'country' => 'GB', 'countries' => ['GB' => 'United Kingdom (UK)'], 'timezone' => 'Europe/London', 'locale' => 'en_GB',
    ],
    'currency' => ['code' => 'GBP', 'symbol' => '£', 'decimals' => 2, 'decimal_separator' => '.', 'thousands_separator' => ','],
    'features' => [ /* §9.2 */ ],
    'catalog' => [
        'presenter' => \Pine\Commerce\Services\Catalog\ProductPresenter::class,
        'per_page' => 24,
        'filters' => [],                           // slug => ['title'=>…, 'type'=>'radio|checkbox|image', 'hide_empty'=>bool]; empty = every is_filterable attribute as checkbox
        'facet_sorter' => \Pine\Commerce\Services\Catalog\DefaultFacetSorter::class,
        'condition_attribute' => 'condition',
        'condition_label_strip' => [],
        'condition_schema_map' => ['used' => 'UsedCondition', '*' => 'NewCondition'],   // needle in the condition slug => schema.org value
        'brand_attribute' => 'brand',
        'known_brands' => [],
        'spec_attributes' => [],                   // attribute slug => label, for generic highlights/card specs
        'ajax_header' => 'X-Commerce-Catalog',
        'legacy_page_params' => [],                // page-builder pagination query parameters of the old site
    ],
    'cart' => ['cookie' => 'commerce_cart', 'open_cookie' => 'commerce_open_cart'],
    'checkout' => ['attribution_cookie' => 'commerce_attr'],
    'forms' => ['contact' => ['prefix' => 'cf_']],
    'orders' => ['reference_prefix' => 'ORD'],
    'pay_in_3' => ['min' => 30, 'max' => 2000, 'instalments' => 3],
    'tax' => ['enabled' => true, 'prices_include_tax' => true, 'display_shop' => 'incl', 'display_cart' => 'incl', 'rounding' => 'line',
        'based_on' => 'shipping', 'shipping_taxable' => true, 'shipping_tax_class' => 'inherit', 'shipping_prices_include_tax' => '',
        'adjust_non_base_prices' => true, 'price_suffix' => '', 'label' => 'VAT', 'install_rates' => 'uk'],   // v1.1 (TAX-AND-SHIPPING.md)
    'shipping' => ['install_zones' => true, 'carriers' => ['Royal Mail' => 'https://www.royalmail.com/track-your-item#/tracking-results/{number}', /* … */]],
    'feeds' => ['google' => ['default_category' => null, 'default_condition' => 'new']],
    'media' => ['placeholder' => 'images/placeholder.png'],
    'images' => [                                  // v1.1 image sizes – THEMES.md "Images"; a missing sub-key = package default
        'generate_on_upload' => true, 'driver' => 'auto',   // auto | imagick | gd
        'sizes' => ['thumbnail' => [150, 150, crop], 'card' => 400, 'medium' => 800, 'large' => 1600],
        'webp' => true, 'picture_webp' => true, 'quality' => ['jpg' => 82, 'webp' => 80, 'avif' => 60, 'png' => 8],
        'auto_orient' => true, 'max_dimension' => 2560, 'strip_metadata' => true,
    ],
    'settings' => ['defaults' => []],              // setting key => default shown/used when no row exists (StoreSettings)
    'payments' => ['gateways' => [
        'stripe' => \Pine\Commerce\Services\Payments\Gateways\StripeGateway::class,
        'paypal' => \Pine\Commerce\Services\Payments\Gateways\PaypalGateway::class,
        'bacs'   => \Pine\Commerce\Services\Payments\Gateways\BacsGateway::class,
    ]],
    'content' => ['shortcode_aliases' => []],      // e.g. ['acme_contact_form' => 'contact_form'] (or via Theme::boot)
];
```

The excerpt above is abridged; `config/commerce.php` in the package documents every key. A client's
`config/commerce.php` sets the values it needs (top-level keys replace the package block – shallow merge – except
`features`, merged key by key).

### 10.2 `config/commerce-import.php` – see §12.8.

### 10.3 Environment variables

`COMMERCE_THEME`; importer: `WP_DB_HOST`, `WP_DB_PORT`, `WP_DB_DATABASE`, `WP_DB_USERNAME`, `WP_DB_PASSWORD`,
`WP_DB_SOCKET`, `WP_DB_PREFIX` (existing, read by the `wordpress` connection), `WP_PATH`, `WP_SITE_URL`,
`WP_SITE_HOST`. No other new variables.

---

## 11. Extension points (client provider / theme)

`App\Providers\ClientServiceProvider` (registered in `bootstrap/providers.php`) and `ThemeDefinition::boot()` use the
static API on `Pine\Commerce\Commerce`:

| API | Purpose |
|---|---|
| `Commerce::gateway(string $code, class-string<Gateway> $class)` | add/replace a payment gateway (also via `commerce.payments.gateways`) |
| `Commerce::adminMenu(): AdminMenu` → `->add(label, icon, route, active[], ?position, ?children)`, `->remove(route)` | client admin pages in the sidebar (routes/views are ordinary client code using `commerce::admin.layouts.app` and `<x-admin.*>`) |
| `Commerce::settingsGroup(string $key, array $group, array $fields)` / `Commerce::settingsFields(string $group, array $fields)` | extra settings screens/fields in `StoreSettings` |
| `Commerce::pageTemplate(string $key, array $meta, array $schema)` | page templates with block schemas (also via theme `config/blocks.php`) |
| `Commerce::shortcode(string $name, callable $render)` / `Commerce::shortcodeAlias(string $alias, string $name)` | content shortcodes processed by `PageContent` |
| `Commerce::presenter(class-string)` | product presenter (also via config) |
| `Commerce::facetSorter(class-string)` | facet option ordering |
| `Commerce::menuLocation(string $key, string $label)` | menu locations offered in the admin |
| `Commerce::orderEmail(string $key, class-string<OrderEmail>)` | extra/replacement order emails (listeners use the registry) |
| `Commerce::importAdapter(class-string<Adapter>)` | importer adapters (also via `commerce-import.adapters.extra`) |
| Events `OrderPlaced`, `OrderStatusChanged` (+ new, add-only: `ProductSaved`, `CheckoutValidating`) | ordinary Laravel listeners |
| View composers on `theme::*` | theme/client view data |
| Model macros / `Model::resolveRelationUsing()` / observers | client-only relations and behaviour without subclassing core models |
| *Added in §18.8:* `Commerce::shippingCalculator()`, `adminRoutes()`, `settings()` (= `settingsGroup`), `dashboardWidget()`, `presenterMethod()`, `onOrderStatus()` / `onOrderPlaced()`, `importStep()`, `feature()`, `payments()`; `ThemeDefinition::composers()`; contracts `Contracts\PaymentGateway`, `ShippingCalculator`, `DashboardWidget` | see [EXTENDING.md](EXTENDING.md) |
| *Added in 1.2:* `Commerce::scheduledTask($key, $options)` – client scheduled jobs run, recorded and listed like core tasks (§13.2) | see [EXTENDING.md](EXTENDING.md) "Scheduled tasks" |

Core models are **not** swappable by config (only `User` via `auth.providers.users.model`). Client data that needs
columns uses its own tables/migrations in `database/migrations` of the client app.

---

## 12. WordPress importer

### 12.1 Command

```
php artisan commerce:import-wordpress
    {--wp-path=        : WordPress root; wp-config.php is PARSED (regex, never included) for DB_NAME/DB_USER/DB_PASSWORD/DB_HOST/DB_CHARSET/$table_prefix}
    {--db-host= --db-port= --db-name= --db-user= --db-pass= --db-socket= : explicit source DB (override wp-config / WP_DB_* env)}
    {--prefix=         : table prefix; default: from wp-config, else auto-detected}
    {--site-url=       : base URL of a running copy of the source site for rendered-content crawling}
    {--site-host=      : Host header to send with --site-url (e.g. staging.acme.example)}
    {--snapshots=      : directory of pre-rendered HTML (default storage/app/wp-reference/html)}
    {--uploads-path=   : wp-content/uploads to copy media from (default {wp-path}/wp-content/uploads)}
    {--target=         : target DB connection (default: database.default) - e.g. 'scratch' for tests}
    {--only=           : comma list of sections/steps (aliases as today: customers, products, categories, pages, posts, coupons, shipping, reviews, forms …)}
    {--skip=           : comma list of sections/steps to skip}
    {--orders-source=  : auto|hpos|posts}
    {--dry-run         : read + transform + report counts, write nothing (target writes go through ImportContext::writer(), a no-op in dry-run)}
    {--fresh           : purge previously imported rows (wp_id NOT NULL / natural keys the step owns) of the selected sections first}
    {--skip-files      : do not copy upload files}
    {--detect          : print detected site info, plugins, storage mode and adapters, then exit}
```

`import:wordpress` stays as an alias with the old options. The command never writes to the source: the runtime
connection `wordpress_import` is built from the resolved credentials with the same `SET SESSION TRANSACTION READ ONLY`
init command as today's `wordpress` connection.

**Prefix auto-detection:** `SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE
'%options'`; for each candidate prefix check `{prefix}options` has `option_name = 'siteurl'` and `{prefix}posts`
exists. One match → use it; several → fail listing them (“pass --prefix”).

**Target:** `--target=scratch` → the command sets `database.default` to that connection for the run (`DB::purge()`),
so models and `ImportContext` write there. The scratch connection for tests is defined in the client
`config/database.php`: a copy of `mysql` with `'prefix' => 'zz_'`; `commerce:install --connection=scratch` migrates
it; afterwards drop only `zz_%` tables (`commerce:scratch:drop`, refuses any other prefix).

### 12.2 Source detection (`WordPressSource` / `SiteProfile`)

`SiteProfile::detect(WordPressSource)` returns:
`siteUrl`, `homeUrl` (options), `wpVersion` (`db_version`), `wooVersion` (`woocommerce_version`), `activePlugins`
(unserialised `active_plugins` + `active_sitewide_plugins`), `theme` (`stylesheet`/`template`), `permalinkStructure`,
`WooSettings { currency, currencyPos, decimals, thousandSep, decimalSep, calcTaxes, pricesIncludeTax, taxDisplayShop,
defaultCountry, allowedCountries, weightUnit, dimensionUnit, permalinks {product_base, category_base, tag_base,
attribute_base}, ordersStorage: 'hpos'|'posts', hposSync: bool }`.

`ordersStorage` = `'hpos'` when `woocommerce_custom_orders_table_enabled = 'yes'` and `{prefix}wc_orders` exists;
otherwise `'posts'` (also when the tables exist but the option is `no`). When HPOS is authoritative and sync is off,
`shop_order` posts are placeholders (`shop_order_placehold`) and must not be read.

`--detect` prints the profile plus which adapters `detect()` true, e.g.: woocommerce, rank-math, redirection,
sequential-order-numbers (wt), premmerce-permalinks, acf, elementor, wpbakery-cleanup (content scan),
back-in-stock-notifier (cwg), yith-wishlist/ti-wishlist (tables), cfdb7 (table), gtm4wp (option present), cost-of-goods
(meta present).

### 12.3 Interfaces (namespace `Pine\Commerce\Import\Contracts`)

```php
interface Step
{
    public function key(): string;                 // 'catalog.products'
    public function section(): string;             // settings|media|users|catalog|orders|extras|content|menus|redirects|<custom>
    /** @return string[] step keys that must run first (topological order; ties keep registration order) */
    public function after(): array;
    public function shouldRun(ImportContext $ctx): bool;
    public function run(ImportContext $ctx): void; // runs inside a DB transaction on the target connection
    public function purge(ImportContext $ctx): void; // --fresh; only rows with wp_id / keys this step owns
}

interface Adapter
{
    public function key(): string;                 // 'rank-math'
    public function label(): string;
    public function detect(SiteProfile $site, WordPressSource $wp): bool;
    public function priority(): int;               // higher runs first among providers of the same capability
    /** @return array<class-string<Step>|Step> extra steps */
    public function steps(): array;
}

/* Capabilities – an Adapter implements any number of these. */
interface SeoProvider {
    public function postSeo(WpPost $post, array $meta, array $vars): ?SeoData;       // pages, posts, products
    public function termSeo(WpTerm $term, string $taxonomy, array $vars): ?SeoData;   // product_cat, category
    public function primaryTermId(WpPost $post, array $meta, string $taxonomy): ?int;
    /** @return array<string,mixed> seo.* settings (site name, separator, suffix, default image, org logo) */
    public function siteSettings(): array;
}
interface OrderNumberProvider {
    public function orderNumber(WcOrder $order): ?string;
    public function lastIssuedNumber(): ?int;
}
interface PermalinkProvider {
    /** @return array{products: array<int,string>, categories: array<int,string>, pages: array<int,string>, posts: array<int,string>} wp id => path without slashes */
    public function permalinks(): array;
}
interface RedirectProvider {
    /** @return iterable<RedirectRule> */
    public function redirects(): iterable;
}
interface ContentTransformer {
    public function transform(string $html, ContentItem $item): string;   // chained by priority
}
interface RenderedContentProvider {
    public function renderedHtml(ContentItem $item): ?string;             // first non-null wins
}
interface ProductMapper {
    /** Return the products row with changes (columns of `products` only). */
    public function mapProduct(array $row, WpProduct $product, ImportContext $ctx): array;
    /** @return list<array{key:string,label:string,value:string,description:?string}>|null spec rows (null = no opinion) */
    public function specRows(WpProduct $product, ImportContext $ctx): ?array;
}
interface TermMapper {
    public function mapCategory(array $row, WpTerm $term, array $termMeta, ImportContext $ctx): array;
}
interface SettingsProvider {
    /** @return array<string,mixed> setting key => value (later providers override earlier; config seed wins last) */
    public function settings(ImportContext $ctx): array;
}
interface MenuProvider {
    /** @return iterable<MenuTree> each replaces the menu at its location */
    public function menus(ImportContext $ctx): iterable;
}
interface OrderSource {                                // chosen by SiteProfile::ordersStorage, not an adapter
    public function count(): int;
    /** @return iterable<list<WcOrder>> chunks */
    public function orders(int $chunk = 500): iterable;
    /** @return list<WcRefund> */
    public function refunds(array $wpOrderIds): array;
}
```

Data objects (`Pine\Commerce\Import\Data`, readonly): `WpPost {id, type, status, title, name, content, excerpt,
parentId, menuOrder, dateGmt, modifiedGmt, authorId}`, `WpTerm {id, taxonomy, name, slug, parentId, description,
order}`, `WpProduct {post: WpPost, meta: array, terms: array<string taxonomy, list<WpTerm>> (ordered like
wc_get_product_terms), categoryIds: int[], attributes: array (unserialised _product_attributes)}`, `ContentItem {kind:
'page'|'post'|'product'|'term', wpId, path, html, meta}`, `SeoData {title, description, focusKeyword, noindex,
canonical}`, `RedirectRule {from (lower-case path, no slashes), to (relative with trailing slash or absolute), status,
source}`, `MenuTree {location, name, items: list<MenuNode>}`, `MenuNode {label, url, badge, icon, cssClass, newTab,
children}`, `WcOrder {id, number, status (no wc- prefix), currency, pricesIncludeTax, totals {subtotal, discount,
discountTax, shipping, shippingTax, tax, total}, billing[], shipping[], paymentMethod, paymentMethodTitle,
transactionId, customerId, customerNote, createdVia, orderKey, dateCreatedGmt, datePaidGmt, dateCompletedGmt, meta[],
items: list<WcOrderItem>}`, `WcOrderItem {id, type, name, productId, variationId, qty, subtotal, total, tax, meta[]}`,
`WcRefund {id, parentId, amount, reason, refundedBy, dateGmt, items[]}`.

`HposOrderSource` reads `{p}wc_orders` (type `shop_order` / `shop_order_refund`, `status`, `currency`, `total_amount`,
`tax_amount`, `customer_id`, `billing_email`, `payment_method(_title)`, `transaction_id`, `customer_note`,
`date_created_gmt`, `date_updated_gmt`, `parent_order_id`), `{p}wc_order_addresses` (address_type billing/shipping),
`{p}wc_order_operational_data` (`order_key`, `created_via`, `prices_include_tax`, `shipping_total_amount`,
`shipping_tax_amount`, `discount_total_amount`, `discount_tax_amount`, `date_paid_gmt`, `date_completed_gmt`,
`cart_hash`, `recorded_sales`) and `{p}wc_orders_meta`. `LegacyPostsOrderSource` = today's post/postmeta logic. Both
read items from `woocommerce_order_items`/`woocommerce_order_itemmeta` and notes from `comments`
(`comment_type = 'order_note'`) – shared code in `OrdersStep`. A fixture test covers both sources (`tests/Import/OrderSourcesTest.php`, §13.1).

### 12.4 Core steps (in order)

`settings` → `media.files` (copy uploads, quarantine non-media into `storage/app/private/quarantine-uploads`, block
list as today's `public/storage/.htaccess`) → `media` → `users` → `catalog.categories` → `catalog.attributes` →
`catalog.products` → `catalog.variations` → `orders` → `extras.reviews` → `extras.coupons` → `extras.shipping` →
adapter extras (stock alerts, wishlists, form submissions) → `content.pages` → `content.posts` → `menus` →
`redirects`. Each step's summary row and warnings are reported exactly as today (table + `storage/logs/import-wordpress.log`).

### 12.5 Built-in plugin adapters (`Pine\Commerce\Import\Adapters`)

| Adapter | Detect | Capabilities |
|---|---|---|
| `WooCommerce` | `woocommerce/woocommerce.php` active (required – abort otherwise, unless `--only=content,menus,redirects,users`) | core steps above |
| `RankMath` | `seo-by-rank-math/rank-math.php` | SeoProvider (`rank_math_title/description/focus_keyword/robots`, `%var%` resolution = `Formatter::seo`, term meta, `rank-math-options-titles`), primary term `rank_math_primary_{taxonomy}`, RedirectProvider (`{p}rank_math_redirections` when the table exists) |
| `Yoast` | `wordpress-seo/wp-seo.php` or `wordpress-seo-premium/…` | SeoProvider (`_yoast_wpseo_title/metadesc/focuskw/meta-robots-noindex`, `wpseo_taxonomy_meta`, `wpseo_titles`), primary term `_yoast_wpseo_primary_{taxonomy}`, RedirectProvider (`wpseo-premium-redirects-base`) |
| `SequentialOrderNumbers` | `wt-woocommerce-sequential-order-numbers/*`, `woocommerce-sequential-order-numbers(-pro)/*`, `custom-order-numbers-for-woocommerce/*` | OrderNumberProvider (`_order_number`, `_alg_wc_custom_order_number`; counters `wt_last_order_number`, …) |
| `PremmercePermalinks` | `woo-permalink-manager(-premium)/premmerce-url-manager.php` | PermalinkProvider (product = `{primary category path}/{slug}`) + sets `commerce.features.wp_404_guess` recommendation |
| `PermalinkManager` | `permalink-manager(-pro)/permalink-manager.php` | PermalinkProvider from option `permalink-manager-uris` |
| `WpCliPermalinks` | `wp` binary available and `--wp-path` given | PermalinkProvider = today's `ImportContext::wpUrls()` (cached to `storage/app/import/wp-urls.json`), highest priority |
| `Acf` | `advanced-custom-fields(-pro)/acf.php` | TermMapper/ProductMapper driven by `commerce-import.acf.{term_fields,product_fields}` (`acf field name => target column`) |
| `Redirection` | `redirection/redirection.php` or table `{p}redirection_items` | RedirectProvider (enabled groups, `action_type = url`, plain matches – today's logic) |
| `Elementor` | `elementor/elementor.php` | RenderedContentProvider (snapshot dir, else HTTP render via `--site-url`/`--site-host`, cached in `storage/app/import/rendered/{host}/{path}.html`, 1 request at a time, 5 s timeout, `--site-url` required for crawling), copies `uploads/elementor/css/post-{id}.css` into the active theme's `assets/css/elementor/`, `_elementor_data` text fallback |
| `WpBakeryCleanup` | `js_composer/js_composer.php` active or `[vc_`/`[us_` found in content | ContentTransformer (`Formatter::pageBuilderToHtml`) |
| `BackInStockNotifier` | post type `cwginstocknotifier` has rows | step `extras.stock-alerts` |
| `YithWishlist` / `TiWishlist` | tables `{p}yith_wcwl` / `{p}tinvwl_items` exist | step `extras.wishlists` |
| `ContactForm7Database` | table `{p}db7_forms` exists | step `extras.forms` |
| `ProductBrands` | taxonomy `product_brand` (WooCommerce Brands) or `pwb-brand` has terms | ProductMapper: `products.brand` + a `brand` attribute |
| `CostOfGoods` | meta `_alg_wc_cog_cost` / `_ni_cost_goods` / `_wc_cog_cost` present | ProductMapper: `cost_price` |
| `GoogleTagManager` | option `gtm4wp-options` | SettingsProvider `tracking.gtm_id` |

### 12.6 Rendered-content crawling

`RenderedSite\RenderedSource::html(string $path): ?string` – snapshot dir first (file name = path with `/` → `__`,
`home.html` for `/`), else HTTP GET `{site-url}/{path}/` with `Host: {site-host}` when given, cached to disk. Used by
Elementor content, `ImportContext::renderedSeo()` and client adapters (e.g. menus or settings only visible in the rendered HTML).

### 12.7 Client adapters (`app/Import/{Client}`, registered in the client `config/commerce-import.php` or with `Commerce::importAdapter()`)

Data the generic importer does not know is mapped by client adapters implementing the §12.3 capabilities, e.g.:

| Adapter | Capability | Content |
|---|---|---|
| `AcmeCatalogAdapter` | ProductMapper | client product columns (`subtitle`, `condition`, `brand`) and spec rows from custom meta |
| `RenderedMenusAdapter` | MenuProvider | menus that exist only in the old theme's rendered HTML (mega menu, footer columns) |
| `RenderedSiteSettingsAdapter` | SettingsProvider | contact details, logos, top-bar text from the rendered header/footer |
| a step such as `extras.shipping.saturday` | step (after `extras.shipping`) | a delivery method the old site hard-coded in its theme |

A static block of store details (phone, email, address, company/VAT numbers, socials, top-bar text) goes into
`commerce-import.settings.defaults` / `settings.seed` instead. Walk-through: [IMPORTER.md](IMPORTER.md) §9.

### 12.8 `config/commerce-import.php`

```php
return [
    'source' => [
        'connection' => 'wordpress',                  // existing connection; CLI options override
        'wp_path' => env('WP_PATH'),
        'site_url' => env('WP_SITE_URL'), 'site_host' => env('WP_SITE_HOST'),
        'snapshots' => storage_path('app/wp-reference/html'),
    ],
    'legacy_hosts' => [],                             // hosts whose links become relative (source siteurl/home are always included)
    'content' => [
        'replace' => [],                              // e.g. ['staging.acme.example' => 'acme.example']
        'system_pages' => ['basket', 'cart', 'checkout', 'my-account', 'shop'],
        'page_templates' => [],                       // wp page path|id => local template (e.g. 'contact-us' => 'contact', 'faq' => 'faq')
    ],
    'attributes' => [
        'filterable' => [],                           // e.g. ['colour', 'size', 'material']
        'map' => [],                                  // attribute slug => products column, e.g. ['brand' => 'brand', 'condition' => 'condition']
    ],
    'menus' => [
        'by_location' => [],                          // WP theme location => local location (nav_menu_locations theme_mod)
        'by_term_id' => [],                           // e.g. [62 => 'main', 66 => 'mobile', 67 => 'footer_information']
    ],
    'orders' => ['meta_keys' => [ /* order meta copied to orders.meta */ ], 'source' => 'auto'],
    'acf' => ['term_fields' => [], 'product_fields' => []],
    'settings' => ['seed' => []],
    'adapters' => ['extra' => [], 'disable' => []],   // class names / adapter keys
];
```

---

## 13. Commands

| Command | Behaviour |
|---|---|
| `commerce:install [--connection=] [--admin-email= --admin-name= --admin-password=] [--no-interaction] [--seed-demo]` | `migrate --force` (on the connection), create the first admin (interactive unless options given; refuses if an admin exists unless `--force-admin`), seed default settings (`commerce.settings.defaults` + store name from `APP_NAME`), shipping (one free method for `store.country`), menus (empty `main`, `footer_*` locations), core pages (home, shop, contact, privacy-policy, terms – only when absent), `commerce:publish`, `commerce:theme:publish`, `optimize`. Idempotent. |
| `commerce:publish [--force]` | copy package admin assets → `public/{commerce.admin.assets_url}`; placeholder image → `public/images/placeholder.png` (never overwrite without `--force`) |
| `commerce:theme:make {slug} [--parent=default] [--copy]` | scaffold `themes/{slug}/{theme.json, Theme.php, config/, views/layouts/app.blade.php, assets/css/site.css}`; `--copy` copies the parent's views/assets for a full fork |
| `commerce:theme:publish [slug] [--all] [--prune]` | §7.6 |
| `commerce:theme:check [slug]` | validates theme.json, parent chain, `public_path`, and that every §8 contract row (required + those in `supports`) resolves; non-zero exit on failure |
| `commerce:theme:cache` / `commerce:theme:clear` | manifest cache (hooked into optimize / optimize:clear) |
| `commerce:doctor` | checks: `APP_URL` https + matches request host config, `APP_KEY`, `APP_DEBUG=false` in production, DB connection + pending migrations, `public/storage` is a real directory (fail if symlink – LiteSpeed), `storage/`+`bootstrap/cache` writable, published admin assets + theme assets up to date (marker hash), active theme valid (`theme:check`), features vs theme `supports`, mail driver configured + from address, payment gateways enabled/configured (Stripe keys + webhook secret, PayPal client/secret), queue = sync note, cron heartbeat (PASS when `schedule:run` beat within 5 minutes; otherwise INFO with the web fallback on, WARN with it off – fix = the cron line), `wordpress` connection read-only if configured, route cache present, `legacy_aliases` still on (warning) |
| `commerce:import-wordpress` | §12 (alias `import:wordpress`) |
| `commerce:scratch:drop {--connection=scratch}` | drops tables with the connection's prefix; refuses empty prefix or a prefix not starting with `zz_` |
| `commerce:schedule:status [--json]` | cron heartbeat + every scheduled task (core and client): schedule (store timezone), state (on / idle: why / off in config), last run + result, next run (§13.2) |
| `commerce:schedule:task {task}` | run one core scheduled task now (recorded as a manual run) |

### 13.1 Tests

- Package tests live in the package's `tests/` (namespace `Pine\Commerce\Tests`) and run standalone in the package
  repository (`composer test`, `orchestra/testbench` as a require-dev only – see §18.9). They never assume a client's
  config: each test sets the config it needs (`config([...])`) and uses in-memory SQLite (`InstallsNeutralStore`) or
  the `scratch` connection (`zz_` prefix) when it needs MySQL tables, dropping them in `tearDown()`.
- A client app may also run them through its own `phpunit.xml` while developing the package against it (a path
  checkout, §4.1); committed clients run only their own tests.
- Mandatory package tests: route fallback order (R15), theme chain resolution + `theme_asset()` + `theme:check default`,
  each feature flag off/on, importer adapter detection on a fake `SiteProfile`, HPOS vs posts order sources,
  `commerce:scratch:drop` prefix guard, the neutral install render (`NeutralInstallRenderTest`: every page type of a
  fresh install with the package config and the default theme; banned strings configurable through the
  `COMMERCE_NEUTRALITY_BANNED` / `COMMERCE_NEUTRALITY_BANNED_REGEX` environment variables, empty in the package).

### 13.2 Scheduled tasks and abandoned-cart recovery (v1.1)

`Pine\Commerce\Scheduling\Scheduler` registers the core tasks with Laravel's scheduler; one cron line runs them:
`* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (cPanel: PLAYBOOK Part 2 "Cron").

| Task key | Cron (store timezone) | Work | Gate |
|---|---|---|---|
| `orders.cancel-unpaid` | `*/5 * * * *` | cancel pending checkout orders holding stock longer than `checkout.hold_stock_minutes` (`Tasks\CancelUnpaidOrders`) | setting > 0 |
| `carts.abandoned-emails` | `*/10 * * * *` | abandoned-cart reminders (`Services\Recovery\AbandonedCartRecovery::sendDue()`) | feature `abandoned_carts` + setting `abandoned_carts.emails_enabled` (off) |
| `stock.back-in-stock` | `20 * * * *` | waiting back-in-stock alerts whose product is available again (restocks outside the back office) | feature `stock_alerts` |
| `catalog.sale-prices` | `*/5 * * * *` | rewrite `products.price` when a scheduled sale starts/ends (updated_at untouched; feed cache cleared) | – |
| `maintenance.prune` | `40 3 * * *` | guest baskets idle > `scheduler.cart_retention_days` (default 90, 0 = keep), expired DB sessions, expired password-reset tokens | – |
| `inventory.low-stock-email` | `0 7 * * *` | `Mail\LowStockReport` to the order-notification addresses | setting `scheduler.low_stock_email` (off) |

- Config `commerce.scheduler`: `enabled`, `tasks` (key => bool; off = not scheduled and never run by a fallback),
  `web_fallback` (package default true), `heartbeat_minutes` (5). Owner switches are settings (Settings › Scheduled
  tasks, Settings › Abandoned carts). A client config without the `scheduler` key gets the package block.
- Heartbeat: every `schedule:run` writes setting `scheduler.last_run`; last result per task: `scheduler.task.{key}`
  (JSON: at, status ok|skipped|failed, summary, ms, via cron|web|manual). Both are written with plain queries (no
  settings-cache flush every minute) and read uncached. `Scheduler::cronRunning()` = heartbeat younger than
  `heartbeat_minutes`.
- Without cron: `CheckoutService::cancelStaleOrders()` (checkout visit, throttled 10 min) still runs
  `orders.cancel-unpaid` unless cron is running; with `web_fallback` every configured task that is due since its last
  run runs in a terminating callback after a storefront response, at most every 5 minutes (cache lock).
- Tasks extend `Scheduling\Task` (`skipReason()`, `handle(): string`), never throw out of `Scheduler::run()`, and are
  safe to run late or twice.
- Client tasks (v1.2): `Commerce::scheduledTask($key, [...])` → `Scheduling\ClientTask` (schedule = cron expression or
  Laravel frequency method; runs a callable, an artisan command or a `Task` class; guards `feature` / `setting` /
  `skip`). Stored in `ExtensionRegistry::scheduledTasks()`; `Scheduler::tasks()` = core + client, used by register,
  run, web fallback, status and `commerce:schedule:task` – a client task behaves exactly like a core one (EXTENDING.md
  "Scheduled tasks").

Abandoned-cart recovery (`AbandonedCartRecovery`, tables `cart_recovery_emails`, `email_unsubscribes`, nullable
`carts.recovery_stopped_at|recovery_stop_reason|recovered_order_id|recovered_at`):
- eligible basket: items, not converted, not stopped, address (`carts.email` captured by `/checkout/update` as soon as
  the email field is filled, or the signed-in customer's), idle ≥ the first step's delay and ≤
  `abandoned_carts.max_age_days`, value ≥ `abandoned_carts.min_value`; consent `abandoned_carts.consent` = `marketing`
  (users.marketing_opt_in or an active newsletter subscriber) | `all`;
- steps 1–3 (`abandoned_carts.step{n}.enabled|delay_hours|subject|intro|coupon|coupon_amount|coupon_days`): one email
  per basket per run; a later step waits its delay after the last activity and the delay gap after the previous email;
  optional coupon = single-use, `allowed_emails` = the address, expiry; placeholders `{name}`, `{store}`;
- stop reasons: `emptied`, `unsubscribed`, `ordered` (a paid order by the address since the basket was started),
  `merged`, `staff`; converted baskets are never selected;
- links (signed, 30 / 90 days): `cart.recover` GET `basket/restore/{cart}/?e=` (click recorded, `Cart::restore()` adopts
  or merges, coupon applied, → /checkout/), `cart.recover.unsubscribe` GET page / POST (CSRF-exempt, RFC 8058
  `List-Unsubscribe-Post`). `SlashUrlGenerator::hasCorrectSignature()` accepts the trailing-slash form;
- no open tracking (no pixels); `OrderPlaced` → `markRecovered()` (the order's basket had a reminder, or a reminder
  was clicked by the order's address within 7 days); `stats()` counts paid orders only.

---

## 14. Regression check (clients with a like-for-like theme)

A client whose storefront must not change keeps a URL list and a baseline snapshot of the rendered HTML, and checks
every platform change against it:

```bash
php artisan optimize:clear
php artisan config:clear && php artisan test        # with the config cache CLEARED: a cached bootstrap/cache/config.php
                                                   # overrides phpunit.xml's env and points tests at the real database
php artisan optimize                               # re-cache so staging keeps serving
<snapshot script> <dir>/after-<change>            # fetch every URL of the list, one file per URL
<diff script> <dir>/before <dir>/after-<change>    # normalised diff; exit 0 = identical
tail -n 50 storage/logs/laravel.log                # no new errors
php artisan route:list --json | md5sum             # route names + URIs identical to the previous run
```

The diff normalises what changes on every request before comparing: CSRF tokens (`name="_token" value="…"`,
`<meta name="csrf-token">`, `"csrfToken"` JSON values), nonces, `?v=…` asset versions, ISO-8601 and `Y-m-d H:i:s`
timestamps and date-driven text (e.g. a delivery estimate). File names: the URL path with every character outside
`[A-Za-z0-9_-]` replaced by `_`, `home` for `/`. Intended changes are listed explicitly; any other difference means
the change is reverted (git revert) rather than patched forward on staging. PLAYBOOK Part 3.4 has the operator steps.

---

## 15. Upgrading clients (summary for UPGRADING.md)

The package is developed in its own repository (`SetWebUK/ecom-core`) and released by tagging `vX.Y.Z` there. Clients
require `"pine/commerce": "^1.2"` through a composer VCS repository and upgrade with `composer update pine/commerce` →
`php artisan migrate` → `commerce:publish` → `commerce:theme:publish` → `optimize`. SemVer: the theme contract (§8),
config keys (§10), extension API (§11), importer interfaces (§12.3) and route names are the public API – breaking them
is a major version. New package migrations are additive only; table/column renames are forbidden (clients hold live
data).

---

## 16. Documents

- **README.md** – what Pine Commerce is, layer diagram (§2), links.
- **PLAYBOOK.md** – repositories, onboarding a client end to end, releases, data safety, troubleshooting, reference.
- **THEMES.md** – §7 + §8 with examples; forking `default`; CSS/JS conventions without Node.
- **IMPORTER.md** – §12, writing a client adapter, dry-run, scratch testing, HPOS.
- **EXTENDING.md** – §9–§11 with code samples (gateway, admin page, settings group, page template, shortcode …).
- **UPGRADING.md** – §15, versioning rules.

---

## 17. Risks and mitigations

| # | Risk | Mitigation |
|---|---|---|
| R1 | Catch-all swallowing package/client routes after the move (provider routes load before `routes/web.php`) | `->fallback()` on the resolve route (§4.4); route:list diff each step |
| R2 | Old `App\` class names referenced from Blade, tests, compiled views, route cache → fatal errors on staging mid-migration | LegacyAliases autoloader (§5.4), `optimize:clear` + `optimize` after each change, a grep for old names |
| R3 | Event discovery stops finding listeners once `app/Listeners` is empty → order emails/stock sync silently stop | explicit registration + `event:list` comparison (§4.5); OrdersTest mail assertions |
| R4 | Admin asset URL change breaks JS paths (icons sprite, TinyMCE base), cached admin pages, stored HTML | `commerce.admin.assets_url` (a client may keep its old URL); `window.Admin.assetBase`; keep the old copy one release |
| R5 | Theme asset URL change breaks sent emails (logo URLs), imported content (`/assets/css/elementor/post-*.css`), cached pages | theme `public_path` set to the old asset directory (URLs unchanged) |
| R6 | Published assets are git-ignored: a deploy that does not publish → missing CSS | every deploy runs `commerce:publish` + `commerce:theme:publish`; `commerce:doctor` checks the marker hash |
| R7 | View name collisions: bare names resolve app `resources/views` first; a stale leftover file shadows the theme | keep storefront views out of `resources/views`; doctor lists files in `resources/views` that also exist in the theme |
| R8 | Class-order / whitespace changes in body classes or menus alter HTML | `Theme::bodyClass` hook specified to reproduce exact strings (§7.5); config values copied verbatim; byte-diff after normalisation |
| R9 | Importer run against the live DB or the WordPress DB by mistake | `--target` required when the default connection has data with `wp_id` and `--fresh` is passed (prompt + `--force`); source connection read-only session; scratch drop refuses non-`zz_` prefixes |
| R10 | HPOS stores with sync off expose placeholder posts | `SiteProfile::ordersStorage` + placeholder post type check + fixture test |
| R11 | Parallel work editing the same files | one change in flight per area; re-read before editing; tests before commit |
| R12 | Composer path repo + `optimize-autoloader` leave stale classmaps | `composer dump-autoload -o` after moving classes |
| R13 | Cart/session cookies renamed for a migrated client → customers lose baskets | cookie names are config; the client keeps its old values (§8.4) |
| R14 | Package default theme drifts from the contract | `commerce:theme:check default` in the package test suite |
| R15 | Laravel `optimizes()` API or `Route::fallback()` behaviour differs in 13.x | `RouteFallbackOrderTest`: a route registered after the catch-all still matches |

---

## 18. Open questions and implementation notes (append here; do not decide silently)

The notes below record decisions taken while building the platform (dated). "The first client" is the client
project the platform was extracted from.

1. Admin branding assets of a client: decided in 7 – client-owned copies (e.g. `public/brand/admin/`) +
   `commerce.admin.brand.*`; the package ships a neutral "Commerce" logo/mark.
2. `products.condition` / `products.brand` / `products.subtitle` stay core columns (generic, flag-gated), no schema
   change.
3. Package name `pine/commerce` and namespace `Pine\Commerce` are final.
4. **Core extraction – implementation notes, 2026-09-23.**
   - **No `LegacyAliases` needed.** Every `App\…` reference to a moved class (PHP, Blade, routes, tests) was rewritten
     in the same commits, and the database held no serialized class names (sessions, cache, jobs/failed_jobs,
     settings, morph types checked). `commerce.legacy_aliases` exists but nothing reads it.
   - **`admin.icon-set` is not registered as a Blade component**: `IconSet` (like `Ui`) is a plain helper class, not a
     `Component`; `<x-admin.icon>` is anonymous and calls it statically.
   - Admin assets: `commerce_admin_asset($path, $version = true, $absolute = true)` (extra optional args keep the
     un-versioned vendor URLs and the root-relative editor CSS URLs byte-identical) and `commerce_admin_brand($key)`.
     `window.Admin` is created by admin.js itself, so the layouts print `<meta name="admin-asset-base">` and admin.js
     exposes `Admin.assetBase`.
   - The client skeleton's `config/database.php` has the `scratch` connection (prefix from `DB_SCRATCH_PREFIX`, forced
     to start with `zz_`).
5. **Platform tooling (install/doctor/new-client/verify-urls, System page) – implementation notes, 2026-09-23.**

   - `commerce:install` options beyond §13: `--store-name`, `--store-email`, `--theme` (validated, written to
     `COMMERCE_THEME` in `.env`), `--order-start` (setting `orders.starting_number`), `--skip-publish`,
     `--no-optimize`; no `--seed-demo`. With `--connection` ≠ default only the database part runs (models and an
     in-memory cache are pointed at that connection); `public/storage`, asset publishing, `.env` and caches are left
     alone. Seeds only what is absent: settings (store/SEO names, order start, terms/privacy page keys,
     `seo.discourage_search_engines` = on), one free delivery method, pages home/about/contact/terms-conditions/
     privacy-policy (home blocks = `theme_config('home.defaults')`), menus for `theme_config('menus.locations')`
     (fallback: main, mobile_nav, footer_shop, footer_company, footer_legal).
   - Admin System page = route `admin.settings.system` (`/admin/settings/system`, `admin:admin`), listed as the
     `system` group in `StoreSettings::groups()` so the Settings sidebar highlight works without a Sidebar change.
   - Extra commands: `commerce:new-client {path}` (copies `stubs/client-skeleton`, placeholders
     `{{CLIENT_NAME}}`/`_JSON`/`_ENV`, `{{CLIENT_SLUG}}`, `{{COMMERCE_REPOSITORY}}`, `{{COMMERCE_CONSTRAINT}}`) and
     `commerce:verify-urls {sitemap|file}`.
   - **Shop tables cannot be prefixed**: admin/catalogue code uses raw SQL with unprefixed qualified column names
     (e.g. `products.price`, `order_items.quantity`), which breaks on a prefixed default connection. The `scratch`
     (`zz_`) connection is therefore only valid for database-level work (install, importer), not for browsing the
     storefront/admin. The client skeleton's `mysql` connection has no prefix option.
5. **Theme system – implementation notes, 2026-09-23.**
   - As specified: `ThemeManager`/`Theme`/`ThemeDefinition`/`ThemeContract`, view-path chain + `theme::` namespace,
     helpers, `commerce:theme:{make,publish,check,cache,clear}`, a complete `default` theme.
   - Additions: runtime active-theme choice **setting `theme.active`** (Admin › Settings › Theme, administrators only;
     overrides `COMMERCE_THEME`, applied after boot); **staff preview** `?preview_theme={slug}` (session-scoped, staff only,
     `no-store`/`noindex`, never on /admin); `ThemeManager::addRoot()` (tests); theme.json `"settings_inherit": false`;
     `Pine\Commerce\Theme\Storefront` view helper; `commerce:theme:make` scaffolds `assets/css/theme.css`, which the
     default layout loads after its own CSS for every child theme.
   - The `view.finder` binding is not shared: runtime switches update `view()->getFinder()`. Middleware added after
     boot is registered through the HTTP kernel (`appendMiddlewareToGroup`), because the kernel re-syncs router groups.
   - Feature flags gate presenter output (`product_condition`, `spec_highlights`, `pay_in_3`, `product_brand`);
     `CatalogController` reads `commerce.catalog.ajax_header`, `ContactController` `commerce.forms.contact.prefix`.
6. **Importer – implementation notes, 2026-09-23.** Built as §12 with these documented differences (details in
   [IMPORTER.md](IMPORTER.md)):
   - `SeoProvider::termSeo(WpTerm $term, string $taxonomy, array $vars)` reads term meta from `WpTerm::$meta`;
     `MenuNode` has an optional `sortOrder`; `OrderSource` also has `storage()` and `refundCount()`;
     `ProductMapper::specRows()` is chained like `mapProduct()` (last non-null answer wins).
   - Extra built-in adapters: `RenderedTheme` (configurable content selectors, `content.selectors`), `ProductTags`
     (`product_tag` → attribute `tags`, the schema has no tag table); YITH + TI wishlists are one `Wishlists` adapter;
     brand/tag attributes are written by `Steps\TaxonomyAttributeStep`. Step keys: `extras.stock-alerts|wishlists|forms`,
     `content.elementor-css`; ordering is by section, then registration, then `after()`.
   - Uploads are copied only with `--copy-uploads` (opt-in) – there is no `--skip-files`. New options `--core-only`,
     `--no-wp-cli`, `--force`, `--skip`. `--dry-run` = the whole run in a rolled-back transaction.
   - Config additions: `settings.defaults` (applied before WooCommerce/adapter settings), `settings.woocommerce` (which
     WooCommerce-derived keys to write), `content.components/selectors/strip_shortcodes/keep_shortcodes`,
     `seo.rendered_fallback`, `menus.import_unassigned`, `media.*`, `elementor.css_path`, `adapters.enable`, and the
     `'key:capability'` disable form (e.g. `rank-math:redirects`).
   - Verified on the first client: a re-import into a fresh scratch schema was identical to the pre-platform
     importer's output (only `settings` row ids differed).
7. **Decoupling – implementation notes, 2026-09-27.** Controllers render contract names through `theme_view()`
   (= `View::first(['theme::…'])`; catalogue tries `catalog.shop|category` / `search.results` before
   `catalog.archive`, home tries `home` before `pages.home`); `Theme::bodyClass()` is called by Catalog/Search/Product
   controllers; home defaults/`blocks`/`menus`/`product`/`seo` are theme config (default theme: neutral home, empty
   menus) and the admin page builder reads the ACTIVE theme's schema; presenter subclassing (§9.3); shortcode registry
   (client names are aliases). Additions, all with neutral package defaults:
   - `ProductPresenter::cardPriceClass()` (core `card-price`); currency symbol/format from `commerce.currency` in
     `money()` and the presenter (`&pound;` is emitted via `htmlentities`).
   - `commerce.content.legacy_class_prefix` (core `wp-`) for generated legacy markup (`{p}legacy-toggle`,
     `{p}legacy-text`, `{p}fa`); `MobileMenu` treats any class containing `mnav-cta` / `mnav-help` as the drawer buttons.
   - `commerce.catalog.legacy_page_params` is read by the catalogue; `commerce.feeds.google.condition_map` (needle →
     g:condition); `condition_schema_map` package default names no client condition; `commerce.documents.logo` (print
     documents fallback logo; the phone fallback is `settings.defaults`).
   - Theme config `product.notify_anchor` (core `stock-notify`); the PayPal icon is the theme partial
     `checkout.partials.paypal-icon`; the contact page detects the contact form by a class containing `contact-form`.
   - Admin: the Condition / Brand side panel and list filters follow flags `product_condition` / `product_brand` and the
     configured attribute slugs (`ProductRequest::asideAttributes()`, role => slug).
   - Gate: `tests/Feature/NeutralInstallRenderTest.php` installs with the PACKAGE config + default theme into in-memory
     SQLite and renders every page type/fragment/email; strings that must never appear (a client's names) are passed
     in through `COMMERCE_NEUTRALITY_BANNED` (comma-separated) and `COMMERCE_NEUTRALITY_BANNED_REGEX`, so the package
     itself names no client. A client project can grep the installed package for its own names in its own tests.
   - Kept deliberately: the feature-flag key `pay_in_3` (public config API, generic instalment line) and
     WooCommerce/WordPress compatibility names (`woocommerce-*` classes, `[woocommerce_order_tracking]`), which are
     platform behaviour.
8. **Feature switches + extension API – implementation notes, 2026-09-28.**

   - **Switches** (`Pine\Commerce\Support\Features`: definitions, defaults, theme-support map, importer-step map):
     routes are gated by `Http\Middleware\RequireFeature` (`RequireFeature::for('blog')` = class middleware string):
     **route names/URIs stay registered and answer 404** when off (theme `route()` calls keep working, `route:cache`
     stays valid when a switch changes) – the "routes not registered" alternative was rejected for that reason. Admin
     routes check the switch only; storefront routes also need theme support (§9.2 helper). The `features` block is
     merged **key by key** (package default for a missing key) in `CommerceServiceProvider::register()`. New switches
     (all on by default): `coupons`, `guest_checkout`, `registration`, `reports`, `redirects`,
     `multi_shipping`. Also gated: sidebar entries + admin cross-links + dashboard messages card, sitemap blog
     entries (cache key per blog state), review JSON-LD, back-in-stock mails, core shortcodes, redirect rules,
     `wp_404_guess` (was not read before), `add_to_cart_query`, importer steps (skipped with a warning). Theme entry
     points are wrapped in **column-0** `@if (commerce_feature())` directives, which emit no whitespace, so existing
     output stays byte-identical. Settings › System lists every switch read-only (state, default, what it gates).
   - **Extension API** (`Pine\Commerce\Commerce`, state in the `Extensions\ExtensionRegistry` singleton):
     `gateway()` (PaymentManager = `commerce.payments.gateways` + registrations; Admin › Settings › Payments renders
     each gateway's `adminSettings()` – the Stripe/PayPal/BACS field definitions moved from `PaymentSettingsRequest`
     into the gateways unchanged; secrets of any gateway are never flashed), `shippingCalculator()` (fnmatch on the
     method code, first match wins, null hides), `adminMenu()` + `adminRoutes()` (queued until the app has booted, then
     registered with the package's prefix/names/middleware), `settings()` / `settingsFields()` (generic settings form;
     the `settings/{group}` routes now use a pattern and the controller 404s unknown groups, so late registrations and
     cached routes work), `dashboardWidget()`, `pageTemplate()` (admin builder + `$meta['view']` on the storefront),
     `menuLocation()`, `presenterMethod()` (ProductPresenter is `Macroable`), `facetSorter()`, `onOrderStatus()` /
     `onOrderPlaced()`, `orderEmail()` (replacement Mailables in `SendOrderStatusEmails`), `importAdapter()` /
     `importStep()`; `ThemeDefinition::composers()` (composers run only while their theme is in the active chain).
     Not built: `Commerce::userModel()` swapping beyond `auth.providers.users.model` (unchanged), per-gateway checkout
     JS (gateways may emit `checkoutHtml()`; the shipped themes' JS drives Stripe/PayPal only).
   - Tests: `tests/Feature/FeatureFlagsTest.php` (every switch on/off), `ExtensionApiTest.php` (every registry); all on
     the fresh in-memory SQLite install (`Pine\Commerce\Tests\Concerns\InstallsNeutralStore`, with SQLite shims for
     `DATE_FORMAT`/`CONCAT_WS`).
9. **Standalone package repository – implementation notes, 2026-09-28; updated 1.2.1.**
   - The package works on its own: its own `composer.json` (`composer validate --strict` passes; require-dev
     `orchestra/testbench ^11` + phpunit; scripts `test`, `validate-package`; stable minimum-stability; **no `version`
     key** – tags `vX.Y.Z` drive versions, `VERSION` file = `Commerce::VERSION` = CHANGELOG entry, checked by
     `tests/PackageRepositoryTest.php`), `phpunit.xml.dist`, `README.md`, `CHANGELOG.md`, `LICENSE` (MIT),
     `.gitattributes` (`/tests`, `/docs`, `/bin`, `phpunit.xml.dist` export-ignore), `.gitignore`.
   - Tests extend `Pine\Commerce\Tests\TestCase`, a `class_alias` to the client app's `Tests\TestCase` when it exists
     (inside a client: real `bootstrap/app.php`, client config, the app's guards) or to `StandaloneTestCase` (Testbench
     wired like the client skeleton: `Commerce::middleware/exceptions`, skeleton base migrations, `sqlite` `:memory:`
     default connection, `auth.providers.users.model` = `Pine\Commerce\Models\User`). Tests use
     `Commerce::userModel()`, never `App\Models\User`. The MySQL `scratch` tests skip standalone.
   - Docs live in the package (`docs/`) so they version and travel with it. `NEW-CLIENT.md` is a pointer to
     `PLAYBOOK.md` part 2.
   - `commerce:new-client --repo=<git url>` (VCS, `^1.0`) / `--path=<dir>` (path repository, `*@dev`) / `--constraint=`;
     `--vcs` / `--package` remain as aliases. The skeleton's `ClientServiceProvider` carries the
     `DB::prohibitDestructiveCommands()` guard and its `.gitignore` excludes `auth.json` and dumps.
   - Until 1.2.0 the package was developed inside the first client's repository and split out with
     `git subtree split`. From 1.2.1 it is developed directly in its own repository (`SetWebUK/ecom-core`, public, MIT);
     `bin/export-client-skeleton.sh` (in this repository) renders the skeleton repository. PLAYBOOK Part 1 and Part 3.
