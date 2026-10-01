<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Support\Sql;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The orders list's filters (status tab, search, dates, payment method, refunds, customer), shared by the
 * orders page and its CSV export so "Export" always downloads exactly what is on screen.
 *
 *   $filters = OrderFilters::fromRequest($request);
 *   $orders = $filters->apply(Order::query())->latest()->paginate(25);
 */
class OrderFilters
{
    /** Status tabs, in display order. "processing" (To fulfil) is the default tab. */
    public const TABS = [
        'processing' => 'To fulfil',
        'on-hold' => 'On hold',
        'pending' => 'Pending payment',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'failed' => 'Failed',
        'all' => 'All',
    ];

    public const DEFAULT_TAB = 'processing';

    public const REFUND_FILTERS = ['yes' => 'Has refunds', 'no' => 'No refunds'];

    public const CREATED_VIA = ['checkout' => 'Online checkout', 'admin' => 'Created by staff'];

    public function __construct(
        public string $status = self::DEFAULT_TAB,
        public string $q = '',
        public ?string $from = null,       // UK date Y-m-d
        public ?string $to = null,         // UK date Y-m-d
        public ?string $payment = null,
        public ?string $refunds = null,    // yes | no
        public ?string $via = null,        // checkout | admin
        public ?User $customer = null,
        public array $ids = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $string = fn (string $key, int $max = 100) => is_string($request->query($key)) ? mb_substr(trim($request->query($key)), 0, $max) : '';
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $string($key)) && strtotime($string($key)) ? $string($key) : null;

