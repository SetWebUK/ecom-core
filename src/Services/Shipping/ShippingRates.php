<?php

namespace Pine\Commerce\Services\Shipping;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\ShippingZone;
use Pine\Commerce\Services\Checkout\CartLine;
use Pine\Commerce\Services\Tax\TaxLocation;
use Pine\Commerce\Support\Features;

/**
 * The delivery options for a basket and address.
 *
 *  1. Zone: the first shipping zone (in the admin's order) whose countries/regions and postcode patterns match the
 *     address. Its active methods are offered, followed by "unzoned" methods (no zone – how every method worked
 *     before zones existed). With no zones at all, every method is unzoned.
 *  2. Each method is checked against its own country list and minimum order, priced by its type
 *     (Pine\Commerce\Models\ShippingMethod), then by a registered calculator (Commerce::shippingCalculator()) if any.
 *  3. Without feature "multi_shipping" only the first available method is offered.
 *
 * All amounts are "as entered" (like the product prices – see Settings › Tax).
 */
class ShippingRates
{
    protected static ?EloquentCollection $methods = null;

    protected static ?EloquentCollection $zones = null;

    /**
     * @param  Collection<int, CartLine>  $lines
     * @param  array{subtotal: float, discount: float, free_shipping: bool, location: TaxLocation}  $context
     * @return array<string, array{method: ShippingMethod, cost: float, zone: ?ShippingZone}>
     */
    public function quote(Collection $lines, array $context): array
    {
        /** @var TaxLocation $location */
        $location = $context['location'];
        $zone = $this->zoneFor($location);
        $subtotal = (float) $context['subtotal'];
        $discount = (float) $context['discount'];
        $freeShipping = (bool) ($context['free_shipping'] ?? false);

        $methods = [];
        foreach ($this->candidates($zone) as $method) {
            if ($method->countries && ! in_array($location->country, (array) $method->countries, true)) {
                continue;
            }
            $type = $method->typeKey();
            if ($type !== 'free_shipping' && $method->min_order_amount !== null && (float) $method->min_order_amount > 0
                && round($subtotal - $discount, 2) < (float) $method->min_order_amount
                && ! ($freeShipping && str_starts_with((string) $method->code, 'free'))) {
                continue;
            }
            $cost = $this->cost($method, $lines, $subtotal, $discount, $freeShipping);
            if ($cost === null) {
                continue;
            }
            if (($calculator = $this->calculator((string) $method->code)) !== null) {
                $cost = $calculator($method, $cost, $lines, ['subtotal' => $subtotal, 'discount' => $discount,
                    'country' => $location->country, 'postcode' => $location->postcode, 'zone' => $zone,
                    'free_shipping' => $freeShipping, 'lines' => $lines]);
                if ($cost === null) {
                    continue;
                }
                $cost = round(max(0, (float) $cost), 2);
            }
            $methods[$method->code] = ['method' => $method, 'cost' => $cost, 'zone' => $zone];
            if (! Features::enabled('multi_shipping', false)) {
                break; // one delivery option only: the first available one
            }
        }

        return $methods;
    }

    /** The zone for an address (null when no zone matches or none exist). */
    public function zoneFor(TaxLocation $location): ?ShippingZone
    {
        foreach (static::zones() as $zone) {
            if ($zone->matches($location->country, $location->state, $location->postcode)) {
                return $zone;
            }
        }

        return null;
    }

    /** @return list<ShippingMethod> the matched zone's methods, then the unzoned ones */
    protected function candidates(?ShippingZone $zone): array
    {
        $all = static::methods();
        $zoneIds = static::zones()->pluck('id')->all();
        $zoned = $zone ? $all->filter(fn (ShippingMethod $m) => (int) $m->shipping_zone_id === (int) $zone->id) : collect();
        // no zone id, or a zone that no longer exists
        $unzoned = $all->filter(fn (ShippingMethod $m) => ! $m->shipping_zone_id || ! in_array((int) $m->shipping_zone_id, $zoneIds, true));

        return $zoned->concat($unzoned)->values()->all();
    }

