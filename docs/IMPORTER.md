# WordPress / WooCommerce importer

`php artisan commerce:import-wordpress` moves a WooCommerce store into Pine Commerce: settings, media, customers,
catalogue, orders, reviews, coupons, shipping, stock alerts, wishlists, form submissions, pages, posts, menus and
redirects. It is **generic** (detects the site and its plugins) and **extensible** (client adapters in
`app/Import/…`). Design contract: [ARCHITECTURE.md §12](ARCHITECTURE.md#12-wordpress-importer).

- The source is opened **read-only** (MySQL session `READ ONLY`; nothing in the importer writes to it).
- Every row is matched on its WordPress id (or a natural key) and **updated in place** – run it as often as you like
  before go-live; the final run brings the delta.
- `--dry-run` runs everything and rolls it back; `--target=scratch` imports into `zz_` tables of the same database.

Code (in the package): `src/Import` (namespace `Pine\Commerce\Import`), command
`src/Console/ImportWordPressCommand.php`, package defaults `config/commerce-import.php`; the client overrides keys in
its own `config/commerce-import.php`.

---

## 1. Quick start

```bash
php artisan commerce:import-wordpress --wp-path=/path/to/wordpress --detect      # what was found
php artisan commerce:install --connection=scratch --no-interaction ...           # rehearsal target (zz_ tables)
php artisan commerce:import-wordpress --wp-path=/path/to/wordpress --target=scratch --dry-run
php artisan commerce:import-wordpress --wp-path=/path/to/wordpress --target=scratch --copy-uploads
#   … compare, fix config/adapters, repeat …
php artisan commerce:scratch:drop --force
php artisan commerce:import-wordpress --wp-path=/path/to/wordpress --copy-uploads   # the real import
```

`--wp-path` can live in `.env` as `WP_PATH` (`commerce-import.source.wp_path`).

## 2. Options

| Option | Meaning |
|---|---|
| `--wp-path=` | WordPress root. `wp-config.php` (in the root or one level up, like WordPress) is **parsed, never included**: comments are stripped with PHP's tokenizer, then `define('DB_*', '…')` and `$table_prefix = '…'` are read by regex. Non-literal values (`getenv()`, concatenation) are reported as unresolved – pass `--db-*` for them. Also used for `wp-includes/version.php` (version, regex), wp-cli permalinks and `--copy-uploads`. |
| `--db-host= --db-port= --db-name= --db-user= --db-pass= --db-socket=` | Explicit source DB; override wp-config and the base connection. |
| `--prefix=` | Table prefix. Default: wp-config's `$table_prefix`, else the base connection's prefix when `{prefix}options` exists, else **auto-detected**: every table `…options` whose `…posts` and `…postmeta` exist is a candidate, confirmed by a `siteurl` row; several → the command asks for `--prefix`. |
| `--target=` | Target connection (default: the default connection). The command switches `database.default` for the run, so models and query builders write there. MySQL/MariaDB or SQLite (a file database for rehearsals – PLAYBOOK part 2; the source is always the read-only MySQL `wordpress` connection). |
| `--only=` / `--skip=` | Comma lists of sections (`settings, media, users, catalog, orders, extras, content, menus, redirects`), step keys (`catalog.products`, `extras.forms` …) or aliases (`customers, products, categories, attributes, variations, pages, posts, blog, coupons, shipping, tax, reviews, forms, wishlists, stock-alerts, uploads`). A section/key also selects its sub-steps (`extras.shipping` includes `extras.shipping.saturday`). |
| `--dry-run` | Read, transform, report – the whole run is one transaction that is rolled back (no file copies either). Row data is unchanged; MySQL auto-increment counters may advance. |
| `--fresh` | Purge previously imported rows of the selected steps first (reverse order; only rows with `wp_id` / keys the step owns). On the default connection with imported data it needs `--force`. |
| `--copy-uploads` | Copy/verify `wp-content/uploads` (or `--uploads-path=`) into the public disk under `uploads/` – real copies, never symlinks (LiteSpeed). Same-size files are skipped as verified. Executables (`.php`, `.phtml`, `.htaccess` …) go to `storage/app/private/quarantine-uploads`, WooCommerce private downloads/logs (`woocommerce_uploads`, `wc-logs` …) to `storage/app/private/wp-uploads`; cache/backup dirs are skipped. With Elementor, `uploads/elementor/css/post-*.css` are copied to `commerce-import.elementor.css_path`. |
| `--orders-source=auto\|hpos\|posts` | Order storage (default from the site, §4). |
| `--site-url= --site-host=` | A running copy of the old site: page-builder content and rendered SEO are fetched over HTTP when no snapshot exists (one request at a time, 5 s timeout, cached in `storage/app/import/rendered/{host}/`). |
| `--snapshots=` | Directories of pre-rendered HTML (comma separated). File name = URL path with `/` → `__` + `.html`, `home.html` for the front page. |
| `--no-wp-cli` | Do not ask wp-cli for permalinks (URLs are then derived from settings/plugins, §5). |
| `--core-only` | Ignore the client config and adapters (package defaults only; source settings kept) – shows what a client without customisation gets. |
| `--detect` | Print the site profile, active plugins, adapters (active/kind/priority/capabilities) and the step order, then exit. |

Report: the summary table (WordPress count vs imported count + notes) and warnings are printed and written as JSON to
`storage/logs/import-wordpress-{Ymd-His}.log` (and `storage/logs/import-wordpress.log` = latest).

## 3. What is detected (`SiteProfile`)

`siteurl`/`home`, blog name, WordPress version (`version.php` when `--wp-path`, else from `db_version`), WooCommerce
version, active plugins (site + network), theme, `permalink_structure`, `woocommerce_permalinks` (product base incl.
`%product_cat%`, category/tag/attribute bases), timezone, front page / posts page, WooCommerce page ids, currency
(position, decimals, separators), tax settings, default/allowed countries, weight/dimension units, stock settings,
email sender, **order storage** (`woocommerce_custom_orders_table_enabled = yes` and `wc_orders` exists → `hpos`, else
`posts`; HPOS sync flag), the uploads URL (`upload_url_path` / `upload_path`, so CDN/custom upload URLs are rewritten
too) and the theme's `nav_menu_locations`.

## 4. Steps

Order (sections first, then registration order, then each step's `after()`):

```
settings → media.files (--copy-uploads) → media → [content.elementor-css] → users → catalog.categories →
catalog.attributes → catalog.products → catalog.variations → [catalog.brands, catalog.tags] → orders →
extras.reviews → extras.coupons → extras.shipping → extras.tax → [adapter extras: stock-alerts, wishlists, forms, client steps] →
content.pages → content.posts → menus → redirects
```

| Step | Source → target |
|---|---|
| `settings` | `settings.defaults` → WooCommerce options (keys in `settings.woocommerce`) → other SettingsProviders (GTM4WP, client) → SEO plugin site settings (`seo.*`, only where unset) → `orders.starting_number` (after the highest number issued) → `settings.seed` (wins). |
| `media` | Attachments whose file exists on the public disk → `media` (private attachments never). |
| `users` | WP users (administrator → admin, shop_manager/editor → manager, others incl. `customer` → customer; WP password hashes kept and verified on first login by `WpPassword`) + guests from `wc_customer_lookup` and order billing emails (read through the order storage – HPOS or posts), deduplicated by e-mail; default billing/shipping addresses from the latest order. |
| `catalog.categories` | `product_cat` → `categories` (path = hierarchical slugs = storefront URL), thumbnails, order, SEO, TermMapper columns. |
| `catalog.attributes` | global attributes + `pa_*` terms; `attributes.filterable` sets shop filters and their order. |
| `catalog.products` | in chunks of 500: simple/variable 1:1; **grouped/external → simple + warning** (schema has no such types; grouped children become related products `grouped`, the external URL is in the warning); images + gallery, categories, global + local attributes (local ones become global attributes), up-sells/cross-sells, SEO, ProductMapper columns (brand, cost, ACF, client), spec rows. Primary category = the category in the product's real source URL, else the SEO plugin's primary term, else the deepest category; breadcrumb category (`breadcrumb_category_id`, only when different) = the SEO plugin's breadcrumb term (Rank Math: primary, else first by name). |
| `catalog.variations` | `product_variation` → `product_variations`; `sort_order` = position per product by `menu_order`, then post ID (WooCommerce's order, 0, 1, 2 …); variable price = cheapest active variation. |
| `catalog.tags` | `product_tag` (no tag table in the schema) → non-filterable attribute `tags`. |
| `catalog.brands` | `product_brand` / `pwb-brand` / `yith_product_brand` → attribute `brand` (+ `products.brand` via the ProductBrands mapper). |
| `orders` | via `OrderSource` (HPOS or posts), in chunks: totals, addresses, payment, attribution meta, line items (+ variation options), shipping line, coupon codes, **fee and coupon lines in `orders.meta`** (no columns), refunds (+ refunded quantities), notes (`order_note` comments), a payment row per paid order. Numbers from the OrderNumberProvider, else the order id. |
| `extras.reviews` / `coupons` / `shipping` | rated product comments; `shop_coupon`; shipping zones (countries, states, continents – AF, AN, AS, EU, NA, OC, SA as WooCommerce's country lists, `Import\Support\WooCommerceContinents` – postcodes, rest of the world), methods by type (flat rate with formulas/class costs, free shipping rules, local pickup; plugin methods switched off + warning) and shipping classes on products/variations – with `commerce-import.shipping.zones` false the pre-v1.1 flat, country-limited list (formulas → 0 + warning). |
| `extras.tax` | WooCommerce tax classes and rates (+ postcode/city locations) → Settings › Tax, when `settings.woocommerce` is null or lists `tax.rates`. [TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md) §4. |
| `extras.stock-alerts` / `wishlists` / `forms` | back-in-stock plugins; YITH/TI wishlists; CFDB7 submissions. |
| `content.pages` / `content.posts` | body via rendered content providers (§6); system pages (Woo shop/cart/checkout/account + `content.system_pages`) keep no content; templates: front page `home`, posts page `blog`, `content.page_templates`; published pages whose URL a redirect plugin redirected become drafts. |
| `menus` | nav menus (`menus.by_term_id`, then theme locations via `menus.by_location`, then – `menus.import_unassigned` – every other menu as `wp-{slug}`), then MenuProvider trees. |
| `redirects` | redirect plugins (first rule per path wins; query-string rules become path rules when all variants agree), `_wp_old_slug`, and **permalink rules** for source URLs the storefront serves elsewhere (custom product/category bases, date-based post URLs); chains collapsed, loops dropped. `/product/{slug}/` and `/product-category/{path}/` are resolved by the storefront itself. |

Transactions: each step runs in its own transaction on the target connection; a failing step rolls back itself
only (earlier steps stay – rerun with `--only`).

## 5. URLs

The storefront serves categories at `/{hierarchical path}/`, products at `/{primary category path}/{slug}/`, posts
at `/blog/{slug}/`, pages at their hierarchical path. The importer computes the **source site's real URLs**
(`ImportContext::permalinks()`), merged per id from PermalinkProviders by priority, then settings:

1. `wp-cli` (100) – `wp post list`/`wp term list` with `--path` (run from the system temp dir); exact, includes every
   plugin; cached in `storage/app/import/wp-urls-{db}-{prefix}.json` and reused when wp-cli fails.
2. Permalink Manager Lite/Pro (60) – option `permalink-manager-uris`.
3. Premmerce Permalink Manager (50) – option `premmerce_permalink_manager` (category/product `slug|hierarchical|category_slug`),
   product category = SEO primary term, else the highest term id (the plugin's rule).
4. Settings (`PermalinkBuilder`) – `permalink_structure` tags (`%year% %monthnum% %day% %hour% %minute% %second% %postname% %post_id% %category% %author%`),
   WooCommerce bases, `%product_cat%` = SEO primary term, else WooCommerce's rule (parent DESC, term_id ASC).

They decide the primary category, the URL parity report ("all N product URLs match WP" or the differences) and the
permalink redirects.

## 6. Content

Order for page/post bodies (`Steps\Concerns\ResolvesContent`), first answer wins:

1. **Elementor** (100): rendered page → the Elementor document container (`data-elementor-id` = id); posts → the
   theme-builder post-content widget. Rendered = snapshot file, else HTTP via `--site-url`.
2. **WPBakery/Impreza** (50): `[vc_*]`/`[us_*]` flattened to semantic HTML (accordions → `<details>`, buttons, text blocks).
3. **Rendered theme** (30): the main content area by `commerce-import.content.selectors`
   (`[['open' => regex of the opening tag, 'require' => 'post-{id} ']]`; default: `div.page-content`, `.entry-content`).
4. Elementor data (`_elementor_data` text/heading/html widgets) when nothing was rendered.
5. `post_content` → ContentTransformers → leftover shortcodes stripped (`content.strip_shortcodes`, except component
   shortcodes and `content.keep_shortcodes`) → wpautop.

Every body is then cleaned: scripts removed, links to legacy hosts made relative (source hosts +
`legacy_hosts`), upload URLs → `/storage/uploads/…`, `content.replace` applied, `content.components` shortcodes →
`<div data-component="…">` placeholders the theme renders. SEO: plugin values; when the plugin generated the value
(`%excerpt%` …) the rendered `<meta name="description">`/`<title>` is used (`seo.rendered_fallback`).

## 7. Built-in adapters (`Pine\Commerce\Import\Adapters`)

| Key | Detect | Provides |
|---|---|---|
| `woocommerce` | plugin active (required for catalog/orders/extras) | SettingsProvider (store/tax/units/stock/email keys) |
| `rendered-theme` | always | RenderedContentProvider (selectors) |
| `wp-cli` | `wp` binary + `--wp-path` (or a cache file) | PermalinkProvider |
| `permalink-manager` | Permalink Manager Lite/Pro | PermalinkProvider |
| `premmerce-permalinks` | woo-permalink-manager(-premium) | PermalinkProvider |
| `rank-math` | seo-by-rank-math | SeoProvider (post/term meta, taxonomy default titles, primary terms, site settings), BreadcrumbTermProvider (breadcrumb category), RedirectProvider (`rank_math_redirections`, exact rules) |
| `yoast` | wordpress-seo(-premium) | SeoProvider (`_yoast_wpseo_*`, `wpseo_taxonomy_meta`, `wpseo_titles`, `%%var%%` converted), RedirectProvider (Premium) |
| `sequential-order-numbers` | WebToffee / SkyVerge / Custom Order Numbers / Booster, or their meta keys | OrderNumberProvider (`_order_number_formatted`, `_order_number`, `_alg_wc_custom_order_number`, `_wcj_order_number`; counters) |
| `acf` | ACF / ACF Pro / SCF | TermMapper + ProductMapper from `acf.term_fields` / `acf.product_fields` |
| `redirection` | plugin or `redirection_items` table | RedirectProvider (enabled groups, URL actions, position order) |
| `elementor` | plugin | RenderedContentProvider, step `content.elementor-css` |
| `wpbakery` | js_composer or `[vc_`/`[us_` in content | RenderedContentProvider |
| `product-brands` | brand taxonomy with terms | ProductMapper (`products.brand`), step `catalog.brands` |
| `product-tags` | `product_tag` terms | step `catalog.tags` |
| `cost-of-goods` | COG meta keys | ProductMapper (`cost_price`) |
| `gtm4wp` | plugin or `gtm4wp-options` | SettingsProvider (`tracking.gtm_id`) |
| `back-in-stock` | `cwginstocknotifier` posts or waitlist meta | step `extras.stock-alerts` |
| `wishlists` | `yith_wcwl` / `tinvwl_items` tables | step `extras.wishlists` |
| `cfdb7` | `db7_forms` table | step `extras.forms` |

Disable an adapter with `adapters.disable => ['yoast']`, one capability with `'rank-math:redirects'` (capabilities:
`seo, redirects, permalinks, content, transform, products, terms, settings, menus, order-numbers, steps`); force one
on with `adapters.enable`.

**Priority**: "first answer wins" capabilities (SEO, permalinks, rendered content, order numbers) take the highest
priority; chained capabilities (ProductMapper, TermMapper, SettingsProvider, ContentTransformer, spec rows) run
highest first, so the **lowest priority has the last word** – client adapters use a negative priority.

## 8. Configuration (`config/commerce-import.php`)

Top-level keys replace the package block (shallow merge) – copy the whole block you change.

| Key | Default | Purpose |
|---|---|---|
| `source.connection / wp_path / site_url / site_host / snapshots` | `wordpress`, `WP_PATH`, `WP_SITE_URL`, `WP_SITE_HOST`, `[storage/app/wp-reference/html]` | source defaults (CLI options override) |
| `legacy_hosts` | `[]` | extra hosts whose links become relative |
| `content.replace` | `[]` | ordered search → replace (e.g. undo a staging domain) |
| `content.components` | `['wp_sitemap_page' => 'sitemap']` | shortcode → theme component |
| `content.system_pages` | basket, cart, checkout, my-account, shop | pages served by routes |
| `content.page_templates` | `[]` | path/id → template |
| `content.selectors` | `null` (defaults) | rendered theme content areas |
| `content.strip_shortcodes / keep_shortcodes` | `true` / `[]` | raw post_content cleanup |
| `seo.rendered_fallback` | `true` | rendered meta as SEO fallback |
| `attributes.filterable / map` | `[]` / `[]` | shop filters; attribute → products column |
| `menus.by_term_id / by_location / import_unassigned` | `[]` / `[]` / `true` | nav menu mapping |
| `orders.source / meta_keys` | `auto` / `[]` | storage; extra meta into `orders.meta` |
| `media.disk / upload_urls / skip_dirs / private_dirs / private_path / quarantine_path` | `public` … | `--copy-uploads` |
| `elementor.css_path` | `null` → `storage/app/import/elementor-css` | Elementor CSS target |
| `acf.term_fields / product_fields` | `[]` | `field => column` or `['column' => …, 'format' => html\|text\|raw\|image\|bool\|decimal]` |
| `settings.defaults / woocommerce / seed` | `[]` / `null` (all) / `[]` | settings precedence (§4) |
| `adapters.extra / disable / enable` | `[]` | client adapters, switches |

## 9. Writing a client adapter

Put it in `app/Import/{Client}/`, extend `Pine\Commerce\Import\Adapters\AbstractAdapter` (binds `$this->ctx`) and
implement capability interfaces from `Pine\Commerce\Import\Contracts`:

```php
namespace App\Import\Acme;

use Pine\Commerce\Import\Adapters\AbstractAdapter;
use Pine\Commerce\Import\Contracts\ProductMapper;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\{SiteProfile, WordPressSource};

class AcmeCatalogAdapter extends AbstractAdapter implements ProductMapper
{
    public function key(): string { return 'acme-catalog'; }
    public function priority(): int { return -10; }                    // run after the core mappers
    public function detect(SiteProfile $site, WordPressSource $wp): bool { return true; }

    public function mapProduct(array $row, WpProduct $product, ImportContext $ctx): array
    {
        $row['subtitle'] = $product->meta['_acme_strapline'] ?? null;  // any `products` column
        $row['condition'] = $product->attributeValues()['pa_grade'] ?? null;

        return $row;
    }

    public function specRows(WpProduct $product, ImportContext $ctx): ?array
    {
        return null;                                                   // or list<[key,label,value,description]>
    }
}
```

Register it in the client provider – `Commerce::importAdapter(App\Import\Acme\AcmeCatalogAdapter::class);` (a step
that needs no detection: `Commerce::importStep(…)`, see [EXTENDING.md](EXTENDING.md#importer-adapters-and-steps)) – or in
config: `'adapters' => ['extra' => [App\Import\Acme\AcmeCatalogAdapter::class], …]`. Steps of switched-off features
(`commerce.features.*`) are skipped with a warning.

Other capabilities: `SettingsProvider::settings($ctx)`, `MenuProvider::menus($ctx)` (yield `Data\MenuTree` with
`Data\MenuNode` children – written depth-first, replacing the location), `RedirectProvider::redirects()` (yield
`Data\RedirectRule`), `SeoProvider`, `BreadcrumbTermProvider::breadcrumbTermId($post, $meta, $taxonomy, $terms)` (1.1:
the term the SEO plugin's breadcrumb showed – stored as `products.breadcrumb_category_id` when it differs from the URL
category; the Rank Math adapter returns the primary term, else the first category by name like `get_the_terms()`),
`RenderedContentProvider::renderedHtml(ContentItem)`,
`ContentTransformer::transform($html, $item)`, `TermMapper::mapCategory()`, `OrderNumberProvider`,
`PermalinkProvider::permalinks()`. Extra **steps**: return step classes/instances from `steps()`; a step extends
`Pine\Commerce\Import\Steps\AbstractStep` with `key()`, `section()`, `after()` (dependencies), `import()` and
optional `clear()` (for `--fresh`, only rows it owns). Use `$this->ctx->save($table, $rows, $key, $keepOnUpdate)` for
idempotent upserts, `$this->ctx->map('products')` for wp id → id, `$this->wp` (read-only source helpers:
`posts()`, `postMeta()`, `terms()`, `objectTerms()`, `option()`, `hasTable()`), `$this->ctx->rendered->html($path)`
for rendered pages, `$this->ctx->count()` / `warn()` for the report.

Typical client adapters (in `app/Import/{Client}`, registered with `Commerce::importAdapter()` or
`commerce-import.adapters.extra`):

| Adapter | What |
|---|---|
| `AcmeCatalogAdapter` | ProductMapper: client product columns (subtitle/condition/brand) + spec rows built from custom meta or snippets the old theme used |
| `RenderedSiteSettingsAdapter` | SettingsProvider: contact details, logos, top bar, footer copy from the rendered page-builder header/footer |
| `RenderedMenusAdapter` | MenuProvider: mega menu, mobile drawer, footer columns from the rendered home page |
| a step such as `extras.shipping.saturday` | a delivery method the old theme hard-coded, with its description |

plus configuration only: staging-domain `content.replace`, `legacy_hosts`, client shortcode components, menu term
ids, filterable attributes, ACF fields, static store details in `settings.defaults`, a built-in capability switched off
(e.g. `rank-math:redirects`).

## 10. Testing on the scratch connection

No extra database is needed: the `scratch` connection is the same database with a `zz_` table prefix.

```bash
php artisan commerce:install --connection=scratch --no-interaction --admin-email=dev@agency.test   # or: migrate --database=scratch
php artisan commerce:import-wordpress --target=scratch --dry-run        # counts; zz_ rows stay unchanged (one rolled-back transaction)
php artisan commerce:import-wordpress --target=scratch                  # real import into zz_ tables
# compare row counts + checksums of key columns against the expectation, then:
php artisan commerce:scratch:drop --force
```

Never run a trial import against the live database; `--fresh` there requires `--force`.

`--target` only switches `database.default` for the run (it never purges/reconnects the connection: a stale
connection object reconnecting would reset the live connection's transaction level and commit a `--dry-run`).
Services resolved before the switch keep the app's default connection – e.g. with `CACHE_STORE=database` the
`settings.all` cache key of the default connection is cleared by the settings step. The storefront works on the
prefixed scratch connection; several admin screens (dashboard, products, customers, reports) use raw SQL with
unprefixed table names and only work on an unprefixed database.

Automated tests (`tests/Import`, SQLite in memory – never MySQL): wp-config parsing, prefix
detection, permalink building (settings, Rank Math primary, Premmerce, Permalink Manager), HPOS vs posts order
sources yielding identical DTOs (3 orders, coupon + fee lines, a refund), adapter detection/disable/priority, step
ordering, `--copy-uploads` (quarantine, private dirs), Formatter configuration.

### Verifying a migration

Before go-live, compare a scratch import with what you expect: run the importer into a fresh scratch schema, then
compare row counts and checksums of key columns (products slug/price/stock/primary category path/SEO, category paths,
order numbers/totals/status, page paths/content, post content, redirects, media) – against the previous importer run
or the live tables (differences only where the admin changed data after the import). Re-running on the same tables
must leave counts and checksums unchanged (child rows are rebuilt with new ids); `--dry-run --fresh` must leave the
data unchanged. `--core-only` (no client adapters/config) shows exactly what the client customisations add.

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| "Cannot open the WordPress database" | check `--wp-path` / `WP_DB_*`; `--detect` shows where each credential came from; non-literal wp-config values need `--db-*`. |
| "Several WordPress installs … pass --prefix" | the database holds more than one install. |
| "WooCommerce is not active" | only `--only=settings,users,content,menus,redirects` can run. |
| Product/category URL differences in the report | wp-cli unavailable and a permalink plugin not covered: add a PermalinkProvider adapter or leave it to the generated redirects. |
| Page bodies empty or shortcode soup | page builder content needs a rendered copy: `--site-url` (+ `--site-host`) or `--snapshots`; tune `content.selectors`. |
| Media missing | run with `--copy-uploads` (or copy `uploads/` beforehand); the report lists missing attachment files. |
| Wrong order numbers | check `--detect` for `sequential-order-numbers`; add an OrderNumberProvider for other plugins. |
| A step failed | only that step was rolled back; fix and rerun `--only=<step>`. The log file has the exception. |
| Memory | products/orders are chunked; `memory_limit` is raised to 1 GB for the run. |
