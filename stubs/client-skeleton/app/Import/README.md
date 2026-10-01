# Client importer adapters

Code in this directory adapts the WordPress/WooCommerce importer (`php artisan commerce:import-wordpress`) to this
client's old site: extra product fields from post meta / ACF, menus rebuilt from the rendered site, settings scraped
from the old header/footer, extra shipping methods, redirects for a custom URL scheme.

- Namespace `App\Import\…`; register adapters in `config/commerce-import.php` → `adapters.extra`.
- The source database is read through the importer's read-only `wordpress` connection - never write to it.
- Always test against the scratch connection first:
  `php artisan commerce:install --connection=scratch` → import into it (see `--target` in pine/commerce docs/IMPORTER.md)
  → compare → `php artisan commerce:scratch:drop`.

See pine/commerce docs/PLAYBOOK.md (part 2, "Import") and pine/commerce docs/IMPORTER.md for the adapter interfaces.