    /** Price of one method for the basket by its type; null = not available. */
    public function cost(ShippingMethod $method, Collection $lines, float $subtotal, float $discount, bool $freeShipping): ?float
    {
        $qty = (int) $lines->sum(fn (CartLine $l) => $l->quantity);
        $contents = round($subtotal - $discount, 2);

        switch ($method->typeKey()) {
            case 'free_shipping':
                $min = (float) ($method->min_order_amount ?? $method->setting('min_amount', 0));
                $amount = $method->setting('ignore_discounts') ? $subtotal : $contents;
                $hasMin = $min <= 0 || round($amount, 2) >= $min;

                return match ((string) $method->setting('requires', '')) {
                    'coupon' => $freeShipping ? 0.0 : null,
                    'min_amount' => $hasMin ? 0.0 : null,
                    'either' => $hasMin || $freeShipping ? 0.0 : null,
                    'both' => $hasMin && $freeShipping ? 0.0 : null,
                    default => 0.0,
                };

            case 'weight_table':
                $weight = round((float) $lines->sum(fn (CartLine $l) => $l->weight() * $l->quantity), 3);

                return $this->band((array) $method->setting('rates', []), $weight);

            case 'price_table':
                return $this->band((array) $method->setting('rates', []), $contents);

            case 'local_pickup':
                return round(max(0, (float) $method->cost), 2);

            default: // flat_rate
                $base = $method->setting('cost');
                $base = $base === null || $base === '' ? (string) $method->cost : (string) $base;
                $calculation = (string) $method->setting('calculation', 'order');
                $cost = CostExpression::evaluate($base, $qty, $contents);
                if ($calculation === 'item') {
                    $cost = CostExpression::evaluate($base, 1, $qty ? $contents / $qty : 0) * $qty;
                } elseif ($calculation === 'class') {
                    $cost += $this->classCosts($method, $lines, $subtotal > 0 ? $contents / $subtotal : 1.0);
                }

                return round(max(0, $cost), 2);
        }
    }

    /** Flat rate "per shipping class" costs: each class in the basket (or only the most expensive one). */
    protected function classCosts(ShippingMethod $method, Collection $lines, float $discountFactor): float
    {
        $costs = (array) $method->setting('class_costs', []);
        $noClass = $method->setting('no_class_cost');
        $groups = $lines->groupBy(fn (CartLine $l) => (string) ($l->shippingClassId() ?? ''));
        $charges = [];
        foreach ($groups as $classId => $group) {
            $expression = $classId !== '' && array_key_exists($classId, $costs) && trim((string) $costs[$classId]) !== ''
                ? (string) $costs[$classId]
                : (string) $noClass;
            if (trim($expression) === '') {
                continue;
            }
            $charges[] = CostExpression::evaluate($expression, (int) $group->sum(fn (CartLine $l) => $l->quantity),
                round($group->sum(fn (CartLine $l) => $l->subtotal()) * $discountFactor, 2));
        }
        if (! $charges) {
            return 0.0;
        }

        return $method->setting('class_mode', 'sum') === 'max' ? max($charges) : array_sum($charges);
    }

    /** Cost of the band containing $value ([{min, max, cost}], max blank = no upper limit); null = no band. */
    protected function band(array $rates, float $value): ?float
    {
        foreach ($rates as $rate) {
            $min = is_numeric($rate['min'] ?? null) ? (float) $rate['min'] : 0.0;
            $max = is_numeric($rate['max'] ?? null) ? (float) $rate['max'] : null;
            if ($value >= $min && ($max === null || $value <= $max)) {
                return round(max(0, (float) ($rate['cost'] ?? 0)), 2);
            }
        }

        return null;
    }

    /**
     * The calculator registered for a shipping method code (Commerce::shippingCalculator(), first matching pattern),
     * as a callable($method, $cost, $lines, $context): ?float.
     */
    protected function calculator(string $code): ?\Closure
    {
        foreach (app(ExtensionRegistry::class)->shippingCalculators() as $entry) {
            if (! fnmatch($entry['pattern'], $code)) {
                continue;
            }
            $calculator = $entry['calculator'];
            if ($calculator instanceof \Closure) {
                return $calculator;
            }
            $calculator = is_string($calculator) ? app($calculator) : $calculator;

            return fn (...$args) => $calculator->cost(...$args);
        }

        return null;
    }

    /** Active methods (per-process memo; flush() after changes in long-running processes/tests). */
    public static function methods(): EloquentCollection
    {
        return static::$methods ??= ShippingMethod::active()->orderBy('id')->get();
    }

    public static function zones(): EloquentCollection
    {
        if (static::$zones === null) {
            try {
                static::$zones = ShippingZone::query()->orderBy('sort_order')->orderBy('id')->get();
            } catch (\Throwable) {
                static::$zones = new EloquentCollection; // not migrated yet: every method is unzoned
            }
        }

        return static::$zones;
    }

    public static function flush(): void
    {
        static::$methods = null;
        static::$zones = null;
    }
}
