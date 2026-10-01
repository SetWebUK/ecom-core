<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Catalogue\StockAlertBulkRequest;
use Pine\Commerce\Models\AttributeValue;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\StockNotification;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Back-in-stock requests ("Email me when available"), grouped by product.
 *
 *   GET    admin/stock-alerts                 admin.stock-alerts.index    ?status=waiting|all, ?q=
 *   POST   admin/stock-alerts/notify          admin.stock-alerts.notify   product_id (one product) or all=1 (everything available)
 *   POST   admin/stock-alerts/bulk            admin.stock-alerts.bulk     ids[] = product ids; notify | delete
 *   DELETE admin/stock-alerts/{alert}         admin.stock-alerts.destroy  one subscription
 *
 * Emails go out through Pine\Commerce\Mail\BackInStock and mark the subscription as notified. They are also sent automatically
 * when staff put a product back in stock (product editor, quick edit, inventory, bulk actions).
 */
class StockAlertController extends Controller
{
    use AdminIndex;

    public function index(Request $request): View
    {
        $status = $this->filterValue($request, 'status', ['waiting' => 1, 'all' => 1]) ?? 'waiting';
        $q = $this->searchTerm($request);
        [$sort, $direction] = $this->sorting($request, ['waiting', 'last_requested', 'name'], 'last_requested', 'desc');

        $aggregate = DB::table('stock_notifications')
            ->selectRaw('product_id, COUNT(*) AS total, SUM(notified_at IS NULL) AS waiting, MAX(created_at) AS last_requested, MAX(notified_at) AS last_notified')
            ->groupBy('product_id');

        $products = Product::withTrashed()
            ->joinSub($aggregate, 'alerts', 'alerts.product_id', '=', 'products.id')
            ->select(['products.id', 'products.name', 'products.sku', 'products.slug', 'products.type', 'products.status', 'products.stock_status',
                'products.manage_stock', 'products.stock_quantity', 'products.low_stock_threshold', 'products.primary_category_id', 'products.deleted_at',
                'alerts.total', 'alerts.waiting', 'alerts.last_requested', 'alerts.last_notified'])
            ->with(['images' => fn ($i) => $i->select('id', 'product_id', 'path', 'sort_order')->orderBy('sort_order')->limit(1), 'primaryCategory:id,path', 'categories:id,path'])
            ->when($status === 'waiting', fn ($query) => $query->where('alerts.waiting', '>', 0))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('products.name', 'like', $this->like($q))
                ->orWhere('products.sku', 'like', $this->like($q))
                ->orWhereExists(fn (QueryBuilder $e) => $e->select(DB::raw(1))->from('stock_notifications as sn')
                    ->whereColumn('sn.product_id', 'products.id')->where('sn.email', 'like', $this->like($q)))))
            ->orderBy($sort === 'name' ? 'products.name' : 'alerts.'.$sort, $direction)
            ->orderBy('products.id')
            ->paginate($this->perPage($request))->withQueryString();

        $subscriptions = StockNotification::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->with('variation:id,product_id,options,stock_status,is_active')
            ->orderByRaw('notified_at IS NULL DESC')->latest('created_at')
            ->get()->groupBy('product_id');

        $summary = DB::table('stock_notifications')
            ->join('products', 'products.id', '=', 'stock_notifications.product_id')
            ->leftJoin('product_variations', 'product_variations.id', '=', 'stock_notifications.product_variation_id')
            ->whereNull('stock_notifications.notified_at')
            ->whereNull('products.deleted_at')
            ->selectRaw("COUNT(*) AS waiting,
                SUM(products.status = 'published' AND (
                    (stock_notifications.product_variation_id IS NULL AND products.stock_status IN ('instock','onbackorder'))
                    OR (product_variations.is_active = 1 AND product_variations.stock_status IN ('instock','onbackorder')))) AS ready,
                COUNT(DISTINCT CASE WHEN products.status = 'published' AND (
                    (stock_notifications.product_variation_id IS NULL AND products.stock_status IN ('instock','onbackorder'))
                    OR (product_variations.is_active = 1 AND product_variations.stock_status IN ('instock','onbackorder'))) THEN products.id END) AS ready_products")
            ->first();

        $counts = DB::table('stock_notifications')->selectRaw('COUNT(DISTINCT product_id) AS all_products, COUNT(DISTINCT CASE WHEN notified_at IS NULL THEN product_id END) AS waiting_products')->first();

        return view('commerce::admin.stock-alerts.index', [
            'products' => $products,
            'subscriptions' => $subscriptions,
            'status' => $status,
            'q' => $q,
            'tabs' => [
                'waiting' => ['label' => 'Waiting', 'count' => (int) ($counts->waiting_products ?? 0)],
                'all' => ['label' => 'All', 'count' => (int) ($counts->all_products ?? 0)],
            ],
            'summary' => [
                'waiting' => (int) ($summary->waiting ?? 0),
                'ready' => (int) ($summary->ready ?? 0),
                'readyProducts' => (int) ($summary->ready_products ?? 0),
            ],
            'variantLabels' => $this->variantLabels($subscriptions->flatten()->pluck('variation')->filter()),
        ]);
    }

    public function notify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'required_without:all'],
            'all' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['product_id'])) {
            $product = Product::withTrashed()->findOrFail((int) $validated['product_id']);
            $result = CatalogueTools::notifyBackInStock($product);
            if ($result['sent'] === 0 && $result['failed'] === 0) {
                return back()->with('warning', $result['waiting']
                    ? "“{$product->name}” isn’t available to buy yet (it’s out of stock, hidden or a variant is unavailable), so nobody was emailed."
                    : 'Nobody is waiting for this product.');
            }

            return back()->with($result['failed'] ? 'warning' : 'success', CatalogueTools::alertSummary($result));
        }

        $productIds = StockNotification::query()->whereNull('notified_at')->distinct()->pluck('product_id')->all();
        $result = CatalogueTools::notifyBackInStock($productIds);

        return back()->with($result['sent'] ? 'success' : 'info', CatalogueTools::alertSummary($result) ?? 'None of the products customers are waiting for are back in stock yet.');
    }

    public function bulk(StockAlertBulkRequest $request): RedirectResponse
    {
        $ids = $request->ids();
        if ($request->input('action') === 'delete') {
            $count = StockNotification::query()->whereIn('product_id', $ids)->delete();

            return back()->with('success', "{$count} ".Str::plural('request', $count).' deleted.');
        }
        $result = CatalogueTools::notifyBackInStock($ids);

        return back()->with($result['sent'] ? 'success' : 'info', CatalogueTools::alertSummary($result) ?? 'None of the selected products are back in stock yet, so nobody was emailed.');
    }

    public function destroy(StockNotification $alert): RedirectResponse
    {
        $email = $alert->email;
        $alert->delete();

        return back()->with('success', "Request from {$email} deleted.");
    }

    /** variation id => "16GB, Silver" (value names in one query). */
    protected function variantLabels($variations): array
    {
        if ($variations->isEmpty()) {
            return [];
        }
        $names = AttributeValue::query()->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attributes.slug as attribute_slug', 'attribute_values.slug', 'attribute_values.value'])
            ->mapWithKeys(fn ($v) => [$v->attribute_slug.'|'.$v->slug => $v->value]);

        return $variations->mapWithKeys(fn ($v) => [$v->id => collect((array) $v->options)->map(fn ($value, $attr) => $names[$attr.'|'.$value] ?? $value)->implode(', ')])->all();
    }
}
