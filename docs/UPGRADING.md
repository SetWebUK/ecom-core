# Versioning and upgrading

## Where the package lives

`pine/commerce` is a standalone, public repository (**`SetWebUK/ecom-core`**, repo A in [PLAYBOOK.md](PLAYBOOK.md)
part 1, MIT licence): its own `composer.json`, tests (`composer test`, Orchestra Testbench on in-memory SQLite),
`README.md`, `CHANGELOG.md`, `LICENSE`, `VERSION`. The platform is developed in a clone of that repository; clients
install tagged releases with composer.

Clients install it in one of two modes (`composer.json`):

| Mode | Repository | Constraint | Use |
|---|---|---|---|
| VCS | `{"type": "vcs", "url": "https://github.com/SetWebUK/ecom-core.git"}` (or the SSH URL `git@github.com:SetWebUK/ecom-core.git`) | `^1.2` | every committed client (staging + live); `composer.lock` pins the release |
| path | `{"type": "path", "url": "../ecom-core", "options": {"symlink": true}}` | `*@dev` | local development of the package together with a client – never committed |

New clients: `commerce:new-client … --repo=<git url>` (VCS) or `--path=<dir>` (path). Switching an existing client
from a path repository: PLAYBOOK part 1.7. Repository access on servers: PLAYBOOK part 1.6 (none needed for the public
HTTPS URL). The package's `extra.branch-alias` maps `dev-main` to `1.4.x-dev`, so a staging site can track unreleased
work with `"pine/commerce": "1.4.x-dev"`.

## Releasing

Versions come from git tags `vX.Y.Z` (no `version` key in composer.json). Per release: move the `[Unreleased]`
notes in `CHANGELOG.md` under the new number, set the same number in `VERSION` and `Pine\Commerce\Commerce::VERSION`
(`tests/PackageRepositoryTest.php` checks all three agree), `composer test`, commit `Release vX.Y.Z`, tag, push.
Hotfixes branch from the released tag and are merged back to `main`. Step by step: PLAYBOOK part 3.

## Upgrading a client

```bash
composer update pine/commerce            # only this package – never a blanket `composer update` on a live site
php artisan migrate --force              # package migrations are additive only
php artisan commerce:publish             # admin assets (copies) → public/vendor/commerce/admin
php artisan commerce:theme:publish       # theme assets (copies)
php artisan optimize:clear && php artisan optimize
php artisan commerce:doctor
```

(The client skeleton wraps the last four as `composer deploy`.) Take a database backup first, upgrade staging
before live, and re-run the client's tests (`composer test`) and a URL parity check
(`php artisan commerce:verify-urls <saved sitemap>`).

Read the release notes for:

- **config keys** added to the package defaults – a client file that overrides that top-level key must copy the new
  sub-key (the merge is shallow), otherwise the feature gets `null`;
- **theme contract** changes (new optional views, new `supports` keys) – run `php artisan commerce:theme:check`;
- **admin view overrides** in `resources/views/vendor/commerce/…` – diff them against the new package views.

### Upgrading from 1.1 to 1.2

1.2 is additive: no migrations, no renames. Full list: CHANGELOG `[1.2.0]`.

- `composer update pine/commerce`, then `php artisan commerce:publish`, `php artisan commerce:theme:publish`,
  `php artisan optimize:clear && php artisan optimize` (no `migrate` needed, running it is harmless).
- A client whose `config/commerce.php` has its own `invoices.attach` block: add `'customer_invoice' => false|true`
  (missing = off). It decides whether the admin "Order details / invoice" email carries the invoice PDF; the owner can
  change it in Settings › Invoices.
- Client scheduled jobs in `routes/console.php` keep working; moving them to `Commerce::scheduledTask()` gives them the
  web fallback and a row in `commerce:schedule:status` (EXTENDING.md "Scheduled tasks").
- Product CSV exports now fill `tax_class` (`parent` = same as the product) and `shipping_class` on variant rows;
  scripts that read the export should expect them.

### Upgrading from 1.0 to 1.1

1.1 is additive (five new migrations, no renames). Full list: CHANGELOG `[1.1.0]`; step-by-step checklist: PLAYBOOK
§3.3 "Upgrading a client from 1.0.x to 1.1".

