<?php

namespace Pine\Commerce\Services\Admin;

use ResourceBundle;
use Throwable;

/** ISO country list (from ICU), United Kingdom first – the store ships to the UK only by default. */
class Countries
{
    protected static ?array $cache = null;

    public static function options(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        $countries = [];
        try {
            foreach (ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries') ?? [] as $code => $name) {
                if (preg_match('/^[A-Z]{2}$/', (string) $code) && ! in_array($code, ['ZZ', 'EU', 'EZ', 'UN', 'QO', 'XA', 'XB'], true)) {
                    $countries[$code] = $name;
                }
            }
        } catch (Throwable) {
            // intl data unavailable – fall back to a short list
        }
        if (! $countries) {
            $countries = ['IE' => 'Ireland', 'FR' => 'France', 'DE' => 'Germany', 'ES' => 'Spain', 'NL' => 'Netherlands', 'US' => 'United States'];
        }
        asort($countries);

        return static::$cache = ['GB' => 'United Kingdom'] + $countries;
    }

    public static function name(?string $code): ?string
    {
        return $code ? (static::options()[$code] ?? $code) : null;
    }
}
