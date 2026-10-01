<?php

namespace Pine\Commerce\Services\Admin;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Format UTC timestamps in the shop's local time (UK) for custom admin views and printouts. */
class LocalTime
{
    /** Dates are stored in UTC and shown to staff in UK time. */
    public const TIMEZONE = 'Europe/London';

    public static function format(?DateTimeInterface $date, string $format = 'd M Y, H:i'): string
    {
        return $date ? Carbon::instance($date)->copy()->setTimezone(LocalTime::TIMEZONE)->format($format) : '';
    }

    /** Local-time boundaries as UTC Carbon instances, for querying. */
    public static function startOfDay(?Carbon $date = null): Carbon
    {
        return ($date ?? now())->copy()->setTimezone(LocalTime::TIMEZONE)->startOfDay()->utc();
    }

    public static function now(): Carbon
    {
        return now()->setTimezone(LocalTime::TIMEZONE);
    }

    /**
     * Parse a UK-time value from a form (<input type="datetime-local"> "2026-09-23T14:30" or "2026-09-23")
     * into a UTC Carbon for storage. Returns null for empty/invalid input.
     */
    public static function fromInput(?string $value, bool $endOfDay = false): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            $local = Carbon::parse($value, LocalTime::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $local = $local->endOfDay();
        }

        return $local->utc();
    }

    /**
     * fromInput() for editing an existing date: the form shows minutes only, so when the submitted value is still the
     * stored date (to the minute) the stored value is kept – saving a form never silently drops the seconds.
     */
    public static function fromInputKeeping(?string $value, ?DateTimeInterface $current): ?Carbon
    {
        if ($current && trim((string) $value) === static::toInput($current)) {
            return Carbon::instance($current)->copy()->utc();
        }

        return static::fromInput($value);
    }

    /** Value for an <input type="datetime-local"> (or "date") in UK time. */
    public static function toInput(?DateTimeInterface $date, string $type = 'datetime'): string
    {
        return static::format($date, $type === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i');
    }
}
