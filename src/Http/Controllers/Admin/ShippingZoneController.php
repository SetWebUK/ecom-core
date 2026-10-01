<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\ShippingClass;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Services\Admin\Countries;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Pine\Commerce\Services\Shipping\ShippingRates;

/**
 * Settings › Shipping: zones (drag to order – checkout uses the first zone matching the delivery address), the
 * methods of each zone (edited with ShippingMethodController), shipping classes and the countries the shop sells to.
 */
class ShippingZoneController extends Controller
{
    public function create(): View
    {
        return view('commerce::admin.shipping.zone', ['zone' => new ShippingZone(['regions' => []]), 'methods' => collect(), 'usage' => collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $zone = ShippingZone::create($this->zoneData($request) + ['sort_order' => (int) ShippingZone::max('sort_order') + 1]);
        ShippingRates::flush();

        return redirect()->route('admin.shipping.zones.edit', $zone)->with('success', "Zone “{$zone->name}” added – now add its delivery options.");
    }

    public function edit(ShippingZone $shippingZone): View
    {
        $methods = $shippingZone->methods()->get();
        $usage = Order::query()->whereIn('shipping_method', $methods->pluck('code'))
            ->selectRaw('shipping_method, count(*) as c')->groupBy('shipping_method')->pluck('c', 'shipping_method');

        return view('commerce::admin.shipping.zone', ['zone' => $shippingZone, 'methods' => $methods, 'usage' => $usage]);
    }

    public function update(Request $request, ShippingZone $shippingZone): RedirectResponse
    {
        $shippingZone->update($this->zoneData($request, $shippingZone));
        ShippingRates::flush();

        return redirect()->route('admin.shipping.zones.edit', $shippingZone)->with('success', "Zone “{$shippingZone->name}” saved.");
    }

    public function destroy(ShippingZone $shippingZone): RedirectResponse
    {
        DB::transaction(function () use ($shippingZone) {
            ShippingMethod::where('shipping_zone_id', $shippingZone->id)->delete();
            $shippingZone->delete();
        });
        ShippingRates::flush();

        return redirect()->route('admin.shipping.index')->with('success', "Zone “{$shippingZone->name}” and its delivery options deleted.");
    }

    /** POST {ids: [...]} – zone order (the first matching zone wins at checkout). */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer', 'exists:shipping_zones,id']]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $position => $id) {
                ShippingZone::whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
        ShippingRates::flush();

        return response()->json(['message' => 'Zone order saved – checkout uses the first zone that matches the address.']);
    }

    /** POST {ids: [...]} – order of a zone's methods at checkout. */
    public function reorderMethods(Request $request, ShippingZone $shippingZone): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer', Rule::exists('shipping_methods', 'id')->where('shipping_zone_id', $shippingZone->id)]]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $position => $id) {
                ShippingMethod::whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }
        });
        ShippingRates::flush();

        return response()->json(['message' => 'New order saved – checkout lists the options in this order.']);
    }

    // ------------------------------------------------------------------ shipping classes

    public function storeClass(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('shippingClass', [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $slug = Str::slug($data['name']) ?: 'class';
        $base = $slug;
        for ($i = 2; ShippingClass::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }
        ShippingClass::create(['name' => trim($data['name']), 'slug' => $slug, 'description' => $data['description'] ?? null]);

        return redirect()->to(route('admin.shipping.index').'#shipping-classes')->with('success', 'Shipping class “'.trim($data['name']).'” added. Give products this class on the product page.');
    }

    public function updateClass(Request $request, ShippingClass $shippingClass): RedirectResponse
    {
        $data = $request->validateWithBag('shippingClass', ['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500']]);
        $shippingClass->update(['name' => trim($data['name']), 'description' => $data['description'] ?? null]);

        return redirect()->to(route('admin.shipping.index').'#shipping-classes')->with('success', 'Shipping class saved.');
    }

    public function destroyClass(ShippingClass $shippingClass): RedirectResponse
    {
        DB::transaction(function () use ($shippingClass) {
            DB::table('products')->where('shipping_class_id', $shippingClass->id)->update(['shipping_class_id' => null]);
            DB::table('product_variations')->where('shipping_class_id', $shippingClass->id)->update(['shipping_class_id' => null]);
            $shippingClass->delete();
        });

        return redirect()->to(route('admin.shipping.index').'#shipping-classes')->with('success', 'Shipping class “'.$shippingClass->name.'” deleted.');
    }

    /** PUT countries[] – where customers can order to (checkout country list). */
    public function updateCountries(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'countries' => ['required', 'array', 'min:1', 'max:300'],
            'countries.*' => ['string', Rule::in(array_keys(Countries::options()))],
        ], ['countries.required' => 'Choose at least one country.']);
        Setting::set('checkout.countries', array_values(array_unique($data['countries'])), 'checkout');

        return redirect()->to(route('admin.shipping.index').'#selling-countries')->with('success', 'Countries saved – checkout offers '.count(CheckoutService::countries()).' '.Str::plural('country', count(CheckoutService::countries())).'.');
    }

    protected function zoneData(Request $request, ?ShippingZone $zone = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'regions' => ['array', 'max:300'],
            'regions.*' => ['string', Rule::in(array_keys(Countries::options()))],
            'regions_extra' => ['nullable', 'string', 'max:2000', 'regex:/^\s*([A-Za-z]{2}:[A-Za-z0-9\- ]{1,20}\s*[,\n\r]*\s*)*$/'],
            'postcodes' => ['nullable', 'string', 'max:10000'],
        ], ['regions_extra.regex' => 'Write regions as country:code, e.g. US:CA or ES:PM, separated by commas.']);

        $regions = array_map('strtoupper', (array) ($data['regions'] ?? []));
        foreach (preg_split('/[\s,]+/', (string) ($data['regions_extra'] ?? '')) ?: [] as $extra) {
            if (str_contains($extra, ':')) {
                $regions[] = strtoupper(trim($extra));
            }
        }

        return [
            'name' => trim($data['name']),
            'regions' => array_values(array_unique($regions)) ?: null,
            'postcodes' => implode("\n", \Pine\Commerce\Models\TaxRate::lines((string) ($data['postcodes'] ?? ''))) ?: null,
        ];
    }
}