        $q = $string('q');
        $from = $date('from');
        $to = $date('to');
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $payment = $string('payment', 60) ?: null;
        $refunds = array_key_exists($string('refunds'), self::REFUND_FILTERS) ? $string('refunds') : null;
        $via = array_key_exists($string('via'), self::CREATED_VIA) ? $string('via') : null;
        $customer = ctype_digit($string('customer')) ? User::find((int) $string('customer')) : null;
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $string('ids', 5000))))));

        $status = $string('status');
        if (! array_key_exists($status, self::TABS)) {
            // Searching or filtering without choosing a tab looks through every order (e.g. a coupon's "orders" link)
            $filtered = $q !== '' || $from || $to || $payment || $refunds || $via || $customer || $ids;
            $status = $filtered ? 'all' : self::DEFAULT_TAB;
        }

        return new self($status, $q, $from, $to, $payment, $refunds, $via, $customer, array_slice($ids, 0, 1000));
    }

    /** Everything except the status tab (used for the tab counts). */
    public function applyFilters(Builder $query): Builder
    {
        $query->when($this->ids, fn (Builder $w) => $w->whereIn('orders.id', $this->ids));

        if ($this->q !== '') {
            static::search($query, $this->q);
        }
        if ($this->from) {
            $query->where('orders.created_at', '>=', LocalTime::fromInput($this->from));
        }
        if ($this->to) {
            $query->where('orders.created_at', '<=', LocalTime::fromInput($this->to, true));
        }
        if ($this->payment) {
            $query->where('orders.payment_method', $this->payment);
        }
        if ($this->refunds === 'yes') {
            $query->where('orders.refunded_total', '>', 0);
        } elseif ($this->refunds === 'no') {
            $query->where('orders.refunded_total', '<=', 0);
        }
        if ($this->via) {
            $query->where('orders.created_via', $this->via);
        }
        if ($this->customer) {
            static::forCustomer($query, $this->customer);
        }

        return $query;
    }

    public function apply(Builder $query): Builder
    {
        $this->applyFilters($query);
        if ($this->status !== 'all') {
            $query->where('orders.status', $this->status);
        }

        return $query;
    }

    /** Orders placed by a customer: their account's orders plus guest orders with the same email. */
    public static function forCustomer(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $w) => $w->where('orders.user_id', $user->id)
            ->orWhere(fn (Builder $guest) => $guest->whereNull('orders.user_id')->where('orders.email', $user->email)));
    }

    /**
     * Search by order number (#1051 or 1051), customer name, email, postcode, phone, SKU, coupon code or payment reference.
     */
    public static function search(Builder $query, string $q): Builder
    {
        $digits = ltrim(trim($q), '#');
        $like = '%'.addcslashes($q, '%_\\').'%';
        $words = array_slice(array_values(array_filter(preg_split('/\s+/', $q) ?: [])), 0, 5);
        $postcode = preg_replace('/\s+/', '', $q);

        return $query->where(function (Builder $w) use ($q, $digits, $like, $words, $postcode) {
            if (ctype_digit($digits)) {
                $w->orWhere('orders.number', $digits);
            }
            $w->orWhere('orders.email', 'like', $like)
                ->orWhere('orders.phone', 'like', $like)
                ->orWhere('orders.shipping_phone', 'like', $like)
                ->orWhere('orders.transaction_id', $q)
                ->orWhere('orders.coupon_code', 'like', $like)
                ->orWhere('orders.tracking_number', $q)
                ->orWhereRaw(Sql::qualify("REPLACE(orders.billing_postcode, ' ', '') LIKE ?", ['orders', 'order_items']), ['%'.addcslashes($postcode, '%_\\').'%'])
                ->orWhereRaw(Sql::qualify("REPLACE(orders.shipping_postcode, ' ', '') LIKE ?", ['orders', 'order_items']), ['%'.addcslashes($postcode, '%_\\').'%'])
                ->orWhere(function (Builder $names) use ($words) {
                    foreach ($words as $word) {
                        $names->whereRaw(
                            Sql::qualify("CONCAT_WS(' ', orders.billing_first_name, orders.billing_last_name, orders.shipping_first_name, orders.shipping_last_name, orders.billing_company) LIKE ?", ['orders']),
                            ['%'.addcslashes($word, '%_\\').'%']
                        );
                    }
                })
                ->orWhereExists(fn ($items) => $items->select(DB::raw(1))->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where(fn ($s) => $s->where('order_items.sku', 'like', $like)->orWhere('order_items.name', 'like', $like)));
        });
    }

    /** Count per status tab for the current filters, in one query. @return array<string, int> */
    public function counts(): array
    {
        $rows = $this->applyFilters(Order::query())
            ->toBase()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (array_keys(self::TABS) as $tab) {
            $counts[$tab] = $tab === 'all' ? (int) $rows->sum() : (int) ($rows[$tab] ?? 0);
        }

        return $counts;
    }

    /** Removable chips for the active filters: [query param => label]. */
    public function chips(): array
    {
        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->format('j M Y') : null;
        $chips = [];
        if ($this->from) {
            $chips['from'] = 'From '.$fmt($this->from);
        }
        if ($this->to) {
            $chips['to'] = 'Until '.$fmt($this->to);
        }
        if ($this->payment) {
            $chips['payment'] = 'Payment: '.PaymentMethods::label($this->payment);
        }
        if ($this->refunds) {
            $chips['refunds'] = self::REFUND_FILTERS[$this->refunds];
        }
        if ($this->via) {
            $chips['via'] = self::CREATED_VIA[$this->via];
        }
        if ($this->customer) {
            $chips['customer'] = 'Customer: '.($this->customer->full_name ?: $this->customer->email);
        }
        if ($this->ids) {
            $chips['ids'] = count($this->ids).' selected '.str('order')->plural(count($this->ids));
        }

        return $chips;
    }

    public function isFiltered(): bool
    {
        return $this->q !== '' || $this->from || $this->to || $this->payment || $this->refunds || $this->via || $this->customer || $this->ids;
    }

    /** Query-string parameters that reproduce these filters (for export links). */
    public function query(): array
    {
        return array_filter([
            'status' => $this->status,
            'q' => $this->q !== '' ? $this->q : null,
            'from' => $this->from,
            'to' => $this->to,
            'payment' => $this->payment,
            'refunds' => $this->refunds,
            'via' => $this->via,
            'customer' => $this->customer?->id,
            'ids' => $this->ids ? implode(',', $this->ids) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
