<?php

namespace Pine\Commerce\Exports;

use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customers CSV (from Pine\Commerce\Services\Admin\CustomerQuery, so the order stats columns are present), default billing
 * address included. Streamed in chunks; dates in UK time.
 */
class CustomersCsvExport extends CsvExport
{
    public const HEADINGS = [
        'Customer ID', 'First name', 'Last name', 'Email', 'Phone', 'Company', 'Address 1', 'Address 2', 'City', 'County',
        'Postcode', 'Country', 'Orders', 'Total spent', 'Last order', 'Account', 'Marketing opt-in', 'Status', 'Customer since', 'Note',
    ];

    public static function download(Builder $query, ?string $filename = null): StreamedResponse
    {
        return static::stream($filename ?? 'customers-'.LocalTime::now()->format('Y-m-d-His').'.csv', self::HEADINGS, static::rows($query));
    }

    public static function rows(Builder $query): \Generator
    {
        foreach ($query->with(['addresses' => fn ($q) => $q->where('type', 'billing')->orderByDesc('is_default')])->lazy(200) as $user) {
            /** @var User $user */
            $address = $user->addresses->first();
            yield [
                $user->id,
                $user->first_name ?: $address?->first_name,
                $user->last_name ?: $address?->last_name,
                $user->email,
                $user->phone ?: $address?->phone,
                $address?->company,
                $address?->address_1,
                $address?->address_2,
                $address?->city,
                $address?->county,
                $address?->postcode,
                $address?->country,
                (int) ($user->orders_count ?? 0),
                number_format((float) ($user->total_spent ?? 0), 2, '.', ''),
                $user->last_order_at ? LocalTime::format(\Illuminate\Support\Carbon::parse($user->last_order_at, 'UTC'), 'Y-m-d H:i') : null,
                $user->password ? 'Registered' : 'Guest',
                $user->marketing_opt_in ? 'Yes' : 'No',
                $user->is_active ? 'Active' : 'Disabled',
                LocalTime::format($user->created_at, 'Y-m-d'),
                $user->admin_note,
            ];
        }
    }
}
