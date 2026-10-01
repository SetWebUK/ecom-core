<?php

namespace Pine\Commerce\Services\Admin\Catalogue;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock & price spreadsheet import for the Inventory page. Columns (header row, any order, case-insensitive):
 *   sku (required) · stock_quantity (or stock / quantity / qty) · regular_price (or price) · sale_price
 * An empty cell leaves that value unchanged; "-" (or "none") in sale_price removes the sale price.
 * A quantity switches on stock tracking for that product/variant. SKUs are matched to products and variants.
 *
 *   $rows = InventoryCsv::parse($path);            // validated rows (errors per row)
 *   $plan = InventoryCsv::plan($rows);             // what would change – shown as the dry-run preview
 *   $result = InventoryCsv::apply($plan);          // saves the changes
 */
class InventoryCsv
{
    public const MAX_ROWS = 5000;

    protected const ALIASES = [
        'sku' => ['sku', 'product sku', 'variant sku'],
        'stock_quantity' => ['stock_quantity', 'stock quantity', 'stock', 'quantity', 'qty', 'stock qty'],
        'regular_price' => ['regular_price', 'regular price', 'price'],
        'sale_price' => ['sale_price', 'sale price'],
    ];

    /** @return list<array{line:int, sku:string, stock_quantity:?int, regular_price:?float, sale_price:float|false|null, errors:list<string>}> */
    public static function parse(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException('The file could not be read.');
        }
        $first = (string) fgets($handle);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv($first, $delimiter, '"', '\\'));

        $columns = [];
        foreach (self::ALIASES as $key => $aliases) {
            foreach ($header as $index => $name) {
                if (in_array($name, $aliases, true)) {
                    $columns[$key] = $index;
                    break;
                }
            }
        }
        if (! isset($columns['sku'])) {
            fclose($handle);
            throw new RuntimeException('The first row must contain column names, including “sku”.');
        }
        if (count($columns) === 1) {
            fclose($handle);
            throw new RuntimeException('Add at least one of these columns: stock_quantity, regular_price, sale_price.');
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new RuntimeException('The file has more than '.number_format(self::MAX_ROWS).' rows – split it into smaller files.');
            }
            $get = fn (string $key) => isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';
            $row = ['line' => $line, 'sku' => mb_substr($get('sku'), 0, 100), 'stock_quantity' => null, 'regular_price' => null, 'sale_price' => null, 'errors' => []];

            if ($row['sku'] === '') {
                $row['errors'][] = 'No SKU';
            }
            $qty = $get('stock_quantity');
            if ($qty !== '') {
                if (preg_match('/^-?\d+$/', $qty) && abs((int) $qty) < 10000000) {
                    $row['stock_quantity'] = (int) $qty;
                } else {
                    $row['errors'][] = 'Quantity “'.$qty.'” isn’t a whole number';
                }
            }
            foreach (['regular_price', 'sale_price'] as $field) {
                $raw = $get($field);
                if ($raw === '') {
                    continue;
                }
                if ($field === 'sale_price' && in_array(mb_strtolower($raw), ['-', 'none', 'clear'], true)) {
                    $row['sale_price'] = false;

                    continue;
                }
                $clean = str_replace(['£', ',', ' '], '', $raw);
                if (is_numeric($clean) && (float) $clean >= 0 && (float) $clean < 1000000) {
                    $row[$field] = round((float) $clean, 2);
                } else {
                    $row['errors'][] = ($field === 'regular_price' ? 'Price' : 'Sale price').' “'.$raw.'” isn’t a valid amount';
                }
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Match rows to products/variants and work out the changes.
     *
     * @return list<array{line:int, sku:string, status:string, message:?string, type:?string, id:?int, name:?string, changes:array<string,array{0:mixed,1:mixed}>}>
     *   status: change | same | missing | error
     */
    public static function plan(array $rows): array
    {
        $skus = collect($rows)->pluck('sku')->filter()->unique()->values();
        $products = Product::query()->whereIn('sku', $skus)->where('type', '<>', 'variable')
            ->get(['id', 'name', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status'])->keyBy(fn ($p) => mb_strtolower($p->sku));
        $variations = ProductVariation::query()->whereIn('sku', $skus)->with('product:id,name')
            ->get(['id', 'product_id', 'sku', 'options', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status'])->keyBy(fn ($v) => mb_strtolower($v->sku));

        $plan = [];
        $seen = [];
        foreach ($rows as $row) {
            $entry = ['line' => $row['line'], 'sku' => $row['sku'], 'status' => 'error', 'message' => null, 'type' => null, 'id' => null, 'name' => null, 'changes' => []];
            if ($row['errors']) {
                $entry['message'] = implode('; ', $row['errors']);
                $plan[] = $entry;

                continue;
            }
            $key = mb_strtolower($row['sku']);
            if (isset($seen[$key])) {
                $entry['message'] = 'SKU appears more than once (line '.$seen[$key].' is used)';
                $plan[] = $entry;

                continue;
            }
            $seen[$key] = $row['line'];

            $item = $products->get($key) ?? $variations->get($key);
            if (! $item) {
                $entry['status'] = 'missing';
                $entry['message'] = 'No product or variant has this SKU';
                $plan[] = $entry;

                continue;
            }
            $entry['type'] = $item instanceof Product ? 'product' : 'variation';
            $entry['id'] = $item->id;
            $entry['name'] = $item instanceof Product ? $item->name : ($item->product?->name.' – '.collect((array) $item->options)->implode(', '));

            $currentQty = $item->manage_stock && $item->stock_quantity !== null ? (int) $item->stock_quantity : null;
            if ($row['stock_quantity'] !== null && $row['stock_quantity'] !== $currentQty) {
                $entry['changes']['stock_quantity'] = [$currentQty, $row['stock_quantity']];
            }
            $regular = $row['regular_price'] ?? ($item->regular_price !== null ? (float) $item->regular_price : null);
            if ($row['regular_price'] !== null && $row['regular_price'] !== ($item->regular_price !== null ? (float) $item->regular_price : null)) {
                $entry['changes']['regular_price'] = [$item->regular_price !== null ? (float) $item->regular_price : null, $row['regular_price']];
            }
            $currentSale = $item->sale_price !== null ? (float) $item->sale_price : null;
            if ($row['sale_price'] === false && $currentSale !== null) {
                $entry['changes']['sale_price'] = [$currentSale, null];
            } elseif (is_float($row['sale_price']) && $row['sale_price'] !== $currentSale) {
                if ($regular === null || $row['sale_price'] >= $regular) {
                    $entry['message'] = 'Sale price must be lower than the price';
                    $plan[] = $entry;

                    continue;
                }
                $entry['changes']['sale_price'] = [$currentSale, $row['sale_price']];
            }
            if ($row['regular_price'] !== null && $row['sale_price'] === null && $currentSale !== null && $currentSale >= $row['regular_price']) {
                $entry['changes']['sale_price'] = [$currentSale, null]; // an old sale price above the new price is removed
            }

            $entry['status'] = $entry['changes'] ? 'change' : 'same';
            $plan[] = $entry;
        }

        return $plan;
    }

    /** @return array{updated:int, alerts:array} */
    public static function apply(array $plan): array
    {
        $updated = 0;
        $touched = [];
        $parents = [];

        DB::transaction(function () use ($plan, &$updated, &$touched, &$parents) {
            foreach ($plan as $entry) {
                if ($entry['status'] !== 'change') {
                    continue;
                }
                $item = $entry['type'] === 'product' ? Product::query()->find($entry['id']) : ProductVariation::query()->find($entry['id']);
                if (! $item) {
                    continue;
                }
                $productId = $item instanceof Product ? $item->id : $item->product_id;
                foreach ($entry['changes'] as $field => [, $new]) {
                    if ($field === 'stock_quantity') {
                        $item->manage_stock = true;
                    }
                    $item->{$field} = $new;
                }
                if ($item->manage_stock && $item->stock_quantity !== null) {
                    $backorders = $item instanceof Product ? $item->backorders : 'no';
                    $item->stock_status = $item->stock_quantity > 0 ? 'instock' : ($backorders === 'no' ? 'outofstock' : 'onbackorder');
                }
                if ($item instanceof Product) {
                    $item->save();
                } else {
                    $item->saveQuietly();
                    $parents[$item->product_id] = true;
                }
                $touched[$productId] = true;
                $updated++;
            }
            foreach (Product::query()->whereIn('id', array_keys($parents))->get() as $product) {
                ProductSaver::refreshVariable($product);
            }
        });

        CatalogueTools::flushStorefrontCaches();
        $alerts = CatalogueTools::notifyBackInStock(array_keys($touched));

        return ['updated' => $updated, 'alerts' => $alerts];
    }

    /** @return array{change:int, same:int, missing:int, error:int} */
    public static function summary(array $plan): array
    {
        $summary = ['change' => 0, 'same' => 0, 'missing' => 0, 'error' => 0];
        foreach ($plan as $entry) {
            $summary[$entry['status']]++;
        }

        return $summary;
    }
}
