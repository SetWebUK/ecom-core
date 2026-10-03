# pine/commerce

Client-agnostic Laravel 13 e-commerce platform: storefront, hand-built back office (Blade + Alpine.js – no admin or
e-commerce framework packages), storefront themes, a WordPress/WooCommerce importer and install/health tooling.
Plain CSS/JS, no Node build. Designed for shared LiteSpeed/cPanel hosting: `sync` queue, no cron required (one optional
cron line runs the scheduled tasks), no symlinks in `public/`.

**Start here: [docs/PLAYBOOK.md](docs/PLAYBOOK.md)** – the step-by-step guide for setting up the repositories,
onboarding a new WooCommerce client, releasing updates and troubleshooting. Index of every document:
[docs/README.md](docs/README.md).

What is in the box: storefront with themes, back office (orders, customers, products, content, discounts, analytics,
settings), tax classes and shipping zones, PDF invoices, product CSV import/export, scheduled tasks without cron,
abandoned-cart emails, a WordPress/WooCommerce importer, install/health tooling and, since 1.3, **Admin › Updates**:
a daily check for new releases, the release notes with their client actions, and one-click updates that an
administrator approves with their password (database backup, maintenance mode, `composer update`, migrations, health
check, automatic rollback) plus updates of untouched skeleton files ([PLAYBOOK part 3.6](docs/PLAYBOOK.md)), and since 1.5
**Admin › Import › WooCommerce API**: pull products, categories, customers, orders, coupons, reviews, shipping/tax,
pages and images from a live WooCommerce shop with just its URL and a read-only REST API key (or the key-less public
Store API for the catalogue) – in the background with live progress, resumable, incremental re-syncs
([IMPORTER.md §12](docs/IMPORTER.md#12-importing-via-the-woocommerce-rest-api)).

- Current version: see [VERSION](VERSION) (= `Pine\Commerce\Commerce::VERSION`), history in [CHANGELOG.md](CHANGELOG.md).
- Requirements: PHP 8.3+, Laravel 13, MySQL 8 / MariaDB 10.6+ (SQLite for tests).

## Install into a client app

The quickest start is the base system, [SetWebUK/ecom-skeleton](https://github.com/SetWebUK/ecom-skeleton): a ready
Laravel 13 app that pulls in this package through composer. Or create a named client project (from
`stubs/client-skeleton`):

```bash
# from any app that already has the package installed (e.g. a clone of ecom-skeleton, PLAYBOOK part 2)
php artisan commerce:new-client /path/to/client --name="Client Name" --repo=https://github.com/SetWebUK/ecom-core.git --constraint=^1.2
cd /path/to/client && composer install && php artisan key:generate --force
php artisan commerce:install --admin-email=you@agency.test
```

`--repo=<git url>` writes a **VCS repository** (production: `"pine/commerce": "^1.0"`, or `--constraint=`);
`--path=<dir>` writes a **path repository** to a local checkout of this package (development: `"pine/commerce":
"*@dev"`, symlinked into `vendor/`).

Existing Laravel 13 app – `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/SetWebUK/ecom-core.git" }],
"require": { "pine/commerce": "^1.2" }
```

then in `bootstrap/app.php`:

```php
->withMiddleware(fn (Middleware $m) => \Pine\Commerce\Commerce::middleware($m))
->withExceptions(fn (Exceptions $e) => \Pine\Commerce\Commerce::exceptions($e))
```

and copy `config/database.php` (read-only `wordpress` + `scratch` connections), `config/filesystems.php` (public
disk = real `public/storage` directory) and `app/Models/User.php` (extends `Pine\Commerce\Models\User`) from
`stubs/client-skeleton`. The service provider is auto-discovered.

The repository is public, so composer needs no credentials for the HTTPS URL. SSH URLs, deploy keys, tokens and
private forks: [docs/PLAYBOOK.md](docs/PLAYBOOK.md) part 1.6.

## Developing the package

```bash
git clone git@github.com:SetWebUK/ecom-core.git && cd ecom-core
composer install
composer test                 # standalone suite: Orchestra Testbench on in-memory SQLite (never a real database)
composer validate --strict
```

The tests extend `Pine\Commerce\Tests\TestCase`: standalone they run on Testbench (`tests/StandaloneTestCase.php`,
wired like the client skeleton); inside a client app that lists `vendor/pine/commerce/tests` (or a path checkout)
in its `phpunit.xml` they run on the app's own `Tests\TestCase`. The few tests that need a client's MySQL `scratch`
connection skip standalone.

To work on the package against a real client, point the client at your clone with a temporary path repository
(`{"type":"path","url":"../ecom-core","options":{"symlink":true}}` and `"pine/commerce": "*@dev"`, then
`composer update pine/commerce`) – never commit that; PLAYBOOK part 3.1 explains how to switch back.

`bin/export-client-skeleton.sh <dir>` renders `stubs/client-skeleton` into the base-system repository
(`SetWebUK/ecom-skeleton`, PLAYBOOK part 1.4).

## Versioning and releases

Semantic Versioning, tags `vMAJOR.MINOR.PATCH`. There is deliberately **no `version` key in composer.json** – composer
reads versions from the git tags; `dev-main` is aliased to `1.5.x-dev` (`extra.branch-alias`). The public API
(theme contract, config keys, `Commerce::` extension API, events, importer interfaces, route names, schema) is listed
in [docs/UPGRADING.md](docs/UPGRADING.md).

Release checklist (full version: PLAYBOOK part 3):

1. `composer test` green; the regression checks of clients that have one are clean.
2. Move the `[Unreleased]` notes in `CHANGELOG.md` under the new version with today's date.
3. Set the same number in `VERSION` and `Pine\Commerce\Commerce::VERSION` (a test checks all three agree).
4. Commit `Release vX.Y.Z`, then `git tag -a vX.Y.Z -m "pine/commerce vX.Y.Z" && git push origin main vX.Y.Z`.
5. Refresh and tag the skeleton: `bin/export-client-skeleton.sh ../ecom-skeleton --tag --push=git@github.com:SetWebUK/ecom-skeleton.git`.
   Clients pick the release up in Admin › Updates (or `composer update pine/commerce`, PLAYBOOK part 3).

## Commands

| Command | Purpose |
|---|---|
| `commerce:install [--connection=] [--admin-email= --admin-name= --admin-password=] [--store-name=] [--store-email=] [--theme=] [--order-start=] [--force-admin] [--skip-publish] [--no-optimize]` | migrate, first admin, default settings / free delivery / core pages / menus, real `public/storage` + `.htaccess`, publish assets, optimize. Idempotent |
| `commerce:doctor [--connection=] [--json] [--only-problems]` | PASS / WARN / FAIL / INFO checks with fixes; exit 1 on FAIL |
| `commerce:publish [--force]` | copy admin assets to `public/{commerce.admin.assets_url}` and the placeholder image |
| `commerce:theme:make {slug} [--parent=default] [--name=] [--copy]` | scaffold a theme (child, or full fork with `--copy`) |
| `commerce:theme:publish [slug] [--all] [--prune]` / `:check [slug]` / `:cache` / `:clear` | theme assets (copies), contract validation, manifest cache |
| `commerce:import-wordpress` (alias `import:wordpress`) | WordPress/WooCommerce import, read-only source – options in [docs/IMPORTER.md](docs/IMPORTER.md) |
| `commerce:import-woo-api [run] [--url= --key= --secret=] [--store] [--only=] [--dry-run] [--since=] [--orders-after=] [--skip-existing] [--no-images] [--no-notes] [--same-site] [--test]` | import from a WooCommerce shop's REST API (API key, or `--store` for the public catalogue); keys also from `WOO_API_URL/KEY/SECRET` – [docs/IMPORTER.md §12](docs/IMPORTER.md#12-importing-via-the-woocommerce-rest-api) |
| `commerce:verify-urls {sitemap-url\|file} [--base=] [--resolve=] [--report=] …` | old URLs must answer 200 or 301→200 on the new site |
| `commerce:new-client {path} [--name=] [--slug=] [--repo=<git url> \| --path=<dir>] [--constraint=] [--force]` | scaffold a client project from `stubs/client-skeleton` |
| `commerce:scratch:drop [--connection=scratch] [--force]` | drop `zz_`-prefixed test tables (refuses any other prefix) |
| `commerce:update:check [--json]` | newest installable release within the composer constraint, newer majors, changelog + client actions (never installs) |
| `commerce:update:run [id] [--approve --yes]` | install an update approved in Admin › Updates (or approve from the CLI): backup, maintenance mode, composer, migrate, publish, health check, rollback on failure |
| `commerce:skeleton:baseline [ref] [--detect] [--disabled]` / `commerce:skeleton:check [--apply-safe --yes]` | record which skeleton release the project matches; compare and apply skeleton files the project never changed |

## Layout

```
composer.json · VERSION · CHANGELOG.md · LICENSE · phpunit.xml.dist
bin/export-client-skeleton.sh  renders stubs/client-skeleton into the ecom-skeleton repository
config/commerce.php            package defaults – every key documented (feature switches: 'features')
config/commerce-import.php     importer defaults
database/migrations/           store schema (additive only)
docs/                          PLAYBOOK, architecture contract, themes, importer, extending, upgrading, admin UI
resources/assets/admin/        admin CSS/JS/images → published (copied) to public/vendor/commerce/admin
resources/themes/default/      neutral default theme (end of every theme chain)
resources/views/admin/         back office (commerce::admin.*), components/admin (<x-admin.*>)
routes/                        storefront.php, admin.php + admin/*.php
src/                           Pine\Commerce\… (Console, Http, Models, Services, Theme, Import, Support …)
stubs/client-skeleton/         new client project template (commerce:new-client, ecom-skeleton repository)
stubs/public-storage.htaccess  hardening rules for public/storage (written by commerce:install)
tests/                         package tests (standalone: composer test; or through a client app's phpunit)
```

`bin/`, `docs/`, `tests/` and `phpunit.xml.dist` are `export-ignore`d: composer **dist** installs on client servers do not
contain them (read the docs in this repository).

## Admin

`/admin` (path: `commerce.admin.path`). Settings › System (administrators) shows the platform version, active
theme, feature switches, the doctor checks and the last import report. Updates (administrators, feature `updater`)
checks for releases and installs an approved one ([docs/PLAYBOOK.md](docs/PLAYBOOK.md) part 3.6). UI conventions:
[docs/ADMIN_UI.md](docs/ADMIN_UI.md).

## License

MIT – see [LICENSE](LICENSE).
