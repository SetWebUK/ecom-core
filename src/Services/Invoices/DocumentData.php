<?php

namespace Pine\Commerce\Services\Invoices;

use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\Countries;
use Pine\Commerce\Services\Admin\StoreSettings;

/**
 * Data shared by the printable HTML documents (admin.print) and the PDF templates (pdf.invoice / pdf.packing-slip):
 * the store's details from Settings, formatted addresses, the paid/due stamp and the tax breakdown of an order.
 */
class DocumentData
{
    /** Store details printed on invoices and packing slips (Settings › Store details + Settings › Invoices). */
    public static function store(): array
    {
        $address = (string) setting('store.address', StoreSettings::defaultFor('store.address', ''));
        $addressLines = str_contains($address, "\n") ? preg_split('/\R/', $address) : explode(',', $address);
        $logo = setting('store.logo') ?: config('commerce.documents.logo');

        return [
            'name' => setting('store.name', config('app.name')),
            'company' => setting('store.company_name') ?: setting('store.name', config('app.name')),
            'address' => implode("\n", array_values(array_filter(array_map('trim', $addressLines)))),
            'phone' => setting('store.phone', StoreSettings::defaultFor('store.phone')),
            'email' => setting('store.email', config('mail.from.address')),
            'company_number' => setting('store.company_number'),
            'vat_number' => setting('store.vat_number'),
            'registered_office' => setting('store.registered_office'),
            // store logo setting, else config commerce.documents.logo (public-disk path or URL), else no logo
            'logo' => $logo ? media_url($logo) : null,
            'logo_path' => $logo ?: null,
            'website' => preg_replace('#^https?://#', '', rtrim((string) config('app.url'), '/')),
            'invoice_footer' => setting('documents.invoice_footer'),
            'packing_footer' => setting('documents.packing_slip_footer'),
            'invoice_notes' => setting('invoices.notes'),
        ];
    }

    /** Address block of an order ("billing" / "shipping") as lines joined by "\n"; the country only when not the shop's. */
    public static function address(Order $order, string $type): string
    {
        $name = trim($order->{$type.'_first_name'}.' '.$order->{$type.'_last_name'});
        $country = $order->{$type.'_country'};
        $home = (string) config('commerce.store.country', 'GB');
        $lines = array_filter([$name, $order->{$type.'_company'}, $order->{$type.'_address_1'}, $order->{$type.'_address_2'},
            $order->{$type.'_city'}, $order->{$type.'_county'}, $order->{$type.'_postcode'},
            $country && $country !== $home ? Countries::name($country) : null], fn ($l) => trim((string) $l) !== '');

        return implode("\n", $lines);
    }

    /** [label, modifier] of the stamp on an invoice: Paid / Payment due / Refunded / Cancelled. */
    public static function stamp(Order $order): array
    {
        $refunded = (float) $order->refunded_total;
        $paid = in_array($order->status, ['processing', 'completed', 'refunded'], true) || ($order->paid_at && $order->status === 'on-hold');

        return match (true) {
            $order->status === 'refunded' || ($refunded > 0 && $refunded >= (float) $order->total) => ['Refunded', 'refunded'],
            $order->status === 'cancelled' => ['Cancelled', 'refunded'],
            $paid => ['Paid', 'paid'],
            default => ['Payment due', 'due'],
        };
    }

    /**
     * Tax breakdown: one row per rate, [label, rate (percent|null), net (float|null), tax (float)].
     *
     * Reads, in order: a "tax_lines" relation/attribute of the order (per-rate tax lines, when the tax module stores
     * them), order meta "tax_lines" ([{label, rate, net, tax}]), per-item tax grouped by the item's "tax_rate",
     * and finally a single line for orders.tax_total with the store's tax label/rate.
     *
     * @return list<array{label:string, rate:?float, net:?float, tax:float}>
     */
    public static function taxLines(Order $order): array
    {
        $label = (string) setting('tax.label', 'VAT');
        $normalise = function ($rows) use ($label): array {
            $out = [];
            foreach ($rows as $row) {
                $row = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
                // order_tax_lines rows (tax module): tax on items + tax on shipping for that rate
                $tax = array_key_exists('tax_total', $row) || array_key_exists('shipping_tax_total', $row)
                    ? (float) ($row['tax_total'] ?? 0) + (float) ($row['shipping_tax_total'] ?? 0)
                    : (float) ($row['tax'] ?? $row['amount'] ?? 0);
                $rate = $row['rate'] ?? $row['tax_rate'] ?? null;
                $out[] = [
                    'label' => (string) ($row['label'] ?? $row['name'] ?? $label),
                    'rate' => $rate === null || $rate === '' ? null : (float) $rate,
                    'net' => isset($row['net']) ? (float) $row['net'] : (isset($row['base']) ? (float) $row['base'] : null),
                    'tax' => $tax,
                ];
            }

            // a rate with no tax still belongs on a VAT invoice when goods were sold at it (0% zero-rated lines)
            return array_values(array_filter($out, fn ($r) => abs($r['tax']) >= 0.005 || ($r['net'] !== null && abs($r['net']) >= 0.005)));
        };

        $candidates = [];
        if (method_exists($order, 'taxLines')) {
            try {
                $rows = $order->taxLines()->get();
                $nets = $rows->isNotEmpty() ? static::netPerRate($order, $rows) : [];
                $candidates[] = $rows->map(fn ($row) => $row->toArray()
                    + ['net' => $nets[(string) $row->tax_rate_id] ?? null])->all();
            } catch (\Throwable) {
            }
        }
        $candidates[] = $order->getAttributes()['tax_lines'] ?? null;
        $candidates[] = ((array) $order->meta)['tax_lines'] ?? null;
        foreach ($candidates as $rows) {
            if (is_string($rows)) {
                $rows = json_decode($rows, true);
            }
            if ($rows && is_iterable($rows) && ($lines = $normalise($rows))) {
                return $lines;
            }
        }

        $total = (float) $order->tax_total;
        if (abs($total) < 0.005) {
            return [];
        }
        $rate = (float) setting('tax.rate', 0);

        return [['label' => $label, 'rate' => $rate > 0 ? $rate : null, 'net' => null, 'tax' => $total]];
    }

