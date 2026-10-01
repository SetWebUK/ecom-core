<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Exports\CsvExport;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\AttributeValue;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\Catalogue\InventoryCsv;
use Pine\Commerce\Services\Admin\Catalogue\ProductFilter;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Pine\Commerce\Services\Admin\CategoryTree;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory: every product and variant with its stock and prices, edited inline (products.quick / variations.update),
 * plus a CSV export and a CSV import (sku, stock_quantity, regular_price, sale_price) with a dry-run preview.
 *
 *   GET  admin/inventory                   admin.products.inventory              ?q=&stock=low|outofstock|instock|onbackorder|untracked&category=
 *   GET  admin/inventory/export            admin.products.inventory.export       CSV
 *   POST admin/inventory/import            admin.products.inventory.import       upload -> preview
 *   GET  admin/inventory/import/{token}    admin.products.inventory.preview      dry run: what will change
 *   POST admin/inventory/import/{token}    admin.products.inventory.apply        save the changes
 *
 * (Route names sit under admin.products.* so the Products menu stays highlighted.)
 */
class InventoryController extends Controller
{
    use AdminIndex;

    public const STOCK = ['low' => 'Low stock', 'outofstock' => 'Out of stock', 'instock' => 'In stock', 'onbackorder' => 'On backorder', 'untracked' => 'Quantity not tracked'];

    public function index(Request $request): View
    {
        $stock = $this->filterValue($request, 'stock', self::STOCK);
        $q = $this->searchTerm($request);
        $category = (int) $request->query('category');
        $category = $category && CategoryTree::flat()->contains('id', $category) ? $category : null;
        [$sort, $direction] = $this->sorting($request, ['name', 'sku', 'stock_quantity', 'price'], 'name');

        $products = $this->query($q, $stock, $category)
            ->select(['id', 'name', 'sku', 'type', 'status', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'price', 'manage_stock',
                'stock_quantity', 'stock_status', 'backorders', 'low_stock_threshold'])
            ->with([
                'images' => fn ($i) => $i->select('id', 'product_id', 'path', 'sort_order')->orderBy('sort_order')->limit(1),
                'variations' => fn ($v) => $v->select('id', 'product_id', 'sku', 'options', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'image', 'is_active'),
            ])
            ->when($sort === 'stock_quantity', fn ($query) => $query->orderByRaw('stock_quantity IS NULL ASC'))
            ->orderBy($sort, $direction)->orderBy('id')
            ->paginate($this->perPage($request, 50))->withQueryString();

        $chips = array_filter([
            'stock' => $stock ? 'Stock: '.self::STOCK[$stock] : null,
            'category' => $category ? 'Category: '.CategoryTree::flat()->firstWhere('id', $category)?->name : null,
        ]);

        return view('commerce::admin.inventory.index', [
            'products' => $products,
            'q' => $q,
            'chips' => $chips,
            'categoryOptions' => CategoryTree::options(),
            'variantLabels' => $this->variantLabels($products->getCollection()->flatMap->variations),
            'isFiltered' => $q !== '' || $chips !== [],
            'threshold' => CatalogueTools::lowStockThreshold(),
        ]);
    }