- `composer update pine/commerce` now also installs `dompdf/dompdf` ^3.1 (PDF invoices). `ext-zip` is optional.
- The package defaults switch the new features **on** (prices including VAT, UK rates and zone on install, image
  sizes + WebP, invoice numbers and PDFs on emails, customer invoice download, scheduler web fallback). To keep 1.0
  behaviour, copy the `tax`, `shipping.install_zones`, `images`, `invoices` and `scheduler` blocks from the reference
  client's `config/commerce.php` (the merge is shallow – a client file that overrides one of these top-level keys
  must list every sub-key it needs; `images` falls back per sub-key).
- Run `php artisan commerce:images:generate --missing` once, and add the cron line
  `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (`commerce:schedule:status` checks it).
- Abandoned-cart reminders stay off until the owner switches them on (Settings › Abandoned carts).
- Themes not based on `default` must add `checkout/verify-email`; everything else in the theme contract is optional
  (THEMES.md "1.1 contract changes at a glance").

#### Tax and shipping zones

- The migration is additive and keeps totals: an old `tax.rate` becomes one equivalent rate row (+ "prices exclude
  tax"), existing delivery options move into one zone. Nothing to do for the shop to behave as before.
- New package config key `tax` (defaults for **new** stores: prices including VAT). A client that wants the pre-1.1
  behaviour for settings nobody has saved yet adds `'tax' => ['prices_include_tax' => false, 'display_shop' => 'excl',
  'display_cart' => 'excl', …]` to its `config/commerce.php`. A client that overrides the
  top-level `shipping` key should add `'install_zones' => false|true` next to its `carriers`.
- Importer: `commerce-import.shipping.zones` (default true) – set it to false to keep the pre-1.1 flat shipping import.
- Settings › Checkout no longer has the "VAT rate" field: rates are on Settings › Tax.
- Themes: `Cart::totals()` keeps its keys (amounts without tax) and adds `*_display`, `tax_lines`, `display_incl`; a
  theme that wants VAT-inclusive display or per-rate VAT lines uses them (see the default theme's
  `checkout/partials/summary.blade.php`, `cart/side-cart.blade.php`, `checkout/partials/order-summary.blade.php`,
  `emails/partials/order-details.blade.php`) and posts the address fields to `/checkout/update` so zones and tax follow
  the customer. Themes that do neither keep working.
- Details: [TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md).

## What counts as a breaking change (SemVer)

The public API of `pine/commerce` is:

| Surface | Reference |
|---|---|
| Theme contract: view names, the data each view receives, `supports` keys, theme.json keys | ARCHITECTURE.md §7–§8 |
| Config keys and their meaning (`commerce.*`, `commerce-import.*`), environment variables | §10 |
| Extension API (`Pine\Commerce\Commerce::*`, `ThemeDefinition` hooks, events and their payloads) | §11 |
| Importer interfaces (`Pine\Commerce\Import\Contracts\*`) and command options | §12 |
| Route names and URLs, cookie/header names, admin asset URLs | §4.4, §1 |
| Database schema (table + column names) | – |

- **Patch** (1.0.x): bug fixes, no API change.
- **Minor** (1.x.0): additions only – new config keys with defaults that keep current behaviour, new optional
  theme views, new events, new commands/options, new **additive** migrations.
- **Major** (x.0.0): anything that removes or renames an item above. Table/column renames are not done at all
  (clients hold live data); a schema change is always a new column/table plus a data migration.

Bump `VERSION` and `Pine\Commerce\Commerce::VERSION` with every tag (shown by `commerce:doctor` and Admin › Settings ›
System), and list in `CHANGELOG.md`, per release, config keys added, contract changes and required client actions.

## Rules for package changes

1. Every existing client's storefront stays byte-identical unless the client opts in: clients with a like-for-like
   theme run their regression check (ARCHITECTURE.md §14, PLAYBOOK 3.4) against each release.
2. Client-specific values go to config (with a neutral package default and the old value in the client's config),
   the client theme or client adapters – never hard-coded in the package. The package names no client.
3. New behaviour ships behind a config key or feature switch whose default preserves current behaviour.
4. Tests for package code live in the package's `tests/` (`Pine\Commerce\Tests\TestCase`) and set their own config;
   they run standalone (`composer test`). Database tests use in-memory SQLite (`InstallsNeutralStore`) or the
   `scratch` connection (`zz_` prefix, tables dropped in `tearDown()`).
