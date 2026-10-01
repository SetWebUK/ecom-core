<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Invoices\DocumentData;
use Pine\Commerce\Services\Invoices\Invoices;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Printable A4 invoices and packing slips for one or many orders (?orders=1,2,3 – up to 200), opened from the admin in a
 * new tab. Route admin.print in routes/admin/sales.php (staff only); store details come from Settings.
 */
class PrintController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(static function (Request $request, Closure $next) {
                $user = $request->user();
                if (! $user) {
                    return redirect()->guest(url("admin/login"));
                }
                abort_unless($user->isStaff() && $user->is_active, 403);

                return $next($request);
            }),
        ];
    }

    public function show(Request $request, string $document)
    {
        $ids = collect(explode(',', is_string($request->query('orders')) ? $request->query('orders') : ''))
            ->map(fn ($id) => (int) trim($id))->filter()->unique()->take(200)->values();
        abort_if($ids->isEmpty(), 404);

        $orders = Order::withTrashed()
            ->with(['items' => fn ($q) => $q->orderBy('id'), 'items.product' => fn ($q) => $q->withTrashed()->with(['images' => fn ($i) => $i->orderBy('sort_order')->limit(1)]), 'items.variation'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Order $order) => $ids->search($order->id))
            ->values();
        abort_if($orders->isEmpty(), 404);

        if ($document === 'invoice') {
            $orders->each(fn (Order $order) => Invoices::number($order)); // issue due invoice numbers (Settings › Invoices)
        }
        $store = DocumentData::store();

        return response()
            ->view('commerce::admin.print.'.$document, ['orders' => $orders, 'store' => $store])
            ->header('X-Robots-Tag', 'noindex');
    }
}
