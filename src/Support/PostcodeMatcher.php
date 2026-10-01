<?php

namespace Pine\Commerce\Support;

/**
 * Postcode patterns for shipping zones and tax rates (case and spaces ignored):
 *
 *  - exact:       "SW1A 1AA"; for UK postcodes a bare outcode matches the whole outcode ("BT1" matches "BT1 1AA")
 *  - wildcard:    "BT*", "IV*", "9021*" (* = anything, ? = one character)
 *  - range:       "HS1-HS9" (same letters, district numbers 1–9: HS1 … HS9, not HS10), "10000...19999" or "10000-19999"
 *                 (numeric, WooCommerce style)
 *  - exclusion:   a leading "!" excludes ("!BT1*") – only meaningful next to positive patterns
 */
class PostcodeMatcher
{
    /** @param  list<string>  $patterns */
    public static function matchesAny(string $postcode, array $patterns, ?string $country = null): bool
    {
        $postcode = static::clean($postcode);
        if ($postcode === '') {
            return false;
        }
        $positive = false;
        $hit = false;
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '!')) {
                if (static::matches($postcode, substr($pattern, 1), $country)) {
                    return false;
                }

                continue;
            }
            $positive = true;
            $hit = $hit || static::matches($postcode, $pattern, $country);
        }

        return $positive ? $hit : true;
    }

    public static function matches(string $postcode, string $pattern, ?string $country = null): bool
    {
        $postcode = static::clean($postcode);
        $pattern = static::clean($pattern);
        if ($postcode === '' || $pattern === '') {
            return false;
        }
        $outcode = static::outcode($postcode, $country);

        // numeric range "10000...19999"
        if (str_contains($pattern, '...')) {
            [$from, $to] = array_map('trim', explode('...', $pattern, 2));

            return static::inRange($postcode, $outcode, $from, $to);
        }
        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            $regex = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/';

            return (bool) preg_match($regex, $postcode);
        }
        // "HS1-HS9" / "10000-19999"
        if (preg_match('/^([A-Z]*\d+[A-Z]?)-([A-Z]*\d+[A-Z]?)$/', $pattern, $m)) {
            return static::inRange($postcode, $outcode, $m[1], $m[2]);
        }

        return $postcode === $pattern || ($outcode !== null && $outcode === $pattern);
    }

    /** Upper case without spaces. */
    public static function clean(string $postcode): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($postcode)));
    }

    /** UK outcode of a full postcode ("SW1A1AA" → "SW1A"); null for other countries / partial postcodes. */
    public static function outcode(string $postcode, ?string $country = null): ?string
    {
        if ($country !== null && ! in_array(strtoupper($country), ['GB', 'UK', 'IM', 'JE', 'GG'], true)) {
            return null;
        }
        $pc = static::clean($postcode);
        if (preg_match('/^([A-Z]{1,2}\d[A-Z\d]?)(\d[A-Z]{2})$/', $pc, $m)) {
            return $m[1];
        }

        return null;
    }

    protected static function inRange(string $postcode, ?string $outcode, string $from, string $to): bool
    {
        // pure numbers (ZIP codes): compare the postcode's number
        if (ctype_digit($from) && ctype_digit($to)) {
            if (! ctype_digit($postcode)) {
                return false;
            }

            return (int) $postcode >= (int) $from && (int) $postcode <= (int) $to;
        }
        // UK districts: same area letters, district number between the two ("HS1-HS9")
        if (! preg_match('/^([A-Z]+)(\d+)$/', $from, $a) || ! preg_match('/^([A-Z]+)(\d+)$/', $to, $b) || $a[1] !== $b[1]) {
            return false;
        }
        $subject = $outcode ?? $postcode;
        if (! preg_match('/^([A-Z]+)(\d+)[A-Z]?$/', $subject, $s) || $s[1] !== $a[1]) {
            return false;
        }

        return (int) $s[2] >= min((int) $a[2], (int) $b[2]) && (int) $s[2] <= max((int) $a[2], (int) $b[2]);
    }
}
