<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Support\Sql;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Sales\CustomerAddressRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\CustomerBulkRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\CustomerNoteRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\CustomerRequest;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Admin\CustomerQuery;
use Pine\Commerce\Services\Admin\OrderFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Customers: list with order stats (orders, total spent, last order – computed in SQL), customer page (stats, orders,
 * addresses, private note), create, edit contact details, password reset link (storefront), disable/enable, delete
 * (only customers without orders). Staff accounts are managed in Settings › Staff; here they are read-only unless
 * you are an administrator.
 */
class CustomerController extends Controller
{
    use AdminIndex;

    public function index(Request $request): View
    {
        $filters = CustomerQuery::fromRequest($request);
        [$sort, $direction] = $this->sorting($request, CustomerQuery::SORTS, 'created_at', 'desc');
        if ($request->query('sort') && ! $request->query('direction') && in_array($sort, ['orders_count', 'total_spent', 'last_order_at'], true)) {
            $direction = 'desc';
        }

        $customers = $filters->sorted($sort, $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return view('commerce::admin.customers.index', [
            'customers' => $customers,
            'filters' => $filters,
            'chips' => $filters->chips(),
            'total' => $filters->isFiltered() ? null : $customers->total(),
            'exportQuery' => array_filter($request->only(['q', 'orders', 'marketing', 'account', 'sort', 'direction']), 'is_string'),
        ]);
    }

    public function show(User $customer): View
    {
        $customer->load(['addresses' => fn ($q) => $q->orderByDesc('is_default')->orderByDesc('id')]);

        $ordersQuery = fn () => OrderFilters::forCustomer(Order::query(), $customer);
        $stats = $ordersQuery()
            ->toBase()
            ->selectRaw('COUNT(*) as orders, MIN(created_at) as first_order, MAX(created_at) as last_order')
            ->selectRaw('SUM(CASE WHEN status IN ('.implode(',', array_fill(0, count(Order::PAID_STATUSES), '?')).') THEN 1 ELSE 0 END) as paid_orders', Order::PAID_STATUSES)
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN ('.implode(',', array_fill(0, count(Order::PAID_STATUSES), '?')).') THEN total - refunded_total ELSE 0 END), 0) as spent', Order::PAID_STATUSES)
            ->first();

        $orders = $ordersQuery()
            ->select(['id', 'number', 'status', 'total', 'refunded_total', 'created_at', 'payment_method', 'payment_method_title'])
            ->withSum('items as item_quantity', 'quantity')
            ->latest()->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->where(fn ($w) => $w->where('orders.user_id', $customer->id)->orWhere(fn ($g) => $g->whereNull('orders.user_id')->where('orders.email', $customer->email)))
            ->selectRaw(Sql::qualify('order_items.product_id, MAX(order_items.name) as name, SUM(order_items.quantity) as quantity', ['order_items', 'orders']))
            ->groupBy('order_items.product_id')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get();

        $billing = $customer->addresses->firstWhere('type', 'billing');
        $shipping = $customer->addresses->firstWhere('type', 'shipping');

