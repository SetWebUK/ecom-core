<?php

namespace Pine\Commerce\View\Components\Admin;

use Pine\Commerce\Services\Admin\LocalTime;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Small helpers shared by the <x-admin.*> Blade components (not a component itself). */
class Ui
{
    /** "meta[title]" -> "meta.title", "ids[]" -> "ids" (the key used by old() and $errors). */
    public static function key(string $name): string
    {
        return trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    }

    /** A safe, stable DOM id for a field name. */
    public static function id(string $name, ?string $suffix = null): string
    {
        $id = 'f-'.trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-');

        return $suffix ? $id.'-'.$suffix : $id;
    }

    public static function date(DateTimeInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Shopify-style relative date in UK time: "Today at 10:32", "Yesterday at 09:15", "Mon at 14:02", "7 Mar at 06:51", "7 Mar 2024". */
    public static function smartDate(DateTimeInterface|string|null $value): string
    {
        $date = static::date($value);
        if (! $date) {
            return '';
        }
        $local = $date->copy()->setTimezone(LocalTime::TIMEZONE);
        $today = LocalTime::now()->startOfDay();
        $day = $local->copy()->startOfDay();
        $diffDays = (int) $day->diffInDays($today, false);

        return match (true) {
            $diffDays === 0 => 'Today at '.$local->format('H:i'),
            $diffDays === 1 => 'Yesterday at '.$local->format('H:i'),
            $diffDays > 1 && $diffDays < 7 => $local->format('D').' at '.$local->format('H:i'),
            $local->year === $today->year => $local->format('j M').' at '.$local->format('H:i'),
            default => $local->format('j M Y'),
        };
    }

    /**
     * Current query string as [name => value] pairs for hidden inputs (nested arrays flattened to name[]=…).
     *
     * @param  list<string>  $except
     * @return list<array{0:string,1:string}>
     */
    public static function queryPairs(array $except = []): array
    {
        $query = http_build_query(collect(request()->query())->except($except)->all());
        if ($query === '') {
            return [];
        }

        return array_map(function (string $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            return [urldecode($name), urldecode($value)];
        }, explode('&', $query));
    }

    public static function initials(?string $name): string
    {
        return collect(preg_split('/\s+/', trim((string) $name)) ?: [])
            ->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?';
    }
}
