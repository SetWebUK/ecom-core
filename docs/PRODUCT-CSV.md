# Product CSV import / export

Admin › Products › **Import / Export** (feature switch `product_csv`, on by default) and the commands
`commerce:products:export` / `commerce:products:import`. One spreadsheet holds the whole catalogue: products, their
variants, categories, images, attributes, specifications, SEO fields and stock. A WooCommerce product export
(WooCommerce › Products › Export) can be imported as it is.

The older Inventory CSV (Products › Inventory: SKU, quantity and prices only) is unchanged.

## Export

- **All products** or **the current list filters** (open Import / Export from the Products list and the filters
  come along: tab, search, category, stock, type, price, condition, brand).
- One row per product, then one row per variant (`type` = `variation`, `parent_sku` = the product's SKU, or
  `id:123` when it has none). Deleted products are left out unless the Deleted tab is filtered.
- UTF-8 with BOM (Excel shows £ and accents). A cell that a spreadsheet would run as a formula (`=`, `+`, `-`, `@`)
  starts with an apostrophe; the import removes it again.

### Columns

| Column | Content |
|---|---|
| `id` | this shop's product ID (variant rows: the variant ID) |
| `type` | `simple`, `variable` (product with variants) or `variation` |
| `sku`, `name`, `slug` | slug = URL handle (made unique; a changed slug of a published product gets a redirect) |
| `status` | `published`, `draft`, `private` (variants: `published` = active) |
| `parent_sku` | variants only: parent SKU or `id:123` |
| `categories` | `Parent > Child` paths separated by ` \| ` |
| `primary_category` | the main category (URL); default = the first category |
| `short_description`, `description` | HTML |
| `regular_price`, `sale_price`, `sale_starts_at`, `sale_ends_at` | dates in UK time: `2026-10-31` or `2026-10-31 18:00` (a date-only end runs to 23:59). Products with variants take their price from the variants (left empty) |
| `cost_price`, `tax_status`, `tax_class` | variants (since 1.2): `tax_class` is the variant's own class – `parent` (as in WooCommerce exports; also `same as product`) or an empty cell = same as the product, `standard` = standard rate even under a reduced-rate product |
| `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `low_stock_threshold` | a quantity switches stock tracking on |
| `weight`, `length`, `width`, `height` | |
| `shipping_class` | only when the schema has shipping classes (`products.shipping_class_id` → `shipping_classes`, written as the class slug; or a `products.shipping_class` column). A class that doesn't exist is created with “create missing”, otherwise the row is rejected. Variants (since 1.2, core shipping classes): the variant's own class; empty or `parent` = same as the product |
| `images` | URLs separated by ` \| `, first = main image (variants: their image) |
| `attributes` | `Memory: 8GB, 16GB \| Colour: Silver` |
| `variation_options` | variants: `Memory: 16GB \| Colour: Silver` |
| `specs` | `Screen: 14 inch \| Ports: USB-C, HDMI` |
| `meta_title`, `meta_description`, `featured` | |
| `condition`, `brand` | only while `product_condition` / `product_brand` are on (they also set the Condition/Brand attribute, like the editor's side panel) |
| `subtitle`, `gtin`, `mpn` | |

A literal `|` or `\` inside a value is written `\|` / `\\`; inside an attribute value a comma is `\,`.

## Import

1. **Upload** a `.csv` (comma, semicolon or tab separated; UTF-8 or Excel's Windows-1252). Limits:
   `commerce.product_csv.max_rows` (10,000) and `max_file_kb` (20 MB).
2. **Map columns.** Headers are matched automatically (export names, common names and WooCommerce's, e.g.
   `Published`, `In stock?`, `Weight (kg)`, `Attribute 1 name / value(s) / visible`, `Meta: _yoast_wpseo_title`,
   `Brands`, `GTIN, UPC, EAN, or ISBN`, `Tax class` / `Shipping class` – on variation rows too); unmapped columns are ignored. Options:
   - **Update existing products** (default on) – off: matching rows are skipped.
   - **Match by** SKU (default) or ID. Rows without a SKU match by their ID column (this shop's ID; in a
     WooCommerce file the WooCommerce ID the product was imported from, `products.wp_id`). Variants also match by
     their options within the parent. SKUs stay unique across products and variants.
   - **Create missing categories and attributes** (default on) – off: unknown ones are skipped with a warning.
   - **Download images** (default on) – other sites' image URLs are downloaded into the media library once
     (`media.source_hash` remembers the URL, so the next import reuses it) and processed like an admin upload (the
     core image sizes when this core version has them, otherwise the 150×150 media-browser thumbnail). This shop's
     own image URLs/paths are used as they are. Only public hosts (no private/reserved addresses, redirects re-checked), raster images up to
     `max_image_kb`, `image_timeout` seconds each.
   - **Empty cells**: keep the current value (default) or clear it.
3. **Dry run** – every row is read, matched and checked; nothing is written. The result lists new / updated /
   skipped / error rows with warnings (new categories, images to download, …) and can be downloaded as CSV.
4. **Import** – the page sends one request per batch (`chunk_size` rows, 25; hard cap 200; `time_budget` 20 s),
   so no queue worker or cron is needed. Pause/continue any time; if the tab closes, reopen the import from
   Import / Export and it carries on where it stopped (a batch cut off half-way is recovered from the per-row
   results, so rows are not imported twice). Each row is saved in its own transaction; a row with an invalid cell
   is not saved at all. Afterwards: storefront caches cleared, parent prices/stock of products with variants
   refreshed, back-in-stock emails sent for items that came back.
5. **Report** – per row: line, result, type, SKU, name, ID, messages (CSV download).

Uploads, state and reports live on the private disk (`commerce.product_csv.disk`, `local`) under
`commerce/product-imports/{token}/` and are deleted after `keep_days` (14). An import is visible to the staff member
who uploaded it and to administrators.

## Commands

```bash
php artisan commerce:products:export [--output=file.csv|-] [--status=active] [--category=12] [--stock=] [--type=] [--q=] [--no-variations]
php artisan commerce:products:import products.csv --dry-run
php artisan commerce:products:import products.csv --update-existing [--match=sku|id] [--create-missing] [--download-images] [--overwrite-empty] [--chunk=100] [--report=report.csv]
```

The command maps columns automatically and, unlike the admin, is opt-in: without `--update-existing` matching rows
are skipped; without `--create-missing` / `--download-images` unknown categories/attributes and remote images are
skipped. Exit code 1 when every row failed.

## Configuration (`config/commerce.php`)

```php
'features' => ['product_csv' => true],
'product_csv' => [
    'chunk_size' => 25, 'time_budget' => 20, 'max_rows' => 10000, 'max_file_kb' => 20480,
    'max_image_kb' => 10240, 'image_timeout' => 15, 'url_guard' => true, 'keep_days' => 14, 'disk' => 'local',
],
```

Code: `Pine\Commerce\Services\Admin\Catalogue\ProductCsv\*` (Columns, Cells, CsvFile, Exporter, ImportSession,
ImportRunner, RowImporter, ImageImporter), `Http\Controllers\Admin\ProductCsvController`, views
`admin/products/csv/*`. Tests: `tests/Feature/ProductCsvTest.php` (fixtures `tests/Fixtures/csv/`, incl. a
WooCommerce export).