    protected function query(string $q, ?string $stock, ?int $category): Builder
    {
        $query = Product::query();
        foreach (array_slice(preg_split('/\s+/', $q) ?: [], 0, 5) as $word) {
            if ($word === '') {
                continue;
            }
            $like = $this->like($word);
            $query->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhereHas('variations', fn ($v) => $v->where('sku', 'like', $like)));
        }
        if ($category) {
            $ids = CategoryTree::descendantIds($category);
            $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('category_product')
                ->whereColumn('category_product.product_id', 'products.id')->whereIn('category_product.category_id', $ids));
        }
        match ($stock) {
            'low' => ProductFilter::applyStock($query, 'low'),
            'untracked' => $query->where(fn ($w) => $w->where(fn ($s) => $s->where('type', '<>', 'variable')->where('manage_stock', false))
                ->orWhere(fn ($v) => $v->where('type', 'variable')->whereHas('variations', fn ($x) => $x->where('manage_stock', false)))),
            'outofstock', 'instock', 'onbackorder' => $query->where(fn ($w) => $w->where(fn ($s) => $s->where('type', '<>', 'variable')->where('stock_status', $stock))
                ->orWhere(fn ($v) => $v->where('type', 'variable')->whereHas('variations', fn ($x) => $x->where('stock_status', $stock)))),
            default => null,
        };

        return $query;
    }

    public function export(Request $request): StreamedResponse
    {
        $stock = $this->filterValue($request, 'stock', self::STOCK);
        $category = (int) $request->query('category') ?: null;
        $query = $this->query($this->searchTerm($request), $stock, $category)->with('variations')->orderBy('name')->orderBy('products.id');
        $labels = $this->allValueNames();

        $rows = (function () use ($query, $labels) {
            foreach ($query->lazy(200) as $product) {
                if ($product->type === 'variable') {
                    foreach ($product->variations as $v) {
                        yield [
                            $v->sku, $product->name, collect((array) $v->options)->map(fn ($val, $attr) => $labels[$attr.'|'.$val] ?? $val)->implode(', '),
                            $v->manage_stock ? $v->stock_quantity : '', \Pine\Commerce\Services\Admin\OrderStatus::STOCK_STATUSES[$v->stock_status] ?? $v->stock_status,
                            $v->regular_price, $v->sale_price,
                        ];
                    }

                    continue;
                }
                yield [
                    $product->sku, $product->name, '', $product->manage_stock ? $product->stock_quantity : '',
                    \Pine\Commerce\Services\Admin\OrderStatus::STOCK_STATUSES[$product->stock_status] ?? $product->stock_status,
                    $product->regular_price, $product->sale_price,
                ];
            }
        })();

        return CsvExport::stream('inventory-'.LocalTime::now()->format('Y-m-d-His').'.csv',
            ['sku', 'product', 'variant', 'stock_quantity', 'stock_status', 'regular_price', 'sale_price'], $rows);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/x-csv'],
        ], [
            'file.required' => 'Choose a CSV file to import.',
            'file.mimes' => 'Upload a .csv file (in Excel: File › Save As › CSV UTF-8).',
            'file.mimetypes' => 'Upload a .csv file (in Excel: File › Save As › CSV UTF-8).',
            'file.max' => 'The file can be up to 5 MB.',
        ]);

        try {
            $rows = InventoryCsv::parse($request->file('file')->getRealPath());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->with('error', $e->getMessage());
        }
        if (! $rows) {
            return back()->withErrors(['file' => 'The file has no rows under the header.'])->with('error', 'The file has no rows under the header.');
        }

        $token = Str::random(32);
        Cache::put($this->cacheKey($request, $token), [
            'filename' => Str::limit($request->file('file')->getClientOriginalName(), 120),
            'plan' => InventoryCsv::plan($rows),
        ], now()->addMinutes(30));

        return redirect()->route('admin.products.inventory.preview', $token);
    }

    public function preview(Request $request, string $token): View|RedirectResponse
    {
        $import = Cache::get($this->cacheKey($request, $token));
        if (! is_array($import)) {
            return redirect()->route('admin.products.inventory')->with('warning', 'That import preview has expired – upload the file again.');
        }
        $filter = $this->filterValue($request, 'show', ['change' => 1, 'problems' => 1, 'all' => 1]) ?? 'change';
        $plan = collect($import['plan']);
        $summary = InventoryCsv::summary($import['plan']);
        $rows = match ($filter) {
            'change' => $plan->where('status', 'change'),
            'problems' => $plan->whereIn('status', ['missing', 'error']),
            default => $plan,
        };

        return view('commerce::admin.inventory.import', [
            'token' => $token,
            'filename' => $import['filename'],
            'rows' => $rows->take(1000)->values(),
            'hidden' => max(0, $rows->count() - 1000),
            'summary' => $summary,
            'show' => $filter,
        ]);
    }

    public function apply(Request $request, string $token): RedirectResponse
    {
        $key = $this->cacheKey($request, $token);
        $import = Cache::pull($key);
        if (! is_array($import)) {
            return redirect()->route('admin.products.inventory')->with('warning', 'That import preview has expired – upload the file again.');
        }
        // Re-plan against the current data so edits made since the preview are not overwritten blindly
        $result = InventoryCsv::apply(InventoryCsv::plan(array_map(fn ($entry) => $this->rowFromPlan($entry), $import['plan'])));

        $message = $result['updated'].' '.Str::plural('item', $result['updated']).' updated from '.$import['filename'].'.';
        if ($summary = CatalogueTools::alertSummary($result['alerts'])) {
            $message .= ' '.$summary;
        }

        return redirect()->route('admin.products.inventory')->with('success', $message);
    }

    /** Rebuild a parsed CSV row from a planned entry (new values only). */
    protected function rowFromPlan(array $entry): array
    {
        $row = ['line' => $entry['line'], 'sku' => $entry['sku'], 'stock_quantity' => null, 'regular_price' => null, 'sale_price' => null,
            'errors' => $entry['status'] === 'error' ? [(string) $entry['message']] : []];
        foreach ($entry['changes'] ?? [] as $field => [, $new]) {
            $row[$field] = $field === 'sale_price' && $new === null ? false : $new;
        }

        return $row;
    }

    protected function cacheKey(Request $request, string $token): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1, 404);

        return 'admin.inventory-import.'.$request->user()->id.'.'.$token;
    }

    /** @return array<string,string> "attribute|value-slug" => value name */
    protected function allValueNames(): array
    {
        return AttributeValue::query()->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attributes.slug as attribute_slug', 'attribute_values.slug', 'attribute_values.value'])
            ->mapWithKeys(fn ($v) => [$v->attribute_slug.'|'.$v->slug => $v->value])->all();
    }

    protected function variantLabels($variations): array
    {
        if ($variations->isEmpty()) {
            return [];
        }
        $names = $this->allValueNames();

        return $variations->mapWithKeys(fn ($v) => [$v->id => collect((array) $v->options)->map(fn ($value, $attr) => $names[$attr.'|'.$value] ?? $value)->implode(', ')])->all();
    }
}
