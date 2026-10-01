<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Sales\FulfilOrderRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\OrderAddressRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\OrderBulkRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\OrderEmailRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\OrderFormRequest;
use Pine\Commerce\Http\Requests\Admin\Sales\OrderStatusRequest;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Admin\OrderFilters;
use Pine\Commerce\Services\Admin\OrderManager;
use Pine\Commerce\Services\Admin\OrderPricing;
use Pine\Commerce\Services\Admin\OrderStatus;
use Pine\Commerce\Services\Admin\PaymentMethods;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Orders: list (status tabs, search, filters, bulk status/print/export), order page (Shopify-style), create a manual /
 * phone order, edit an order, status changes, fulfilment, addresses, emails and (soft) delete.
 * Refunds and notes have their own controllers; every write goes through Pine\Commerce\Services\Admin\OrderManager.
 */
class OrderController extends Controller
{
    use AdminIndex;

    public const SORTS = ['created_at', 'total', 'number'];

    /** Columns the list needs (keeps the page light with ~1000 orders). */
    protected const LIST_COLUMNS = [
        'id', 'number', 'status', 'user_id', 'email', 'billing_first_name', 'billing_last_name', 'billing_company', 'total',
        'refunded_total', 'payment_method', 'payment_method_title', 'shipping_method_title', 'created_via', 'created_at', 'customer_note',
    ];

    public function index(Request $request): View
    {
        $filters = OrderFilters::fromRequest($request);
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'created_at', 'desc');

