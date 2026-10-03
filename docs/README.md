# Pine Commerce

Pine Commerce (`pine/commerce`) is the agency's reusable e-commerce platform for clients moving off
WordPress/WooCommerce. It is a Laravel 13 package: storefront, a hand-built back office (Blade + Alpine.js,
**no** Filament/Livewire/Nova or any admin/e-commerce framework), a theme system, a WordPress/WooCommerce importer and
install/health tooling. No Node build: themes and the admin are plain CSS/JS.

The platform was extracted from a client's like-for-like WooCommerce rebuild. Existing clients' storefronts must stay
byte-identical while the platform evolves, so changes are regression-checked against them (ARCHITECTURE.md §14).

## Layers

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Client app (one per client)          .env · config/commerce*.php · App\…      │
│   ClientServiceProvider, client importer adapters (app/Import), client tests │
├──────────────────────────────────────────────────────────────────────────────┤
│ Theme  themes/{slug}                  views · assets · theme config · Theme.php│
│   child of another theme; the chain always ends in the package's `default`   │
├──────────────────────────────────────────────────────────────────────────────┤
│ Core   pine/commerce (repository `ecom-core`, namespace Pine\Commerce)        │
│   schema + models · services · storefront & admin controllers/routes ·       │
│   admin UI · mail · importer framework · commerce:* commands · default theme │
└──────────────────────────────────────────────────────────────────────────────┘
```

Dependency rule: core depends on nothing client-specific; a theme depends on core; the client depends on both.
The client never edits the package - it configures, themes and extends it (see [EXTENDING.md](EXTENDING.md)).

## Documents

**Start with [PLAYBOOK.md](PLAYBOOK.md)** – the step-by-step guide used every time: the three repositories
(package, skeleton, one per client), onboarding a WooCommerce client end to end, releasing and rolling out updates,
data safety, troubleshooting and a reference of every command, config key and switch.

| Document | For |
|---|---|
| [PLAYBOOK.md](PLAYBOOK.md) | **Operations**: repositories + private composer access, new client → import → theme → payments/mail → QA → go-live → rollback, releases/hotfixes, data safety, troubleshooting, reference |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Binding design contract (layers, theme contract, config keys, extension API, importer, regression check, implementation notes §18) |
| [THEMES.md](THEMES.md) | Writing and forking themes (theme.json, view chain, assets, theme contract) |
| [IMPORTER.md](IMPORTER.md) | WordPress/WooCommerce importer (database, and since 1.5 the WooCommerce REST API), adapters, scratch testing |
| [EXTENDING.md](EXTENDING.md) | Client code on top of the platform: feature switches, the `Commerce::` extension API (gateways, shipping, admin pages/menu/settings/widgets, page templates, shortcodes, presenter, order hooks, importer), events, views |
| [UPGRADING.md](UPGRADING.md) | Versioning (SemVer), what counts as a breaking change, upgrading clients |
| [INVOICES.md](INVOICES.md) | PDF invoices and packing slips, invoice numbering, Settings › Invoices, customer downloads, email attachments, template overrides |
| [ADMIN_UI.md](ADMIN_UI.md) | Back-office component library and conventions |
| [TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md) | VAT/tax settings, classes and rates, the maths, what orders store; shipping zones, method types, classes; the v1.1 upgrade and import |
| [PRODUCT-CSV.md](PRODUCT-CSV.md) | Product CSV import/export (columns, WooCommerce files, dry run, batches, commands) |
| [NEW-CLIENT.md](NEW-CLIENT.md) | (moved into PLAYBOOK part 2) |
| [../README.md](../README.md) · [../CHANGELOG.md](../CHANGELOG.md) | Package quick reference (install, develop, release, commands, layout) · release history |

These documents live in the package (`docs/` of the `ecom-core` repository). They are left out of composer dist
archives, so read them in the repository (or in `vendor/pine/commerce/docs` when the package is installed from
source).

## Commands at a glance

| Command | What it does |
|---|---|
| `commerce:new-client {path} [--repo=<git url> \| --path=<dir>]` | scaffold a client project from the package's `stubs/client-skeleton` (VCS or path repository) |
| `commerce:install` | migrate, first admin, default settings/pages/menus, a UK shipping zone + UK VAT rates (`commerce.tax.install_rates`), `public/storage`, publish assets (idempotent) |
| `commerce:doctor` | health check (PASS/WARN/FAIL with the fix) – also on Admin › Settings › System |
| `commerce:import-wordpress` | import the old WordPress/WooCommerce site (alias `import:wordpress`) |
| `commerce:verify-urls {sitemap\|file}` | every old URL must answer 200 or 301→200 on the new site |
| `commerce:products:export` / `commerce:products:import {file}` | full product CSV out / in (dry run, WooCommerce exports) |
| `commerce:publish` | copy admin assets + placeholder image into `public/` (copies, never symlinks) |
| `commerce:theme:make / :publish / :check / :cache / :clear` | theme scaffolding, asset publishing, contract validation, manifest cache |
| `commerce:scratch:drop` | drop the `zz_`-prefixed test tables of the `scratch` connection |

## Server assumptions (shared LiteSpeed hosting)

- PHP 8.3+, MySQL/MariaDB, **no Node**, **no queue worker** (`QUEUE_CONNECTION=sync`), **no cron required** (recommended for the scheduled tasks – PLAYBOOK "Cron").
- LiteSpeed does **not follow symlinks** out of `public/`: `public/storage`, published admin and theme assets are real
  copies (`commerce:install`, `commerce:publish`, `commerce:theme:publish`). Never run `php artisan storage:link`.
- After changing `.env`, config or routes on a server: `php artisan optimize:clear && php artisan optimize`.
