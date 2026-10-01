<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\ShippingMethodRequest;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\ShippingClass;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Pine\Commerce\Services\Shipping\ShippingRates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Settings › Shipping: the zones overview (ShippingZoneController edits zones) and the delivery options themselves –
 * each belongs to a zone (or none: "unzoned", offered wherever its country list allows, as before zones existed).
 */
class ShippingMethodController extends Controller
{
    public function index(): View
    {
        $zones = ShippingZone::query()->with('methods')->orderBy('sort_order')->orderBy('id')->get();
        $unzoned = ShippingMethod::query()->where(fn ($q) => $q->whereNull('shipping_zone_id')->orWhereNotIn('shipping_zone_id', $zones->pluck('id')->all() ?: [0]))
            ->orderBy('sort_order')->orderBy('id')->get();
        $codes = $zones->flatMap->methods->pluck('code')->merge($unzoned->pluck('code'));
        $usage = Order::query()->whereIn('shipping_method', $codes)
            ->selectRaw('shipping_method, count(*) as c')->groupBy('shipping_method')->pluck('c', 'shipping_method');

        return view('commerce::admin.shipping.index', [
            'zones' => $zones,
            'methods' => $unzoned,
            'usage' => $usage,
            'classes' => ShippingClass::query()->orderBy('name')->get(),
            'classUsage' => DB::table('products')->whereNotNull('shipping_class_id')->whereNull('deleted_at')
                ->selectRaw('shipping_class_id, count(*) as c')->groupBy('shipping_class_id')->pluck('c', 'shipping_class_id'),
            'sellTo' => CheckoutService::countries(),
        ]);
    }

    public function create(Request $request): View
    {
        $zone = $request->integer('zone') ? ShippingZone::find($request->integer('zone')) : null;

        return view('commerce::admin.shipping.form', [
            'method' => new ShippingMethod(['is_active' => true, 'countries' => $zone ? [] : ['GB'], 'cost' => null, 'type' => 'flat_rate',
                'tax_status' => 'taxable', 'shipping_zone_id' => $zone?->id, 'settings' => ['calculation' => 'order']]),
            'orders' => 0,
            'zones' => ShippingZone::query()->orderBy('sort_order')->pluck('name', 'id')->all(),
            'classes' => ShippingClass::options(),
        ]);
    }

    public function store(ShippingMethodRequest $request): RedirectResponse
    {
        $method = ShippingMethod::create($request->methodData() + ['sort_order' => (int) ShippingMethod::max('sort_order') + 1]);
        ShippingRates::flush();

        return $this->back($method)->with('success', "Delivery option “{$method->name}” added.");
    }

    public function edit(ShippingMethod $shippingMethod): View
    {
        return view('commerce::admin.shipping.form', [
            'method' => $shippingMethod,
            'orders' => Order::where('shipping_method', $shippingMethod->code)->count(),
            'zones' => ShippingZone::query()->orderBy('sort_order')->pluck('name', 'id')->all(),
            'classes' => ShippingClass::options(),
        ]);
    }

    public function update(ShippingMethodRequest $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->update($request->methodData());
        ShippingRates::flush();

        return $this->back($shippingMethod)->with('success', "Delivery option “{$shippingMethod->name}” saved.");
    }

    public function destroy(ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->delete();
        ShippingRates::flush();

        return $this->back($shippingMethod)->with('success', "Delivery option “{$shippingMethod->name}” deleted.");
    }

    /** POST {ids: [...]} – new display order from the drag-and-drop list. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer', 'exists:shipping_methods,id']]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $position => $id) {
                ShippingMethod::whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }
        });
        ShippingRates::flush();

        return response()->json(['message' => 'New order saved – checkout lists the options in this order.']);
    }

    /** Back to the method's zone, or to the overview for unzoned methods. */
    protected function back(ShippingMethod $method): RedirectResponse
    {
        $zone = $method->shipping_zone_id ? ShippingZone::find($method->shipping_zone_id) : null;

        return $zone ? redirect()->route('admin.shipping.zones.edit', $zone) : redirect()->route('admin.shipping.index');
    }
}
