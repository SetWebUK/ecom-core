# {{CLIENT_NAME}}

Online shop built on **Pine Commerce** (`pine/commerce`, Laravel 13 + Blade + Alpine, no Node build).

| Where | What |
|---|---|
| `vendor/pine/commerce` | the platform (never edit - upgrade with composer) |
| `themes/{{CLIENT_SLUG}}` | this shop's storefront theme (views, CSS/JS, theme config) |
| `config/commerce.php`, `config/commerce-import.php` | client overrides (feature switches, importer mappings) |
| `app/Providers/ClientServiceProvider.php` | client code hooks (events, view composers, extension API) |
| `app/Import/` | client adapters for the WordPress importer |
| `.env` | environment (see `.env.example` - every key is documented) |

## Where pine/commerce comes from

`composer.json` → `repositories` decides (written by `commerce:new-client --repo=…` or `--path=…`):

| Mode | composer.json | Use |
|---|---|---|
| **VCS** (production) | `{"type": "vcs", "url": "https://github.com/SetWebUK/ecom-core.git"}` + `"pine/commerce": "^1.2"` | staging + live servers; `composer.lock` pins the exact release |
| **path** (development) | `{"type": "path", "url": "../ecom-core", "options": {"symlink": true}}` + `"pine/commerce": "*@dev"` | working on the platform and this client at the same time – never committed |

To switch modes, edit the two places in `composer.json` by hand (the `repositories` entry and the `pine/commerce`
constraint – `composer config` mangles array-style repositories), then run `composer update pine/commerce` and
commit `composer.json` + `composer.lock`. Commit only the VCS mode.

`SetWebUK/ecom-core` is public: the HTTPS URL needs no credentials (an optional read-only token in
`~/.composer/auth.json` / `COMPOSER_AUTH` lifts GitHub's API rate limit – never commit `auth.json`). With the SSH URL
(`git@github.com:SetWebUK/ecom-core.git`) the server needs a deploy key; if you install from a **private** fork over SSH
only, also add `"preferred-install": {"pine/commerce": "source", "*": "dist"}` to `config` so composer clones over git
instead of downloading a zip through the GitHub API (which needs a token). Steps: `docs/PLAYBOOK.md` part 1.6 in the
pine/commerce repository.

## Everyday commands

    composer install
    php artisan commerce:doctor                 # health check with fixes
    php artisan commerce:publish                # admin assets → public/vendor/commerce/admin (copies)
    php artisan commerce:theme:publish          # theme assets → public/ (copies)
    composer deploy                             # migrate + publish + theme:publish + optimize
    composer test

Updating the platform: `composer update pine/commerce` then `composer deploy` (take a database backup first).

The step-by-step playbook for setting up / migrating a client is `docs/PLAYBOOK.md` in the pine/commerce repository.
