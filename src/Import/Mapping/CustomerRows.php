<?php

namespace Pine\Commerce\Import\Mapping;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Customers as both importers create them: accounts and guests deduplicated on the lower-cased e-mail, guests built
 * from their orders (oldest to newest, so the latest order wins for names, phone and addresses), and default
 * billing/shipping addresses created once – addresses a customer edited since are never overwritten.
 */
final class CustomerRows
{
    public const ADDRESS_FIELDS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email'];

    /** @var array<int,true>|null users that already have addresses */
    private ?array $withAddresses = null;

    public function __construct(private readonly string $defaultCountry = 'GB') {}

    /** WooCommerce billing/shipping array -> address (decoded) or null when it has no street and no postcode. */
    public static function address(?array $raw): ?array
    {
        if (! $raw) {
            return null;
        }
        $a = [];
        foreach (self::ADDRESS_FIELDS as $f) {
            $a[$f] = trim(Formatter::decode((string) ($raw[$f] ?? ''))) ?: null;
        }
        if (! $a['address_1'] && ! $a['postcode']) {
            return null;
        }

        return $a;
    }

    /** An empty aggregate entry. */
    public static function blank(string $email): array
    {
        return ['email' => $email, 'first_name' => null, 'last_name' => null, 'phone' => null, 'created_at' => null,
            'last_order' => null, 'billing' => null, 'shipping' => null, 'wp_user' => null];
    }

    /**
     * Fold one order into the customer aggregate (call oldest -> newest). Returns the e-mail, or null when the order has
     * no valid billing e-mail.
     *
     * @param  array<string,array>  $customers  email => aggregate (see blank())
     */
    public static function addOrder(array &$customers, ?string $dateGmt, array $billing, array $shipping): ?string
    {
        $email = strtolower(trim((string) ($billing['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $customers[$email] ??= self::blank($email);
        $c = &$customers[$email];
        $c['first_name'] = trim((string) ($billing['first_name'] ?? '')) ?: $c['first_name'];
        $c['last_name'] = trim((string) ($billing['last_name'] ?? '')) ?: $c['last_name'];
        $c['phone'] = trim((string) ($billing['phone'] ?? '')) ?: $c['phone'];
        if (! $c['created_at'] || ($dateGmt && $dateGmt < $c['created_at'])) {
            $c['created_at'] = $dateGmt;
        }
        $c['last_order'] = $dateGmt;
        if ($b = self::address($billing)) {
            $c['billing'] = $b;
        }
        if ($s = self::address($shipping)) {
            $c['shipping'] = $s;
        }

        return $email;
    }

    /**
     * `users` rows for customers without an account (password null – they set one with "forgot password").
     *
     * @param  array<string,array>  $customers
     * @param  array<string,mixed>  $skip  e-mails that already have an account from this import
     */
    public static function guestRows(array $customers, array $skip, string $now): array
    {
        $rows = [];
        foreach ($customers as $email => $c) {
            if (isset($skip[$email])) {
                continue;
            }
            $first = $c['first_name'] ? Formatter::decode($c['first_name']) : null;
            $last = $c['last_name'] ? Formatter::decode($c['last_name']) : null;
            $rows[] = [
                'email' => $email,
                'name' => trim(($first ?? '').' '.($last ?? '')) ?: $email,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $c['phone'],
                'role' => 'customer',
                'is_active' => true,
                'password' => null,
                'created_at' => $c['created_at'] ?? $now,
                'updated_at' => $c['last_order'] ?? $c['created_at'] ?? $now,
            ];
        }

        return $rows;
    }

    /**
     * Create the default addresses of a user once; never overwrite addresses the customer may have edited since.
     *
     * @param  array{billing?:?array, shipping?:?array}  $addresses  address() results
     */
    public function ensureAddresses(int $userId, array $addresses, ?string $date, string $now): bool
    {
        $this->withAddresses ??= array_flip(DB::table('addresses')->distinct()->pluck('user_id')->all());
        if (isset($this->withAddresses[$userId])) {
            return false;
        }
        $rows = [];
        foreach ($addresses as $type => $a) {
            if (! $a) {
                continue;
            }
            $rows[] = [
                'user_id' => $userId,
                'type' => $type,
                'first_name' => $a['first_name'],
                'last_name' => $a['last_name'],
                'company' => $a['company'],
                'address_1' => $a['address_1'],
                'address_2' => $a['address_2'],
                'city' => $a['city'],
                'county' => $a['state'],
                'postcode' => $a['postcode'],
                'country' => strtoupper(substr($a['country'] ?: $this->defaultCountry, 0, 2)),
                'phone' => $a['phone'],
                'email' => $a['email'] ? strtolower($a['email']) : null,
                'is_default' => true,
                'created_at' => $date ?? $now,
                'updated_at' => $date ?? $now,
            ];
        }
        if (! $rows) {
            return false;
        }
        DB::table('addresses')->insert($rows);
        $this->withAddresses[$userId] = true;

        return true;
    }
}
