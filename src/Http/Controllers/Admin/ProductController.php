<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Support\Sql;
use Pine\Commerce\Exports\CsvExport;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Catalogue\ProductBulkRequest;
use Pine\Commerce\Http\Requests\Admin\Catalogue\ProductRequest;
use Pine\Commerce\Http\Requests\Admin\Catalogue\QuickUpdateRequest;
use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\Catalogue\PriceAdjuster;
use Pine\Commerce\Services\Admin\Catalogue\ProductFilter;
use Pine\Commerce\Services\Admin\Catalogue\ProductSaver;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Pine\Commerce\Services\Admin\CategoryTree;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Services\Admin\ProductDuplicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Products: list (tabs, filters, quick edit, bulk actions, CSV export) and the one-page product editor.
 *
 *   GET    admin/products                     admin.products.index
 *   GET    admin/products/create|{id}/edit    admin.products.create|edit
 *   POST   admin/products · PUT {id}          admin.products.store|update      (ProductRequest -> ProductSaver)
 *   DELETE admin/products/{id}                admin.products.destroy           (soft delete)
 *   POST   admin/products/{id}/restore        admin.products.restore
 *   POST   admin/products/{id}/duplicate      admin.products.duplicate
 *   PATCH  admin/products/{id}/quick          admin.products.quick             JSON inline edit (prices, stock)
 *   POST   admin/products/{id}/featured       admin.products.featured          JSON star toggle
 *   POST   admin/products/bulk                admin.products.bulk
 *   POST   admin/products/price-preview       admin.products.price-preview     JSON dry run of a bulk price change
 *   GET    admin/products/export              admin.products.export            CSV (current filters or ?ids=1,2)
 */
class ProductController extends Controller
{
    use AdminIndex;

    public const SORTS = ['name', 'price', 'stock', 'updated_at', 'total_sales', 'created_at'];

    public function index(Request $request): View
    {
        $filter = ProductFilter::fromRequest($request);
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'updated_at', 'desc');

        $query = $filter->query()
            ->select(['id', 'name', 'slug', 'sku', 'type', 'status', 'subtitle', 'primary_category_id', 'regular_price', 'sale_price',
                'sale_starts_at', 'sale_ends_at', 'price', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_threshold',
                'is_featured', 'total_sales', 'updated_at', 'created_at', 'deleted_at'])
            ->with([
                'images' => fn ($q) => $q->select('id', 'product_id', 'path', 'alt', 'sort_order')->orderBy('sort_order')->limit(1),
                'categories:id,name,path',
                'primaryCategory:id,name,path',
                'variations' => fn ($q) => $q->select('id', 'product_id', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'is_active'),
            ]);

        match ($sort) {
            'stock' => $query->orderByRaw('stock_quantity IS NULL '.($direction === 'asc' ? 'ASC' : 'DESC'))->orderBy('stock_quantity', $direction),
            default => $query->orderBy($sort, $direction),
        };
        $products = $query->orderByDesc('id')->paginate($this->perPage($request))->withQueryString();

        $counts = ProductFilter::counts();
        $tabs = [];
        foreach (ProductFilter::TABS as $key => $label) {
            if ($key === 'trashed' && $counts['trashed'] === 0 && $filter->tab !== 'trashed') {
                continue;
            }
            $tabs[$key] = ['label' => $label, 'count' => $counts[$key]];
        }

