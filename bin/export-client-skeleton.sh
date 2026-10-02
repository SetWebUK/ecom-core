#!/usr/bin/env bash
# =====================================================================================================================
# Export the base system ("ecom-skeleton", repo B in docs/PLAYBOOK.md part 1) from stubs/client-skeleton of this
# package: a runnable Laravel 13 app that pulls in pine/commerce from its VCS repository through composer. It is
# rendered by the same code as `php artisan commerce:new-client` (name "Commerce Skeleton"), so repo B and new client
# projects never drift apart.
#
# Run it from a clone of pine/commerce (ecom-core) after `composer install` (it boots the package on Orchestra
# Testbench, a require-dev dependency – no Laravel app needed):
#
#   bin/export-client-skeleton.sh ../ecom-skeleton
#   bin/export-client-skeleton.sh ../ecom-skeleton --push=git@github.com:SetWebUK/ecom-skeleton.git
#   bin/export-client-skeleton.sh ../ecom-skeleton --repo=git@github.com:SetWebUK/ecom-core.git --constraint=^1.2
#   bin/export-client-skeleton.sh ../ecom-skeleton --tag --push=git@github.com:SetWebUK/ecom-skeleton.git   (release)
#
# <out-dir> empty/missing: a new git repository (branch main) with one commit.
# <out-dir> already a git checkout of repo B: its files are replaced (git history kept) and the changes committed –
# re-run after every skeleton change in pine/commerce.
# Options: --repo=<pine/commerce git url> (default https://github.com/SetWebUK/ecom-core.git), --constraint=
#          (default ^MAJOR.MINOR of VERSION), --name=, --slug=, --push=<repo B git url> (push main after committing),
#          --tag (tag the skeleton vVERSION – skeleton baselines / Admin › Updates use these tags; pushed with --push).
# =====================================================================================================================
set -euo pipefail

OUT=""
REPO="https://github.com/SetWebUK/ecom-core.git"
CONSTRAINT=""
NAME="Commerce Skeleton"
SLUG="commerce-skeleton"
PUSH=""
TAG=0
for arg in "$@"; do
    case "$arg" in
        --repo=*) REPO="${arg#*=}" ;;
        --constraint=*) CONSTRAINT="${arg#*=}" ;;
        --name=*) NAME="${arg#*=}" ;;
        --slug=*) SLUG="${arg#*=}" ;;
        --push=*) PUSH="${arg#*=}" ;;
        --tag) TAG=1 ;;
        -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
        -*) echo "Unknown option: $arg (see --help)" >&2; exit 2 ;;
        *) OUT="$arg" ;;
    esac
done
if [ -z "$OUT" ]; then
    echo "Usage: bin/export-client-skeleton.sh <out-dir> [--repo=<pine/commerce git url>] [--constraint=] [--push=<git url>]" >&2; exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ ! -f "$ROOT/composer.json" ] || [ ! -d "$ROOT/stubs/client-skeleton" ]; then
    echo "ERROR: $ROOT is not a pine/commerce checkout." >&2; exit 1
fi
if [ ! -f "$ROOT/vendor/autoload.php" ] || [ ! -d "$ROOT/vendor/orchestra/testbench-core" ]; then
    echo "ERROR: run 'composer install' in $ROOT first (the export boots the package on Orchestra Testbench)." >&2; exit 1
fi
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
if [ -z "$CONSTRAINT" ]; then
    CONSTRAINT="^$(echo "$VERSION" | cut -d. -f1-2)"