    /**
     * Net amount taxed at each rate (tax_rate_id => net), from the per-rate taxes the checkout stores on every order
     * item (order_items.taxes) plus the shipping when that rate taxed it. Empty when the items carry no per-rate
     * taxes (orders from before v1.1 or from an import) – the invoice then shows no net column value.
     *
     * @return array<string, float>
     */
    protected static function netPerRate(Order $order, $rows): array
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $nets = [];
        foreach ($items as $item) {
            $taxes = $item->taxes;
            if (is_string($taxes)) {
                $taxes = json_decode($taxes, true);
            }
            foreach (array_keys((array) $taxes) as $rateId) {
                $nets[(string) $rateId] = ($nets[(string) $rateId] ?? 0.0) + (float) $item->total;
            }
        }
        if (! $nets) {
            return [];
        }
        foreach ($rows as $row) {
            if (abs((float) $row->shipping_tax_total) >= 0.005) {
                $nets[(string) $row->tax_rate_id] = ($nets[(string) $row->tax_rate_id] ?? 0.0) + (float) $order->shipping_total;
            }
        }

        return array_map(fn ($net) => round($net, 2), $nets);
    }

    /** "VAT 20%" – the rate is added unless the label already shows it. */
    public static function taxLabel(array $line): string
    {
        $label = (string) $line['label'];
        if ($line['rate'] === null || str_contains($label, '%')) {
            return $label;
        }

        return trim($label.' '.rtrim(rtrim(number_format((float) $line['rate'], 4, '.', ''), '0'), '.').'%');
    }

    /**
     * Whether the order's prices included tax: the order's own flag when the tax module stored one, otherwise worked
     * out from the totals (total = subtotal − discount + shipping means the tax was already inside).
     */
    public static function taxIncluded(Order $order): bool
    {
        if ((float) $order->tax_total <= 0) {
            return false;
        }
        $flag = $order->getAttributes()['prices_include_tax'] ?? null;
        if ($flag !== null) {
            return (bool) $flag;
        }

        return abs(((float) $order->subtotal - (float) $order->discount_total + (float) $order->shipping_total) - (float) $order->total) < 0.015;
    }

    /**
     * The logo as a data: URI for the PDF renderer (which never fetches remote files): a public-disk path
     * ("uploads/…"), a path under public/ ("/brand/…") or a URL on this site. Null when it is not a local image.
     */
    public static function logoDataUri(?string $logo): ?string
    {
        $logo = trim((string) $logo);
        if ($logo === '') {
            return null;
        }
        $candidates = [];
        if (preg_match('#^(https?:)?//#i', $logo)) {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (! $host || strcasecmp((string) parse_url($logo, PHP_URL_HOST), $host) !== 0) {
                return null;
            }
            $path = ltrim((string) parse_url($logo, PHP_URL_PATH), '/');
            $candidates[] = public_path($path);
        } else {
            $path = ltrim($logo, '/');
            try {
                $candidates[] = Storage::disk('public')->path($path);
            } catch (\Throwable) {
                return null; // path traversal
            }
            $candidates[] = public_path($path);
        }

        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];
        $publicRoot = realpath(public_path()) ?: public_path();
        foreach ($candidates as $file) {
            $real = realpath($file);
            if (! $real || ! is_file($real) || filesize($real) > 5 * 1024 * 1024) {
                continue;
            }
            $disk = realpath(Storage::disk('public')->path('')) ?: '';
            if (! str_starts_with($real, $publicRoot.DIRECTORY_SEPARATOR) && ! ($disk !== '' && str_starts_with($real, $disk.DIRECTORY_SEPARATOR))) {
                continue; // never read files outside public/ or the public disk
            }
            $type = $types[strtolower(pathinfo($real, PATHINFO_EXTENSION))] ?? null;
            if (! $type || ($type === 'image/webp' && ! function_exists('imagecreatefromwebp'))) {
                continue;
            }

            return 'data:'.$type.';base64,'.base64_encode((string) file_get_contents($real));
        }

        return null;
    }
}
