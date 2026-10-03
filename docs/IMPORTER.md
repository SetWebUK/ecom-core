# WordPress / WooCommerce importer

`php artisan commerce:import-wordpress` moves a WooCommerce store into Pine Commerce: settings, media, customers,
catalogue, orders, reviews, coupons, shipping, stock alerts, wishlists, form submissions, pages, posts, menus and
redirects. It is **generic** (detects the site and its plugins) and **extensible** (client adapters in
`app/Import/…`). Design contract: [ARCHITECTURE.md §12](ARCHITECTURE.md#12-wordpress-importer).

- The source is opened **read-only** (MySQL session `READ ONLY`; nothing in the importer writes to it).
- Every row is matched on its WordPress id (or a natural key) and **updated in place** – run it as often as you like
  before go-live; the final run brings the delta. Since 1.5 the id lookups only see rows without an import source, so
  rows from a REST API import of another shop are never touched (§12.5); a real import records the site in the setting
  `import.wordpress.site_url`.
- `--dry-run` runs everything and rolls it back; `--target=scratch` imports into `zz_` tables of the same database.

**No access to the old database?** Since 1.5 the shop can also be pulled over HTTP from WooCommerce's REST API with
an API key (Admin › Import › WooCommerce API or `commerce:import-woo-api`) – see
[§12 Importing via the WooCommerce REST API](#12-importing-via-the-woocommerce-rest-api). It writes the same tables
through the same mappers (`Import\Mapping`); the database import remains the most complete route (passwords, plugin
data, menus, redirects).

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
| `orders` | via `OrderSource` (HPOS or posts), in chunks: totals, addresses, payment, attribution meta, line items (+ variation options), shipping line, coupon codes, **fee and coupon lines in `orders.meta`** (no columns), tax lines → `order_tax_lines` (1.5), refunds (+ refunded quantities), notes (`order_note` comments), a payment row per paid order. Numbers from the OrderNumberProvider, else the order id. |
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

---

## 12. Importing via the WooCommerce REST API

Since 1.5. For a shop whose database you cannot reach (another host, managed WordPress, no SSH): the platform reads
the live shop over HTTPS with a WooCommerce REST API key, page by page, and writes the same products, categories,
customers, orders … as the database importer – through the same mappers (`src/Import/Mapping`: `ProductRows`,
`ProductChildren`, `OrderRows`/`OrderWriter`, `CustomerRows`, `CatalogRows`, `ShippingTaxRows`). Code:
`src/Import/WooApi` (`Client`, `Importer`, `ApiMap`, `StoreApi`, `MediaDownloader`, `RunManager`), command
`src/Console/ImportWooApiCommand.php`, admin `Admin\WooApiImportController`. Nothing on the old shop is changed.

### 12.1 Create the API key (on the old shop)

1. WordPress admin › **WooCommerce › Settings › Advanced › REST API › Add key**.
2. Description "Pine Commerce import", **User**: an administrator (or shop manager), **Permissions: Read**.
3. **Generate API key** and copy the **Consumer key** (`ck_…`) and **Consumer secret** (`cs_…`) – WooCommerce shows the
   secret only once. Revoke the key in the same screen when the migration is finished.
4. Optional, for draft/private pages and posts: the same user's **Users › Profile › Application Passwords** → add one
   ("Pine import") and copy it. Without it only published pages and posts are read.

"Read" is enough for everything the importer does. The shop must be reachable over HTTPS (plain HTTP works with
OAuth 1.0a signatures, see 12.4) and its REST API must not be blocked by a security plugin or firewall
(Wordfence/Cloudflare rules for `/wp-json/`).

### 12.2 Run it from the admin

**Admin › Import** (sidebar footer; administrators only, feature switch `woo_api_import`):

1. **Connect** – shop address, consumer key and secret (stored encrypted with the app key – `Crypt` – and never shown
   again; an empty box keeps the saved value), authentication (automatic is right for almost every shop), TLS
   verification (switch off only for a staging shop with a self-signed certificate), optional WordPress user +
   application password. Or choose **Public catalogue only**: no key at all, the public Store API (12.6).
2. **Save & test connection** – shows the shop's name, WooCommerce/WordPress version and currency, how many products,
   categories, customers, orders, coupons, reviews, tax rates, shipping zones, pages, posts and images there are, and
   every permission problem per entity (e.g. a key without access to customers).
3. **Choose what to import** – categories, attributes & terms, products (variations, tags, images), customers
   (+ guests from orders), coupons, orders (refunds, notes, tax lines), reviews, shipping zones & tax rates, pages &
   blog posts, media library. Options: **download images**, **items imported before: update / leave alone**, **only
   orders placed from** a date, **only items changed since** (incremental re-sync, `modified_after`), **order notes**
   (one request per order), **same site as the database import** (12.5), **dry run** (reads and maps everything,
   counts what would be created/updated, writes nothing, downloads nothing).
4. **Start import** – the run starts in the background (a detached `php artisan commerce:import-woo-api {id}`, like
   Admin › Updates: no queue worker or cron needed) and the page follows it live: per entity read / created / updated /
   skipped / failed with a progress bar, warnings and errors with the shop's id of the item, and the log. You can
   leave the page. **Cancel** stops after the current page; **Resume** continues an interrupted, failed or cancelled run
   from its checkpoint (finished entities and pages are not read again). **History** lists every run with who started
   it; each run's log can be downloaded.

If the web server cannot start background processes (`proc_open` disabled), the page shows the command to run instead:
`php artisan commerce:import-woo-api {id}`.

### 12.3 Run it from the command line

```bash
php artisan commerce:import-woo-api --url=https://shop.example.com --key=ck_… --secret=cs_… --test         # connection test
php artisan commerce:import-woo-api --url=https://shop.example.com --key=ck_… --secret=cs_… --dry-run
php artisan commerce:import-woo-api --url=https://shop.example.com --key=ck_… --secret=cs_…
php artisan commerce:import-woo-api --only=products,orders --since=2026-09-01      # incremental re-sync
php artisan commerce:import-woo-api 12                                             # run / resume run #12
php artisan commerce:import-woo-api --store --url=https://shop.example.com         # public catalogue, no key
```

| Option | Meaning |
|---|---|
| `--url= --key= --secret=` | Shop and key. Fallbacks: `WOO_API_URL`, `WOO_API_KEY`, `WOO_API_SECRET` in `.env`, then the connection saved in the admin. |
| `--wp-user= --wp-password=` | WordPress application password for private pages/posts (`WOO_API_WP_USER` / `WOO_API_WP_PASSWORD`). |
| `--auth=auto\|basic\|query\|oauth` | 12.4. |
| `--insecure` | Do not verify the TLS certificate. |
| `--store` | Public Store API, no key (12.6). |
| `--only=` | `categories, attributes, products, customers, coupons, orders, reviews, shipping_tax, content, media` (aliases `shipping`, `tax`, `pages`, `posts`, `images`, `variations`, `tags`, `refunds`, `guests`). Default: everything. |
| `--dry-run` | Read + map, write nothing. |
| `--since=` | Only products, orders, coupons, pages and posts modified after this date (`modified_after`). |
| `--orders-after=` | Only orders placed after this date. |
| `--skip-existing` | Leave items imported before untouched (only new ones are added). |
| `--no-images` / `--no-notes` | Do not download images / do not read order notes. |
| `--same-site` | 12.5. |
| `--test` | Test the connection (store, versions, counts, permission problems) and exit. |

The command prints the per-entity table and the log path; it exits 0 only when the run completed.

### 12.4 Authentication, limits and safety

- **Authentication** (`auto` = Basic over HTTPS, OAuth over HTTP): **Basic** – the key and secret as HTTP Basic auth
  (HTTPS only); **query** – `consumer_key`/`consumer_secret` in the query string, for hosts (some CGI/FastCGI set-ups)
  that strip the `Authorization` header – the symptom is "401 … cannot list resources" with a correct key;
  **OAuth 1.0a** – one-legged HMAC-SHA256 signatures exactly as WooCommerce verifies them, the only mode WooCommerce
  accepts over plain HTTP. Basic and query auth are never sent over plain HTTP.
- **Pages** of 100 (WooCommerce's maximum, `commerce.woo_api.per_page`) following `X-WP-TotalPages`; a pause between
  requests (`delay_ms`, default 250 ms); retries with exponential backoff on 429 and 5xx and on network errors,
  honouring `Retry-After` (`retries`, `max_backoff`); per-request timeouts (`timeout`, `connect_timeout`). Sites
  without pretty permalinks are detected and read through `?rest_route=`.
- **SSRF guard** on every request (API and image downloads, redirects re-checked): only `http`/`https`, no
  credentials in the URL, and every address the host resolves to must be public – loopback, private, link-local,
  carrier-grade NAT, multicast and reserved ranges (IPv4, IPv6, IPv4-mapped) are refused unless
  `commerce.woo_api.allow_private_hosts` (`WOO_API_ALLOW_PRIVATE_HOSTS=true`, local testing only) is set. The
  connection is pinned to the checked address. API calls never follow redirects (the message names the address to use).
- **Images**: JPG, PNG, GIF, WebP and AVIF only – checked from the bytes, not the extension (SVG and anything else is
  skipped with a warning) – at most `max_image_kb` (10 MB), then processed like an admin upload (orientation, maximum
  size, the core image sizes). WordPress uploads keep their path (`uploads/2025/01/shirt.jpg` – where the database
  importer's `--copy-uploads` puts them, so links in page content resolve); each file is downloaded once
  (`media.source_hash` = sha1 of the URL; an identical file already at the path is reused).
- **Secrets** are encrypted at rest (settings `woo_api.key`, `woo_api.secret`, `woo_api.wp_password`), never sent
  back to the browser or flashed after a validation error, and masked in every log line and error message (the log
  shows the key as `ck_…1234`). The admin screens are for administrators only and every form carries the CSRF token.
- **Resumable**: each page is read completely (variations, refunds, notes, images), then written in one database
  transaction, then the checkpoint (entity + next page) and the progress are saved on the `woo_api_imports` row. One
  run at a time (lock file in `commerce.woo_api.path`, default `storage/app/private/woo-api-import`, which also holds
  the run logs). A run whose process disappeared is shown as *interrupted*.

Config (`config/commerce.php` → `woo_api`): `allow_private_hosts` (false), `per_page` (100), `timeout` (30),
`connect_timeout` (10), `delay_ms` (250), `retries` (4), `max_backoff` (60), `max_image_kb` (10240), `image_timeout`
(30), `path` (null), `user_agent`. Feature switch `features.woo_api_import` (true).

### 12.5 How records are matched (import sources)

Every row is matched on the shop's own id **within its import source** (column `import_source`, 1.5 migration):
rows of a REST API connection carry `woo:{host}`; the database importer's rows (and everything imported before 1.5)
have none. Product 42 of shop A therefore never overwrites product 42 of shop B, and a re-run updates in place – run
it as often as you like before go-live. Slugs/paths another shop's rows already use get a `-2` suffix and the old URL
a 301. Natural keys are shared: customers match on e-mail, coupons on their code, attributes on their slug, shipping
methods on their code.

**Same site as the database import**: when the shop was first imported with `commerce:import-wordpress` (which since
1.5 remembers the site in the setting `import.wordpress.site_url`) and you now re-sync it over the API, tick "This is
the shop this site was imported from" (pre-ticked when the host matches; CLI `--same-site`, also automatic). The API
import then updates those rows instead of adding a second copy.

### 12.6 What is imported

| Entity | From (wc/v3 unless noted) | Into / notes |
|---|---|---|
| Categories | `products/categories` (+ links from public `wp/v2/product_cat`) | hierarchy, image, description, order; path = hierarchical slugs; old category URL → 301 when the storefront serves it elsewhere; Yoast `yoast_head_json` SEO |
| Attributes | `products/attributes` + `/{id}/terms` | global attributes and terms (order kept); product-level attributes become global ones; tags → non-filterable attribute `tags` (as the database importer) |
| Products | `products` (`status=any`) + `products/{id}/variations` | simple + variable 1:1, **grouped/external → simple + warning** (grouped children become related products); prices, sale dates, stock, dimensions, tax status/class, featured, GTIN, brand (`brands`), shipping class, images + gallery, categories with the **primary category from the permalink** (URLs stay what they were; otherwise a 301), up-sells/cross-sells, SEO from Yoast (`yoast_head_json`) or Rank Math (`rank_math_*` meta, `%variables%` resolved; its headless `getHead` endpoint when switched on), else the first 30 words of the description. Variations removed on the shop are switched off, never deleted. |
| Customers | `customers` | accounts (role customer, **no password**), billing/shipping addresses; customers without an account are built from their orders' billing details (latest order wins), as the database importer does. WordPress staff accounts are not imported – create staff in Admin › Staff. |
| Coupons | `coupons` | as `extras.coupons` (unsupported discount types → fixed basket + warning) |
| Orders | `orders` (`status=any`) + `orders/{id}/refunds` + `orders/{id}/notes` | number (sequential-number plugins' formatted number), totals, addresses, payment, attribution meta, line items with variation options, shipping, **coupon and fee lines (orders.meta)**, **tax lines → `order_tax_lines`**, refunds with refunded quantities, notes (customer notes flagged), a payment record per paid order |
| Reviews | `products/reviews` (`status=all`) | approved and on-hold reviews, verified-owner flag |
| Shipping & tax | `taxes/classes`, `taxes`, `products/shipping_classes`, `shipping/zones` + `/locations` + `/methods` | as `extras.tax` / `extras.shipping` (continents expanded, flat-rate formulas and class costs, free-shipping rules, plugin methods switched off) |
| Pages, posts | `wp/v2/pages`, `wp/v2/posts` (+ `wp/v2/categories`, featured images via `_embed`) | rendered content cleaned like the database importer's (links to the old site relative, uploads → `/storage/uploads/…`, scripts removed); page paths from the permalinks, front page and posts page from the site settings; posts at `/blog/{slug}/` with a 301 from the old URL |
| Media | `wp/v2/media?media_type=image` | every image of the media library (for images in page content) |

**Public catalogue only (Store API, no key).** `wc/store/v1` shows what a visitor sees: published products that are
visible in the catalogue (and in stock, when the shop hides out-of-stock products), their categories and attributes
(empty categories/terms hidden), variations, prices (converted from minor units) and stock status (no quantities),
plus the public pages, posts and images. No customers, orders, coupons, reviews, shipping, tax, drafts, SEO fields or
stock quantities.

### 12.7 Limits compared with the database import

| | Database import (`commerce:import-wordpress`) | REST API import |
|---|---|---|
| Customer passwords | WordPress hashes kept – customers sign in as before | **Not available through the API** – customers set a new password with "Forgot password" (tell them before go-live) |
| Staff accounts | administrators/shop managers with their roles | not imported (create them in Admin › Staff) |
| Plugin data | adapters read plugin tables/meta (ACF, brands, cost of goods, wishlists, back-in-stock alerts, CFDB7 forms, Redirection rules, Permalink Manager …) | only what the plugin exposes in the REST API (e.g. Yoast `yoast_head_json`, Rank Math meta, WooCommerce brands); client import adapters (`ProductMapper`, `TermMapper` …) do not run |
| Menus, redirects plugins, settings | imported | not available (rebuild menus in Admin › Content › Menus; Settings by hand) |
| Page-builder content | rendered snapshots, Elementor CSS | the rendered HTML from `content.rendered` only |
| Media | `--copy-uploads` copies every upload | images only, downloaded one by one (slower); attachments of non-public post types (sliders, page blocks …) are not listed by WordPress' REST API |
| Product URLs | real permalinks (wp-cli, permalink plugins) | the permalink the API returns – equally exact |
| Speed | minutes | one request per 100 items + one per variable product + one per order (notes) + images: a shop with 1,000 orders and images takes roughly 15–30 minutes |
| Access needed | database (and files) | an HTTPS URL and a Read API key |

Use the database import when you can; use the API import for shops you cannot reach otherwise and for incremental
re-syncs of a running shop (`--since`, "only items changed since").

### 12.8 Troubleshooting

| Symptom | Fix |
|---|---|
| "The shop refused the API key" (401) with a correct key | the host strips the `Authorization` header: choose authentication "query string". |
| 403 on one entity in the connection test | the key's user cannot read it – use an administrator's key with Read permission. |
| "No WordPress REST API found" / not JSON | wrong address, or a security plugin/firewall blocks `/wp-json/` – allow it for this server's IP. |
| "points to a private or reserved address" | the guard refused a local/intranet shop; only for local testing set `WOO_API_ALLOW_PRIVATE_HOSTS=true`. |
| "The shop redirects its API to …" | enter that address (https, www) as the shop address. |
| TLS / certificate errors | a staging shop with a self-signed certificate: switch "Verify the TLS certificate" off. |
| The run stays "Waiting to start" | the web server cannot start background processes: run the command shown on the page. |
| "interrupted" | the process stopped (server restart, time limit): press Resume. |
| Images "not a JPG, PNG …" | SVG or a broken file – upload it in Admin › Content › Media if needed. |
| Many 429 warnings in the log | raise `commerce.woo_api.delay_ms`. |
