<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Mapping\CustomerRows;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Support\WpPassword;

/**
 * WordPress users (administrators -> role admin, shop managers/editors -> manager, everyone else incl. the
 * `customer` role -> customer; the WP password hash is kept so Pine\Commerce\Support\WpPassword can verify it on
 * first login) and every WooCommerce customer without an account (wc_customer_lookup guests + order billing emails,
 * read through the order storage – HPOS or posts) -> role customer, password null, with default billing/shipping
 * addresses taken from their most recent order. Deduplicated on lower-cased email.
 */
class UsersStep extends AbstractStep
{
    public function section(): string
    {
        return 'users';
    }

    protected function clear(): void
    {
        $emails = array_keys($this->customerData()[0]);
        foreach (array_chunk($emails, 500) as $chunk) {
            DB::table('users')->whereIn('email', $chunk)->where('role', 'customer')->whereNull('wp_id')->delete();
        }
        $this->ctx->owned('users')->delete();
    }

    protected function import(): void
    {
        $adminIds = $this->importWordPressUsers();
        [$customers, $orderEmails] = $this->customerData();
        $this->importCustomers($customers, $adminIds);

        $lookupRows = $this->wp->hasTable('wc_customer_lookup') ? $this->wp->table('wc_customer_lookup')->count() : 0;
        $this->ctx->count('Customers (unique emails)', count($customers).' ('.$lookupRows.' lookup rows, '.$orderEmails.' order emails)',
            DB::table('users')->where('role', 'customer')->count());
        $this->ctx->count('Addresses', '—', DB::table('addresses')->count());
    }

    /** @return array<string,int> lower-case email => Laravel user id for the WP accounts */
    private function importWordPressUsers(): array
    {
        $users = $this->wp->table('users')->orderBy('ID')->get();
        $meta = $this->wp->userMeta($users->pluck('ID')->all());
        $prefix = $this->wp->db()->getTablePrefix();

        $existingByEmail = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn ($u) => strtolower(trim($u->user_email)), $users->all()))), 1000) as $chunk) {
            foreach (DB::table('users')->whereIn('email', $chunk)->get() as $row) {
                $existingByEmail[strtolower($row->email)] ??= $row;
            }
        }

        $out = [];
        foreach ($users as $u) {
            $m = $meta[$u->ID] ?? [];
            $caps = WordPressSource::unserialize($m[$prefix.'capabilities'] ?? '');
            $caps = is_array($caps) ? array_keys(array_filter($caps)) : [];
            $role = in_array('administrator', $caps, true) ? 'admin'
                : (in_array('shop_manager', $caps, true) || in_array('editor', $caps, true) ? 'manager' : 'customer');
            $email = strtolower(trim($u->user_email));
            $first = trim($m['first_name'] ?? '') ?: null;
            $last = trim($m['last_name'] ?? '') ?: null;

            $existing = $existingByEmail[$email] ?? null;
            $row = [
                'name' => trim(($first ?? '').' '.($last ?? '')) ?: ($u->display_name ?: $u->user_login),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => trim($m['billing_phone'] ?? '') ?: null,
                'role' => $role,
                'is_active' => true,
                'wp_id' => $u->ID,
                'email_verified_at' => WordPressSource::gmt($u->user_registered),
                'created_at' => WordPressSource::gmt($u->user_registered) ?? $this->now(),
                'updated_at' => $this->now(),
            ];
            if (! $existing) {
                $row['password'] = $u->user_pass; // WordPress hash – verified by Pine\Commerce\Support\WpPassword, then rehashed
                $id = DB::table('users')->insertGetId($row);
                $existingByEmail[$email] = (object) (['id' => $id] + $row);
            } else {
                // Never overwrite a password that has already been rehashed by Laravel after login.
                if ($existing->password === null || WpPassword::isWordPressHash($existing->password)) {
                    $row['password'] = $u->user_pass;
                }
                DB::table('users')->where('id', $existing->id)->update($row);
                $id = $existing->id;
            }
            $out[$email] = $id;
            $this->addresses()->ensureAddresses($id, [
                'billing' => $this->addressFrom($m, 'billing_'),
                'shipping' => $this->addressFrom($m, 'shipping_'),
            ], WordPressSource::gmt($u->user_registered), $this->now());
        }

        $this->ctx->count('WP users (staff)', $users->count(), $this->ctx->owned('users')->count(), 'administrators → role admin, WP hash kept');

        return $out;
    }

    /**
     * @return array{0: array<string,array>, 1:int} [email => customer data], number of distinct order emails
     */
    private function customerData(): array
    {
        $customers = [];

        $lookup = $this->wp->hasTable('wc_customer_lookup') ? $this->wp->table('wc_customer_lookup')->orderBy('customer_id')->get() : collect();
        foreach ($lookup as $c) {
            $email = strtolower(trim((string) $c->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $customers[$email] ??= CustomerRows::blank($email);
            $customers[$email]['first_name'] ??= trim((string) $c->first_name) ?: null;
            $customers[$email]['last_name'] ??= trim((string) $c->last_name) ?: null;
            $customers[$email]['wp_user'] ??= $c->user_id ?: null;
            $registered = $c->date_registered ?: $c->date_last_active;
            if ($registered && (! $customers[$email]['created_at'] || $registered < $customers[$email]['created_at'])) {
                $customers[$email]['created_at'] = $registered;
            }
        }

        // Orders, oldest -> newest (date, then id) so the latest order wins for names/addresses. Only the fields
        // needed here are kept per order, so memory stays small on large stores.
        $orders = [];
        if ($this->ctx->adapters->isActive('woocommerce')) {
            foreach ($this->ctx->orderSource()->orders(1000) as $chunk) {
                foreach ($chunk as $o) {
                    $orders[] = [$o->dateCreatedGmt, $o->id, $o->billing, $o->shipping];
                }
            }
        }
        usort($orders, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $orderEmails = [];
        foreach ($orders as [$date, $id, $billingRaw, $shippingRaw]) {
            if ($email = CustomerRows::addOrder($customers, $date, $billingRaw, $shippingRaw)) {
                $orderEmails[$email] = true;
            }
        }

        return [$customers, count($orderEmails)];
    }

    private function importCustomers(array $customers, array $staff): void
    {
        // Existing accounts keep their role / password / active flag.
        $map = $this->ctx->save('users', CustomerRows::guestRows($customers, $staff, $this->now()), 'email', ['role', 'password', 'is_active', 'created_at']);

        foreach ($customers as $email => $c) {
            if (isset($map[$email])) {
                $this->addresses()->ensureAddresses($map[$email], ['billing' => $c['billing'], 'shipping' => $c['shipping'] ?? $c['billing']], $c['last_order'], $this->now());
            }
        }
    }

    private ?CustomerRows $addressWriter = null;

    private function addresses(): CustomerRows
    {
        return $this->addressWriter ??= new CustomerRows($this->defaultCountry());
    }

    private function defaultCountry(): string
    {
        return strtoupper(explode(':', (string) ($this->ctx->site->woo['default_country'] ?? ''))[0]) ?: 'GB';
    }

    private function addressFrom(array $meta, string $prefix): ?array
    {
        $raw = [];
        foreach (CustomerRows::ADDRESS_FIELDS as $f) {
            $raw[$f] = $meta[$prefix.$f] ?? '';
        }

        return CustomerRows::address($raw);
    }
}
