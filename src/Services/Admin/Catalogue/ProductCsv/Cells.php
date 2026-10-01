<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pine\Commerce\Services\Admin\LocalTime;

/**
 * Reading and writing one CSV cell of the product file: lists ("a | b", WooCommerce "a, b"), "Name: value" pairs,
 * yes/no, money, whole numbers, dates (UK time) and the status / stock words of both formats.
 * Parsers throw InvalidArgumentException with a message for the row report.
 */
class Cells
{
    /** Backslash-escape the list separators inside one value ("\\", "|" and, for attribute values, ","). */
    public static function escape(string $value, bool $commas = false): string
    {
        return str_replace($commas ? ['\\', '|', ','] : ['\\', '|'], $commas ? ['\\\\', '\\|', '\\,'] : ['\\\\', '\\|'], trim($value));
    }

    public static function unescape(string $value): string
    {
        return trim((string) preg_replace('/\\\\(.)/s', '$1', $value));
    }

    /** Join list items with " | " (empty items dropped). */
    public static function join(iterable $items): string
    {
        $out = [];
        foreach ($items as $item) {
            if (trim((string) $item) !== '') {
                $out[] = static::escape((string) $item);
            }
        }

        return implode(' | ', $out);
    }

    /**
     * Split a list cell on unescaped "|" – or, when there is none and $commas (WooCommerce files), on unescaped ",".
     *
     * @return list<string>
     */
    public static function split(string $cell, bool $commas = false): array
    {
        $separator = $commas && ! static::tokens($cell, '|', true) ? ',' : '|';

        return array_values(array_filter(array_map([static::class, 'unescape'], static::tokens($cell, $separator)), fn ($item) => $item !== ''));
    }

    /**
     * Raw (still escaped) pieces of $cell between unescaped $separator characters.
     * $probe = only report whether the separator occurs at all (returns [true] or []).
     */
    public static function tokens(string $cell, string $separator, bool $probe = false): array
    {
        $pieces = [];
        $current = '';
        $length = strlen($cell);
        for ($i = 0; $i < $length; $i++) {
            $char = $cell[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$cell[++$i];

                continue;
            }
            if ($char === $separator) {
                if ($probe) {
                    return [true];
                }
                $pieces[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        if ($probe) {
            return [];
        }
        $pieces[] = $current;

        return array_values(array_filter(array_map('trim', $pieces), fn ($piece) => $piece !== ''));
    }

    /**
     * "Memory: 8GB, 16GB | Colour: Silver" → [['Memory', ['8GB', '16GB']], ['Colour', ['Silver']]].
     * $multi = false keeps each value whole ("Label: value, with commas").
     *
     * @return list<array{0:string, 1:list<string>}>
     */
    public static function pairs(string $cell, bool $multi = true): array
    {
        $pairs = [];
        foreach (static::tokens($cell, '|') as $item) {
            $pos = strpos($item, ':');
            if ($pos === false) {
                throw new InvalidArgumentException('“'.static::short(static::unescape($item)).'” needs a name and a value, like “Colour: Red”');
            }
            $name = static::unescape(substr($item, 0, $pos));
            $raw = substr($item, $pos + 1);
            if ($name === '') {
                throw new InvalidArgumentException('“'.static::short(static::unescape($item)).'” has no name before the colon');
            }
            $values = $multi
                ? array_values(array_filter(array_map([static::class, 'unescape'], static::tokens($raw, ',')), fn ($v) => $v !== ''))
                : (static::unescape($raw) === '' ? [] : [static::unescape($raw)]);
            $pairs[] = [$name, $values];
        }

        return $pairs;
    }

    /** [[name, [values]], …] → "Memory: 8GB, 16GB | Colour: Silver" ($multi = false: one value, commas kept). */
    public static function joinPairs(iterable $pairs, bool $multi = true): string
    {
        $out = [];
        foreach ($pairs as [$name, $values]) {
            $values = array_filter(array_map(fn ($v) => static::escape((string) $v, $multi), (array) $values), fn ($v) => $v !== '');
            if (trim((string) $name) !== '' && $values) {
                $out[] = static::escape(str_replace(':', '', (string) $name)).': '.implode(', ', $values);
            }
        }

        return implode(' | ', $out);
    }

    public static function bool(string $value): bool
    {
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['1', 'yes', 'y', 'true', 'on', 'visible'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'no', 'n', 'false', 'off', 'hidden'], true)) {
            return false;
        }
        throw new InvalidArgumentException('“'.static::short($value).'” should be yes or no');
    }

    public static function money(string $value, string $label = 'Price'): float
    {
        $symbol = (string) config('commerce.currency.symbol', '£');
        $clean = str_replace([$symbol, '£', '$', '€', ',', ' ', "\u{00A0}"], '', trim($value));
        if (! is_numeric($clean) || (float) $clean < 0 || (float) $clean >= 100000000) {
            throw new InvalidArgumentException($label.' “'.static::short($value).'” isn’t a valid amount');
        }

        return round((float) $clean, 2);
    }

    public static function decimal(string $value, string $label, int $places = 3): float
    {
        $clean = str_replace([',', ' '], ['.', ''], trim($value));
        if (! is_numeric($clean) || (float) $clean < 0 || (float) $clean >= 100000) {
            throw new InvalidArgumentException($label.' “'.static::short($value).'” isn’t a valid number');
        }

        return round((float) $clean, $places);
    }

    public static function int(string $value, string $label, bool $negative = true): int
    {
        $clean = trim($value);
        if (preg_match('/^-?\d+(\.0+)?$/', $clean) !== 1 || abs((int) $clean) >= 10000000 || (! $negative && (int) $clean < 0)) {
            throw new InvalidArgumentException($label.' “'.static::short($value).'” isn’t a whole number');
        }

        return (int) $clean;
    }

    /** A date/time in the shop's local time (UK), stored as UTC. Accepts "2026-10-01", "2026-10-01 09:00", "01/10/2026". */
    public static function date(string $value, string $label, bool $endOfDay = false): Carbon
    {
        $value = trim($value);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(.*)$#', $value, $m)) {
            $value = sprintf('%04d-%02d-%02d%s', $m[3], $m[2], $m[1], $m[4]); // UK day/month/year
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{1,2}:\d{2}(:\d{2})?)?$/', $value) ? LocalTime::fromInput($value, $endOfDay) : null;
        if (! $date) {
            throw new InvalidArgumentException($label.' “'.static::short($value).'” isn’t a date (use 2026-10-31 or 2026-10-31 18:00)');
        }

        return $date;
    }

