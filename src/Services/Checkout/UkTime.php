<?php

namespace Pine\Commerce\Services\Checkout;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Customer-facing dates: stored in UTC, shown in UK time (like the legacy site's Europe/London setting). */
class UkTime
{
    public const TIMEZONE = 'Europe/London';

    public static function format(?DateTimeInterface $date, string $format = 'j F Y'): string
    {
        return $date ? Carbon::instance($date)->copy()->setTimezone(self::TIMEZONE)->format($format) : '';
    }
}
