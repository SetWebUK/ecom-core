<?php

namespace Pine\Commerce\Services\Tax;

use Illuminate\Support\Collection;
use Pine\Commerce\Models\TaxClass;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Support\PostcodeMatcher;

/**
 * Finds the rates that apply to a tax class at a location (WooCommerce's WC_Tax::find_rates):
 * a rate matches when its country, state, postcode patterns and cities are empty or match; for each priority only the
 * most specific matching rate is used; the result is ordered by priority (compound rates are charged on top).
 */
class TaxRates
{
    /** @var Collection<int, TaxRate>|null */
    protected static ?Collection $all = null;

    protected static array $memo = [];

    /** @return list<TaxRate> */
    public static function find(?string $class, TaxLocation $location, bool $forShipping = false): array
    {
        $class = TaxClass::normalise($class);
        $key = $class.'#'.$location->key().'#'.(int) $forShipping;
        if (array_key_exists($key, static::$memo)) {
            return static::$memo[$key];
        }

        $matches = static::all()->filter(function (TaxRate $rate) use ($class, $location, $forShipping) {
            if (TaxClass::normalise($rate->tax_class) !== $class || ($forShipping && ! $rate->shipping)) {
                return false;
            }
            if ($rate->country !== '' && strtoupper($rate->country) !== $location->country) {
                return false;
            }
            if ($rate->state !== '' && strcasecmp($rate->state, $location->state) !== 0) {
                return false;
            }
            if (($patterns = $rate->postcodeList()) && ! PostcodeMatcher::matchesAny($location->postcode, $patterns, $location->country)) {
                return false;
            }
            if (($cities = $rate->cityList()) && ! in_array(mb_strtoupper($location->city), array_map('mb_strtoupper', $cities), true)) {
                return false;
            }

            return true;
        });

        $best = [];
        foreach ($matches->sortBy([
            fn (TaxRate $a, TaxRate $b) => $a->priority <=> $b->priority,
            fn (TaxRate $a, TaxRate $b) => static::specificity($b) <=> static::specificity($a),
            fn (TaxRate $a, TaxRate $b) => $a->sort_order <=> $b->sort_order ?: $a->id <=> $b->id,
        ]) as $rate) {
            $best[$rate->priority] ??= $rate;
        }

        return static::$memo[$key] = array_values($best);
    }

    /** Total percentage of a set of rates (compound rates included), e.g. 20.0. */
    public static function totalPercent(array $rates): float
    {
        return round(array_sum(TaxEngine::exclusive(100.0, $rates)), 6);
    }

    /** @return Collection<int, TaxRate> */
    public static function all(): Collection
    {
        if (static::$all === null) {
            try {
                static::$all = TaxRate::query()->orderBy('priority')->orderBy('sort_order')->orderBy('id')->get();
            } catch (\Throwable) {
                static::$all = collect(); // table not migrated yet: no tax, as before v1.1
            }
        }

        return static::$all;
    }

    public static function flush(): void
    {
        static::$all = null;
        static::$memo = [];
    }

    protected static function specificity(TaxRate $rate): int
    {
        return ($rate->country !== '' ? 8 : 0) + ($rate->state !== '' ? 4 : 0) + ($rate->postcodeList() ? 2 : 0) + ($rate->cityList() ? 1 : 0);
    }
}
