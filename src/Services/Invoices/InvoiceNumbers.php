<?php

namespace Pine\Commerce\Services\Invoices;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\LocalTime;

/**
 * Sequential invoice numbers, separate from order numbers.
 *
 * The counter is the "invoice" row of the `sequences` table (last value issued). assign() takes the next value inside
 * one transaction that locks the order row and then the counter row (SELECT … FOR UPDATE, always in that order), so
 * two requests paying/completing orders at the same moment never get the same number, the same order never gets two,
 * and a number is never handed out twice – even when the prefix changes or an admin moves "next number" (it can only
 * move forward). orders.invoice_number is unique as a last line of defence.
 *
 * Format: {prefix}{number padded to "padding" digits}{suffix}; {Y} {y} {m} in the prefix/suffix = the invoice date.
 */
class InvoiceNumbers
{
    public const SEQUENCE = 'invoice';

    /** Issue the order's invoice number (idempotent: returns the existing one). Null when numbering is off. */
    public function assign(Order $order): ?string
    {
        if (! Invoices::numbering() || ! $order->exists) {
            return $order->invoice_number ?: null;
        }

        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        [$number, $date] = DB::transaction(function () use ($order, $sqlite) {
            if ($sqlite) {
                // SQLite has no row locks: take the database write lock first, so a concurrent writer waits
                // (busy_timeout) instead of failing on a read→write lock upgrade ("database is locked")
                DB::table('sequences')->where('name', self::SEQUENCE)->update(['updated_at' => now()]);
            }
            $row = Order::withTrashed()->toBase()->where('id', $order->id)->lockForUpdate()->first(['id', 'invoice_number', 'invoice_date']);
            if (! $row) {
                return [null, null];
            }
            if ($row->invoice_number) {
                return [$row->invoice_number, $row->invoice_date];
            }

            $last = $this->lockCounter();
            $date = now();
            $next = max($last + 1, $this->floor());
            // skip any value whose formatted number already exists (imported invoices, a changed prefix/padding)
            for ($guard = 0; $guard < 1000; $guard++) {
                $number = $this->format($next, $date);
                if (! Order::withTrashed()->toBase()->where('invoice_number', $number)->exists()) {
                    break;
                }
                $next++;
            }
            DB::table('sequences')->where('name', self::SEQUENCE)->update(['value' => $next, 'updated_at' => now()]);
            Order::withTrashed()->toBase()->where('id', $order->id)->update(['invoice_number' => $number, 'invoice_date' => $date]);

            return [$number, $date];
        }, 5);

        if ($number !== null) {
            // keep the in-memory model in step without saving it (and without touching updated_at)
            $order->setRawAttributes(array_merge($order->getAttributes(), ['invoice_number' => $number, 'invoice_date' => $date]), true);
        }

        return $number;
    }

    /** Lock the counter row (created on first use) and return the last value issued. Lock order: order row, then counter. */
    protected function lockCounter(): int
    {
        $value = DB::table('sequences')->where('name', self::SEQUENCE)->lockForUpdate()->value('value');
        if ($value === null) {
            DB::table('sequences')->insertOrIgnore(['name' => self::SEQUENCE, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $value = DB::table('sequences')->where('name', self::SEQUENCE)->lockForUpdate()->value('value');
        }

        return (int) $value;
    }

    /** The number the next invoice will get (for Settings › Invoices). */
    public function peek(): int
    {
        try {
            $last = (int) DB::table('sequences')->where('name', self::SEQUENCE)->value('value');
        } catch (\Throwable) {
            $last = 0;
        }

        return max($last + 1, $this->floor());
    }

    /** Last number issued (0 = none yet). */
    public function last(): int
    {
        try {
            return (int) DB::table('sequences')->where('name', self::SEQUENCE)->value('value');
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Lowest value the next number may take: "next number" from Settings, else config commerce.invoices.start. */
    public function floor(): int
    {
        $setting = (int) setting('invoices.next_number', 0);

        return max(1, $setting > 0 ? $setting : (int) config('commerce.invoices.start', 1));
    }

    public function format(int $value, ?Carbon $date = null): string
    {
        $local = ($date ?? now())->copy()->setTimezone(LocalTime::TIMEZONE);
        $tokens = ['{Y}' => $local->format('Y'), '{y}' => $local->format('y'), '{m}' => $local->format('m')];
        $padding = max(0, min(12, (int) Invoices::option('padding', 0)));
        $prefix = strtr((string) Invoices::option('prefix', ''), $tokens);
        $suffix = strtr((string) Invoices::option('suffix', ''), $tokens);

        return $prefix.($padding ? str_pad((string) $value, $padding, '0', STR_PAD_LEFT) : (string) $value).$suffix;
    }
}