        return view('commerce::admin.products.index', [
            'products' => $products,
            'filter' => $filter,
            'tabs' => $tabs,
            'counts' => $counts,
            'categoryOptions' => CategoryTree::options(),
            'sort' => $sort,
        ]);
    }

    public function create(Request $request): View
    {
        $product = new Product([
            'type' => $request->query('type') === 'variable' ? 'variable' : 'simple',
            'status' => 'draft',
            'stock_status' => 'instock',
            'backorders' => 'no',
            'manage_stock' => true,
            'stock_quantity' => 1,
        ]);
        $categoryId = (int) $request->query('category');
        if ($categoryId && CategoryTree::flat()->contains('id', $categoryId)) {
            $product->primary_category_id = $categoryId;
            $product->setRelation('categories', Category::query()->whereKey($categoryId)->get(['id', 'name', 'path']));
        } else {
            $product->setRelation('categories', collect());
        }
        foreach (['images', 'specs', 'variations', 'productAttributes', 'attributeValues', 'related'] as $relation) {
            $product->setRelation($relation, collect());
        }

        return view('commerce::admin.products.form', $this->formData($product));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $result = ProductSaver::save(new Product, $request);
        $product = $result['product'];

        return redirect()->route('admin.products.edit', $product)
            ->with('success', $product->status === 'published' ? 'Product created and published.' : 'Product created as a '.($product->status === 'draft' ? 'draft' : 'private product').'.');
    }

    public function edit(Product $product): View
    {
        $product->load([
            'images', 'categories:id,name,path', 'primaryCategory:id,name,path', 'specs', 'variations',
            'productAttributes.attribute', 'attributeValues:id,attribute_id,value,slug', 'related:id,name,sku,status,price,type,stock_status',
        ]);

        return view('commerce::admin.products.form', $this->formData($product));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $result = ProductSaver::save($product, $request);
        $messages = ['Product saved.'];
        if ($result['redirected']) {
            $messages[] = 'The old web address now redirects to the new one.';
        }
        if ($summary = CatalogueTools::alertSummary($result['alerts'])) {
            $messages[] = $summary;
        }

        return redirect()->route('admin.products.edit', $product)->with('success', implode(' ', $messages));
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();
        CatalogueTools::flushStorefrontCaches();

        return redirect()->route('admin.products.index')
            ->with('success', "“{$product->name}” deleted. You can restore it from the Deleted tab.");
    }

    public function restore(int $product): RedirectResponse
    {
        $model = Product::onlyTrashed()->findOrFail($product);
        $model->restore();
        CatalogueTools::flushStorefrontCaches();

        return redirect()->route('admin.products.edit', $model)->with('success', "“{$model->name}” restored.");
    }

    public function duplicate(Product $product): RedirectResponse
    {
        $copy = ProductDuplicator::duplicate($product);

        return redirect()->route('admin.products.edit', $copy)->with('success', 'Product duplicated. This copy is a draft – check the title, URL and SKU, then publish it.');
    }

    // Inline edits (JSON) -------------------------------------------------------------------------------

    public function quickUpdate(QuickUpdateRequest $request, Product $product): JsonResponse
    {
        if ($product->type === 'variable') {
            return response()->json(['message' => 'This product has variants – edit their prices and stock on the product page.'], 422);
        }
        $result = ProductSaver::quickUpdate($product, $request->changes());
        $product->refresh();
        $message = 'Saved.';
        if ($summary = CatalogueTools::alertSummary($result['alerts'])) {
            $message .= ' '.$summary;
        }

        return response()->json(['message' => $message, 'row' => static::rowData($product)]);
    }

    public function toggleFeatured(Request $request, Product $product): JsonResponse
    {
        $featured = $request->has('featured') ? $request->boolean('featured') : ! $product->is_featured;
        $product->forceFill(['is_featured' => $featured])->save();
        CatalogueTools::flushStorefrontCaches();

        return response()->json([
            'featured' => $product->is_featured,
            'message' => $product->is_featured ? 'Marked as featured.' : 'No longer featured.',
        ]);
    }

    /** Values the list's quick-edit component displays for one row. */
    public static function rowData(Product $product): array
    {
        $stock = CatalogueTools::stock($product);

        return [
            'regular_price' => $product->regular_price !== null ? number_format((float) $product->regular_price, 2, '.', '') : '',
            'sale_price' => $product->sale_price !== null ? number_format((float) $product->sale_price, 2, '.', '') : '',
            'on_sale' => $product->isOnSale(),
            'price' => $product->currentPrice(),
            'manage_stock' => (bool) $product->manage_stock,
            'stock_quantity' => $product->manage_stock ? $product->stock_quantity : null,
            'stock_status' => $product->stock_status,
            'stock_label' => $stock['label'],
            'stock_color' => $stock['color'],
        ];
    }

    // Bulk actions ---------------------------------------------------------------------------------------

    public function bulk(ProductBulkRequest $request): RedirectResponse
    {
        $ids = $request->ids();
        $action = $request->input('action');
        $noun = fn (int $n) => $n.' '.Str::plural('product', $n);

        switch ($action) {
            case 'publish':
            case 'draft':
                $count = 0;
                foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
                    $product->status = $action === 'publish' ? 'published' : 'draft';
                    if ($action === 'publish' && ! $product->published_at) {
                        $product->published_at = now();
                    }
                    if ($product->isDirty()) {
                        $product->save();
                        $count++;
                    }
                }
                $message = $noun($count).($action === 'publish' ? ' published.' : ' set to draft (hidden from the shop).');
                break;

            case 'feature':
            case 'unfeature':
                $count = Product::query()->whereIn('id', $ids)->update(['is_featured' => $action === 'feature', 'updated_at' => now()]);
                $message = $noun($count).($action === 'feature' ? ' marked as featured.' : ' no longer featured.');
                break;

            case 'outofstock':
            case 'instock':
                return $this->bulkStock($ids, $action);

            case 'add_category':
            case 'remove_category':
                $category = Category::findOrFail((int) $request->input('category_id'));
                $existing = Product::query()->whereIn('id', $ids)->pluck('id')->all();
                if ($action === 'add_category') {
                    $rows = array_map(fn ($id) => ['category_id' => $category->id, 'product_id' => $id], $existing);
                    DB::table('category_product')->insertOrIgnore($rows);
                    Product::query()->whereIn('id', $existing)->whereNull('primary_category_id')->update(['primary_category_id' => $category->id]);
                    $message = $noun(count($existing))." added to “{$category->name}”.";
                } else {
                    DB::table('category_product')->where('category_id', $category->id)->whereIn('product_id', $existing)->delete();
                    // Products whose main category was removed fall back to another of their categories
                    foreach (Product::query()->whereIn('id', $existing)->where('primary_category_id', $category->id)->with('categories:id')->get() as $product) {
                        $product->forceFill(['primary_category_id' => $product->categories->first()?->id])->saveQuietly();
                    }
                    $message = $noun(count($existing))." removed from “{$category->name}”.";
                }
                Product::query()->whereIn('id', $existing)->update(['updated_at' => now()]);
                break;

            case 'adjust_price':
                $result = PriceAdjuster::apply($ids, $request->priceParams());
                $message = $result['changed'].' '.Str::plural('price', $result['changed']).' updated.'
                    .($result['skipped'] ? ' '.$result['skipped'].' skipped (see the preview for why).' : '');
                break;

            case 'set_sale':
                $ends = LocalTime::fromInput($request->input('sale_ends_at'), true);
                $count = PriceAdjuster::setSale($ids, (float) $request->input('percent'), null, $ends);
                $message = $count.' '.Str::plural('item', $count).' put on sale at '.rtrim(rtrim(number_format((float) $request->input('percent'), 2), '0'), '.').'% off'
                    .($ends ? ' until '.LocalTime::format($ends, 'j M Y') : '').'.';
                break;

            case 'end_sale':
                $count = PriceAdjuster::setSale($ids, null);
                $message = 'Sale ended for '.$count.' '.Str::plural('item', $count).'.';
                break;

            case 'delete':
                $count = Product::query()->whereIn('id', $ids)->get()->each->delete()->count();
                $message = $noun($count).' deleted. You can restore them from the Deleted tab.';
                break;

            case 'restore':
                $count = Product::onlyTrashed()->whereIn('id', $ids)->get()->each->restore()->count();
                $message = $noun($count).' restored.';
                break;

            default:
                abort(422);
        }

        CatalogueTools::flushStorefrontCaches();

        return back()->with('success', $message);
    }

    /** Mark products (and all variants of products with variants) in or out of stock. */
    protected function bulkStock(array $ids, string $action): RedirectResponse
    {
        $changed = 0;
        $tracked = 0;
        $touched = [];
        DB::transaction(function () use ($ids, $action, &$changed, &$tracked, &$touched) {
            foreach (Product::query()->whereIn('id', $ids)->with('variations')->get() as $product) {
                if ($product->type === 'variable') {
                    foreach ($product->variations as $variation) {
                        if ($action === 'outofstock') {
                            if ($variation->manage_stock) {
                                $variation->stock_quantity = 0;
                            }
                            $variation->stock_status = 'outofstock';
                        } elseif ($variation->manage_stock && (int) $variation->stock_quantity <= 0) {
                            $tracked++;

                            continue;
                        } else {
                            $variation->stock_status = 'instock';
                        }
                        $variation->saveQuietly();
                    }
                    ProductSaver::refreshVariable($product);
                    $changed++;
                    $touched[] = $product->id;

                    continue;
                }
                if ($action === 'outofstock') {
                    if ($product->manage_stock) {
                        $product->stock_quantity = 0;
                        $product->backorders = 'no';
                    }
                    $product->stock_status = 'outofstock';
                } else {
                    if ($product->manage_stock && (int) $product->stock_quantity <= 0) {
                        $tracked++; // quantity decides – they need a number, not a status

                        continue;
                    }
                    $product->stock_status = 'instock';
                }
                if ($product->isDirty()) {
                    $product->save();
                    $changed++;
                    $touched[] = $product->id;
                }
            }
        });
        CatalogueTools::flushStorefrontCaches();

        $message = $changed.' '.Str::plural('product', $changed).' marked '.($action === 'instock' ? 'in stock' : 'out of stock').'.';
        if ($tracked) {
            $message .= " {$tracked} ".Str::plural('item', $tracked).' track stock with a quantity of 0 – set a quantity on the Inventory page instead.';
        }
        if ($action === 'instock' && ($summary = CatalogueTools::alertSummary(CatalogueTools::notifyBackInStock($touched)))) {
            $message .= ' '.$summary;
        }

        return back()->with($tracked && ! $changed ? 'warning' : 'success', $message);
    }

    public function pricePreview(ProductBulkRequest $request): JsonResponse
    {
        if ($request->input('action') !== 'adjust_price') {
            return response()->json(['message' => 'Nothing to preview.'], 422);
        }
        $preview = PriceAdjuster::preview($request->ids(), $request->priceParams(), 50);

        return response()->json([
            'rows' => array_map(fn ($row) => [
                'name' => $row['name'],
                'sku' => $row['sku'] ?? null,
                'old' => $row['old'] !== null ? money($row['old']) : '—',
                'new' => $row['new'] !== null ? money($row['new']) : '—',
                'error' => $row['error'],
            ], $preview['rows']),
            'changed' => $preview['changed'],
            'skipped' => $preview['skipped'],
            'more' => max(0, $preview['changed'] + $preview['skipped'] - count($preview['rows'])),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filter = ProductFilter::fromRequest($request);
        $query = $filter->query()->with(['categories:id,name,path', 'primaryCategory:id,name,path'])->orderBy('name')->orderBy('products.id');

        // Condition / Brand columns only while their feature switches are on (product_condition / product_brand)
        $withCondition = commerce_feature('product_condition', false);
        $withBrand = commerce_feature('product_brand', false);

        $rows = (function () use ($query, $withCondition, $withBrand) {
            foreach ($query->lazy(200) as $p) {
                yield [
                    $p->id, $p->name, $p->sku, $p->type === 'variable' ? 'With variants' : 'Simple',
                    \Pine\Commerce\Services\Admin\OrderStatus::PRODUCT_STATUSES[$p->status] ?? $p->status,
                    \Pine\Commerce\Services\Admin\OrderStatus::STOCK_STATUSES[$p->stock_status] ?? $p->stock_status,
                    $p->manage_stock ? $p->stock_quantity : '',
                    $p->regular_price, $p->sale_price, $p->price,
                    $p->categories->pluck('name')->implode(' | '),
                    ...($withCondition ? [$p->condition] : []), ...($withBrand ? [$p->brand] : []),
                    $p->is_featured ? 'Yes' : 'No', $p->total_sales,
                    $p->url, LocalTime::format($p->updated_at, 'Y-m-d H:i'),
                ];
            }
        })();

        return CsvExport::stream('products-'.LocalTime::now()->format('Y-m-d-His').'.csv', [
            'ID', 'Name', 'SKU', 'Type', 'Status', 'Stock status', 'Stock quantity', 'Regular price', 'Sale price', 'Current price',
            'Categories', ...($withCondition ? ['Condition'] : []), ...($withBrand ? ['Brand'] : []),
            'Featured', 'Total sales', 'URL', 'Last updated (UK time)',
        ], $rows);
    }

    // Form data --------------------------------------------------------------------------------------------

    protected function formData(Product $product): array
    {
        $attributes = Attribute::query()->orderBy('sort_order')->orderBy('name')
            ->with(['values' => fn ($q) => $q->select('id', 'attribute_id', 'value', 'slug', 'sort_order')])
            ->get(['id', 'name', 'slug']);
        $asideSlugs = ProductRequest::asideAttributes(); // role => slug (condition / brand, per feature flags)
        $aside = collect($asideSlugs)->map(fn ($slug) => $attributes->firstWhere('slug', $slug))->filter();
        $productValues = $product->attributeValues->groupBy('attribute_id');

        // Attribute rows for the Attributes / Variants cards (Condition and Brand live in the side panel)
        $rows = $product->productAttributes
            ->filter(fn ($pa) => $pa->attribute && ! in_array($pa->attribute->slug, $asideSlugs, true))
            ->sortBy('position')->values()
            ->map(fn ($pa) => [
                'attribute_id' => $pa->attribute_id,
                'values' => $productValues->get($pa->attribute_id, collect())->pluck('id')->values()->all(),
                'visible' => (bool) $pa->is_visible,
                'variation' => (bool) $pa->is_variation,
            ])->all();

        $conditionValue = $aside->has('condition') ? $productValues->get($aside['condition']->id)?->first()?->value : null;
        $brandValue = $aside->has('brand') ? $productValues->get($aside['brand']->id)?->first()?->value : null;

        $sales = $product->exists ? $this->salesSummary($product) : null;

        return [
            'product' => $product,
            'attributes' => $attributes->reject(fn ($a) => in_array($a->slug, $asideSlugs, true))->values()
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'slug' => $a->slug,
                    'values' => $a->values->map(fn ($v) => ['id' => $v->id, 'value' => $v->value, 'slug' => $v->slug])->values()->all()])->all(),
            'attributeRows' => $rows,
            // Condition values come from the Condition attribute (what the shop's filter and badge use); without it, from the column
            'conditionOptions' => collect($aside->has('condition') ? $aside['condition']->values->pluck('value') : ProductFilter::conditionOptions())
                ->push($conditionValue ?? ($aside->has('condition') ? null : $product->condition))->filter()->unique()->values()->all(),
            'conditionValue' => $conditionValue ?? $product->condition,
            'brandOptions' => collect($aside->get('brand')?->values?->pluck('value') ?? [])->merge(ProductFilter::brandOptions())->filter()->unique(fn ($b) => mb_strtolower($b))->sort()->values()->all(),
            'brandValue' => $brandValue,
            'categories' => CategoryTree::flat(),
            'sales' => $sales,
            'variations' => $product->variations->map(fn ($v) => [
                'id' => $v->id,
                'options' => (object) ((array) $v->options),
                'sku' => (string) $v->sku,
                'regular_price' => $v->regular_price !== null ? number_format((float) $v->regular_price, 2, '.', '') : '',
                'sale_price' => $v->sale_price !== null ? number_format((float) $v->sale_price, 2, '.', '') : '',
                'stock_quantity' => $v->manage_stock && $v->stock_quantity !== null ? (string) $v->stock_quantity : '',
                'stock_status' => $v->stock_status ?: 'instock',
                'image' => (string) $v->image,
                'image_url' => $v->image ? media_url($v->image) : null,
                'tax_class' => (string) ($v->tax_class ?? ''),
                'is_active' => (bool) $v->is_active,
            ])->values()->all(),
            'waitingAlerts' => $product->exists ? \Pine\Commerce\Models\StockNotification::query()->where('product_id', $product->id)->whereNull('notified_at')->count() : 0,
        ];
    }

    /** @return array{units:int, revenue:float, orders:int, last:?\Illuminate\Support\Carbon} */
    protected function salesSummary(Product $product): array
    {
        $row = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereNull('orders.deleted_at')
            ->selectRaw(Sql::qualify('COALESCE(SUM(order_items.quantity - order_items.refunded_quantity), 0) as units, COALESCE(SUM(order_items.total), 0) as revenue,
                COUNT(DISTINCT orders.id) as orders, MAX(orders.created_at) as last', ['order_items', 'orders']))
            ->first();

        return [
            'units' => (int) ($row->units ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
            'orders' => (int) ($row->orders ?? 0),
            'last' => $row?->last ? \Illuminate\Support\Carbon::parse($row->last, 'UTC') : null,
        ];
    }
}