        return view('commerce::admin.customers.show', [
            'customer' => $customer,
            'stats' => $stats,
            'orders' => $orders,
            'aov' => (int) $stats->paid_orders > 0 ? round((float) $stats->spent / (int) $stats->paid_orders, 2) : 0,
            'billing' => $billing,
            'shipping' => $shipping,
            'topProducts' => $topProducts,
            'canEdit' => ! $customer->isStaff() || auth()->user()->isAdmin(),
            'isSelf' => $customer->id === auth()->id(),
        ]);
    }

    public function create(): View
    {
        return view('commerce::admin.customers.create', ['customer' => new User(['marketing_opt_in' => false])]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = DB::transaction(function () use ($request) {
            $customer = new User;
            $customer->forceFill($request->customerData() + [
                'role' => 'customer',
                'is_active' => true,
                'password' => null,
                'admin_note' => $request->input('admin_note') ?: null,
            ]);
            $customer->save();
            if ($address = $request->billingAddress()) {
                $customer->addresses()->create($address + ['type' => 'billing', 'is_default' => true]);
            }

            return $customer;
        });

        $message = 'Customer '.($customer->full_name ?: $customer->email).' created.';
        if ($request->boolean('send_invite')) {
            $message .= $this->sendResetLink($customer)
                ? ' They’ve been emailed a link to set a password.'
                : ' The set-password email couldn’t be sent.';
        }

        return redirect()->route('admin.customers.show', $customer)->with('success', $message);
    }

    public function update(CustomerRequest $request, User $customer): RedirectResponse
    {
        $customer->forceFill($request->customerData())->save();

        return back()->with('success', 'Customer details saved.');
    }

    public function address(CustomerAddressRequest $request, User $customer): RedirectResponse
    {
        $this->authorizeEdit($customer);
        $type = $request->type();
        $customer->addresses()->updateOrCreate(['type' => $type, 'is_default' => true], $request->addressData() + ($type === 'billing' ? ['email' => $customer->email] : []));

        return back()->with('success', ucfirst($type).' address saved.');
    }

    public function note(CustomerNoteRequest $request, User $customer): RedirectResponse
    {
        $customer->forceFill(['admin_note' => $request->note()])->save();

        return back()->with('success', $request->note() ? 'Note saved.' : 'Note removed.');
    }

    /** Email the customer a storefront "set / reset your password" link (also turns a guest into a registered account). */
    public function passwordReset(User $customer): RedirectResponse
    {
        $this->authorizeEdit($customer);
        if (! $customer->is_active) {
            return back()->with('error', 'This account is disabled – enable it before sending a password link.');
        }

        return $this->sendResetLink($customer)
            ? back()->with('success', 'Password reset link emailed to '.$customer->email.'.')
            : back()->with('error', 'The password email couldn’t be sent. Try again in a minute.');
    }

    public function toggleActive(User $customer): RedirectResponse
    {
        $this->authorizeEdit($customer);
        abort_if($customer->id === auth()->id(), 403, 'You can’t disable your own account.');

        $customer->forceFill(['is_active' => ! $customer->is_active])->save();
        if (! $customer->is_active) {
            DB::table('sessions')->where('user_id', $customer->id)->delete(); // sign them out everywhere
        }

        return back()->with('success', $customer->is_active
            ? 'Account enabled – they can sign in again.'
            : 'Account disabled – they can no longer sign in. Their orders are kept.');
    }

    public function destroy(User $customer): RedirectResponse
    {
        abort_if($customer->isStaff(), 403, 'Staff accounts are removed in Settings › Staff.');
        if (OrderFilters::forCustomer(Order::query()->withTrashed(), $customer)->exists()) {
            return back()->with('error', 'Customers with orders can’t be deleted (the orders must stay on record). Disable the account instead.');
        }
        $name = $customer->full_name ?: $customer->email;
        DB::transaction(function () use ($customer) {
            DB::table('sessions')->where('user_id', $customer->id)->delete();
            $customer->delete();
        });

        return redirect()->route('admin.customers.index')->with('success', "Customer {$name} deleted.");
    }

    public function bulk(CustomerBulkRequest $request): RedirectResponse
    {
        $count = User::where('role', 'customer')->whereIn('id', $request->ids())
            ->update(['marketing_opt_in' => $request->input('action') === 'subscribe', 'updated_at' => now()]);

        return back()->with('success', $count.' '.Str::plural('customer', $count).($request->input('action') === 'subscribe' ? ' marked as subscribed to marketing.' : ' unsubscribed from marketing.'));
    }

    /** Contact details + saved addresses for the create-order form (JSON). */
    public function lookup(User $customer): JsonResponse
    {
        $addresses = $customer->addresses()->orderByDesc('is_default')->orderByDesc('id')->get();
        $pick = fn (string $type) => ($a = $addresses->firstWhere('type', $type)) ? $a->only(['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'county', 'postcode', 'country', 'phone']) : null;

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->full_name ?: $customer->email,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'billing' => $pick('billing'),
            'shipping' => $pick('shipping'),
        ]);
    }

    protected function authorizeEdit(User $customer): void
    {
        abort_if($customer->isStaff() && ! auth()->user()->isAdmin(), 403, 'Only administrators can change staff accounts.');
    }

    protected function sendResetLink(User $customer): bool
    {
        try {
            $notification = class_exists(\Pine\Commerce\Notifications\CustomerResetPassword::class) ? \Pine\Commerce\Notifications\CustomerResetPassword::class : null;
            $status = Password::broker()->sendResetLink(
                ['email' => $customer->email],
                $notification ? fn (User $u, string $token) => $u->notify(new $notification($token)) : null,
            );

            return $status === Password::RESET_LINK_SENT;
        } catch (Throwable $e) {
            Log::warning('Admin could not send a password reset to user '.$customer->id.': '.$e->getMessage());

            return false;
        }
    }
}
