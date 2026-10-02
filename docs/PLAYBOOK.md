# Pine Commerce playbook

The one document to follow every time: setting up the repositories, moving a WooCommerce client onto Pine Commerce,
shipping platform updates, keeping data safe and fixing the usual problems. Plain steps, commands you can paste,
checklists to tick.

Placeholders used throughout – replace them with the real values:

| Placeholder | Meaning |
|---|---|
| `SetWebUK` | the GitHub organisation (repos: `ecom-core` = package (public), `ecom-skeleton` = base system / starter (public), one private repo per client) |
| `acme` / `Acme Tools` | the new client's slug / name |
| `/home/acme/app` | the client project on its server (the web server's document root is `/home/acme/app/public`) |
| `https://acme.example.test` | the client's staging URL; `https://www.acme.co.uk` the live URL |
| `/home/acme/wordpress` | the client's old WordPress site on the same server (if it is there) |

Where things are documented in more depth: [README.md](README.md) (index). This playbook links to them.

**Contents**

- [Part 1 – One-time setup: the three repositories](#part-1--one-time-setup-the-three-repositories)
- [Part 2 – Onboarding a new WooCommerce client](#part-2--onboarding-a-new-woocommerce-client)
  (first time? do the [rehearsal on your own machine](#rehearsal-on-your-own-machine-sqlite-no-github) first)
- [Part 3 – Day to day: releasing and rolling out updates](#part-3--day-to-day-releasing-and-rolling-out-updates)
- [Part 4 – Data safety rules](#part-4--data-safety-rules)
- [Part 5 – Troubleshooting](#part-5--troubleshooting)
- [Part 6 – Reference](#part-6--reference)

---

## Part 1 – One-time setup: the three repositories

### 1.1 The layout

| Repository | Contents | Made from | Who uses it |
|---|---|---|---|
| **A. `ecom-core`** (public, MIT) | the platform package `pine/commerce`: code, default theme, importer, commands, client skeleton, docs (this file), tests | developed directly in this repository (a working clone, e.g. `~/work/ecom-core`) | every client project, through composer |
| **B. `ecom-skeleton`** (public) | the **base system**: a ready Laravel 13 app that pulls in `pine/commerce` from repo A through composer – the starting point of a new client | `stubs/client-skeleton` of repo A, rendered by `bin/export-client-skeleton.sh` (in repo A) | you, when a new client starts |
| **C. one per client**, e.g. `acme` (private) | that client's app: theme, config, client code, tests, `composer.lock` | the client project (`commerce:new-client`, or a copy of repo B) | that client's servers |

Rules that follow from it:

- The platform is changed **only in repo A**. A client never edits `vendor/pine/commerce`: it configures, themes and
  extends it ([EXTENDING.md](EXTENDING.md)).
- Clients install a **tagged release** (`"pine/commerce": "^1.2"`) through a composer **VCS repository**; the exact
  version is pinned by the client's `composer.lock`.
- Repos A and B are public: they never contain client names, client data, server paths, credentials or other
  infrastructure details. Everything client-specific lives in that client's private repo C.
- Nobody commits `.env`, `auth.json`, database dumps, `vendor/`, uploaded media (`public/storage`) or published
  asset copies (`public/vendor/commerce`, `public/admin-assets`, `public/themes`, a theme's custom `public_path`).
  The `.gitignore` files already say so.

### 1.2 Checklist

- [ ] Repositories on GitHub under `SetWebUK`: `ecom-core` and `ecom-skeleton` **public**, one **private**
      repository per client.
- [ ] `LICENSE` (MIT) names the right copyright holder (currently "SetWeb UK"); change it in repo A if needed.
- [ ] Your own machine/server user can push to GitHub over SSH (`ssh -T git@github.com` says "Hi …").
- [ ] Repo A pushed with its release tags (1.3), repo B pushed (1.4), each repo C pushed (1.5).
- [ ] Every client installs pine/commerce from repo A through the VCS repository (1.7).

### 1.3 Repo A – `ecom-core` (the package)

The package is developed in a clone of repo A:

```bash
git clone git@github.com:SetWebUK/ecom-core.git ~/work/ecom-core && cd ~/work/ecom-core
composer install
composer test                 # standalone suite, in-memory SQLite – must say OK
composer validate --strict
```

Day-to-day changes and releases: Part 3. A release is an annotated tag `vX.Y.Z` on `main`; composer reads versions
from the tags (there is no `version` key in `composer.json`).

History: until 1.2.0 the package lived inside the first client's repository (`packages/commerce`) and was published
with `git subtree split`. 1.2.1 started the public repository with a clean history (one commit, tag `v1.2.1`); the
earlier tags are not in it, so clients require `^1.2` (resolves to 1.2.1 or later).

### 1.4 Repo B – `ecom-skeleton` (the base system)

The skeleton is rendered from repo A by the same code as `php artisan commerce:new-client` (name "Commerce
Skeleton"), so it can never drift from what new clients get. From the repo A clone:

```bash
cd ~/work/ecom-core && composer install
bin/export-client-skeleton.sh ~/work/ecom-skeleton           # a new directory: git init + one commit
cd ~/work/ecom-skeleton
git remote add origin git@github.com:SetWebUK/ecom-skeleton.git
git push -u origin main
```

It requires `"pine/commerce": "^1.2"` from `https://github.com/SetWebUK/ecom-core.git` (`--repo=<url>` and
`--constraint=` change that). After a skeleton change in repo A, run the script again on the same directory: it
replaces the files, commits the difference and (with `--push=<url>`) pushes.

### 1.5 Repo C – the client app

Create an empty **private** repository for the client, then from the client project:

```bash
cd /home/acme/app
git ls-files | grep -E '(^|/)\.env$|auth\.json|\.sql' || echo "no secrets or dumps tracked"
git remote add origin git@github.com:SetWebUK/acme.git
git push -u origin main
```

If an old WordPress install shares the project directory, its files must be git-ignored (check with
`git ls-files | grep -E '^(wp-|xmlrpc|license\.txt|readme)'`).

### 1.6 Composer access to repo A on servers

Repo A is **public**: with the HTTPS URL (`https://github.com/SetWebUK/ecom-core.git`) composer needs no credentials.
Anonymous GitHub API requests are rate-limited (60 per hour per IP), which is plenty for `composer update
pine/commerce`; on busy CI runners add a read-only token (below) to lift the limit. Never commit credentials: they
live in the server user's home directory.

**Token (optional, also for a private fork).** A *fine-grained personal access token* (GitHub › Settings › Developer
settings › Fine-grained tokens): resource owner `SetWebUK`, repository access *only* `ecom-core`, permission
*Contents: Read-only*, an expiry date you put in the calendar:

```bash
composer config --global --auth github-oauth.github.com github_pat_XXXXXXXX
# written to ~/.composer/auth.json (or ~/.config/composer/auth.json) – outside the project, never in git
```

For CI or one-off shells use the environment instead: `export COMPOSER_AUTH='{"github-oauth":{"github.com":"github_pat_…"}}'`.

**SSH URL (`git@github.com:SetWebUK/ecom-core.git`) – deploy key or personal key.** Works for public and private
repositories alike, but the server needs a key GitHub accepts:

```bash
ssh-keygen -t ed25519 -N "" -C "acme-server commerce read" -f ~/.ssh/commerce_deploy
cat ~/.ssh/commerce_deploy.pub     # GitHub › SetWebUK/ecom-core › Settings › Deploy keys › Add (leave "write" unticked)
cat >> ~/.ssh/config <<'EOF'
Host github.com
    IdentityFile ~/.ssh/commerce_deploy
    IdentitiesOnly yes
EOF
ssh -T git@github.com               # "Hi SetWebUK/ecom-core! You've successfully authenticated…"
```

With an SSH-only setup **and a private repository**, add `"preferred-install": {"pine/commerce": "source", "*": "dist"}`
to the client's `composer.json` `config`: Composer then clones repo A over SSH instead of downloading a zip from the
GitHub API (which needs a token and answers **404** for a private repository without one – `…/zipball/… could not be
downloaded (HTTP/2 404)`). For the public repository the rule is not needed; it is harmless to keep (installs from
source also carry `docs/` and `tests/`).

A deploy key opens **one** repository. If the same server user must also clone the client's private repo C over SSH,
give it a second key with a host alias (`Host github-client` … `HostName github.com`) and clone repo C as
`git@github-client:SetWebUK/acme.git`; keep `github.com` for repo A in `composer.json`.

Check on the server: `composer show pine/commerce --all 2>&1 | grep versions` lists the release tags.

### 1.7 Switching a client from a path repository to the VCS repository

A client is wired to the package in one of two modes (`composer.json`):

| Mode | `repositories` entry | `require` | Use |
|---|---|---|---|
| **VCS** (production) | `{"type": "vcs", "url": "https://github.com/SetWebUK/ecom-core.git"}` (or the SSH URL, 1.6) | `"pine/commerce": "^1.2"` | every committed client, staging and live |
| **path** (development) | `{"type": "path", "url": "../ecom-core", "options": {"symlink": true}}` | `"pine/commerce": "*@dev"` | working on the platform and a client together on your machine – never committed |

To switch a client that still uses a path repository (on a branch, with a backup – Part 4):

1. Edit `composer.json` by hand (`composer config` mangles array-style repositories): replace the path repository
   with the VCS entry above and set `"pine/commerce": "^1.2"`; remove any `"Pine\\Commerce\\Tests\\"` entry from
   `autoload-dev`.
2. `composer update pine/commerce` → `vendor/pine/commerce` is the latest 1.x release from GitHub.
3. Remove the in-repository package copy (if any) and any `phpunit.xml` testsuite/source entry that pointed at it
   (the package's tests run in repo A with `composer test`).
4. Docs links that pointed into the package copy point at `vendor/pine/commerce/docs/…` (installed from source) or
   at `https://github.com/SetWebUK/ecom-core/tree/main/docs`.
5. `php artisan optimize:clear && php artisan commerce:publish && php artisan commerce:theme:publish && php artisan optimize`,
   `php artisan config:clear && php artisan test`, the regression check if the client has one (Part 3.4) – must be
   identical.
6. Commit `composer.json`, `composer.lock` and the removals; deploy.

New clients get the VCS mode directly (`commerce:new-client --repo=…`, Part 2 step 2).

### 1.8 Trying Part 1 without GitHub (local bare repositories)

Every command above works with local bare repositories and `file://` URLs instead of GitHub – useful to rehearse, or
to check a release before pushing. Composer treats a `file://` git URL exactly like a GitHub VCS repository (tags =
versions).

```bash
R=~/rehearsal && mkdir -p $R/repos
git clone --bare ~/work/ecom-core $R/repos/ecom-core.git            # carries the tags of your clone
cd ~/work/ecom-core
bin/export-client-skeleton.sh $R/ecom-skeleton --repo=file://$R/repos/ecom-core.git
cd $R/ecom-skeleton && composer install                             # resolves pine/commerce from the bare repository
```

Then follow Part 2 step 2 with `file://$R/repos/ecom-core.git` in place of the GitHub URL (`composer show
pine/commerce` lists the tags). Clean up: `rm -rf $R`.

---

## Part 2 – Onboarding a new WooCommerce client

Every step ends with a check. Commands run in the **client project root** unless stated otherwise.

### Rehearsal on your own machine (SQLite, no GitHub)

The whole of Part 2 up to step 8 runs without MySQL and without a web server: a SQLite file as the shop database,
PHP's built-in server, and the old WordPress database read-only. Do this once before your first real client; it is
also the quickest way to try an unusual WordPress site. (Checked end to end on 2026-09-28 with a real store's
WordPress data: about 400 products, 1,000 orders, 950 users, 7,700 media files – every page type answered 200.)

```bash
# 1. project from repo B (or a local rehearsal of Part 1: §1.8)
git clone https://github.com/SetWebUK/ecom-skeleton.git /tmp/commerce-skeleton && cd /tmp/commerce-skeleton && composer install
php artisan commerce:new-client ~/acme --name="Acme Store" --slug=acme --repo=https://github.com/SetWebUK/ecom-core.git
cd ~/acme && composer install
# 2. .env: SQLite + plain http (edit by hand)
touch database/database.sqlite
#   DB_CONNECTION=sqlite
#   DB_DATABASE=/home/you/acme/database/database.sqlite     (absolute path; delete the DB_HOST/PORT/USERNAME/PASSWORD lines)
#   APP_URL=http://127.0.0.1:8000
#   SESSION_SECURE_COOKIE=false                             (true = no session over http: admin login bounces back to the form)
#   LOG_LEVEL=debug                                         (MAIL_MAILER=log writes each email at debug level: with the
#                                                            skeleton's LOG_LEVEL=warning no email reaches storage/logs)
#   WP_PATH=/path/to/wordpress   or   WP_DB_* of a copy of the old database
php artisan key:generate --force
# 3. install, import, serve
php artisan commerce:install --admin-email=dev@agency.test --store-name="Acme Store" --admin-password='choose-one'
php artisan commerce:import-wordpress --detect
php artisan commerce:import-wordpress --copy-uploads
php artisan serve --port=8000        # or, from public/: cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
#                                      (the router script serves getcwd()/index.php: started from the project root, every page is an empty 500)
```

Open `http://127.0.0.1:8000` and `/admin`. Expected on this setup: `commerce:doctor` reports **FAIL APP_URL** (http)
and **WARN** for mail/payments – correct for a rehearsal. Enable *Bank transfer* in Admin › Settings › Payments to
place a test order. Without the router script, `php -S` answers 404 for `/sitemap.xml`, `/robots.txt` and the feeds
(it treats `.xml`/`.txt` as static files). SQLite is for rehearsals, development and CI – staging and live use
MySQL/MariaDB. Delete the rehearsal directory when done.

Trying the v1.1 features in the rehearsal (checked end to end on 2026-09-30 on the default theme):
`commerce:products:import file.csv --dry-run` then again with `--create-missing --download-images` (a WooCommerce
export, variations and remote image URLs included; the image sizes and WebP twins appear under
`public/storage/uploads`); zones and rates in Admin › Settings › Shipping (a `BT*` postcode zone above the UK zone
catches Northern Ireland; a zone with no countries last = rest of the world); after an order is marked Processing the
invoice PDF is attached to the email in `storage/logs` (base64) and downloadable from My account › Orders. The
scheduler has no "pretend it is later" option: `php artisan commerce:schedule:task carts.abandoned-emails` runs one
task now, and for a time-travel test boot the app in a small PHP script, call
`Illuminate\Support\Carbon::setTestNow('2026-10-01 18:00')` and then `Artisan::call('schedule:run')` (reminders
follow the basket's last activity; sale prices switch at their start/end times). `commerce:schedule:status` then shows
each task's last result (a heartbeat "from now" is expected after time travel).

### Step 0 – Collect before you start

- [ ] Hosting for the new site: PHP **8.3+** (extensions: pdo_mysql (pdo_sqlite for a local rehearsal), mbstring, intl, gd or imagick, curl, zip,
      bcmath, fileinfo, openssl, tokenizer, xml), MySQL 8 / MariaDB 10.6+, **SSH access**, composer (or install it,
      below), a document root you can point at the project's `public/` directory, TLS for the staging host name.
- [ ] Access to the old site: its database (a **copy** or read-only credentials), `wp-config.php`, and its
      `wp-content/uploads` directory (on the same server, or copied over – step 6).
- [ ] The old site's sitemap URL (`/sitemap_index.xml` for Yoast / Rank Math, `/wp-sitemap.xml` for core) –
      saved as a file too, before anything changes:
      `curl -s https://www.acme.co.uk/sitemap_index.xml -o storage/app/old-sitemap.xml`.
- [ ] Payment accounts: Stripe (publishable + secret key, webhook access), PayPal REST app (client id + secret), bank
      details for bank transfer. Mail: SMTP / transactional provider credentials and DNS access for SPF/DKIM.
- [ ] Decisions: which theme approach (step 8), who approves it, the go-live date and a content freeze for the final import.

### Step 1 – Server prerequisites (LiteSpeed / cPanel notes)

```bash
php -v                                  # 8.3 or newer on the CLI (cPanel: /opt/cpanel/ea-php83/root/usr/bin/php, or
                                        #   "MultiPHP Manager" for the web PHP – both must be 8.3+)
composer --version || (cd ~ && curl -sS https://getcomposer.org/installer | php && mkdir -p ~/bin && mv composer.phar ~/bin/composer)
```

- **Document root = `public/`** of the project. cPanel: *Domains › Manage › Document Root* →
  `/home/acme/app/public`. If the host forces `public_html`, put the project in `public_html` and set the document
  root to `public_html/public`. Never expose the project root.
- **LiteSpeed does not follow symlinks out of `public/`**: `public/storage` and all published assets are real
  directories/copies (`commerce:install`, `commerce:publish`, `commerce:theme:publish` do it). Never run
  `php artisan storage:link`.
- If the old WordPress site stays in a parent directory and its `.htaccess` sets `auto_prepend_file` (Wordfence,
  Really Simple SSL…), uncomment `php_value auto_prepend_file none` in the project's `public/.htaccess`.
- No Node, no queue worker (`QUEUE_CONNECTION=sync`). Cron is recommended but optional – see "Cron" after Step 11.
- Server access to repo A: Part 1.6.

Check: `php -m | grep -iE 'pdo_mysql|intl|mbstring|gd|zip|bcmath'` lists them; the staging host name answers over https.

### Step 2 – Create the project

Recommended – from any checkout that has the package (repo B, or any existing client):

```bash
git clone https://github.com/SetWebUK/ecom-skeleton.git /tmp/commerce-skeleton && cd /tmp/commerce-skeleton
composer install
php artisan commerce:new-client /home/acme/app --name="Acme Tools" --slug=acme --repo=https://github.com/SetWebUK/ecom-core.git
```

`--repo` writes the VCS repository and `"pine/commerce": "^1.0"` (add `--constraint=^1.2` to require the current minor version). (`--path=/path/to/commerce` writes a path
repository to a local checkout instead – development only; `--constraint=` overrides the version constraint.)
Alternative without the command: clone repo B straight into `/home/acme/app`, `rm -rf .git`, and replace
"Commerce Skeleton" / "commerce-skeleton" in `composer.json`, `.env.example` and `README.md`.

```bash
cd /home/acme/app
git init -b main && git add -A && git commit -m "Acme Tools: Pine Commerce client skeleton"
git remote add origin git@github.com:SetWebUK/acme.git   # the client's repo C (create it empty on GitHub first)
composer install
git add composer.lock && git commit -m "Lock pine/commerce" && git push -u origin main
```

Check: `php artisan list commerce` lists the `commerce:*` commands; `composer show pine/commerce` shows v1.x.

### Step 3 – Environment (`.env`)

`commerce:new-client` created `.env` from `.env.example`; every key is explained there. Fill in at least:

| Key | Value |
|---|---|
| `APP_NAME`, `APP_URL` | store name; `https://acme.example.test` (https, no trailing slash) |
| `APP_ENV=production`, `APP_DEBUG=false` | on staging too |
| `DB_*` | the new, empty shop database (no table prefix). SQLite only for a rehearsal (see above) |
| `COMMERCE_THEME` | `default` until the client theme exists (step 8) |
| `WP_DB_*`, `WP_PATH`, `WP_SITE_URL` | the old site – step 6 |
| `MAIL_*` | `MAIL_MAILER=log` on staging until you want real mail – step 11 |
| `TRUSTED_PROXIES` | empty unless behind a CDN/load balancer – go-live |
| `SESSION_SECURE_COOKIE` | `true` (the default) on every https site; `false` only for plain-http rehearsals |

```bash
php artisan key:generate --force   # once – never change APP_KEY later (it encrypts the stored payment keys)
```

### Step 4 – Install

```bash
php artisan commerce:install --admin-email=dev@agency.test --store-name="Acme Tools"
```

Migrates, creates the first administrator (asks for a password, or generates and prints one – or pass
`--admin-password=`), seeds default
settings (search engines discouraged on staging), a free delivery option, core pages and menus, creates
`public/storage` as a real directory with its hardening `.htaccess`, publishes admin + theme assets and runs
`optimize`. It only creates what is missing, so it is safe to re-run.

Check: sign in at `https://acme.example.test/admin`; `php artisan commerce:doctor` shows no FAIL.

### Step 5 – Back up (before every import)

```bash
mkdir -p ~/backups && mysqldump --single-transaction --routines acme_shop | gzip > ~/backups/acme_shop-$(date +%Y%m%d-%H%M)-pre-import.sql.gz
```

(Part 4 has the rules. The old WordPress database is only ever read.)

### Step 6 – Point the importer at the old WordPress site

**Same server** (WordPress files readable by this user) – the importer parses `wp-config.php` (never runs it).
Its `DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_HOST` and `$table_prefix` then **win over** `WP_DB_*` in `.env`
(`--db-*`/`--prefix` options win over both); `--detect` shows where each value came from ("Credentials from"):

```bash
# .env
WP_PATH=/home/acme/wordpress
# or per run:  --wp-path=/home/acme/wordpress
```

**Remote database** (the old site is elsewhere) – ask the host for a read-only MySQL user, or import a dump of it
into a separate database on this server (safest):

```bash
# .env – the "wordpress" connection is forced READ ONLY by the platform
WP_DB_HOST=db.oldhost.example   WP_DB_PORT=3306   WP_DB_DATABASE=acme_wp   WP_DB_USERNAME=readonly   WP_DB_PASSWORD=…   WP_DB_PREFIX=wp_
# or per run:  --db-host= --db-port= --db-name= --db-user= --db-pass= [--prefix=wp_]
```

and copy the media over (read-only on the old server):

```bash
rsync -az --info=progress2 olduser@oldhost:/path/to/wordpress/wp-content/uploads/ /home/acme/wp-uploads/
# then import with --uploads-path=/home/acme/wp-uploads
```

**Page-builder content** (Elementor etc.): if the old site still runs somewhere, set `WP_SITE_URL` (and
`WP_SITE_HOST` when you reach it by IP) so rendered pages can be read, or capture HTML snapshots (step 8) and pass
`--snapshots=storage/app/wp-reference/html`.

Check:

```bash
php artisan commerce:import-wordpress --detect
```

shows WordPress/WooCommerce versions, the table prefix, the order storage (HPOS or posts), active plugins and which
adapters will run. Fix credentials until it does ("Cannot open the WordPress database" → Part 5).

### Step 7 – Dry run, import, media, URL check

Before the first import, list every host name the old site used – live, `www`/non-`www`, and any old staging host
that appears in its content or redirect rules – in **`config/commerce-import.php` → `legacy_hosts`** (the importer
already adds the WordPress `siteurl`/`home` hosts). Links and redirect targets on those hosts are imported as relative
URLs; otherwise they keep pointing at the old host (e.g. a redirect to `https://www.staging.acme.co.uk/…`). Put the
same hosts in `config/commerce.php` → `legacy_hosts` (menu links are made relative when rendered). Site-specific
text replacements: `config/commerce-import.php` → `content.replace`.

```bash
php artisan commerce:import-wordpress --dry-run                     # everything read + transformed, all writes rolled back
php artisan commerce:import-wordpress --copy-uploads                # the real import + media copy into public/storage/uploads
php artisan commerce:import-wordpress --only=catalog,content        # a section again later (idempotent)
```

- A first-time or unusual site: rehearse on the `scratch` connection first (same database, `zz_` tables):
  `php artisan commerce:install --connection=scratch --admin-email=dev@agency.test --no-interaction`,
  `php artisan commerce:import-wordpress --target=scratch --copy-uploads`, compare, then
  `php artisan commerce:scratch:drop --force`. Details: [IMPORTER.md](IMPORTER.md) §10.
- The import is **idempotent**: every row is matched on its WordPress id and updated, so import early, work, and
  re-run for the final delta at go-live.
- Summary table (WordPress count vs imported) + warnings: on screen, in `storage/logs/import-wordpress.log`, and in
  Admin › Settings › System.
- **Image sizes.** Imported media keeps the sizes WordPress generated (`photo-300x300.jpg` …); the storefront picks
  the best one that exists. Fill in the platform's sizes (`commerce.images.sizes`: thumbnail, card, medium, large +
  WebP twins) once the media is copied – it only ADDS files, never changes or deletes an original, and is safe to
  re-run (THEMES.md "Images"):

  ```bash
  php artisan commerce:images:generate --missing --dry-run      # what would be written
  php artisan commerce:images:generate --missing                # media-library images
  php artisan commerce:images:generate --missing --scan         # + files under public/storage/uploads without a library row
  ```

**Verify every old URL** against the saved sitemap (each must answer 200, or 301 to a 200):

```bash
php artisan commerce:verify-urls storage/app/old-sitemap.xml --base=https://acme.example.test --report=storage/app/url-parity.csv
```

Fix failures with redirects (Admin › Content › Redirects, CSV import available) or importer config, re-run until it
exits 0. Before DNS moves you can test the new server under the live name: `--base=https://www.acme.co.uk --resolve=<new server IP>`.

Post-import review in the admin:

- [ ] Products: prices, sale prices, stock, variations, images, categories (spot-check 10 against the old site).
- [ ] Customers can sign in with their old passwords (WordPress hashes are imported as-is).
- [ ] Orders: numbers continue the old sequence (Settings › Checkout › Lowest order number).
- [ ] Pages/posts: content, SEO titles/descriptions; starter pages from `commerce:install` that WordPress pages
      replace (`/about` next to `/about-us`…) deleted or redirected.
- [ ] Menus: WordPress menus without a theme location arrive as `wp-{slug}` – map them with `menus.by_term_id` in
      `config/commerce-import.php` and re-run `--only=menus`, or assign them in Admin › Content › Menus.

### Step 8 – Choose and build the theme

| Approach | When | How |
|---|---|---|
| **Default theme + settings** | new look is fine, small budget | keep `COMMERCE_THEME=default`; logo, colours via Admin › Settings › Theme, home sections in Admin › Content › Pages › Home, menus in Admin › Content › Menus |
| **Child theme** | the default layout with the client's branding and a few different templates | `php artisan commerce:theme:make acme --parent=default`, set `COMMERCE_THEME=acme`, override only the views/CSS that differ (`themes/acme/views/…`, `themes/acme/assets/css/theme.css`) |
| **Bespoke like-for-like** | the client wants the old site to look exactly the same | full fork: `php artisan commerce:theme:make acme --parent=default --copy`, then rebuild the old site's markup and CSS (below) |

How a like-for-like theme is made:

1. **Capture reference HTML** of every page type from the running old site before it changes – one file per URL
   (path with `/` → `__`, `home.html` for `/`), including states (item in basket, checkout, my-account, search,
   page 2 of a category, 404):
   ```bash
   mkdir -p storage/app/wp-reference/html
   while read -r path; do f=$(echo "${path#/}" | sed 's#/$##; s#/#__#g'); [ -z "$f" ] && f=home
     curl -s "https://www.acme.co.uk${path}" -o "storage/app/wp-reference/html/${f}.html"; sleep 1
   done < storage/app/url-paths.txt
   ```
   (`storage/app` is git-ignored – reference material never goes into the repository.) The same directory feeds the
   importer: `--snapshots=storage/app/wp-reference/html`.
2. **Extract the CSS that is actually used** (theme/page-builder CSS, plugin CSS, inline `<style>`) into the theme's
   own stylesheets under `themes/acme/assets/css/` – consolidate, don't load 50 plugin files. Fonts and icons the
   same way.
3. **Rebuild the views** in `themes/acme/views/` with the same DOM structure and classes: layout, header/footer/menus,
   product card, category, product, basket/checkout, account, blog, pages. The views core renders and the data each
   one receives are the **theme contract** ([ARCHITECTURE.md](ARCHITECTURE.md) §8, [THEMES.md](THEMES.md)).
4. **Compare** page by page against the reference HTML (title, meta description, canonical, H1, visible text, layout
   in the browser) and fix differences. Keep a URL list, a snapshot script and a normalising diff script in the
   client repository – they become the regression check for later releases (3.4).

Always: `php artisan commerce:theme:check acme` (every contract view resolves), `php artisan commerce:theme:publish acme`
after asset edits (copies into `public/`), and wrap entry points of optional features in `@if (commerce_feature('…'))`.

### Step 9 – Client-specific behaviour (never edit the package)

- **Feature switches** in `config/commerce.php` → `features` (blog, wishlist, reviews, coupons, guest checkout…
  table in Part 6.3). After a change on a server: `php artisan optimize:clear && php artisan optimize`.
- **Code hooks** in `app/Providers/ClientServiceProvider.php` through the `Commerce::` extension API: extra payment
  gateways, shipping calculators, admin pages/menu/settings/widgets, page templates, shortcodes, presenter methods,
  order hooks and emails ([EXTENDING.md](EXTENDING.md); worked examples in `app/Providers/ExtensionExamples.php`).
- **Importer adapters** for data the generic importer does not know (custom meta, ACF fields, a page builder, menus
  only visible in the rendered HTML): `app/Import/Acme/…Adapter.php` implementing a
  `Pine\Commerce\Import\Contracts\*` interface, registered with `Commerce::importAdapter()`; simple mappings are config
  in `config/commerce-import.php` (`attributes.map`, `menus.by_term_id`, `acf.product_fields`, `settings.seed`…).
  [IMPORTER.md](IMPORTER.md) §9. Rehearse on `scratch`, then re-import.
- **Client tests** in `tests/` (`composer test`).
- Something the platform should do for every client? Change repo A instead (Part 3) – with a config key or feature
  switch whose default keeps current behaviour.

### Step 10 – Payments

Keys are entered in **Admin › Settings › Payments** (administrators only) and stored encrypted with `APP_KEY` – not
in `.env`. Use test/sandbox keys on staging, live keys only at go-live.

| Gateway | Keys | Webhook URL | Events |
|---|---|---|---|
| Stripe | publishable key, secret key, webhook signing secret | `https://acme.example.test/webhooks/stripe` (live: `https://www.acme.co.uk/webhooks/stripe`) | `payment_intent.succeeded`, `payment_intent.payment_failed` |
| PayPal | REST app client id + secret (sandbox / live) | `https://…/webhooks/paypal` | `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.DECLINED` |
| Bank transfer | account name, number, sort code / IBAN | – | – |

Check: a test order with each method (Stripe test card `4242 4242 4242 4242`), a refund from the admin,
`php artisan commerce:doctor` → Payments PASS.

### Step 11 – Mail (SMTP)

```bash
# .env
MAIL_MAILER=smtp
MAIL_HOST=smtp.provider.example   MAIL_PORT=587   MAIL_USERNAME=…   MAIL_PASSWORD=…   MAIL_SCHEME=null
MAIL_FROM_ADDRESS="shop@acme.co.uk"   MAIL_FROM_NAME="${APP_NAME}"
```

Then `php artisan optimize:clear && php artisan optimize`. Sender name and order-notification recipients: Admin ›
Settings › Emails. Publish SPF/DKIM (and DMARC) DNS records for the sender domain as the provider instructs.
On staging keep `MAIL_MAILER=log` (emails are written to the log: `storage/logs/laravel-YYYY-MM-DD.log` with the
skeleton's `LOG_CHANNEL=daily`, `storage/logs/laravel.log` with `single`) unless you are testing mail.

Check: place an order → customer + admin emails arrive and are not in spam; password reset email arrives.

### Tax, shipping zones, invoices and product CSV (v1.1)

Owner settings, done once per client in the admin (details: [TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md),
[INVOICES.md](INVOICES.md), [PRODUCT-CSV.md](PRODUCT-CSV.md)):

1. **Tax** – Admin › Settings › Tax: prices entered including or excluding VAT (package default: including, UK
   style), what the shop and the basket show, rounding, and the rate table per tax class. `commerce:install` seeds
   UK 20 % / 5 % / 0 % (`tax.install_rates`: `uk`, `eu` – check EU rates before selling there – or `none`); the
   importer brings WooCommerce's classes and rates when `settings.woocommerce` lists `tax.*`. Rates can also be
   imported from a WooCommerce tax-rate CSV on the same screen.
2. **Shipping zones** – Admin › Settings › Shipping: the countries you sell to, then zones in order (the first zone
   that matches the delivery address wins; postcode patterns like `BT*`, `HS1-HS9`, `!IV*`), each with its delivery
   options (flat rate, free shipping, weight or price bands, local pickup) and shipping classes. `commerce:install`
   creates a UK zone with free delivery; the importer brings WooCommerce's zones (`commerce-import.shipping.zones`).
   Check a UK address, a Highlands/Islands postcode and an unsupported country (shows "we don't deliver to …").
3. **Invoices** – Admin › Settings › Invoices: numbering on/off, prefix (`{Y}` = year), padding and the next number
   (continue the old shop's sequence if it had invoice numbers), when numbers are issued (paid/completed), PDF on the
   processing/completed emails, customer download, notes, footers, A4/Letter. The logo comes from Settings › Store.
   Check: pay a test order → the email carries `INV-…pdf`; Orders › order › Print › Invoice PDF.
4. **Product CSV** – Admin › Products › Import / Export (feature `product_csv`): export the catalogue as a backup
   before bulk edits; imports always start with the dry run. Server-side alternative:
   `php artisan commerce:products:import file.csv --dry-run`.
5. **Images** – new uploads get the configured sizes automatically; after the import run
   `php artisan commerce:images:generate --missing` (step 7).
6. **Cron and abandoned carts** – below.

### Cron – scheduled tasks (recommended)

The core has background jobs (cancel unpaid orders, abandoned-cart reminders, back-in-stock sweep, scheduled sale
prices, nightly tidy-up, daily low-stock email). **One** cron job runs them all, every minute:

```
* * * * * cd /home/acme/app && php artisan schedule:run >> /dev/null 2>&1
```

**cPanel:** *Advanced › Cron Jobs* → *Common Settings: Once Per Minute (\* \* \* \* \*)* → *Command*:
`cd /home/acme/app && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1` (use the full
path of a PHP 8.3+ CLI – plain `php` may be an older version in cron's environment) → *Add New Cron Job*. Leave the
"Cron Email" empty or cPanel emails you every minute. **Plesk:** *Scheduled Tasks › Add Task › Run a PHP script*
`artisan` with arguments `schedule:run`, every minute. Shell: `crontab -e` and paste the line.

Check (within 2 minutes):

```bash
php artisan commerce:schedule:status   # "Cron is running", each task with last/next run
php artisan commerce:doctor            # Server › Scheduler: PASS
```

Admin › Settings › Scheduled tasks shows the same table; `php artisan commerce:schedule:task {task}` runs one now.

**Without cron nothing breaks** (it degrades gracefully):
- unpaid card/PayPal orders are still cancelled when someone opens the checkout (as in v1.0);
- with `commerce.scheduler.web_fallback` (package default **on**) every due task runs right after a storefront page
  has been sent, at most every 5 minutes – fine for small shops, but reminders and reports then depend on traffic;
- with it off the other tasks simply wait for cron; `commerce:doctor` warns.

Developer switches: `config/commerce.php` → `scheduler` (`enabled`, `tasks` per key, `web_fallback`,
`heartbeat_minutes`). Owner switches: Admin › Settings › Scheduled tasks (low-stock email – off by default; how long
abandoned guest baskets are kept – 90 days, 0 = forever) and Admin › Settings › Abandoned carts.

### Abandoned-cart reminder emails (optional)

Off until the owner switches them on in **Admin › Settings › Abandoned carts** (needs cron or the web fallback):
- *Who can receive them*: only customers with marketing consent (default – account opt-in or newsletter subscriber),
  or everyone who typed an email address at checkout (a service reminder; check this fits your privacy policy);
- up to three emails (default 1 h and 24 h after the last basket activity; a third with a discount is prepared but
  off), each with its own subject, opening text and optional single-use discount code (% or fixed amount, only for
  that shopper's address, with an expiry);
- every email lists the basket, has a secure **Return to my basket** link (restores the basket in the shopper's
  browser, applies the code and opens the checkout) and an unsubscribe link (also one-click in Gmail/Apple Mail);
- reminders stop when the shopper orders, empties the basket or unsubscribes; staff can stop one basket in
  Orders › Abandoned checkouts › basket › *Stop reminders*. No tracking pixels – only link clicks are recorded;
- results: Orders › Abandoned checkouts (reminders sent, recovered baskets, recovered revenue – paid orders only),
  the dashboard card, and each basket's timeline.

Check on staging (`MAIL_MAILER=log`): switch reminders on with *Everyone*, add a product, type an email at checkout,
leave; after the first delay run `php artisan commerce:schedule:task carts.abandoned-emails` – the email is in
`storage/logs/`; open its *Return to my basket* link in another browser → the basket is restored at the checkout.

### Step 12 – SEO and redirects

- [ ] URL parity: `commerce:verify-urls` clean (step 7). Products and categories keep their WooCommerce paths;
      redirects from the Redirection plugin, Rank Math / Yoast Premium and old slugs are imported.
- [ ] Titles, meta descriptions, canonicals, Open Graph – spot-check against the old site (reference HTML).
- [ ] `/sitemap.xml`, `/robots.txt` (Settings › SEO › custom robots.txt), Google Shopping feed
      `/feeds/google-shopping.xml` if used (feature `google_feed`), structured data (Google Rich Results Test on a
      product page), the 404 page, old `?add-to-cart=` links.
- [ ] Analytics / Tag Manager id in Settings › SEO & tracking.

### Step 13 – QA checklist (staging)

- [ ] `php artisan commerce:doctor` – no FAIL, every WARN understood.
- [ ] `composer test` green.
- [ ] Browse on phone and desktop: home, category (filters, sort, page 2), product (variations, gallery, add to
      basket), search, basket (coupon, delivery options), checkout as guest and as customer, order received,
      My account (orders, addresses, password change), wishlist, reviews, contact form, newsletter sign-up, blog.
- [ ] Tax and shipping (Admin › Settings › Tax / Shipping): prices entered with or without VAT as the client wants,
      rates per class/country checked (the importer brings WooCommerce's when allowed), zones in the right order, every
      country the shop sells to has a delivery option; checkout with a UK, a Highlands/other-zone postcode and (if sold
      there) a foreign address shows the right options, VAT lines and totals.
- [ ] Each payment method end to end, including a failed card (`4000 0000 0000 0002`) and a refund.
- [ ] Emails for every order status (Admin: change an order's status) look right.
- [ ] Admin: create/edit a product with images and variations, process an order to completed, print invoice and
      packing slip, export orders CSV, edit home page sections and menus, add a redirect.
- [ ] Session expiry: open checkout, wait/clear cookies, submit → friendly message, typed data kept.
- [ ] Imported customers can sign in; order numbers continue the old sequence.
- [ ] The client has signed off the theme.

### Step 14 – Go-live checklist

Content freeze on the old site first (no new orders or edits), then, in order:

- [ ] **Backup** the new shop database (Part 4) – and keep the old site's database dump + files.
- [ ] **Final import** (delta: orders/customers since the last run): `php artisan commerce:import-wordpress --copy-uploads`.
- [ ] `.env`: `APP_URL=https://www.acme.co.uk`, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`,
      live `MAIL_*`; `php artisan optimize:clear && php artisan optimize`.
- [ ] **Payments** switched to live keys + live webhooks (new signing secrets); one real low-value order + refund.
- [ ] **DNS**: lower the TTL to 300 s a day before; switch A/AAAA for the apex and `www`; TLS certificate for every
      host name; the non-canonical host redirects to the canonical one. Keep the old server running until
      propagation is complete.
- [ ] **Robots**: untick *Ask search engines not to index this site* (Settings › SEO & tracking); `/robots.txt` allows
      crawling; no `X-Robots-Tag: noindex` header from the server config (`curl -sI https://www.acme.co.uk/ | grep -i robots`).
- [ ] **HSTS**: once https works on every host name, enable the `Strict-Transport-Security` line in `public/.htaccess`
      (start with a short `max-age` if unsure).
- [ ] **TRUSTED_PROXIES**: empty on a plain LiteSpeed host; behind Cloudflare / a load balancer set its IP ranges,
      otherwise customer IPs and https detection are wrong.
- [ ] **Backups** scheduled: daily database dump (host backup or cron + `mysqldump`), `public/storage` (media) and
      `.env`, kept off-server; a restore tested once.
- [ ] **Cron** (recommended – see "Cron" after Step 11): `* * * * * cd /home/acme/app && php artisan schedule:run >> /dev/null 2>&1`;
      `php artisan commerce:schedule:status` says "Cron is running".
- [ ] `php artisan commerce:verify-urls storage/app/old-sitemap.xml --base=https://www.acme.co.uk` – clean.
- [ ] `php artisan commerce:doctor` – no FAIL.
- [ ] Google Search Console: verify the property, submit `/sitemap.xml`, watch Pages/404s for two weeks; Merchant
      Center feed URL updated.
- [ ] Old WordPress: keep a read-only copy (files + database dump) for at least 3 months; disable its mail/cron so it
      stops emailing customers.

### Step 15 – Rollback plan (decide before go-live)

- **DNS rollback** (first hours, serious problem): point DNS back to the old server (short TTL makes this fast). Orders
  placed on the new site in the meantime must be re-entered on the old one – export them first
  (Admin › Orders › Export CSV).
- **Code rollback**: `git revert <commit>` (never reset/force on a shared branch), then the deploy commands
  (Part 3.3). For a platform update: set `pine/commerce` back to the previous tag (`composer require pine/commerce:1.0.3`),
  commit the lock file, deploy.
- **Data rollback**: restore the pre-change dump (Part 4) – only when nothing new was written since, otherwise
  repair forward.
- Write down who decides, and by when (e.g. "roll back if checkout is not fixed within 2 hours").

---

## Part 3 – Day to day: releasing and rolling out updates

### 3.1 Changing the platform (repo A)

```bash
cd ~/work/ecom-core && git switch main && git pull     # the clone of 1.3
git switch -c feature/short-name
# … change code, add tests …
composer test                            # standalone suite, in-memory SQLite
composer validate --strict
```

Work on it against a real client at the same time (your machine or a staging checkout, **never** a live server):
point the client at your clone with a temporary **path repository** – it must not be committed:

```bash
cd /path/to/client
cp composer.json composer.json.vcs && cp composer.lock composer.lock.vcs      # or rely on git checkout below
# edit composer.json by hand: "repositories": [{"type": "path", "url": "/home/you/work/ecom-core", "options": {"symlink": true}}]
#                             "require": { "pine/commerce": "*@dev", … }
composer update pine/commerce            # vendor/pine/commerce is now a symlink to the clone
php artisan optimize:clear && php artisan commerce:publish && php artisan commerce:theme:publish
php artisan config:clear && php artisan test    # + the client's regression check (3.4)
```

Switch back before committing anything in the client: `git checkout composer.json composer.lock && composer install`
(then `commerce:publish`, `commerce:theme:publish`, `optimize`). `git status` must not show `composer.json` or
`composer.lock` as changed.

Rules for platform changes (they keep every client working – [UPGRADING.md](UPGRADING.md)):

- Client-specific values go to config (neutral package default), the client theme or client code – never hard-coded.
  Repo A is public: no client names, data, hosts or credentials in code, tests or docs.
- New behaviour ships behind a config key or feature switch whose default keeps current behaviour.
- Migrations are **additive only** (new tables/columns); never rename or drop columns clients hold data in.
- Add a line under `## [Unreleased]` in `CHANGELOG.md` (config keys added, contract changes, client actions required).
- Clients with a regression check stay clean (3.4).

Merge with a pull request (review + green tests), then release.

### 3.2 Releasing a version

Pick the number (SemVer, [UPGRADING.md](UPGRADING.md)): **patch** 1.0.x = fixes only; **minor** 1.x.0 = additions
that keep current behaviour; **major** x.0.0 = anything that breaks the public API (theme contract, config keys,
`Commerce::` API, events, importer interfaces, route names, schema).

```bash
cd ~/work/ecom-core && git switch main && git pull
# 1. CHANGELOG.md: rename "## [Unreleased]" to "## [1.3.0] - 2026-10-15", add a fresh empty [Unreleased], update the links at the bottom
# 2. the same number in VERSION and in src/Commerce.php (const VERSION) – a test checks all three agree
composer test
git commit -am "Release v1.3.0"
git tag -a v1.3.0 -m "pine/commerce v1.3.0"
git push origin main v1.3.0
```

That is the whole release: there is no split or export step for the package. Optionally create a GitHub release
from the tag with the CHANGELOG section as notes. Then refresh repo B from the same clone and **tag it with the same
version** (skeleton baselines and Admin › Updates refer to skeleton tags):
`bin/export-client-skeleton.sh ~/work/ecom-skeleton --tag --push=git@github.com:SetWebUK/ecom-skeleton.git`.
Clients then pick the release up with `composer update pine/commerce` (3.3).

### 3.3 Rolling an update out to a client

Staging first, then live. From 1.3 an administrator can do this from Admin › Updates (3.6) – the same steps with a
backup, maintenance mode and an automatic rollback. By hand, on the client project (your machine or the staging server):

```bash
mysqldump --single-transaction acme_shop | gzip > ~/backups/acme_shop-$(date +%Y%m%d-%H%M)-pre-update.sql.gz
composer update pine/commerce            # ONLY this package – never a blanket `composer update` on a live site
git diff composer.lock                   # shows the version change
php artisan migrate --force              # additive package migrations
php artisan commerce:publish             # admin assets (copies)
php artisan commerce:theme:publish       # theme assets (copies)
php artisan optimize:clear && php artisan optimize
php artisan commerce:doctor
composer test
git commit -am "pine/commerce 1.1.0" && git push
```

(The skeleton's `composer deploy` = migrate + publish + theme:publish + optimize.) Then on each other server of that
client: `git pull && composer install --no-dev --optimize-autoloader && composer deploy`.

Read the release notes for: **config keys** added (a client file that overrides that top-level key must copy the new
sub-key – the merge is shallow, except `features`, which is merged key by key), **theme contract** changes
(`php artisan commerce:theme:check`), and **admin view overrides** in `resources/views/vendor/commerce/…`.

Check after the update: `commerce:verify-urls` against the saved sitemap, a test order, the regression check if the
client has one.

**v1.1 image sizes:** after updating, run `php artisan commerce:images:generate --missing` once (add `--scan` for
imported files without a library row) so existing media gets the new sizes; new uploads get them automatically. A
client whose `config/commerce.php` has its own `images` block keeps exactly those sizes (e.g. only the pre-v1.1
single 150×150 crop).

#### Upgrading a client from 1.0.x to 1.1 – checklist

- [ ] Backup, then on staging: `composer update pine/commerce` (adds `dompdf/dompdf`), `php artisan migrate --force`
      (five additive migrations), `commerce:publish`, `commerce:theme:publish`, `optimize:clear && optimize`,
      `commerce:doctor`.
- [ ] Decide per feature whether the client gets the new behaviour or keeps 1.0's (CHANGELOG 1.1.0 "Client actions
      required" lists the config blocks to add to keep 1.0's behaviour):
      `tax` (prices incl./excl. VAT), `shipping.install_zones`, `images`, `invoices`, `scheduler.web_fallback`.
- [ ] Settings › Tax and Settings › Shipping: the migration turned the old VAT rate into a rate row and put the old
      delivery options into one zone with the same totals – check a test basket's totals match the old site.
- [ ] `php artisan commerce:images:generate --missing` (`--dry-run` first; `--scan` for imported files).
- [ ] Add the cron line (above, "Cron – scheduled tasks") and check `php artisan commerce:schedule:status`.
- [ ] Settings › Invoices and Settings › Abandoned carts: switch on what the owner wants (reminders stay off).
- [ ] Custom theme not based on `default`: add `checkout/verify-email`, run `php artisan commerce:theme:check`.
- [ ] Tests, the regression check (3.4), a test order, then the same on live.

### 3.4 Regression check (clients with a like-for-like theme)

A client whose storefront must not change keeps a URL list (every page type, a few hundred URLs) and a baseline
snapshot of the rendered HTML in its project (`storage/app/regression/`, git-ignored), plus two small scripts in its
repository: one that fetches every URL into a directory (one file per URL), one that diffs two directories after
normalising what changes per request (ARCHITECTURE.md §14):

```bash
<snapshot script> storage/app/regression/after-<change>
<diff script> storage/app/regression/before storage/app/regression/after-<change>
# normalise CSRF tokens, nonces, ?v= asset versions, timestamps and date-driven text; print the differing pages
```

Zero unexplained differences, or it does not ship. When a change is meant to alter pages, list them in the merge
notes, then take a new baseline (`mv` the after- directory to `before`) once it is live.

### 3.5 Hotfixes

```bash
# repo A, from the tag the clients run
git switch -c hotfix/1.1.1 v1.1.0
# … the smallest possible fix + a test …
composer test
# CHANGELOG [1.1.1] + VERSION + Commerce::VERSION, commit "Release v1.1.1"
git tag -a v1.1.1 -m "pine/commerce v1.1.1" && git push origin hotfix/1.1.1 v1.1.1
git switch main && git merge --no-ff hotfix/1.1.1 && git push   # the fix must also reach main
```

Clients on `^1.1` get it with `composer update pine/commerce` (3.3). A client-only hotfix is an ordinary commit in its
repo C + deploy.

### 3.6 Updating from the admin (since 1.3)

From 1.3 a client can take a release **from the back office**: Admin › **Updates** (administrators only, feature
switch `updater`, on by default). It finds updates by itself, but **nothing is installed until an administrator
approves it** with their password.

**Finding updates.** Once a day (scheduler task `updates.check`, 06:15 – cron or the web fallback) and whenever an
administrator presses **Check now**, the updater reads the tags of the repository `composer.json` installs
`pine/commerce` from (`repositories` entry; GitHub: the public API, then `git ls-remote --tags`; other hosts: `git
ls-remote`). It offers the newest stable `vX.Y.Z` above the installed version **that the project's composer
constraint allows** (`^1.2` → 1.3.0 yes, 2.0.0 no). A newer major or a release outside the constraint is shown as
"requires a developer" – change `composer.json`, read the upgrade notes and test it first. The check fetches
`CHANGELOG.md` at the new tag and shows every section in between, with **Client actions required** highlighted.
The dashboard shows a notice and the sidebar's Updates entry a badge while an update is available. Each check is
kept in the history (who, when, what was found).

**Installing (approve & install).** The administrator reads the notes, presses *Approve & install*, re-enters their
password and ticks the confirmation. The update then runs **in the background** (`php artisan commerce:update:run
{id}` started with `setsid`/`nohup`; no queue worker needed) while the page follows its log live. Only one update
runs at a time (a lock file in `storage/app/private/updater/`). Steps:

1. **Pre-flight** – PHP CLI and composer found (from a web request PHP_BINARY is the web SAPI and HOME is often
   unset: the updater resolves both, see the config keys below), enough free disk space, `vendor/`, `composer.lock`,
   `bootstrap/cache`, `storage/` and `public/` writable, uncommitted changes in the project's git tree (a warning),
   `commerce:doctor` before the update.
2. **Database backup** – `mysqldump --single-transaction` (gzip) or a copy of the SQLite file, in
   `storage/app/private/updater/backups/` (`commerce.updater.backup_path`, never under `public/`), newest 5 kept.
3. **Record** `composer.json` + `composer.lock` and the installed version.
4. **Maintenance mode** – `php artisan down --secret=…`. The approving administrator gets the bypass cookie (they
   can keep using the site) and the run page shows the bypass link while it runs.
5. `composer update pine/commerce --with-dependencies --with=pine/commerce:X.Y.Z --no-interaction` – pinned to the
   approved version; the project's `preferred-install` is respected; `--no-dev` when the project was installed
   without dev packages.
6. `php artisan migrate --force` → `commerce:publish` → `commerce:theme:publish` → `optimize:clear` + `optimize`.
7. **Health check** – `commerce:doctor --json`; a check that fails now but did not fail before fails the update.
8. `php artisan up`.

**When a step fails** the updater restores `composer.json` / `composer.lock`, runs `composer install` (the previous
code comes back), publishes the admin and theme assets again from it, rebuilds the caches and brings the site up. The
run is marked *failed* with its full log. **The database is never restored automatically**: package migrations are
additive, so the previous version keeps working. If a migration ran and the shop misbehaves, restore the backup by
hand – the log and the run page print the command, e.g.

```bash
gunzip -c storage/app/private/updater/backups/db-20261015-0930-before-1.3.0.sql.gz | mysql -u acme_shop -p acme_shop
```

A run whose process disappears (server restart) is marked failed ("interrupted") the next time the page is opened –
check `php artisan up` and `composer install` by hand.

**From the command line** (same steps, same audit log):

```bash
php artisan commerce:update:check                 # what is available + changelog + client actions (never installs)
php artisan commerce:update:run --approve --yes   # approve the newest installable release as "CLI" and install it now
php artisan commerce:update:run 12                # run update #12, approved in the admin (fallback when the web server
                                                  # cannot start background processes, e.g. proc_open disabled)
```

`commerce:update:run` without an approved id refuses to run; `--approve` needs `--yes` when not interactive.

**Skeleton files.** Files a project got from the base system (repo B: `bootstrap/app.php`, `config/*.php` stubs,
`tests/TestCase.php` …) are updated separately, under *Project files from the skeleton*. `commerce:new-client`
writes `.commerce-skeleton.json` (skeleton tag, project name/slug, a hash of every file it wrote); projects made
before 1.3 record it once with `php artisan commerce:skeleton:baseline v1.3.0` (`--detect` compares the newest
skeleton releases with the project; `--disabled --note="…"` records it but switches skeleton updates off, e.g. for
a project that was not created from the skeleton). *Compare skeleton files* fetches the baseline tag and the newest
skeleton tag that is not newer than the installed core (shallow git checkouts in
`storage/app/private/updater/skeleton/`) and classifies every file the skeleton changed:

| Class | Meaning | Admin |
|---|---|---|
| New file / Unchanged here / Removed from the skeleton | the project never changed it | tick to apply (or "select all safe files"), password + confirm |
| Changed here and upstream / Deleted here / Exists here / Removed upstream, changed here | needs a developer: upstream and local line counts and the upstream diff are shown | never applied |
| Never updated automatically | `.env*` (not `.env.example`), `composer.lock`, `themes/`, `README.md`, `LICENSE`, `storage/` data, `public/vendor`, `public/assets` | never applied |

Applied files are backed up first (`storage/app/private/updater/skeleton-backups/{id}/`) and their hashes recorded;
the baseline moves to the new tag once nothing is left to apply or review. CLI: `php artisan commerce:skeleton:check
[--apply-safe --yes]`. The skeleton repository is tagged with the core version it was exported from (3.2), so
update the core first, then the skeleton files.

**The first update to 1.3.** A project on 1.2.x has no updater yet: take 1.3.0 the usual way (3.3), then use the
admin for every later release.

---

## Part 4 – Data safety rules

1. **Back up before every import, migration or platform update** – a dated dump outside the web root:
   `mysqldump --single-transaction --routines acme_shop | gzip > ~/backups/acme_shop-$(date +%Y%m%d-%H%M)-<reason>.sql.gz`.
   Restore: `gunzip -c ~/backups/<file>.sql.gz | mysql acme_shop` (only into a database you mean to overwrite).
2. **Never on a database with real data**: `php artisan migrate:fresh`, `migrate:refresh`, `migrate:reset`,
   `db:wipe`, `TRUNCATE`, `DROP TABLE`, `commerce:import-wordpress --fresh --force`. Tests use in-memory SQLite only.
3. **Guards that must stay in every client** (the skeleton has them):
   - `DB::prohibitDestructiveCommands()` in `AppServiceProvider` (on MySQL) – blocks the commands in rule 2;
   - `tests/TestCase.php` refuses `RefreshDatabase` / `DatabaseMigrations` / `DatabaseTruncation` unless the database
     is in-memory SQLite;
   - `phpunit.xml` forces SQLite `:memory:` and ignores the production config cache.
4. **Scratch tables** only on the `scratch` connection (`zz_` prefix – `commerce:scratch:drop` refuses anything else);
   drop them when done.
5. **The old WordPress database and files are read-only** for us (the `wordpress` connection forces read-only
   sessions). Never edit the old site.
6. **Never test against a live database by accident**: `php artisan config:clear` before `php artisan test` (a cached
   config would bypass phpunit's settings). Count key tables before and after if in doubt:
   `mysql acme_shop -e "select (select count(*) from products), (select count(*) from orders), (select count(*) from users)"`.
7. **Live servers are not workbenches**: change code in a checkout elsewhere, test, commit, then deploy. Editing files
   in place on a live site has caused errors for real customers.
8. Secrets (`.env`, payment keys, `auth.json`, tokens) never go into git, tickets or chat.

---

## Part 5 – Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Images/uploads give **403** or 404 at `/storage/…` | `public/storage` is a **symlink** (LiteSpeed does not follow it) or missing | `rm public/storage` (only if it is a symlink), then `php artisan commerce:install` (creates the real directory + `.htaccess`, safe to re-run); re-copy media with `commerce:import-wordpress --only=media --copy-uploads`. Never `storage:link`. |
| **Admin has no CSS/JS** (unstyled back office) | published admin assets missing or old (after deploy/update) | `php artisan commerce:publish` (then `php artisan optimize`) |
| Storefront CSS/JS missing or old | theme assets not published | `php artisan commerce:theme:publish` (`--prune` removes deleted files) |
| Every URL redirects to add or remove a **trailing slash**, or loops | WordPress-style URLs end with `/` (`commerce.urls.trailing_slash`); a server/CDN rule strips it | remove the server/CDN rule; links in content should end with `/` |
| **419 Page expired** | the session expired or the cookie was blocked (form left open, `SESSION_SECURE_COOKIE=true` on http, wrong `SESSION_DOMAIN`, cached pages with a stale token) | storefront forms recover automatically (message + typed data kept); check `APP_URL` is https, `SESSION_DOMAIN=null`, no page cache on forms (LiteSpeed Cache off for this site) |
| **Emails not arriving** | `MAIL_MAILER=log` (default on staging) – they are written to the log (`storage/logs/laravel-*.log`) | set SMTP in `.env` (Part 2 step 11), `php artisan optimize:clear && php artisan optimize`; `commerce:doctor` → Mail |
| Importer: product/category **URLs differ from the old site** | permalink plugin not recognised, wp-cli missing | read the URL parity note in the import summary; `--detect` shows the permalink provider; add a PermalinkProvider adapter or let the generated redirects cover it; then `commerce:verify-urls` ([IMPORTER.md](IMPORTER.md) §5, §11) |
| Importer: "Cannot open the WordPress database" | wrong `WP_PATH` / `WP_DB_*`, non-literal values in wp-config | `--detect` shows where each credential came from; pass `--db-*` explicitly |
| Importer: "Several WordPress installs … pass --prefix" | more than one install in that database | `--prefix=wp_` (the right one) |
| Importer: page bodies empty or shortcode soup | page-builder content needs a rendered copy | `--site-url` (+ `--site-host`) or `--snapshots=storage/app/wp-reference/html` |
| Importer: media missing | uploads not copied | `--copy-uploads` (or `--uploads-path=` for a copied directory) |
| Product/blog images load the **full-size original** (slow pages, no `srcset`) | the size variants were never generated (media imported or uploaded before v1.1, or sizes changed) | `php artisan commerce:images:generate --missing --dry-run`, then without `--dry-run` (`--scan` for files without a library row); check the driver line it prints (Imagick or GD) |
| `commerce:images:generate` reports **unsupported** / "Neither GD nor Imagick" | PHP extension missing, or the format (e.g. AVIF) is not compiled into it | install/enable `imagick` or `gd` (with WebP/AVIF support); `commerce.images.driver` forces one; `php -r 'print_r(gd_info());'` |
| Phone photos appear **sideways** | uploaded before v1.1 or `commerce.images.auto_orient` off | re-upload (new uploads are rotated by their EXIF orientation); generated sizes are rotated anyway |
| Changes to `.env`/config/routes have no effect | config/route cache | `php artisan optimize:clear && php artisan optimize` |
| 500 error | see the log | `tail -n 50 "$(ls -t storage/logs/laravel*.log \| head -1)"` (daily files `laravel-YYYY-MM-DD.log`, or `laravel.log`); `php artisan commerce:doctor` |
| **Admin login returns to the login form** (no error) | `SESSION_SECURE_COOKIE=true` while the site is reached over plain http | use https, or `SESSION_SECURE_COOKIE=false` for a local http rehearsal; `php artisan optimize:clear && php artisan optimize` |
| `/sitemap.xml`, `/robots.txt`, feeds 404 on a local `php -S` | the built-in server treats `.xml`/`.txt` as static files | `php artisan serve`, or `cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` (run it from `public/`: the router loads `getcwd()/index.php`, so from the project root every page is an empty 500) |
| Redirects or links point at the **old host** after the import | the host is not in the importer's `legacy_hosts` | add it to `config/commerce-import.php` → `legacy_hosts` (and `config/commerce.php` → `legacy_hosts`), re-run `commerce:import-wordpress --only=content,menus,redirects` |
| `composer install` asks for GitHub credentials / "Repository not found" | an SSH URL without a key GitHub accepts, a private fork, or the API rate limit | use the public HTTPS URL, or Part 1.6 (token in `~/.composer/auth.json` or deploy key) |
| "Theme [acme] is not installed" in the log | `COMMERCE_THEME` names a theme that does not exist | create it (`commerce:theme:make`) or set `COMMERCE_THEME=default` |
| Abandoned-cart reminders / low-stock email never arrive | no cron and the web fallback is off, the setting is off, or no marketing consent | `php artisan commerce:schedule:status` (why each task is idle); add the cron job; Settings › Abandoned carts |
| Old WordPress `.htaccess` breaks the app (white page, Wordfence errors) | `auto_prepend_file` inherited from a parent directory | uncomment `php_value auto_prepend_file none` in `public/.htaccess` |

---

## Part 6 – Reference

### 6.1 Commands

| Command | Options | What it does |
|---|---|---|
| `commerce:new-client {path}` | `--name=` `--slug=` `--repo=<git url>` (VCS, `^1.0`) \| `--path=<dir>` (path repository, `*@dev`) `--constraint=` `--force` (aliases `--vcs`, `--package`) | new client project from `stubs/client-skeleton` |
| `commerce:install` | `--connection=` `--admin-email=` `--admin-name=` `--admin-password=` `--force-admin` `--store-name=` `--store-email=` `--theme=` `--order-start=` (1000) `--skip-publish` `--no-optimize` | migrate, first admin, default settings / delivery / pages / menus, `public/storage`, publish, optimize. Idempotent |
| `commerce:doctor` | `--connection=` `--json` `--only-problems` | health checks (PASS/WARN/FAIL/INFO) with fixes; exit 1 on FAIL |
| `commerce:publish` | `--force` (also overwrite the placeholder image) | copy admin assets into `public/` |
| `commerce:theme:make {slug}` | `--parent=default` `--name=` `--copy` (full fork) | scaffold a theme |
| `commerce:theme:publish [slug]` | `--all` `--prune` | copy theme assets into `public/` |
| `commerce:theme:check [slug]` | – | validate theme.json, parent chain and the theme contract |
| `commerce:theme:cache` / `commerce:theme:clear` | – | theme manifest cache (also run by `optimize` / `optimize:clear`) |
| `commerce:import-wordpress` (alias `import:wordpress`) | `--wp-path=` `--db-host= --db-port= --db-name= --db-user= --db-pass= --db-socket=` `--prefix=` `--site-url= --site-host=` `--snapshots=` `--uploads-path=` `--copy-uploads` `--target=` `--only=` `--skip=` `--orders-source=auto\|hpos\|posts` `--core-only` `--no-wp-cli` `--dry-run` `--fresh` `--force` `--detect` | one-way, idempotent WordPress/WooCommerce import ([IMPORTER.md](IMPORTER.md)) |
| `commerce:verify-urls {source}` | `--base=` `--resolve=<ip>` `--no-follow` `--allow-302` `--concurrency=8` `--timeout=20` `--limit=` `--insecure` `--report=<csv>` | every old URL must answer 200 or 301→200 |
| `commerce:products:export` | `--output=<file>\|-` `--status=` `--category=` `--stock=` `--type=` `--q=` `--no-variations` | full product CSV ([PRODUCT-CSV.md](PRODUCT-CSV.md)) |
| `commerce:products:import {file}` | `--dry-run` `--update-existing` `--match=sku\|id` `--create-missing` `--download-images` `--overwrite-empty` `--chunk=100` `--report=<csv>` | import products (full product CSV or a WooCommerce product export) in batches |
| `commerce:scratch:drop` | `--connection=scratch` `--force` | drop `zz_` test tables |
| `commerce:images:generate` | `--missing` `--size=<name>` (repeatable) `--path=<folder or file>` `--scan` `--no-webp` `--dry-run` `--chunk=100` `--limit=` `-v` | (re)build the `commerce.images.sizes` variants + WebP twins of existing uploads; never modifies/deletes an original or overwrites another library item; `--missing` never replaces any file; exit 1 when a file failed |
| `commerce:schedule:status` | `--json` | cron heartbeat, every core scheduled task with last/next run |
| `commerce:schedule:task {task}` | – | run one scheduled task now (e.g. `carts.abandoned-emails`, `maintenance.prune`) |
| `commerce:update:check` | `--json` | newest installable pine/commerce release, majors that need a developer, changelog + client actions, skeleton status (3.6). Never installs |
| `commerce:update:run [id]` | `--approve` `--yes` | install update `{id}` approved in Admin › Updates, or approve the newest release from the CLI (`--approve --yes`): backup, maintenance, composer, migrate, publish, health check, rollback on failure |
| `commerce:skeleton:baseline [ref]` | `--detect` `--write` `--name=` `--slug=` `--repository=` `--disabled` `--enabled` `--note=` | show or record `.commerce-skeleton.json` (the skeleton release the project matches) |
| `commerce:skeleton:check` | `--apply-safe` `--yes` | compare the project with the newest skeleton release; apply the files nobody changed here |

Repo A script: `bin/export-client-skeleton.sh <out-dir> [--repo=] [--constraint=] [--push=] [--tag] [--name=] [--slug=]`
(renders repo B, Part 1.4).

### 6.2 Configuration

Package defaults: `config/commerce.php` and `config/commerce-import.php` in repo A (every key commented). A client
overrides keys in its own `config/commerce.php` / `config/commerce-import.php`; **top-level keys replace the whole
package block** (shallow merge) – except `features`, merged key by key. Contract: [ARCHITECTURE.md](ARCHITECTURE.md) §10.

| `commerce.*` key | For |
|---|---|
| `theme` | active theme (`COMMERCE_THEME`); Admin › Settings › Theme can override at runtime |
| `legacy_hosts` | old host names rewritten to the current host in imported content |
| `routes`, `urls.trailing_slash` | storefront/admin route loading, WordPress-style trailing slashes |
| `admin.path`, `admin.assets_url`, `admin.brand`, `admin.menu` | back-office URL, published asset URL, branding, extra menu entries |
| `store` (country, countries, timezone, locale), `currency` | shop locale and money format |
| `features` | feature switches (6.3) |
| `catalog` | presenter, per page, filters, facet sorter, condition/brand attributes, spec attributes, legacy page params |
| `cart`, `checkout`, `forms`, `orders.reference_prefix` | cookie names, contact form, order reference prefix |
| `pay_in_3`, `shipping.carriers`, `feeds.google`, `documents.logo`, `media.placeholder` | instalment line, tracking carriers, Google feed, print logo, placeholder image |
| `images` (generate_on_upload, driver, sizes, webp, picture_webp, quality, auto_orient, max_dimension, strip_metadata) | image sizes generated on upload / by `commerce:images:generate`, served by `media_url($path, $size)`, `image_srcset()`, `<x-media-image>` (THEMES.md "Images"); a missing sub-key falls back to the package default |
| `tax.*` (prices_include_tax, display_shop, display_cart, rounding, based_on, shipping_*, adjust_non_base_prices, price_suffix, label, install_rates) | tax defaults – Admin › Settings › Tax overrides each; new stores: prices incl. VAT, UK rates seeded ([TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md)) |
| `shipping.install_zones` | `commerce:install` creates a UK zone with free delivery (default true) |
| `invoices.*` (`numbering`, `prefix`, `suffix`, `padding`, `start`, `assign_on`, `attach.*`, `customer_download`, `paper`, `cache`) | defaults of Settings › Invoices – sequential invoice numbers, PDF on order emails, customer download ([INVOICES.md](INVOICES.md)) |
| `settings.defaults` | default settings seeded by `commerce:install` |
| `scheduler` (`enabled`, `tasks`, `web_fallback`, `heartbeat_minutes`) | core scheduled tasks and the no-cron fallback ("Cron" after Step 11) |
| `updater.*` (`repository`, `default_repository`, `skeleton_repository`, `github_token`, `check`, `php_binary`, `composer_binary`, `mysqldump_binary`, `git_binary`, `home`, `composer_home`, `path`, `backup`, `backup_path`, `keep_backups`, `min_free_mb`, `timeout`, `http_timeout`, `git_timeout`) | Admin › Updates (3.6; every key in [EXTENDING.md](EXTENDING.md) "Updates") |
| `payments.gateways` | payment gateway classes in checkout order |
| `content` (shortcode aliases, legacy class prefix) | content rendering |

| `commerce-import.*` key | For |
|---|---|
| `source` (connection, wp_path, site_url, site_host, snapshots) | where the old site is (`WP_*` env) |
| `legacy_hosts`, `content.*`, `seo.rendered_fallback` | content rewriting, page-builder selectors, shortcodes |
| `attributes.map`, `attributes.filterable` | WooCommerce attributes → product columns / shop filters |
| `menus.by_term_id`, `menus.by_location`, `menus.import_unassigned` | WordPress menus → theme menu locations |
| `orders.source`, `orders.meta_keys` | HPOS/posts order storage, custom order meta |
| `media.*`, `elementor.css_path` | upload copy rules, quarantine, Elementor CSS |
| `acf.*`, `settings.*` | ACF fields, settings seeding (`settings.woocommerce` listing `tax.*` keys also imports WooCommerce's tax classes/rates – step `extras.tax`) |
| `shipping.zones` | WooCommerce zones/methods/classes as shipping zones (default true; false = the pre-v1.1 flat list) |
| `adapters.extra`, `adapters.disable`, `adapters.enable` | client adapters, switching built-in adapters off/on |

Environment variables (`.env`, documented in the skeleton's `.env.example`): `APP_*`, `DB_*`, `DB_SCRATCH_PREFIX`,
`COMMERCE_THEME`, `WP_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD/SOCKET/PREFIX`, `WP_PATH`, `WP_SITE_URL`, `WP_SITE_HOST`,
`MAIL_*`, `SESSION_*`, `QUEUE_CONNECTION=sync`, `TRUSTED_PROXIES`.

### 6.3 Feature switches (`commerce.features.*`)

On by default: `blog`, `wishlist`, `reviews`, `stock_alerts`, `newsletter`, `contact_form`, `order_tracking`,
`quick_view`, `google_feed`, `abandoned_carts`, `coupons`, `guest_checkout`, `registration`, `reports`, `redirects`,
`multi_shipping`, `product_brand`, `legacy_content`, `wp_404_guess`, `add_to_cart_query`, `product_csv`, `updater`.
Off by default: `product_condition`, `spec_highlights`, `pay_in_3`.

A switched-off feature is off everywhere: its routes answer 404 (names kept), admin pages and menu entries
disappear, the shipped themes hide its entry points, sitemap/emails/importer steps follow. What each one gates:
[EXTENDING.md](EXTENDING.md) "Feature switches". Current state per site: Admin › Settings › System.

### 6.4 Extension points

| Where | What | Docs |
|---|---|---|
| `App\Providers\ClientServiceProvider` | `Commerce::gateway()`, `shippingCalculator()`, `adminRoutes()`, `adminMenu()`, `settings()`, `settingsFields()`, `dashboardWidget()`, `pageTemplate()`, `shortcode()`, `menuLocation()`, `presenter()`, `presenterMethod()`, `facetSorter()`, `onOrderStatus()`, `onOrderPlaced()`, `orderEmail()`, `importAdapter()`, `importStep()` | [EXTENDING.md](EXTENDING.md) |
| Theme (`themes/{slug}`) | views of the theme contract, assets, theme config, `Theme.php` (`ThemeDefinition`: body classes, composers, boot) | [THEMES.md](THEMES.md), [ARCHITECTURE.md](ARCHITECTURE.md) §7–§8 |
| Importer | adapters (`Pine\Commerce\Import\Contracts\*`), steps, `config/commerce-import.php` | [IMPORTER.md](IMPORTER.md) |
| Events | `Pine\Commerce\Events\OrderPlaced`, `OrderStatusChanged` | [EXTENDING.md](EXTENDING.md) "Order events" |
| Admin view overrides | `resources/views/vendor/commerce/…` (diff them after every update) | [ADMIN_UI.md](ADMIN_UI.md) |

### 6.5 Documents

| Document | Read it for |
|---|---|
| [README.md](README.md) | index of the platform docs |
| [ARCHITECTURE.md](ARCHITECTURE.md) | the binding design contract (layers, theme contract, config, extension API, importer) and implementation notes (§18) |
| [THEMES.md](THEMES.md) | writing and forking themes |
| [IMPORTER.md](IMPORTER.md) | the WordPress/WooCommerce importer in depth |
| [EXTENDING.md](EXTENDING.md) | client code: feature switches and the extension API |
| [UPGRADING.md](UPGRADING.md) | versioning rules and what counts as a breaking change |
| [ADMIN_UI.md](ADMIN_UI.md) | back-office components and conventions |
| [../README.md](../README.md), [../CHANGELOG.md](../CHANGELOG.md) | package quick reference, release history |
