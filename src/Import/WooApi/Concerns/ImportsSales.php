<?php

namespace Pine\Commerce\Import\WooApi\Concerns;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Mapping\CatalogRows;
use Pine\Commerce\Import\Mapping\CustomerRows;
use Pine\Commerce\Import\Mapping\OrderRows;
use Pine\Commerce\Import\Mapping\OrderWriter;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\WooApi\ApiMap;
use Pine\Commerce\Import\WooApi\WooApiException;
use Throwable;

/**
 * Customers (+ guests from orders), coupons, orders (+ refunds, notes, tax lines) and reviews of the WooCommerce API
 * import (REST mode only – the Store API has none of them). Passwords cannot be read through the API: imported
 * customers set one with "forgot password".
 */
trait ImportsSales
{
    protected ?CustomerRows $addresses = null;

    protected function addressWriter(): CustomerRows
    {
        $country = strtoupper(explode(':', (string) config('commerce.store.country', 'GB'))[0]) ?: 'GB';

        return $this->addresses ??= new CustomerRows($country);
    }

    protected function importCustomers(): void
    {
        if ($this->store()) {
            $this->entity('customers', 'skipped');

            return;
        }
        $query = ['orderby' => 'id', 'order' => 'asc'];
        $this->paged('customers', 'customers', $query, 'wc/v3', function (array $items) {
            $rows = [];
            $addresses = [];
            foreach ($items as $c) {
                $email = strtolower(trim((string) ($c['email'] ?? '')));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->issue('warning', 'customers', $c['id'] ?? null, 'no valid e-mail address – not imported');

                    continue;
                }
                $first = Formatter::decode((string) ($c['first_name'] ?? '')) ?: null;
                $last = Formatter::decode((string) ($c['last_name'] ?? '')) ?: null;
                $created = ApiMap::date($c['date_created_gmt'] ?? null);
                $rows[$email] = [
                    'email' => $email,
                    'name' => trim(($first ?? '').' '.($last ?? '')) ?: (Formatter::decode((string) ($c['username'] ?? '')) ?: $email),
                    'first_name' => $first,
                    'last_name' => $last,
                    'phone' => trim((string) ($c['billing']['phone'] ?? '')) ?: null,
                    'role' => 'customer',
                    'is_active' => true,
                    'password' => null, // the API never exposes password hashes – customers use "forgot password"
                    'wp_id' => (int) $c['id'],
                    'email_verified_at' => $created,
                    'created_at' => $created ?? $this->now(),
                    'updated_at' => ApiMap::date($c['date_modified_gmt'] ?? null) ?? $this->now(),
                ];
                $addresses[$email] = ['billing' => CustomerRows::address((array) ($c['billing'] ?? [])), 'shipping' => CustomerRows::address((array) ($c['shipping'] ?? []))];
            }
            $rows = $this->withoutExisting('customers', 'users', array_values($rows), 'email');
            $this->transaction(function () use ($rows, $addresses) {
                // existing accounts (any source, or made here) keep their role, password, active flag and remote id
                $map = $this->saveRows('customers', 'users', $rows, 'email', ['role', 'password', 'is_active', 'created_at', 'wp_id', 'email_verified_at']);
                if (! $this->dryRun) {
                    foreach ($map as $email => $userId) {
                        $a = $addresses[$email] ?? [];
                        $this->addressWriter()->ensureAddresses((int) $userId, ['billing' => $a['billing'] ?? null, 'shipping' => ($a['shipping'] ?? null) ?? ($a['billing'] ?? null)],
                            null, $this->now());
                    }
                }
            });
        });
    }

    protected function importCoupons(): void
    {
        if ($this->store()) {
            $this->entity('coupons', 'skipped');

            return;
        }
        $this->paged('coupons', 'coupons', $this->since(['orderby' => 'id', 'order' => 'asc']), 'wc/v3', function (array $items) {
            $productMap = $this->u->map('products');
            $categoryMap = $this->u->map('categories');
            $rows = [];
            foreach ($items as $c) {
                try {
                    $rows[] = CatalogRows::coupon((string) ($c['code'] ?? ''), (string) ($c['description'] ?? ''), (string) ($c['status'] ?? 'publish'),
                        ApiMap::couponMeta($c), ApiMap::date($c['date_created_gmt'] ?? null), ApiMap::date($c['date_modified_gmt'] ?? null),
                        $productMap, $categoryMap, $this->now(), fn ($w) => $this->issue('warning', 'coupons', $c['id'] ?? null, $w));
                } catch (Throwable $e) {
                    $this->issue('error', 'coupons', $c['id'] ?? null, $e->getMessage());
                }
            }
            $rows = array_values(array_filter($rows, fn ($r) => $r['code'] !== ''));
            $this->saveRows('coupons', 'coupons', $this->withoutExisting('coupons', 'coupons', $rows, 'code'), 'code', ['created_at']);
        });
    }

    protected function importOrders(): void
    {
        if ($this->store()) {
            $this->entity('orders', 'skipped');

            return;
        }
        $query = $this->since(['orderby' => 'id', 'order' => 'asc', 'status' => 'any']);
        if ($after = $this->option('orders_after')) {
            $query['after'] = gmdate('Y-m-d\TH:i:s', strtotime((string) $after.' 00:00:00 UTC'));
        }
        $guests = in_array('customers', $this->run->entities(), true);
        $this->paged('orders', 'orders', $query, 'wc/v3', fn (array $items) => $this->orderPage($items, $guests));
    }

    protected function orderPage(array $items, bool $guests): void
    {
        $entity = 'orders';
        $orders = [];
        foreach ($items as $o) {
            try {
                $orders[(int) $o['id']] = [ApiMap::order($o), $o];
            } catch (Throwable $e) {
                $this->issue('error', $entity, $o['id'] ?? null, $e->getMessage());
            }
        }
        if ($this->option('existing', 'update') === 'skip' && $orders) {
            [$existing] = $this->u->plan('orders', array_map(fn ($id) => ['wp_id' => $id], array_keys($orders)));
            foreach (array_keys($orders) as $id) {
                if (isset($existing[$id])) {
                    unset($orders[$id]);
                    $this->bump($entity, 'skipped');
                }
            }
        }

        // read refunds (orders that have some) and notes from the shop before the write transaction
        $refunds = [];
        $notes = [];
        foreach ($orders as $id => [$order, $json]) {
            if (! empty($json['refunds'])) {
                try {
                    foreach ($this->client->pages('orders/'.$id.'/refunds', []) as [$page, $list]) {
                        foreach ($list as $r) {
                            $refunds[] = ApiMap::refund($r, $id, (array) ($json['line_items'] ?? []));
                        }
                    }
                } catch (WooApiException $e) {
                    $this->issue('error', $entity, $id, 'refunds not read: '.$e->getMessage());
                }
            }
            if ($this->option('notes', true)) {
                try {
                    $notes[$id] = array_reverse($this->client->get('orders/'.$id.'/notes', ['type' => 'any'])->items()); // the API lists newest first
                } catch (WooApiException $e) {
                    $this->issue('warning', $entity, $id, 'notes not read: '.$e->getMessage());
                }
            }
        }
        $this->bump($entity, 'refunds', count($refunds));
        $this->bump($entity, 'notes', array_sum(array_map('count', $notes)));
        if ($this->dryRun) {
            $lookups = OrderRows::lookups($this->u);
            $rows = [];
            foreach ($orders as $id => [$order]) {
                $result = $lookups->map($order, $this->now());
                is_string($result) ? $this->issue('warning', $entity, $id, $result) : $rows[] = $result['row'];
            }
            $this->upsert($entity, 'orders', $rows);

            return;
        }

        $this->transaction(function () use ($orders, $refunds, $notes, $guests, $entity) {
            // customers without an account: built from their orders (oldest first, so the latest order wins)
            if ($guests) {
                $customers = [];
                foreach ($orders as [$order]) {
                    CustomerRows::addOrder($customers, $order->dateCreatedGmt, $order->billing, $order->shipping);
                }
                $known = array_change_key_case(DB::table('users')->whereIn('email', array_keys($customers) ?: [''])->pluck('id', 'email')->all());
                $new = array_diff_key($customers, $known);
                if ($new) {
                    $map = $this->u->save('users', CustomerRows::guestRows($new, [], $this->now()), 'email', ['role', 'password', 'is_active', 'created_at']);
                    $this->bump('customers', 'created', $this->u->created);
                    foreach ($new as $email => $c) {
                        if (isset($map[$email])) {
                            $this->addressWriter()->ensureAddresses((int) $map[$email], ['billing' => $c['billing'], 'shipping' => $c['shipping'] ?? $c['billing']], $c['last_order'], $this->now());
                        }
                    }
                }
            }

            $lookups = OrderRows::lookups($this->u, (array) config('commerce-import.orders.meta_keys', []), (string) config('commerce.currency.code', 'GBP'));
            $mapped = [];
            foreach ($orders as $id => [$order]) {
                $result = $lookups->map($order, $this->now());
                if (is_string($result)) {
                    $this->issue('warning', $entity, $id, $result);
                    $this->bump($entity, 'skipped');

                    continue;
                }
                $mapped[$id] = $result;
            }

            $writer = new OrderWriter($this->u, 'wordpress');
            $written = ['orders' => [], 'items' => []];
            try {
                $written = DB::transaction(fn () => $writer->write(array_values($mapped), $this->now(), (bool) $this->option('notes', true)));
                $this->bump($entity, 'created', $this->u->created);
                $this->bump($entity, 'updated', $this->u->updated);
            } catch (\Illuminate\Database\QueryException) {
                // one order breaks a unique key (number / order key): save them one by one and report that one
                foreach ($mapped as $id => $m) {
                    try {
                        $one = DB::transaction(fn () => $writer->write([$m], $this->now(), (bool) $this->option('notes', true)));
                        $this->bump($entity, 'created', $this->u->created);
                        $this->bump($entity, 'updated', $this->u->updated);
                        $written['orders'] += $one['orders'];
                        $written['items'] += $one['items'];
                    } catch (\Illuminate\Database\QueryException $e) {
                        $this->issue('error', $entity, $id, 'not saved: '.mb_substr($e->getPrevious()?->getMessage() ?? $e->getMessage(), 0, 300));
                    }
                }
            }
            $orderIds = $written['orders'];
            $writer->refunds($refunds, $orderIds, $written['items'], $lookups->staffByWpId, $this->now());

            $staff = DB::table('users')->whereIn('role', ['admin', 'manager'])->get(['id', 'name']);
            $rows = [];
            foreach ($notes as $id => $list) {
                if (! isset($orderIds[$id])) {
                    continue;
                }
                foreach ($list as $n) {
                    $author = (string) ($n['author'] ?? '');
                    $system = in_array(strtolower($author), ['woocommerce', 'system', ''], true);
                    $userId = $system ? null : $staff->first(fn ($s) => strcasecmp((string) $s->name, $author) === 0)?->id;
                    $rows[] = OrderWriter::noteRow((int) $orderIds[$id], $userId, (string) ($n['note'] ?? ''), ! empty($n['customer_note']), $system,
                        ApiMap::date($n['date_created_gmt'] ?? null), $this->now());
                }
            }
            $this->u->insert('order_notes', $rows);
        });
    }

    protected function importReviews(): void
    {
        if ($this->store()) {
            $this->entity('reviews', 'skipped');

            return;
        }
        $this->paged('reviews', 'products/reviews', ['status' => 'all', 'orderby' => 'id', 'order' => 'asc'], 'wc/v3', function (array $items) {
            $productMap = $this->u->map('products');
            $users = DB::table('users')->pluck('id', 'email')->all();
            $rows = [];
            foreach ($items as $r) {
                $productId = $productMap[(int) ($r['product_id'] ?? 0)] ?? null;
                $rating = (int) ($r['rating'] ?? 0);
                if (! $productId || $rating < 1) {
                    $this->bump('reviews', 'skipped');

                    continue;
                }
                $status = (string) ($r['status'] ?? 'approved');
                if (in_array($status, ['spam', 'trash'], true)) {
                    $this->bump('reviews', 'skipped');

                    continue;
                }
                $email = strtolower(trim((string) ($r['reviewer_email'] ?? ''))) ?: null;
                $rows[] = CatalogRows::review($productId, $email ? ($users[$email] ?? null) : null, (string) ($r['reviewer'] ?? ''), $email, $rating,
                    trim(strip_tags((string) ($r['review'] ?? ''))), $status === 'approved', ! empty($r['verified']), ApiMap::date($r['date_created_gmt'] ?? null), $this->now());
            }
            if ($this->dryRun) {
                $this->bump('reviews', 'created', count($rows));

                return;
            }
            $before = DB::table('product_reviews')->count();
            $this->transaction(fn () => CatalogRows::saveReviews($rows));
            $created = DB::table('product_reviews')->count() - $before;
            $this->bump('reviews', 'created', $created);
            $this->bump('reviews', 'updated', count($rows) - $created);
        });
    }
}
