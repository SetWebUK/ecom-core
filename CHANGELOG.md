# Changelog

All notable changes to `pine/commerce` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/)
(what counts as public API and as a breaking change: `docs/UPGRADING.md`).

Every entry lists, where relevant: **Added / Changed / Fixed / Removed**, **config keys added** (with their defaults),
**theme contract changes**, **migrations**, and **client actions required** after `composer update pine/commerce`.

## [Unreleased]

## [1.5.0] - 2026-10-03

Import a WooCommerce shop over its REST API: no database access needed – a shop URL and a read-only API key. One
additive migration.

### Added
- **Admin › Import › WooCommerce API** (sidebar footer, administrators only, feature switch `woo_api_import`): shop
  address, consumer key + secret (encrypted with `Crypt`, never shown again – like the payment secrets), optional
  WordPress application password, authentication mode and TLS switch; **Save & test connection** (store name,
  WooCommerce/WordPress version, currency, items per entity, permission problems per entity); entity checklist
  (categories, attributes, products, customers, coupons, orders, reviews, shipping & tax, pages & posts, media) with
  options (download images, update or leave items imported before, orders from a date, only items changed since,
  order notes, same site as the database import, dry run); runs in the background as a detached
  `commerce:import-woo-api {id}` (no queue / cron, like Admin › Updates) with a live progress page (per-entity
  counters, warnings and errors with the shop's ids, log), cancel, resume from the checkpoint, history and log download.
- `php artisan commerce:import-woo-api [run] --url= --key= --secret= [--only=] [--dry-run] [--since=] [--store] …`
  (keys also from `WOO_API_URL` / `WOO_API_KEY` / `WOO_API_SECRET`); `--test` checks the connection.
- `Pine\Commerce\Import\WooApi`: HTTP client (wc/v3, wp/v2, Store API; `X-WP-TotalPages` pagination at 100 per page,
  polite delay, retries with backoff on 429/5xx honouring `Retry-After`, timeouts, TLS verification on by default,
  `?rest_route=` fallback), authentication by HTTP Basic (HTTPS), query string (hosts that strip `Authorization`) or
  OAuth 1.0a one-legged signatures (plain HTTP, as WooCommerce requires); SSRF guard on every request and image
  download (http/https only, no private/loopback/reserved addresses unless `commerce.woo_api.allow_private_hosts`,
  connection pinned to the checked address); image downloads into the public disk (images only by content, size
  limit, deduplicated by URL and content, core image sizes); SEO from Yoast (`yoast_head_json`) or Rank Math (meta,
  headless `getHead`), else a description excerpt; key-less "public catalogue only" mode via the Store API.
- Imported entities: categories (hierarchy, images, SEO, old URLs → 301), attributes + terms, tags, products (simple,
  variable + variations; grouped/external as simple + warning; primary category from the real permalink so URLs are
  kept), customers + addresses (no passwords) and guests from orders, coupons, orders (line items, shipping, fees,
  coupons, **tax lines → `order_tax_lines`**, refunds via `orders/{id}/refunds`, notes via `orders/{id}/notes`),
  reviews, tax classes/rates, shipping classes/zones/methods, pages and posts (wp/v2), the media library.
- `Pine\Commerce\Import\Mapping` (`ProductRows`, `ProductChildren`, `OrderRows`, `OrderWriter`, `CustomerRows`,
  `CatalogRows`, `ShippingTaxRows`) and `Import\Support\Upserter`: the row mapping and idempotent upserts of the
  database importer, now shared by both importers.
- `WooApiImport` model (table `woo_api_imports`).
- Docs: IMPORTER.md §12 "Importing via the WooCommerce REST API" (creating the key, admin and CLI, what is imported,
  limits compared with the database import, troubleshooting), PLAYBOOK step 6, ADMIN_UI.md, ARCHITECTURE.md §12.9.

### Changed
- Database importer: rows are matched on their WordPress id **within their import source** (`import_source` null),
  so it never touches rows an API import brought in from another shop (id maps, `--fresh` purges, counts).
- Database importer: order `tax` items are now written to `order_tax_lines` (per-rate tax in reports and invoices).
- Database importer: a real run remembers the source site (setting `import.wordpress.site_url`) so a later API
  re-sync of the same site updates those rows instead of duplicating them.

### Fixed
- Tests: the tax report test no longer fails between 23:00 and 00:00 UTC.

### Migrations
- `2026_10_03_000100_add_import_sources_and_woo_api_imports`: nullable indexed `import_source` (varchar 100) on
  `users, categories, attribute_values, products, product_variations, orders, pages, posts, media, tax_rates,
  shipping_classes, shipping_zones`; new table `woo_api_imports`. Additive; existing rows unchanged.

### Config keys added
- `commerce.features.woo_api_import` (`true`).
- `commerce.woo_api`: `allow_private_hosts` (`env WOO_API_ALLOW_PRIVATE_HOSTS`, false), `per_page` (100), `timeout`
  (30), `connect_timeout` (10), `delay_ms` (250), `retries` (4), `max_backoff` (60), `max_image_kb` (10240),
  `image_timeout` (30), `path` (null = `storage/app/private/woo-api-import`), `user_agent`, `credentials.url|key|secret|
  wp_user|wp_password` (`env WOO_API_URL` …, read by the command).

### Client actions required
- None beyond the usual update (`migrate` runs automatically in Admin › Updates). To use the API import: create a
  Read API key on the old shop (IMPORTER.md §12.1) and open Admin › Import.

## [1.4.0] - 2026-10-02

Separate sign-in and registration pages, and a new look for the default theme's account forms. No migrations, no
config changes.

### Added
- **Registration page** `GET /my-account/register/` (route `register.show`, theme view `auth.register`): only when
  registration is enabled (feature `registration` + Settings › "Customers can create an account", else 404); signed-in
  customers are redirected to `/my-account/` (or a safe `?redirect=`). `POST /my-account/register` (route `register`)
  is unchanged.
- Theme contract: `auth.register` (variables `registration: true`, `redirect: string`). A theme without it gets the
  default theme's view. Documented in THEMES.md ("Sign-in and registration pages") and ARCHITECTURE.md §8.
- Default theme: `auth/partials/shell` (centred card + notices, optional aside), `auth/partials/password` (show/hide
  button, strength meter with requirements, confirm-match hint) and `js/auth.js` (vanilla, data-attribute hooks,
  loaded only on these pages; everything works without JavaScript).

### Changed
- `/my-account/` for guests (`auth.login`) is a **sign-in page only** with a “Create an account” link to the register
  page (when registration is enabled). The URL, route names and form fields are unchanged.
- Every registration error (validation, email already registered, rate limit) now returns to the **register page**
  with old input (was `/my-account/`); a safe `redirect` (e.g. back to the checkout) is kept. Sign-in validation errors
  always return to `/my-account/` (or the checkout) instead of the previous URL.
- Default theme: new sign-in, register, lost-password and reset-password design (centred card, clear labels, inline
  errors with `aria-invalid`/`aria-describedby`, show/hide password, strength hint, success state).

### Fixed
- Default theme: the footer newsletter field no longer shows the email typed into another form after a validation
  error (it only refills from its own error bag).

### Client actions required
- Themes that override `auth/login.blade.php` should drop its register form, link to
  `route('register.show', ['redirect' => $redirect])` instead, and may add `auth/register.blade.php`. Without one the
  default theme's register view is used. Run `php artisan commerce:theme:publish` (new `js/auth.js`) and
  `php artisan commerce:theme:check`.

## [1.3.2] - 2026-10-02

### Fixed
- Default theme: Admin › Settings › Theme colours, fonts and corner radius now show on the storefront and checkout.
  The inline `--c-*` variables were printed before `css/app.css`, whose own `:root` defaults then won the cascade.
  Order is now: `app.css` defaults → theme settings → child theme `theme.css`. Regression test added.
- `commerce:theme:make`: the generated `theme.css` example uses the real variable names (`--c-primary`, not
  `--color-primary`).

### Added
- `SECURITY.md`: report vulnerabilities privately via GitHub's "Report a vulnerability".

### Client actions required
- None. Child themes that worked around the bug by setting `--c-*` in `theme.css` keep working (theme.css still loads last).

## [1.3.1] - 2026-10-02

A patch release from the first roll-out of Admin › Updates. No migrations, no config changes.

### Fixed
- Database backup: `mariadb-dump` is preferred over `mysqldump` when both exist (MariaDB 11 prints a "Deprecated
  program name" warning for `mysqldump`, which ended up in the update log).
- `.commerce-skeleton.json`: an empty hash list is written as `{}` (was `[]`).
- Update check: says why no skeleton release was found (`commerce:update:check` and the stored check result) instead
  of an empty value.

### Client actions required
- None. This is the first release that 1.3.0 clients can install from Admin › Updates.

## [1.3.0] - 2026-10-02

A minor release: **Admin › Updates** – the shop finds new platform releases by itself and an administrator installs
one with a click, after approving it with their password. Additive: one new table, one new feature switch (on), one
new scheduled task. Storefront output does not change.

### Added
- **Admin › Updates** (administrators only, sidebar footer; feature switch `updater`, default on):
  - **Update check** – the tags of the repository `composer.json` installs pine/commerce from (GitHub: public API,
    `git ls-remote --tags` as the fallback; other hosts: `git ls-remote`). Offers the newest stable release above the
    installed one **within the project's composer constraint**; newer majors (or releases outside the constraint) are
    listed as "requires a developer". The CHANGELOG.md sections in between are fetched from the new tag and shown,
    with **Client actions required** highlighted. "Check now" button, a daily scheduled check (core task
    `updates.check`, 06:15) – never an automatic install. Dashboard notice and a sidebar badge while an update is
    available.
  - **Approve & install** – password re-entry + confirmation; the run starts in the background
    (`php artisan commerce:update:run {id}` detached with `setsid`/`nohup` – no queue worker needed) and the page
    follows its log through a status endpoint. One run at a time (file lock). Steps: pre-flight (PHP CLI + composer
    found, HOME/COMPOSER_HOME set for web-started processes, disk space, writable directories, git tree warning,
    `commerce:doctor` baseline), database backup (`mysqldump` + gzip or a SQLite copy, outside `public/`, newest 5
    kept), composer.json/composer.lock saved, maintenance mode with a bypass secret for the approver,
    `composer update pine/commerce --with-dependencies` pinned to the approved version (`preferred-install` respected),
    `migrate --force`, `commerce:publish`, `commerce:theme:publish`, `optimize:clear` + `optimize`,
    `commerce:doctor --json` (new failures fail the update), `up`. **On any failure** composer.lock is restored,
    `composer install` brings the previous code back, assets are published again, caches rebuilt and the site comes
    back up; the database backup is kept and the log says how to restore it (never restored automatically).
  - **Skeleton file updates** – `.commerce-skeleton.json` records the skeleton release a project was created from
    (written by `commerce:new-client`, so also present in ecom-skeleton) with a hash of every file. The updater
    compares the baseline with the newest skeleton release for the installed core (shallow git checkouts) and
    classifies each changed file: safe (new, unchanged here, removed upstream) or needs a developer (changed here,
    deleted here …, with line counts and the upstream diff); `.env`, `composer.lock`, `themes/`, README/LICENSE are never
    touched. The administrator ticks the safe files (or all of them); overwritten files are backed up and the
    baseline follows.
  - **History** – every check, approval, update and skeleton apply with who, versions, files, status, timestamps
    and the full log (table `platform_updates`).
- Commands: `commerce:update:check [--json]`, `commerce:update:run [id] [--approve --yes]` (the CLI approves only with
  both flags; without an approved id it refuses), `commerce:skeleton:baseline [ref] [--detect] [--disabled] [--note=]`,
  `commerce:skeleton:check [--apply-safe --yes]`.
- `Pine\Commerce\Updater\*` (UpdateChecker, UpdateManager, UpdateRunner, ReleaseSource, Versions, Changelog,
  ComposerProject, DatabaseBackup, UpdateLock, Environment, Skeleton\*), the `ProcessRunner` interface
  (`SystemProcessRunner`; tests use a fake), model `PlatformUpdate`.
- `bin/export-client-skeleton.sh --tag`: tags the skeleton repository with the core version (pushed with `--push`).
- Dependency `composer/semver` ^3.4 (version constraints).

### Config keys added
- `features.updater` (true).
- `scheduler.tasks.updates.check` (true).
- `updater` block: `repository` (env `COMMERCE_UPDATER_REPOSITORY`, null = from composer.json), `default_repository`,
  `skeleton_repository` (`https://github.com/SetWebUK/ecom-skeleton.git`), `github_token`
  (env `COMMERCE_UPDATER_GITHUB_TOKEN`), `check` (true), `php_binary` (env `COMMERCE_PHP_BINARY`), `composer_binary`
  (env `COMMERCE_COMPOSER_BINARY`), `mysqldump_binary`, `git_binary`, `home` (env `COMMERCE_UPDATER_HOME`),
  `composer_home` (env `COMMERCE_COMPOSER_HOME`), `path`, `backup` (true), `backup_path`, `keep_backups` (5),
  `min_free_mb` (1024), `timeout` (900), `http_timeout` (10), `git_timeout` (120), `project_path`.
  Documented in `docs/EXTENDING.md` "Updates".

### Theme contract
- No changes.

### Migrations
- `2026_10_02_000100_create_platform_updates_table` – new table `platform_updates` (additive).

### Client skeleton (`stubs/client-skeleton`)
- New projects get `.commerce-skeleton.json` from `commerce:new-client`. The skeleton repository is tagged with the
  core version from this release on (`v1.3.0`).

### Client actions required
- After `composer update pine/commerce`: `php artisan migrate --force` (one new table), `commerce:publish` (admin
  CSS), `optimize`. A project on 1.2.x has no updater yet, so **this** update is done the usual way (PLAYBOOK 3.3);
  later releases can be installed from Admin › Updates.
- Optional: record the skeleton baseline once – `php artisan commerce:skeleton:baseline v1.3.0` (`--detect` to
  compare; `--disabled --note="…"` for a project that was not created from the skeleton).
- Optional: a project whose `config/commerce.php` overrides the whole `scheduler` block keeps working (the new task
  defaults to on); add `'updates.check' => false` to its `tasks` to switch the daily check off.
- Web servers that cannot start background processes (`proc_open` disabled): the approved update waits and the page
  shows the command to run on the server (`php artisan commerce:update:run {id}`).

## [1.2.1] - 2026-10-01

1.2.1 - public release: client/infrastructure references removed, MIT licence; no code behaviour changes.

The package repository (`SetWebUK/ecom-core`) is public from this release and starts with a clean history: one
commit, tag `v1.2.1`. Earlier tags (1.0.0 – 1.2.0) were published from the previous, private history and are not in
it; clients should require `^1.2`.

### Changed
- Licence: MIT (copyright SetWeb UK), `composer.json` `"license": "MIT"`. Previously proprietary.
- Docs, tests, comments and stubs name no client and no server: client-specific examples, migration records and
  verification records moved to the client projects they belong to; examples use `acme` / `example.test`.
  `docs/ARCHITECTURE.md` is the generic contract (the original migration plan, §14, is replaced by the generic
  regression check; §3 shows a client project layout).
- `docs/PLAYBOOK.md` Part 1 and Part 3, `docs/UPGRADING.md`, `README.md`: the package is developed directly in its
  own repository – no subtree split; a release is a tag in `ecom-core`; clients run `composer update pine/commerce`;
  local integration testing uses a temporary, uncommitted path repository.
- `tests/Feature/NeutralInstallRenderTest.php`: the strings a neutral install must never render are configurable
  (`COMMERCE_NEUTRALITY_BANNED`, comma-separated, and `COMMERCE_NEUTRALITY_BANNED_REGEX`; empty by default). The
  package-source grep moved to the client project that owns the banned strings.
- Neutral example data in an admin placeholder, a code comment and importer test fixtures.

### Added
- `bin/export-client-skeleton.sh` (export-ignored): renders `stubs/client-skeleton` into the base-system repository
  (`SetWebUK/ecom-skeleton`) from a clone of this repository, booting the package on Orchestra Testbench. The skeleton
  requires `^MAJOR.MINOR` of the current version from `https://github.com/SetWebUK/ecom-core.git` and carries the MIT
  `LICENSE`.

### Client skeleton (`stubs/client-skeleton`)
- `composer.json`: `"license": "MIT"`; `"preferred-install": "dist"` – the source-install rule for `pine/commerce`
  (1.0.1) is only needed for a private repository reached over SSH and is now documented instead (README, PLAYBOOK
  1.6). Existing clients keep whatever they have.

### Migrations
- None.

### Client actions required
- None. Optional: switch the VCS repository URL to `https://github.com/SetWebUK/ecom-core.git` (no credentials
  needed) and require `^1.2`.

## [1.2.0] - 2026-10-01

A minor release that closes four gaps listed under *Known limitations* in 1.1.0. Everything is additive; there are
no migrations. Storefront output does not change.

### Added
- **Client scheduled jobs** – `Commerce::scheduledTask($key, $options)` ([docs/EXTENDING.md](docs/EXTENDING.md)
  "Scheduled tasks"). The schedule is a cron expression or a Laravel frequency method (`'hourly'`, `'dailyAt:02:30'`,
  `'weekdays|dailyAt:09:00'`). The task runs a callable, an artisan command or a `Scheduling\Task` class, and can be
  guarded by a `feature`, `setting` or `skip` callback. Client jobs run like the core ones: Laravel's scheduler, the
  web fallback without cron, and `commerce:schedule:task {key}`. Their last result is recorded, and they are listed by
  `commerce:schedule:status` (marked `(client)`, `"source"` in `--json`) and in Settings › Scheduled tasks (badge
  "Custom"). `commerce.scheduler.tasks.{key}` switches one off. New: `Scheduling\ClientTask`,
  `Scheduler::tasks()` / `has()` / `task()`, `ExtensionRegistry::scheduledTasks()`. The skeleton's
  `ClientServiceProvider` and `ExtensionExamples` show the API.
- **Invoice PDF on the admin "Order details / invoice" email** – `Mail\Admin\CustomerInvoice` attaches the PDF when
  the order has an invoice under the existing numbering rules: an issued number, or a qualifying paid order (its
  number is issued when the email is sent); with numbering off, a paid order. An unpaid order gets no PDF and no
  number. The send modal on the order page, the success message and the order note say whether the PDF was
  attached. New setting Settings › Invoices › "Attach the invoice PDF to the Order details / invoice email"
  (`invoices.attach_customer_invoice`), `Invoices::emailAttachment()`, `CustomerInvoice::invoiceAttachment()` /
  `attachmentNote()`.
- **Product CSV: per-variant tax class and shipping class.** Variant rows export their own `tax_class` (`parent` =
  same as the product, as in WooCommerce) and `shipping_class` (empty = same as the product). The import reads both on
  variation rows, including WooCommerce's `Tax class` / `Shipping class` columns and the `variant_*` / `variation_*`
  aliases. `standard` is kept as an override, and a missing shipping class is created only with "create missing".
- **Importer: shipping zones by continent** – every WooCommerce continent (AF, AN, AS, EU, NA, OC, SA) now expands to
  the countries WooCommerce assigns to it, using a static copy of WooCommerce's continents list
  (`Import\Support\WooCommerceContinents`). Unknown codes still warn.

### Fixed
- Importer: zones defined by a continent other than Europe were skipped with a warning.
- Importer: the Europe list now follows WooCommerce exactly, so `ShippingStep::EUROPE` includes Turkey and leaves
  out Cyprus, which WooCommerce lists under Asia.

### Config keys added (package defaults = new-client defaults)
- `invoices.attach.customer_invoice` true. If a client `invoices.attach` block does not have this key, the
  attachment is off.

### Migrations
- None.

### Client actions required
1. `composer update pine/commerce`, then `php artisan commerce:publish`, `php artisan commerce:theme:publish` and
   `php artisan optimize:clear && php artisan optimize`.
2. If your `config/commerce.php` has its own `invoices.attach` block, add `'customer_invoice' => true` or `false`.
   A client that sets `false` keeps its 1.1 behaviour until the owner switches the attachment on in
   Settings › Invoices.
3. Optional: move client jobs from `routes/console.php` to `Commerce::scheduledTask()` so they get the web fallback
   and show up in the status table.
4. Scripts that read the product CSV export should expect `tax_class` / `shipping_class` values on variant rows.

### Known limitations (still open)
- Grouped and external WooCommerce products are rejected per row. Images: `max_dimension` replaces the original in
  place. The sale-price job covers simple products only. Packing slips have no thumbnails.
- Client scheduled tasks use the store timezone and cannot use `between()`, sub-minute frequencies or
  `onOneServer()`. Use `Schedule::` in `routes/console.php` for those.

## [1.1.0] - 2026-09-30

A minor release: everything is additive. Six areas – VAT done properly, shipping zones, image resizing, full product
CSV import/export, scheduled jobs with abandoned-cart reminder emails, and PDF invoices – plus 16 bug fixes. Every
new behaviour has a config key or owner setting; a client that copies the "keep today's behaviour" blocks listed
under *Client actions required* renders exactly as on 1.0.x. The package defaults switch the new features **on** for
new clients (marked *new-client default* below).

### Added

**Tax (VAT)** – [docs/TAX-AND-SHIPPING.md](docs/TAX-AND-SHIPPING.md)
- Tax classes and rates, Admin › Settings › Tax (options, classes, per-class rate table, CSV import/export in
  WooCommerce's format).
- Prices entered with or without VAT and shown with or without VAT (shop and basket separately); rounding per line or
  per order; compound rates and priorities; rates by country, county, postcode pattern or city; re-pricing for
  customers whose rate differs from the shop's (`adjust_non_base_prices`); shipping tax class ("same as the items" or
  a fixed class); tax status per delivery option; price suffix.
- Tax per order line and per rate (new table `order_tax_lines`); the breakdown is shown on receipts, emails, the admin
  order page, printed and PDF invoices, and reports (new "Tax by rate" card + CSV).
- Products: tax status, tax class, shipping class; variations: tax class.
- Importer: WooCommerce tax settings, classes and rates (new step `extras.tax`).

**Shipping zones**
- Zones matched on country/region/postcode (patterns shared with tax rates: exact, UK outcodes, `BT*`, `HS1-HS9`,
  `10000...19999`, `!` to exclude), drag-to-reorder list, zone and method editors, shipping classes, countries you
  sell to (Admin › Settings › Shipping).
- Delivery option types: flat rate (per order / item / shipping class, formulas like `5 + 1.50 * [qty]`), free
  shipping (minimum amount and/or coupon), weight bands, price bands, local pickup.
- Checkout re-prices delivery and tax from the address as it is typed (POST `/checkout/update`); "we don't deliver
  to …" message when nothing applies.
- `commerce:install` seeds a UK zone with free delivery and UK VAT rates 20 % / 5 % / 0 % (*new-client default*).
- Importer: WooCommerce zones, methods and shipping classes (`extras.shipping`).

**Image resizing** – [docs/THEMES.md](docs/THEMES.md) "Images"
- Configurable sizes (`commerce.images`) generated on upload, WordPress-style names `file-WxH.ext` plus WebP twins;
  Imagick or GD; EXIF orientation fix, `max_dimension` cap, metadata stripped (colour profile kept), never upscales,
  animated GIFs left alone, originals never overwritten.
- `media_url($path, $size)` (unchanged without `$size`), `image_srcset()`, `Media::sizeUrl()/srcset()/variants()`,
  Blade component `<x-media-image>` (`<picture>` + WebP, `srcset`/`sizes`, width/height, lazy loading).
- `commerce:images:generate {--missing} {--size=*} {--path=} {--scan} {--no-webp} {--dry-run} {--chunk=100} {--limit=}`.
- `commerce:doctor` check "Image sizes".

**Product CSV import/export** – [docs/PRODUCT-CSV.md](docs/PRODUCT-CSV.md)
- Admin › Products › Import / Export: export all or the filtered list (variants as their own rows, UTF-8 BOM,
  formula-injection guard); import with header auto-mapping (own format and WooCommerce product exports), dry run,
  batched resumable runs driven by the page (no queue worker), per-row CSV report, image download into the media
  library with de-duplication (public hosts only).
- Options: update existing (match by SKU or ID), create missing categories/attributes, download images, keep or clear
  empty cells.
- Commands `commerce:products:export` and `commerce:products:import {file}`.
- Feature switch `product_csv` (default on).

**Scheduled jobs and abandoned-cart reminders**
- `Pine\Commerce\Scheduling\Scheduler` registers six jobs with Laravel's scheduler: `orders.cancel-unpaid` (5 min),
  `carts.abandoned-emails` (10 min), `stock.back-in-stock` (hourly), `catalog.sale-prices` (5 min),
  `maintenance.prune` (03:40), `inventory.low-stock-email` (07:00, owner setting, off).
- Cron heartbeat (`scheduler.last_run` setting) and last result per job (`scheduler.task.{key}`); web fallback for
  sites without cron (*new-client default*: on); `commerce:schedule:status [--json]`, `commerce:schedule:task {task}`,
  `commerce:doctor` cron check, Admin › Settings › Scheduled tasks.
- Abandoned-cart reminder emails (Admin › Settings › Abandoned carts, **off** until the owner switches them on):
  marketing-consent or everyone, up to three steps (1 h / 24 h / 72 h, third off) with subject, intro and optional
  single-use, email-bound discount code; signed "Return to my basket" link that restores the basket and opens the
  checkout; unsubscribe page + one-click unsubscribe headers; no tracking pixels.
- Abandoned checkouts list: Reminders column, reminders sent / recovered baskets / recovered revenue; basket detail
  page with timeline and "Stop reminders"; dashboard card.
- Routes `cart.recover` (`GET basket/restore/{cart}`), `cart.recover.unsubscribe` (`GET`/`POST basket/unsubscribe/{cart}`),
  `admin.carts.show`, `admin.carts.stop`; `Cart::restore()`; listener `MarkRecoveredCart`; mailables
  `AbandonedCartReminder`, `LowStockReport`.

**PDF invoices** – [docs/INVOICES.md](docs/INVOICES.md)
- PDF invoices and packing slips (`dompdf/dompdf` ^3.1, DejaVu Sans embedded; remote files, PHP and JavaScript off in
  the renderer). Templates `pdf.invoice` / `pdf.packing-slip`, overridable by the client or theme.
- Sequential invoice numbers separate from order numbers (`orders.invoice_number` unique, `orders.invoice_date`,
  `sequences` table): prefix/suffix/padding with `{Y}`/`{y}`/`{m}`, forward-only next number, issued on paid or
  completed by the `AssignInvoiceNumber` listener (runs before the order emails); race-safe, never reused.
- Admin › Settings › Invoices; per-order Invoice PDF / Packing slip PDF / Regenerate; bulk merged PDF or ZIP for up to
  200 orders (`admin.pdf`); Download PDF on the HTML print pages.
- Invoice PDF attached to the "order received" (processing) and "order completed" emails (each switchable).
- Customer downloads: `account.order.invoice` (signed-in owner) and `checkout.invoice` (guest, order key; follows
  `OrderAccess`).
- `OrderEmail::invoiceEmailKey()`.

**Other**
- 410 Gone redirect rules (admin form and CSV import); theme view `errors.410` (falls back to `errors.404`).
- Order-received billing-email check (theme view `checkout.verify-email`, route `checkout.thankyou.verify`),
  `Services\Checkout\OrderAccess`.
- Menu `{store.*}` tokens in labels and links.
- `Support\Sql` (`col` / `table` / `qualify`) for table-prefix-safe raw SQL.
- `Import\Contracts\BreadcrumbTermProvider` (Rank Math primary term); `products.breadcrumb_category_id`.
- Theme config `seo.description_limit` (0 = no limit).
- Settings screens: read-only panels; settings groups tied to a feature return 404 while it is off.
- Docs: `TAX-AND-SHIPPING.md`, `PRODUCT-CSV.md`, `INVOICES.md`; cron, tax/shipping, invoices, abandoned carts,
  product CSV and image steps in `PLAYBOOK.md`; 1.0 → 1.1 in `UPGRADING.md`.

### Changed
- Checkout and admin orders use tax rates and zones; shop prices follow the display setting. Order lines are stored
  without tax (like WooCommerce) – unchanged for stores without VAT.
- The shipping calculator extension (`Commerce::shippingCalculator()`) also receives `postcode` and `zone`.
- Settings › Checkout: the "VAT rate" field is replaced by Settings › Tax; "Invoice footer" moved to Settings ›
  Invoices (same key `documents.invoice_footer`).
- Postcode validation outside the UK.
- Admin uploads are rotated, capped and get every configured size plus WebP twins; upload names avoid clashes with
  generated files (`-WxH` in a name becomes `-W-H`); deleting media removes its variants
  (`ImageGenerator::deleteVariants()`); `<x-admin.thumb>` uses a sized image. The protected
  `MediaUploadController::makeThumbnail()` was replaced by `storeImage()` / `fileDetails()`.
- Default theme: responsive images everywhere (product cards, gallery, quick view, home, blog); product-card hover
  CSS uses `.has-alt`; `picture { display: contents }`; the variation gallery swap removes `<source>`/`srcset`/`sizes`.
- `CheckoutService::cancelStaleOrders()` uses the shared `CancelUnpaidOrders` job and skips itself while cron runs.
- The HTML print invoice uses the invoice number and date when numbering is on.
- A registered customer's order-pay page requires that customer's login; the guest invoice PDF follows `OrderAccess`.
- Category/shop page numbers past the end 301 to the last page (search stays 404).
- `money()` puts the minus sign first (`-£5.00`).
- Meta descriptions are no longer truncated at 300 characters (cap with `seo.description_limit`).
- Imported variations are numbered 0..n per product; an admin save keeps the stored order; `Product::variations()`
  breaks ties by id.
- Products list: "Import / Export" button and menu entry (while `product_csv` is on).

### Fixed
- Signed URLs never validated on sites with trailing slashes (`SlashUrlGenerator::hasCorrectSignature()`).
- Item refunds include the item's tax.
- Review replies are shown under the review.
- Draft/private product URLs return 404 for guests (staff see a preview banner) instead of redirecting.
- Removed coupons say why.
- `previous()` / `back()` keep the trailing slash (no extra 301).
- Deleting a user deletes their password-reset token.
- Marking a bank-transfer order paid confirms its pending payment row.
- The back office works on a table-prefixed database connection.
- Pre-release onboarding rehearsal fixes: the default theme's checkout summary no longer ends "Includes £50.00 VAT 20%,"
  when the basket also holds zero-rated goods; invoice PDFs show the net amount per VAT rate (and a 0% line for
  zero-rated goods) for orders placed with v1.1 tax rates; on inclusive-price orders delivered outside the shop's
  country the invoice's unit price is the price charged (VAT taken off), not the UK catalogue price.
- Docs: `php -S` with Laravel's router must run from `public/` (PLAYBOOK); the skeleton's `.env.example` says the log
  mailer needs `LOG_LEVEL=debug`; PLAYBOOK part 2 describes rehearsing the v1.1 features.

### Config keys added (package defaults = new-client defaults)
- `tax.*`: `enabled` true, `prices_include_tax` true, `display_shop` `incl`, `display_cart` `incl`, `rounding`
  `line`, `based_on` `shipping`, `shipping_taxable` true, `shipping_tax_class` `inherit`,
  `shipping_prices_include_tax` `''`, `adjust_non_base_prices` true, `price_suffix` `''`, `label` `VAT`,
  `install_rates` `uk`. Admin › Settings › Tax overrides each.
- `shipping.install_zones` true.
- `images.*`: `generate_on_upload` true, `driver` `auto`, `sizes` (thumbnail 150×150 crop, card 400, medium 800,
  large 1600), `webp` true, `picture_webp` true, `quality` (jpg 82, webp 80, avif 60, png 8), `auto_orient` true,
  `max_dimension` 2560, `strip_metadata` true. A missing sub-key falls back to the package default.
- `product_csv.*`: `chunk_size` 25 (cap 200), `time_budget` 20, `max_rows` 10000, `max_file_kb` 20480,
  `max_image_kb` 10240, `image_timeout` 15, `url_guard` true, `keep_days` 14, `disk` `local`.
- `features.product_csv` true.
- `scheduler.*`: `enabled` true, `tasks` (every job true), `web_fallback` true, `heartbeat_minutes` 5.
- `invoices.*`: `numbering` true, `prefix` `INV-`, `suffix` `''`, `padding` 5, `start` 1, `assign_on` `paid`,
  `attach.customer_processing` true, `attach.customer_completed` true, `customer_download` true, `paper` `a4`,
  `cache` false. Settings › Invoices overrides each.
- `commerce-import.shipping.zones` true.
- Owner settings (Admin, stored in the database): `abandoned_carts.emails_enabled` (off), `.consent` (marketing),
  `.max_age_days` (7), `.min_value` (0), `.step{1-3}.*`; `scheduler.low_stock_email` (off),
  `scheduler.cart_retention_days` (90, 0 = keep all).

### Theme contract changes (all optional; themes that ignore them keep working)
- New views: `checkout/verify-email` (**required** for themes not based on `default`), `errors/410` (falls back to
  `errors/404`), `cart/unsubscribe`, `pdf/invoice`, `pdf/packing-slip` (core fallbacks).
- `Cart::totals()` keeps its keys (amounts without tax) and adds `*_display`, `tax_lines`, `display_incl`; post the
  address fields to `/checkout/update` so zones and tax follow the customer.
- Show `$review->reply`, a banner when `$product->status !== 'published'`, and `session('account_notice')` on the
  login page.
- Images: use `<x-media-image>` or `media_url($path, $size)` + `image_srcset()`; JS that swaps gallery images must
  remove `<source>` elements and `srcset`/`sizes`.
- Invoice links: the default theme links the PDF from View order and the thank-you page when customer download is on.
- `seo.description_limit` theme config key.

### Migrations (all additive; existing rows stay valid)
- `2026_09_30_001000_add_breadcrumb_category_to_products` – nullable `products.breadcrumb_category_id`.
- `2026_09_30_120000_add_abandoned_cart_recovery` – nullable `carts.recovery_stopped_at`, `recovery_stop_reason`,
  `recovered_order_id`, `recovered_at`; tables `cart_recovery_emails`, `email_unsubscribes`.
- `2026_09_30_120000_add_tax_rates_and_shipping_zones` – tables `tax_classes`, `tax_rates`, `shipping_classes`,
  `shipping_zones`, `order_tax_lines`; new columns on `shipping_methods`, `products`, `product_variations`, `carts`,
  `orders`, `order_items`. Data: an old `tax.rate` setting becomes one equivalent rate row (prices exclude tax);
  existing delivery options move into one zone with unchanged totals.
- `2026_09_30_130100_add_source_hash_to_media` – nullable, indexed `media.source_hash`.
- `2026_09_30_150500_add_invoice_numbers_to_orders` – nullable unique `orders.invoice_number`, `orders.invoice_date`;
  table `sequences`.

### Client actions required
1. `composer update pine/commerce` (pulls in `dompdf/dompdf` ^3.1; `ext-zip` optional – without it bulk ZIP falls
   back to one merged PDF), then `php artisan migrate --force`, `php artisan commerce:publish`,
   `php artisan commerce:theme:publish`, `php artisan optimize:clear && php artisan optimize`.
2. `php artisan commerce:images:generate --missing` (add `--scan` for files without a media-library row) so existing
   media gets the new sizes. A client with its own `images` block keeps its sizes.
3. Add the cron line (recommended):
   `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` – check with
   `php artisan commerce:schedule:status`. Without cron, the web fallback (package default on) runs due jobs after
   storefront requests; a client config with its own `scheduler` block decides that itself.
4. **To keep 1.0 behaviour exactly**, add to `config/commerce.php` (a like-for-like client does all of these):
   a `tax` block with `prices_include_tax` false, `display_shop`/`display_cart` `excl` and `install_rates` `none`;
   `shipping.install_zones` false; `invoices` with
   `numbering` false, both `attach.*` false and `customer_download` false; an `images` block with only the 150×150
   thumbnail, `webp`/`picture_webp`/`auto_orient` false and `max_dimension` null; `scheduler.web_fallback` false.
   Without them the new-client defaults apply.
5. A client that overrides the top-level `shipping` key adds `install_zones`; set
   `commerce-import.shipping.zones` false to keep the pre-1.1 flat shipping import.
6. Custom themes not based on `default`: add `checkout/verify-email`; drop any hard 300-character meta description
   cut (or set `seo.description_limit`); optionally adopt the theme contract additions above
   (`php artisan commerce:theme:check`).
7. Client code with raw SQL names tables through `Pine\Commerce\Support\Sql`.
8. Owners: review Admin › Settings › Tax, Shipping, Invoices, Scheduled tasks and Abandoned carts (reminders stay off
   until switched on; check the consent choice against the privacy policy).

### Known limitations
- WooCommerce zones by continent are imported for Europe only; variant-level shipping/tax class is not in the product
  CSV; grouped/external WooCommerce products are rejected per row.
- Images: `max_dimension` replaces the original in place (no `-scaled` copy).
- Clients cannot register their own scheduled jobs yet; the sale-price job covers simple products only.
- The admin "Order details / invoice" email does not attach the PDF; packing slips have no thumbnails.

## [1.0.1] - 2026-09-30

### Fixed
- Client skeleton: `pine/commerce` is installed from **source** (git clone over SSH) instead of a GitHub API zip
  (`config.preferred-install`: `{"pine/commerce": "source", "*": "dist"}`), so a server with only an SSH
  deploy key can install the private package. Previously `composer install` failed with a 404 on
  `api.github.com/…/zipball` unless a GitHub token was configured.

### Client actions required
- Existing clients using an SSH key only: add the same `preferred-install` block to `composer.json` `config`.

## [1.0.0 – also included in the v1.0.0 tag]

### Fixed
- SQLite projects (development, CI, import rehearsals): the storefront search suggestions (`CHAR_LENGTH`) and the
  admin dashboard/reports (`DATE_FORMAT`) no longer fail. `Support\SqliteFunctions` registers the MySQL functions the
  package's SQL uses on every SQLite connection (`CONCAT`/`CONCAT_WS` too below SQLite 3.44); MySQL is unchanged.
- Default theme: JSON-LD (`seo`, `breadcrumbs`, home/FAQ) printed compiled PHP instead of `"@context"` – Blade
  compiled it as Laravel's `@context` directive. Themes that write `'@context'` inside `{!! … !!}` must escape it as
  `'@@context'` (or build the array in `@php`).
- Importer: the "redirect shadows a live URL" warning describes the resolver correctly (the redirect wins).
- Feature switches applied everywhere they are documented to apply: `legacy_content` off now also skips the
  WPBakery/Impreza shortcode clean-up, the Font Awesome → SVG replacement and the Elementor wrapper (not only the
  per-page Elementor CSS); `product_condition` / `product_brand` off now also drop the condition / brand group from
  the shop filters (`Facets::filters()`), ignore `?condition=` / `?brand=` in Admin › Products and leave the
  Condition / Brand columns out of the product CSV export. Nothing changes while the switches are on.

### Changed
- Client skeleton: `config/commerce-import.php` lists `legacy_hosts` (the importer's list of old host names); the
  `config/commerce.php` comment says what its own `legacy_hosts` does (menus at render time).
- Docs: PLAYBOOK rehearsal on SQLite without GitHub (Part 2), local bare repositories (§1.8), `legacy_hosts` before
  the first import, wp-config precedence, daily log file names, new troubleshooting rows.

**Client actions required:** none. New clients: fill both `legacy_hosts` lists before the first import.

## [1.0.0] - 2026-09-28

First release as a standalone package. Extracted from the first client project (a WooCommerce store rebuilt
like-for-like), with every client-specific value moved out to client config, theme and code.

### Storefront
- Catalogue: shop, category, brand and attribute archives with AJAX filtering (facets, price range, sort, pagination),
  search, product pages (simple + variable products, galleries, specifications, related products), quick view.
- Basket and checkout: guest or account checkout, coupons, delivery methods (flat rate, free over a threshold,
  per-method rules, multiple options), terms acceptance, order-received page, session-expiry (419) recovery that
  keeps what the customer typed.
- Payments: Stripe (webhooks), PayPal (REST, webhooks) and bank transfer (BACS); keys stored encrypted in the
  database (Admin › Settings › Payments); more gateways through `Commerce::gateway()`.
- Customer accounts: register/login (imports WordPress password hashes as-is), orders, addresses, wishlist,
  password reset; order tracking; reviews; back-in-stock alerts; newsletter sign-up; contact form.
- Content: pages with a block-based page builder, blog (posts, categories, RSS), menus, shortcodes, legacy WordPress
  content support (Elementor/WPBakery markup and CSS), redirects, `/sitemap.xml`, custom `robots.txt`,
  Google Shopping feed, structured data (Product, Offer, Review, BreadcrumbList), SEO titles/descriptions/canonicals.
- Transactional emails (WooCommerce-equivalent set: new/processing/on-hold/completed/refunded/cancelled/failed order,
  customer note, new account, password reset, back-in-stock, contact form).

### Back office (`/admin`, hand-built Blade + Alpine.js, no admin framework)
- Dashboard with widgets, orders (statuses, notes, refunds, printable invoices/packing slips, CSV export),
  abandoned checkouts, products (variations, images, specifications, inventory, CSV), categories, attributes,
  discounts, reviews, stock alerts, customers, pages (block builder), blog, menus, redirects (CSV import/export),
  inbox (form submissions, newsletter), analytics/reports, settings (store, checkout, delivery, payments, emails,
  SEO & tracking, theme, system), staff roles (administrator / manager).
- Settings › System: platform version, active theme, feature switches, `commerce:doctor` checks, last import report.

### Themes
- Theme system: `themes/{slug}/theme.json`, parent chains ending in the package `default` theme, view resolution
  through the chain, theme config files, `ThemeDefinition` hooks (body classes, view composers, boot), assets
  published as real copies (`public/themes/{slug}`), staff theme preview (`?preview_theme=`), runtime theme switch.
- Neutral, complete `default` theme implementing the full theme contract (`docs/ARCHITECTURE.md` §8).

### WordPress / WooCommerce importer
- `commerce:import-wordpress`: one-way, idempotent, read-only on the source; site detection (`--detect`), dry runs,
  sections (settings, media, users, catalog, orders, extras, content, menus, redirects), HPOS and legacy order
  storage, permalink reproduction (old URLs keep working), media copy (`--copy-uploads`), rendered-site crawling for
  page-builder content (`--site-url`, `--snapshots`), remote database options (`--db-*`) or `--wp-path` (parses
  `wp-config.php`), rehearsal on the `zz_`-prefixed `scratch` connection.
- Built-in adapters: WooCommerce, Yoast SEO, Rank Math, Redirection, Elementor, WPBakery clean-up, ACF, YITH/TI
  wishlists, product tags, product brands, back-in-stock notifier, Contact Form 7 database, cost of goods, sequential
  order numbers, permalink plugins, Google Tag Manager, rendered theme content; client adapters/steps through `Commerce::importAdapter()` /
  `Commerce::importStep()`.

### Platform
- Feature switches (`config('commerce.features.*')`, 23 switches) that gate routes (404 with names kept), admin
  pages and menu entries, theme entry points, sitemap, emails and importer steps.
- Extension API (`Pine\Commerce\Commerce`): payment gateways, shipping calculators, admin routes/menu/settings
  screens/dashboard widgets, page templates, shortcodes, menu locations, product presenter methods, facet sorter,
  order status hooks and replacement emails, importer adapters and steps.
- Tooling: `commerce:install`, `commerce:doctor`, `commerce:publish`, `commerce:theme:make|publish|check|cache|clear`,
  `commerce:verify-urls`, `commerce:new-client` (path or VCS repository), `commerce:scratch:drop`.
- Client skeleton (`stubs/client-skeleton`): a minimal Laravel 13 app wired to the package.
- Shared-hosting friendly: no Node build, `sync` queue, no cron required, no symlinks under `public/` (LiteSpeed).
- Standalone test suite (`composer test`, Orchestra Testbench on in-memory SQLite).

### Requirements
- PHP 8.3+, Laravel 13, MySQL 8 / MariaDB 10.6+ (SQLite for tests), `stripe/stripe-php` ^21.

[Unreleased]: https://github.com/SetWebUK/ecom-core/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/SetWebUK/ecom-core/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/SetWebUK/ecom-core/compare/v1.3.2...v1.4.0
[1.3.2]: https://github.com/SetWebUK/ecom-core/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/SetWebUK/ecom-core/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/SetWebUK/ecom-core/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/SetWebUK/ecom-core/releases/tag/v1.2.1
