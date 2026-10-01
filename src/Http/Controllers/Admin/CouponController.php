<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\BulkActionRequest;
use Pine\Commerce\Http\Requests\Admin\CouponRequest;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\CouponStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Discounts (coupon codes) – the reference CRUD for the back office. Copy this shape for new areas:
 *   index   filters + status tabs + sortable, paginated table with bulk actions
 *   create/edit share one form view; store/update validate with a FormRequest and only save its normalised data
 *   destroy/bulk are POST/DELETE forms behind a confirm dialog; every action redirects with a toast.
 */
class CouponController extends Controller
{
    use AdminIndex;

    public const SORTS = ['code', 'amount', 'usage_count', 'expires_at', 'created_at'];

    public function index(Request $request): View
    {
        $status = $this->filterValue($request, 'status', CouponStatus::LABELS) ?? 'all';
        $type = $this->filterValue($request, 'type', Coupon::TYPES);
        $q = $this->searchTerm($request);
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'created_at', 'desc');

        $coupons = Coupon::query()
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('code', 'like', $this->like($q))
                ->orWhere('description', 'like', $this->like($q))))
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->tap(fn (Builder $query) => CouponStatus::scope($query, $status))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $counts = CouponStatus::counts();
        $tabs = ['all' => ['label' => 'All', 'count' => $counts['all']]];
        foreach (CouponStatus::LABELS as $key => $label) {
            $tabs[$key] = ['label' => $label, 'count' => $counts[$key]];
        }

        return view('commerce::admin.coupons.index', [
            'coupons' => $coupons,
            'tabs' => $tabs,
            'status' => $status,
            'q' => $q,
            'chips' => array_filter(['type' => $type ? 'Type: '.Coupon::TYPES[$type] : null]),
            'isFiltered' => $q !== '' || $type !== null || $status !== 'all',
        ]);
    }

    public function create(): View
    {
        return view('commerce::admin.coupons.form', [
            'coupon' => new Coupon(['type' => 'percent', 'is_active' => true, 'amount' => null]),
            'ordersCount' => 0,
        ]);
    }

    public function store(CouponRequest $request): RedirectResponse
    {
        $coupon = Coupon::create($request->couponData());

        return redirect()->route('admin.coupons.edit', $coupon)->with('success', "Discount {$coupon->code} created.");
    }

    public function edit(Coupon $coupon): View
    {
        return view('commerce::admin.coupons.form', [
            'coupon' => $coupon,
            'ordersCount' => Order::where('coupon_code', $coupon->code)->count(),
        ]);
    }

    public function update(CouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $coupon->update($request->couponData());

        return redirect()->route('admin.coupons.edit', $coupon)->with('success', 'Discount saved.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $coupon->delete();

        return redirect()->route('admin.coupons.index')->with('success', "Discount {$coupon->code} deleted.");
    }

    public function bulk(BulkActionRequest $request): RedirectResponse
    {
        $query = Coupon::whereIn('id', $request->ids());

        $count = match ($request->input('action')) {
            'activate' => $query->update(['is_active' => true, 'updated_at' => now()]),
            'deactivate' => $query->update(['is_active' => false, 'updated_at' => now()]),
            'delete' => $query->delete(),
        };

        $noun = $count === 1 ? 'discount' : 'discounts';
        $verb = ['activate' => 'activated', 'deactivate' => 'deactivated', 'delete' => 'deleted'][$request->input('action')];

        return back()->with('success', "{$count} {$noun} {$verb}.");
    }
}
