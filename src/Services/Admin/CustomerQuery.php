<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Support\Sql;

/**
 * Customers list query (page + CSV export): customer accounts with their order stats computed in SQL
 * (correlated subqueries, one query for the whole page – no N+1), plus filters and whitelisted sorting.
 *
 * A customer's orders = orders on their account + guest orders placed with the same email address.
 *   orders_count   every order (any status except deleted)
 *   total_spent    paid orders (Order::PAID_STATUSES) net of refunds
 *   last_order_at  most recent order
 */
class CustomerQuery
{
    public const SORTS = ['name', 'created_at', 'orders_count', 'total_spent', 'last_order_at'];

    public const HAS_ORDERS = ['yes' => 'Has orders', 'no' => 'No orders'];

    public const MARKETING = ['yes' => 'Subscribed to marketing', 'no' => 'Not subscribed'];

    public const ACCOUNT = ['registered' => 'Registered account', 'guest' => 'Guest (no password)', 'disabled' => 'Disabled accounts'];

    public function __construct(
        public string $q = '',
        public ?string $hasOrders = null,
        public ?string $marketing = null,
        public ?string $account = null,
        public array $ids = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $value = fn (string $key, array $allowed) => is_string($request->query($key)) && array_key_exists($request->query($key), $allowed) ? $request->query($key) : null;
        $q = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '';
        $ids = is_string($request->query('ids')) ? array_values(array_unique(array_filter(array_map('intval', explode(',', $request->query('ids')))))) : [];

        return new self($q, $value('orders', self::HAS_ORDERS), $value('marketing', self::MARKETING), $value('account', self::ACCOUNT), array_slice($ids, 0, 1000));
    }

    /** SQL matching orders to the outer users row (account orders + same-email guest orders). */
    public static function orderMatch(): string
    {
        return Sql::qualify('(o.user_id = users.id OR (o.user_id IS NULL AND o.email = users.email)) AND o.deleted_at IS NULL', ['users']);
    }

    public function query(): Builder
    {
        $paid = implode(',', array_map(fn ($s) => DB::getPdo()->quote($s), Order::PAID_STATUSES));
        $match = static::orderMatch();
        $orders = Sql::table('orders');
        $addresses = Sql::table('addresses');
        $usersId = Sql::col('users.id');

        $query = User::query()
            ->where('users.role', 'customer')
            ->select('users.*')
            ->selectRaw("(SELECT COUNT(*) FROM {$orders} o WHERE {$match}) AS orders_count")
            ->selectRaw("(SELECT COALESCE(SUM(o.total - o.refunded_total), 0) FROM {$orders} o WHERE {$match} AND o.status IN ({$paid})) AS total_spent")
            ->selectRaw("(SELECT MAX(o.created_at) FROM {$orders} o WHERE {$match}) AS last_order_at")
            ->selectRaw("(SELECT CONCAT_WS('|', COALESCE(a.city, ''), COALESCE(a.postcode, ''), COALESCE(a.country, '')) FROM {$addresses} a WHERE a.user_id = {$usersId} AND a.type = 'billing' ORDER BY a.is_default DESC, a.id DESC LIMIT 1) AS location");

        if ($this->q !== '') {
            foreach (array_slice(array_values(array_filter(preg_split('/\s+/', $this->q) ?: [])), 0, 5) as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';
                $query->where(fn (Builder $w) => $w
                    ->where('users.email', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhere('users.first_name', 'like', $like)
                    ->orWhere('users.last_name', 'like', $like)
                    ->orWhere('users.phone', 'like', $like)
                    ->orWhereExists(fn ($a) => $a->select(DB::raw(1))->from('addresses')->whereColumn('addresses.user_id', 'users.id')
                        ->where(fn ($f) => $f->where('addresses.postcode', 'like', $like)->orWhere('addresses.city', 'like', $like)->orWhere('addresses.company', 'like', $like))));
            }
        }
        if ($this->hasOrders) {
            $exists = fn ($o) => $o->select(DB::raw(1))->from(DB::raw(Sql::table('orders').' o'))->whereRaw($match); // raw alias: $match uses o.*
            $this->hasOrders === 'yes' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }
        if ($this->marketing) {
            $query->where('users.marketing_opt_in', $this->marketing === 'yes');
        }
        match ($this->account) {
            'registered' => $query->whereNotNull('users.password'),
            'guest' => $query->whereNull('users.password'),
            'disabled' => $query->where('users.is_active', false),
            default => null,
        };
        if ($this->ids) {
            $query->whereIn('users.id', $this->ids);
        }

        return $query;
    }

    public function sorted(string $sort, string $direction): Builder
    {
        $query = $this->query();
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'name' => $query->orderByRaw(Sql::qualify("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', users.first_name, users.last_name)), ''), users.name) {$direction}", ['users'])),
            'orders_count', 'total_spent', 'last_order_at' => $query->orderByRaw("{$sort} IS NULL")->orderBy($sort, $direction),
            default => $query->orderBy('users.created_at', $direction),
        };

        return $query->orderByDesc('users.id');
    }

    public function chips(): array
    {
        return array_filter([
            'orders' => $this->hasOrders ? self::HAS_ORDERS[$this->hasOrders] : null,
            'marketing' => $this->marketing ? self::MARKETING[$this->marketing] : null,
            'account' => $this->account ? self::ACCOUNT[$this->account] : null,
            'ids' => $this->ids ? count($this->ids).' selected' : null,
        ]);
    }

    public function isFiltered(): bool
    {
        return $this->q !== '' || $this->hasOrders || $this->marketing || $this->account || $this->ids;
    }

    /** "Swindon, SN1 2AB" (+ country when not the UK) from the location subquery. */
    public static function location(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        [$city, $postcode, $country] = array_pad(explode('|', $raw), 3, null);
        $parts = array_filter([$city, $postcode, $country && $country !== 'GB' ? Countries::name($country) : null]);

        return $parts ? implode(', ', $parts) : null;
    }
}