    /** published | draft | private (WooCommerce "Published": 1 / 0 = private / -1 = draft). */
    public static function status(string $value): string
    {
        return match (mb_strtolower(trim($value))) {
            '1', 'publish', 'published', 'active', 'live', 'yes' => 'published',
            '-1', 'draft', 'pending', 'no' => 'draft',
            '0', 'private', 'hidden' => 'private',
            default => throw new InvalidArgumentException('Status “'.static::short($value).'” should be published, draft or private'),
        };
    }

    /** instock | outofstock | onbackorder (WooCommerce "In stock?": 1 / 0 / backorder). */
    public static function stockStatus(string $value): string
    {
        return match (str_replace([' ', '_', '-'], '', mb_strtolower(trim($value)))) {
            '1', 'instock', 'yes', 'available' => 'instock',
            '0', 'outofstock', 'no', 'soldout' => 'outofstock',
            'backorder', 'onbackorder', 'backorders' => 'onbackorder',
            default => throw new InvalidArgumentException('Stock status “'.static::short($value).'” should be instock, outofstock or onbackorder'),
        };
    }

    /** no | notify | yes (WooCommerce "Backorders allowed?": 0 / notify / 1). */
    public static function backorders(string $value): string
    {
        return match (mb_strtolower(trim($value))) {
            '0', 'no', 'false', 'do not allow' => 'no',
            'notify', 'allow, but notify customer' => 'notify',
            '1', 'yes', 'true', 'allow' => 'yes',
            default => throw new InvalidArgumentException('Backorders “'.static::short($value).'” should be no, notify or yes'),
        };
    }

    /** simple | variable | variation, or throws for types this shop doesn't sell (grouped, external). */
    public static function type(string $value): string
    {
        $v = mb_strtolower(trim($value));
        foreach (['variation', 'variable', 'simple'] as $type) {
            if ($v === $type || preg_match('/(^|[\s,])'.$type.'($|[\s,])/', $v)) {
                return $type;
            }
        }
        if (in_array($v, ['product with variants', 'with variants'], true)) {
            return 'variable';
        }
        throw new InvalidArgumentException('Type “'.static::short($value).'” isn’t supported (use simple, variable or variation)');
    }

    public static function short(string $value, int $limit = 40): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }
}