fi
OUT="$(mkdir -p "$OUT" && cd "$OUT" && pwd)"
case "$OUT/" in
    "$ROOT"/*) echo "ERROR: export outside the package checkout (got $OUT)." >&2; exit 1 ;;
esac

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "==> rendering stubs/client-skeleton (\"$NAME\", pine/commerce $CONSTRAINT from $REPO)"
(cd "$ROOT" && php -d memory_limit=512M -r '
    require "vendor/autoload.php";
    [$target, $name, $slug, $repo, $constraint] = array_slice($argv, 1);
    $app = Orchestra\Testbench\Foundation\Application::create(options: [
        "extra" => ["providers" => [Pine\Commerce\CommerceServiceProvider::class], "dont-discover" => ["*"]],
    ]);
    $status = $app->make(Illuminate\Contracts\Console\Kernel::class)->call("commerce:new-client", [
        "path" => $target, "--name" => $name, "--slug" => $slug, "--repo" => $repo, "--constraint" => $constraint,
        "--no-interaction" => true,
    ]);
    exit($status);
' -- "$TMP/skeleton" "$NAME" "$SLUG" "$REPO" "$CONSTRAINT" >/dev/null)
rm -f "$TMP/skeleton/.env"          # generated from .env.example for a real project; never part of the repository

# the base system is published under the MIT licence, like pine/commerce (a client project may change it)
cp "$ROOT/LICENSE" "$TMP/skeleton/LICENSE"

cat >> "$TMP/skeleton/README.md" <<EOF

## About this repository – the base system

This is the **base system** of Pine Commerce: a plain Laravel 13 application that pulls in the platform,
[\`pine/commerce\`](https://github.com/SetWebUK/ecom-core), through composer (\`composer.json\` → \`repositories\`:
\`{"type": "vcs", "url": "$REPO"}\`, \`"pine/commerce": "$CONSTRAINT"\`). The shop itself – storefront, back office,
default theme, WordPress importer – lives in \`vendor/pine/commerce\` and is upgraded with
\`composer update pine/commerce\`. Everything a client changes lives in this app: \`config/commerce*.php\`,
\`themes/\`, \`app/Providers/ClientServiceProvider.php\`, \`app/Import/\`.

Try it locally (SQLite, PHP 8.3+):

    git clone https://github.com/SetWebUK/ecom-skeleton.git shop && cd shop
    composer install
    cp .env.example .env && php artisan key:generate
    # .env: DB_CONNECTION=sqlite, DB_DATABASE=/absolute/path/to/database/database.sqlite, APP_URL=http://127.0.0.1:8000,
    #       SESSION_SECURE_COOKIE=false
    touch database/database.sqlite
    php artisan commerce:install --admin-email=you@example.test --store-name="Demo Shop"
    php artisan serve

### Installing pine/commerce: public HTTPS (default) or SSH

- **HTTPS** (\`https://github.com/SetWebUK/ecom-core.git\`, the default): the repository is public, so no credentials
  are needed and composer downloads release zips (\`preferred-install: dist\`). Add a read-only GitHub token to
  \`~/.composer/auth.json\` only if you hit GitHub's anonymous API rate limit.
- **SSH** (\`git@github.com:SetWebUK/ecom-core.git\`): needs a key GitHub accepts (deploy key or your own). For a
  **private** copy of the core reached over SSH only, also set
  \`"config": {"preferred-install": {"pine/commerce": "source", "*": "dist"}}\` so composer clones over git instead
  of downloading a zip through the GitHub API (which needs a token for private repositories).

## Starting a new client from this repository

Recommended (names everything for you):

    git clone https://github.com/SetWebUK/ecom-skeleton.git /tmp/commerce-skeleton && cd /tmp/commerce-skeleton
    composer install
    php artisan commerce:new-client /var/www/acme/app --name="Acme Tools" --slug=acme --repo=$REPO --constraint=$CONSTRAINT

Or copy it by hand: clone into the new project directory, \`rm -rf .git && git init\`, then replace
"Commerce Skeleton" / "commerce-skeleton" in \`composer.json\`, \`.env.example\` and this README. A client's own
repository is normally private: change \`"license"\` in \`composer.json\` and the LICENSE file as you need.

This repository is exported from pine/commerce (\`bin/export-client-skeleton.sh\` in the ecom-core repository) –
change the skeleton in pine/commerce \`stubs/client-skeleton\`, not here.
EOF

if [ ! -d "$OUT/.git" ]; then
    if [ -n "$(ls -A "$OUT")" ]; then
        echo "ERROR: $OUT is not empty and not a git checkout." >&2; exit 1
    fi
    git -C "$OUT" init --quiet -b main
fi
rsync -a --delete --exclude=.git "$TMP/skeleton/" "$OUT/"

SOURCE="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unversioned)"
git -C "$OUT" add -A
if git -C "$OUT" diff --cached --quiet; then
    echo "==> $OUT is already up to date (nothing to commit)"
else
    git -C "$OUT" commit --quiet -m "Base system (client skeleton) from pine/commerce $VERSION ($SOURCE)"
    echo "==> committed: $(git -C "$OUT" log --oneline -1)"
fi

if [ "$TAG" = 1 ]; then
    if git -C "$OUT" rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null; then
        echo "==> tag v$VERSION already exists in $OUT (not moved)"
    else
        git -C "$OUT" tag -a "v$VERSION" -m "Base system (client skeleton) for pine/commerce $VERSION"
        echo "==> tagged v$VERSION"
    fi
fi

if [ -n "$PUSH" ]; then
    git -C "$OUT" push "$PUSH" main
    if [ "$TAG" = 1 ]; then
        git -C "$OUT" push "$PUSH" "v$VERSION"
    fi
fi

cat <<EOF

Done: $OUT ($(git -C "$OUT" ls-files | wc -l) files).
  first push:  cd $OUT && git remote add origin git@github.com:SetWebUK/ecom-skeleton.git && git push -u origin main
  check:       cd $OUT && composer validate && composer install
EOF