        $orders = $filters->apply(Order::query())
            ->select(self::LIST_COLUMNS)
            ->withSum('items as item_quantity', 'quantity')
            ->when($sort === 'number', fn (Builder $q) => $q->orderByRaw('CAST(number AS UNSIGNED) '.$direction), fn (Builder $q) => $q->orderBy($sort, $direction))
            ->orderBy('id', $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        $counts = $filters->counts();
        $tabs = [];
        foreach (OrderFilters::TABS as $key => $label) {
            $tabs[$key] = ['label' => $label, 'count' => $counts[$key]];
        }

        return view('commerce::admin.orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'tabs' => $tabs,
            'status' => $filters->status,
            'chips' => $filters->chips(),
            'paymentOptions' => PaymentMethods::filterOptions(),
            'totalOrders' => $filters->isFiltered() ? null : $counts['all'],
            'exportQuery' => $filters->query() + array_filter(['sort' => $request->query('sort'), 'direction' => $request->query('direction')], 'is_string'),
        ]);
    }

    public function show(Order $order): View
    {
        $order->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product' => fn ($q) => $q->select(['id', 'name', 'slug', 'sku', 'type', 'status', 'deleted_at', 'primary_category_id'])
                ->with(['images' => fn ($images) => $images->orderBy('sort_order')->limit(1)]),
            'items.variation:id,product_id,image,sku',
            'notes' => fn ($q) => $q->reorder()->orderByDesc('created_at')->orderByDesc('id'),
            'notes.user:id,name,first_name,last_name',
            'refunds' => fn ($q) => $q->orderByDesc('created_at'),
            'refunds.user:id,name,first_name,last_name',
            'user:id,name,first_name,last_name,email,phone,role,is_active',
        ]);

        // Guest orders belong to the customer account with the same email, if there is one
        $customer = $order->user ?? User::where('email', $order->email)->first(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'role', 'is_active']);
        $customerOrders = $customer
            ? OrderFilters::forCustomer(Order::query(), $customer)->count()
            : Order::where('email', $order->email)->count();

        $newer = Order::query()
            ->where(fn (Builder $q) => $q->where('created_at', '>', $order->created_at)
                ->orWhere(fn (Builder $same) => $same->where('created_at', $order->created_at)->where('id', '>', $order->id)))
            ->orderBy('created_at')->orderBy('id')->value('id');
        $older = Order::query()
            ->where(fn (Builder $q) => $q->where('created_at', '<', $order->created_at)
                ->orWhere(fn (Builder $same) => $same->where('created_at', $order->created_at)->where('id', '<', $order->id)))
            ->orderByDesc('created_at')->orderByDesc('id')->value('id');

        $refundable = OrderManager::refundableAmount($order);
        $refundLines = $order->items->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'options' => $item->options,
            'quantity' => $item->quantity,
            'refunded' => $item->refunded_quantity,
            'remaining' => max(0, $item->quantity - $item->refunded_quantity),
            'unit' => $item->quantity > 0 ? round((float) $item->total / $item->quantity, 4) : 0,
        ])->values()->all();

        return view('commerce::admin.orders.show', [
            'order' => $order,
            'customer' => $customer,
            'customerOrders' => $customerOrders,
            'newerId' => $newer,
            'olderId' => $older,
            'refundable' => $refundable,
            'refundLines' => $refundLines,
            'shippingRefundable' => max(0, round((float) $order->shipping_total - OrderManager::refundedShipping($order), 2)),
            'canRefund' => $refundable > 0 && ! in_array($order->status, ['pending', 'failed', 'cancelled'], true),
            'gatewayRefunds' => OrderManager::gatewayCanRefund($order),
            'emails' => OrderManager::resendableEmails(),
            'transactionLink' => PaymentMethods::transactionLink($order->payment_method, $order->transaction_id),
            'attribution' => $this->attribution($order),
            'editable' => OrderManager::isEditable($order),
            'deletable' => OrderManager::isDeletable($order),
            'payUrl' => \Pine\Commerce\Mail\Admin\CustomerInvoice::payUrl($order),
            'carriers' => $this->carriers(),
        ]);
    }

    public function create(Request $request): View
    {
        $customer = ctype_digit((string) $request->query('customer')) ? User::find((int) $request->query('customer')) : null;

        return view('commerce::admin.orders.form', [
            'order' => new Order(['status' => 'pending', 'billing_country' => 'GB', 'shipping_country' => 'GB']),
            'initial' => $this->formState(null, $customer),
            'shippingMethods' => ShippingMethod::orderBy('sort_order')->get(['id', 'name', 'code', 'cost', 'is_active']),
            'editableItems' => true,
        ]);
    }

    public function store(OrderFormRequest $request): RedirectResponse
    {
        try {
            $order = OrderManager::createManualOrder($request->orderData());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = "Order #{$order->number} created.";
        if ($request->boolean('send_invoice') && $order->status === 'pending') {
            $message .= ' Payment link emailed to '.$order->email.'.';
        }

        return redirect()->route('admin.orders.show', $order)->with('success', $message);
    }

    public function edit(Order $order): View
    {
        $order->load(['items' => fn ($q) => $q->orderBy('id'), 'items.product:id,name,sku,type,deleted_at', 'user:id,name,first_name,last_name,email']);

        return view('commerce::admin.orders.form', [
            'order' => $order,
            'initial' => $this->formState($order),
            'shippingMethods' => ShippingMethod::orderBy('sort_order')->get(['id', 'name', 'code', 'cost', 'is_active']),
            'editableItems' => OrderManager::isEditable($order),
        ]);
    }

    public function update(OrderFormRequest $request, Order $order): RedirectResponse
    {
        $data = $request->orderData();
        if (! OrderManager::isEditable($order)) {
            unset($data['lines']);
        }
        try {
            $changes = OrderManager::updateOrder($order, $data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.orders.show', $order)
            ->with($changes ? 'success' : 'info', $changes ? 'Order updated: '.implode(', ', $changes).'.' : 'Nothing changed.');
    }

    public function destroy(Order $order): RedirectResponse
    {
        try {
            OrderManager::delete($order);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.orders.index', ['status' => 'all'])->with('success', "Order #{$order->number} deleted.");
    }

    public function status(OrderStatusRequest $request, Order $order): RedirectResponse
    {
        $status = $request->input('status');
        if (! OrderManager::changeStatus($order, $status, $request->input('status_note'))) {
            return back()->with('info', 'The order is already '.Str::lower(OrderStatus::label($status)).'.');
        }

        return back()->with('success', 'Order #'.$order->number.' marked as '.Str::lower(OrderStatus::label($status)).'.');
    }

    public function fulfil(FulfilOrderRequest $request, Order $order): RedirectResponse
    {
        if (in_array($order->status, ['cancelled', 'refunded', 'failed'], true)) {
            return back()->with('error', 'A '.Str::lower(OrderStatus::label($order->status)).' order can’t be fulfilled.');
        }
        OrderManager::fulfil($order, $request->input('tracking_carrier'), $request->input('tracking_number'));

        return back()->with('success', 'Order #'.$order->number.' marked as completed.'.($order->email ? ' The customer has been emailed.' : ''));
    }

    public function address(OrderAddressRequest $request, Order $order): RedirectResponse
    {
        $changed = OrderManager::saveAddress($order, $request->type(), $request->addressData());

        return back()->with($changed ? 'success' : 'info', $changed ? ucfirst($request->type()).' address saved.' : 'Nothing changed.');
    }

    public function email(OrderEmailRequest $request, Order $order): RedirectResponse
    {
        $class = $request->input('email');
        $invoice = $class === \Pine\Commerce\Mail\Admin\CustomerInvoice::class ? \Pine\Commerce\Mail\Admin\CustomerInvoice::attachmentNote($order) : null;
        $to = OrderManager::resendEmail($order, $class);

        return $to
            ? back()->with('success', '“'.OrderManager::emailLabel($class).'” sent to '.$to.'.'.($invoice ? ' '.$invoice.'.' : ''))
            : back()->with('error', 'The email couldn’t be sent. Check the order has a valid email address and the mail settings are correct.');
    }

    public function bulk(OrderBulkRequest $request): RedirectResponse
    {
        $status = $request->input('action');
        $changed = 0;
        $skipped = 0;
        Order::whereIn('id', $request->ids())->orderBy('id')->get()->each(function (Order $order) use ($status, &$changed, &$skipped) {
            OrderManager::changeStatus($order, $status, 'Bulk update.') ? $changed++ : $skipped++;
        });

        $message = $changed.' '.Str::plural('order', $changed).' marked as '.Str::lower(OrderStatus::label($status)).'.';
        if ($skipped) {
            $message .= ' '.$skipped.' already had that status.';
        }

        return back()->with($changed ? 'success' : 'info', $message);
    }

    // JSON for the create / edit order form ------------------------------------------

    /** Live totals for the order form (the same maths the order is saved with). */
    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.variation_id' => ['nullable', 'integer'],
            'lines.*.order_item_id' => ['nullable', 'integer'],
            'lines.*.name' => ['nullable', 'string', 'max:190'],
            'lines.*.sku' => ['nullable', 'string', 'max:100'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'lines.*.unit_price' => ['nullable', 'string', 'max:20'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'manual_discount' => ['nullable', 'string', 'max:20'],
            'shipping_method' => ['nullable', 'string', 'max:60'],
            'shipping_cost' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:190'],
            'user_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'shipping_same_as_billing' => ['nullable', 'boolean'],
            'billing_country' => ['nullable', 'string', 'max:2'], 'billing_postcode' => ['nullable', 'string', 'max:20'],
            'billing_county' => ['nullable', 'string', 'max:100'], 'billing_city' => ['nullable', 'string', 'max:100'],
            'shipping_country' => ['nullable', 'string', 'max:2'], 'shipping_postcode' => ['nullable', 'string', 'max:20'],
            'shipping_county' => ['nullable', 'string', 'max:100'], 'shipping_city' => ['nullable', 'string', 'max:100'],
        ]);
        $input['address'] = OrderManager::taxAddress($input);
        $order = ! empty($input['order_id']) ? Order::find($input['order_id']) : null;
        $input['lines'] = $this->withReservedStock($order, $input['lines'] ?? []);

        $quote = OrderPricing::present(OrderPricing::quote($input + [
            'exclude_order_id' => $order?->id,
            'trusted_coupons' => $order ? array_filter(array_map('trim', explode(',', (string) $order->coupon_code))) : [],
        ]));

        // Options for variable products, so the form can show an option picker on every line
        $variableIds = collect($quote['lines'])->where('is_variable', true)->pluck('product_id')->filter()->unique()->values();
        if ($variableIds->isNotEmpty()) {
            $products = Product::withTrashed()->whereIn('id', $variableIds)
                ->with(['variations' => fn ($v) => $v->where('is_active', true)->orderBy('sort_order')])->get()->keyBy('id');
            foreach ($quote['lines'] as $i => $line) {
                $product = $line['is_variable'] ? $products->get($line['product_id']) : null;
                if ($product) {
                    $quote['lines'][$i]['variations'] = $product->variations->map(fn ($v) => [
                        'id' => $v->id,
                        'label' => implode(', ', OrderPricing::variationOptions($v->setRelation('product', $product))) ?: 'Option #'.$v->id,
                        'sku' => $v->sku,
                        'price' => $v->currentPrice(),
                        'stock' => $v->manage_stock ? (int) $v->stock_quantity : null,
                        'stock_status' => $v->stock_status,
                    ])->values()->all();
                }
            }
        }

        return response()->json($quote);
    }

    /** Product search for the order form: price, stock and variations included. */
    public function products(Request $request): JsonResponse
    {
        $q = $this->searchTerm($request);
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $query = Product::query()
            ->with(['images' => fn ($images) => $images->orderBy('sort_order')->limit(1), 'variations' => fn ($v) => $v->where('is_active', true)->orderBy('sort_order')]);
        foreach (array_slice(array_values(array_filter(preg_split('/\s+/', $q) ?: [])), 0, 5) as $word) {
            $like = $this->like($word);
            $query->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhereHas('variations', fn ($v) => $v->where('sku', 'like', $like)));
        }
        $products = $query
            ->orderByRaw('sku = ? DESC', [$q])
            ->orderByRaw("status = 'published' DESC")
            ->orderByRaw("stock_status = 'outofstock' ASC")
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'sku', 'type', 'status', 'price', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'manage_stock', 'stock_quantity', 'stock_status']);

        return response()->json(['data' => $products->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'type' => $p->type,
            'status' => $p->status,
            'price' => $p->currentPrice(),
            'image' => ($image = $p->images->first()?->path) ? media_url($image) : null,
            'stock' => $p->manage_stock ? (int) $p->stock_quantity : null,
            'stock_status' => $p->stock_status,
            'sub' => implode(' · ', array_filter([
                $p->sku ? 'SKU '.$p->sku : null,
                $p->currentPrice() !== null ? money($p->currentPrice()) : null,
                $p->manage_stock ? (int) $p->stock_quantity.' in stock' : (OrderStatus::STOCK_STATUSES[$p->stock_status] ?? null),
                $p->status !== 'published' ? (OrderStatus::PRODUCT_STATUSES[$p->status] ?? $p->status) : null,
            ])),
            'variations' => $p->type === 'variable' ? $p->variations->map(fn ($v) => [
                'id' => $v->id,
                'label' => implode(', ', OrderPricing::variationOptions($v->setRelation('product', $p))) ?: 'Option #'.$v->id,
                'sku' => $v->sku,
                'price' => $v->currentPrice(),
                'stock' => $v->manage_stock ? (int) $v->stock_quantity : null,
                'stock_status' => $v->stock_status,
            ])->values() : [],
        ])->values()]);
    }

    // Helpers -------------------------------------------------------------------------

    /** Initial state for the order form's Alpine component. */
    protected function formState(?Order $order, ?User $customer = null): array
    {
        $old = fn (string $key, $default = null) => old($key, $default);
        if (old('user_id') !== null) {
            // Back from a validation error: keep the customer that was picked (or none)
            $customer = ctype_digit((string) old('user_id')) ? User::find((int) old('user_id')) : null;
        } else {
            $customer ??= $order?->user;
        }
        $billing = $customer && ! $order ? $customer->addresses()->where('type', 'billing')->orderByDesc('is_default')->first() : null;
        $shipping = $customer && ! $order ? $customer->addresses()->where('type', 'shipping')->orderByDesc('is_default')->first() : null;

        $address = [];
        foreach (OrderManager::ADDRESS_FIELDS as $field) {
            $address['billing_'.$field] = (string) $old('billing_'.$field, $order ? $order->{'billing_'.$field} : ($billing?->{$field} ?? ($field === 'first_name' ? $customer?->first_name : ($field === 'last_name' ? $customer?->last_name : null)))) ?? '';
            $address['shipping_'.$field] = (string) $old('shipping_'.$field, $order ? $order->{'shipping_'.$field} : ($shipping?->{$field})) ?? '';
        }
        $address['billing_country'] = $address['billing_country'] ?: 'GB';
        $address['shipping_country'] = $address['shipping_country'] ?: 'GB';

        $sameAsBilling = $order
            ? collect(OrderManager::ADDRESS_FIELDS)->every(fn ($f) => (string) $order->{'billing_'.$f} === (string) $order->{'shipping_'.$f} || (string) $order->{'shipping_'.$f} === '')
            : ! $shipping || collect(OrderManager::ADDRESS_FIELDS)->every(fn ($f) => (string) ($billing?->{$f}) === (string) $shipping->{$f});

        $lines = [];
        if (is_array(old('lines'))) {
            $lines = array_values(old('lines'));
        } elseif ($order) {
            foreach ($order->items as $item) {
                $lines[] = [
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'variation_id' => $item->product_variation_id,
                    'name' => $item->product_id ? null : $item->name,
                    'sku' => $item->product_id ? null : $item->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                ];
            }
        }

        $knownMethod = $order && $order->shipping_method ? ShippingMethod::where('code', $order->shipping_method)->first(['id', 'code', 'cost']) : null;
        $manualDiscount = $order ? (float) ($order->meta['manual_discount'] ?? 0) : 0;
        $couponCode = (string) ($order?->coupon_code ?? '');
        if ($couponCode !== '' && ! Coupon::whereIn(DB::raw('LOWER(code)'), array_map('mb_strtolower', array_map('trim', explode(',', $couponCode))))->exists()) {
            $couponCode = ''; // the code was deleted since – keep its discount as a plain amount
            $manualDiscount = (float) $order->discount_total;
        }
        if ($order && $couponCode === '' && $manualDiscount <= 0 && (float) $order->discount_total > 0) {
            $manualDiscount = (float) $order->discount_total; // imported order discounts without a code
        }

        return array_merge($address, [
            'orderId' => $order?->id,
            'userId' => (string) $old('user_id', $order?->user_id ?? $customer?->id ?? ''),
            'customer' => $customer ? [
                'id' => $customer->id, 'name' => $customer->full_name ?: $customer->email, 'email' => $customer->email,
            ] : null,
            'email' => (string) $old('email', $order?->email ?? $customer?->email ?? ''),
            'phone' => (string) $old('phone', $order?->phone ?? $customer?->phone ?? $billing?->phone ?? ''),
            'shipping_phone' => (string) $old('shipping_phone', $order?->shipping_phone ?? $shipping?->phone ?? ''),
            'sameAsBilling' => (bool) $old('shipping_same_as_billing', $sameAsBilling),
            'lines' => $lines,
            'coupon_code' => (string) $old('coupon_code', $couponCode),
            'manual_discount' => (string) $old('manual_discount', $manualDiscount > 0 ? number_format($manualDiscount, 2, '.', '') : ''),
            'shipping_method' => (string) $old('shipping_method', $order ? ($knownMethod ? $order->shipping_method : '') : (ShippingMethod::active()->orderBy('sort_order')->value('code') ?? '')),
            // Keep what the order charged when it differs from the method's price (or the method no longer exists)
            'shipping_cost' => (string) $old('shipping_cost', $order && ((float) $order->shipping_total > 0 || $knownMethod)
                && (! $knownMethod || abs((float) $knownMethod->cost - (float) $order->shipping_total) > 0.004)
                ? number_format((float) $order->shipping_total, 2, '.', '') : ''),
            'customer_note' => (string) $old('customer_note', $order?->customer_note ?? ''),
            'status' => (string) $old('status', 'pending'),
            'payment_method' => (string) $old('payment_method', ''),
            'transaction_id' => (string) $old('transaction_id', ''),
            'private_note' => (string) $old('private_note', ''),
            'reduce_stock' => (bool) $old('reduce_stock', true),
            'send_invoice' => (bool) $old('send_invoice', false),
            'save_addresses' => (bool) $old('save_addresses', false),
        ]);
    }

    /** Lines of an order that already holds stock don't need that stock again (no false "only 0 in stock" warnings). */
    protected function withReservedStock(?Order $order, array $lines): array
    {
        if (! $order || ! class_exists(OrderManager::ORDER_STOCK) || ! (($order->meta['stock_reduced'] ?? false))) {
            return $lines;
        }
        $held = $order->items()->get(['id', 'product_id', 'product_variation_id', 'quantity', 'refunded_quantity'])
            ->groupBy(fn ($i) => $i->product_id.'-'.$i->product_variation_id)
            ->map(fn ($group) => $group->sum(fn ($i) => $i->quantity - $i->refunded_quantity));
        foreach ($lines as $i => $line) {
            $key = ($line['product_id'] ?? '').'-'.($line['variation_id'] ?? '');
            if ($held->has($key)) {
                $lines[$i]['reserved'] = $held->pull($key);
            }
        }

        return $lines;
    }

    /** @return list<array{label:string, value:string}> marketing attribution from the checkout (new + imported formats) */
    protected function attribution(Order $order): array
    {
        $meta = (array) $order->meta;
        $new = is_array($meta['attribution'] ?? null) ? $meta['attribution'] : [];
        $pick = fn (string $key) => $new[$key] ?? ($meta['attribution_'.$key] ?? null);

        $rows = [
            'Source type' => static::sourceTypeLabel($order->source_type ?: $pick('source_type')),
            'Source' => $order->source ?: $pick('utm_source'),
            'Campaign' => $pick('utm_campaign'),
            'Medium' => $pick('utm_medium'),
            'Referrer' => $pick('referrer'),
            'Landing page' => $pick('session_entry') ?? $pick('landing_page'),
            'Device' => $pick('device_type'),
            'Pages viewed' => $pick('session_pages'),
            'Visits' => $pick('session_count'),
        ];

        return collect($rows)
            ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '' && $v !== '(none)')
            ->map(fn ($v, $label) => ['label' => $label, 'value' => Str::limit((string) $v, 300)])
            ->values()->all();
    }

    public static function sourceTypeLabel(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        return [
            'typein' => 'Direct (typed in / bookmark)',
            'direct' => 'Direct',
            'organic' => 'Organic search',
            'referral' => 'Referral (another website)',
            'utm' => 'Campaign link (UTM)',
            'admin' => 'Created by staff',
            'mobile_app' => 'Mobile app',
        ][$type] ?? Str::headline($type);
    }

    /** Carrier suggestions: the store's usual couriers plus any used before. */
    protected function carriers(): array
    {
        $used = DB::table('orders')->whereNotNull('tracking_carrier')->where('tracking_carrier', '!=', '')
            ->distinct()->limit(20)->pluck('tracking_carrier')->all();

        return array_values(array_unique(array_merge(['DPD', 'Royal Mail', 'Parcelforce', 'DHL', 'UPS', 'Evri', 'FedEx'], $used)));
    }
}
