<?php

namespace Pine\Commerce\Services\Tax;

use Pine\Commerce\Models\TaxClass;
use Pine\Commerce\Models\TaxRate;

/**
 * The tax maths shared by the basket/checkout (Pine\Commerce\Services\Cart) and back-office orders (OrderPricing).
 *
 * Input amounts are "as entered": including tax when the store enters prices with tax, otherwise without. Coupons are
 * applied to the entered amounts first (a £10 coupon takes £10 off what the customer sees), then tax is added
 * (exclusive) or extracted (inclusive) per line. Rounding is per line (each rate, 2 dp – WooCommerce's default) or
 * per order (unrounded per line, each rate rounded once).
 *
 * Results are NET (without tax) like WooCommerce stores orders: subtotal, discount, shipping, tax, total – with
 * total = subtotal − discount + shipping + tax to the penny, and, for inclusive prices, total = what the customer saw.
 */
class TaxEngine
{
    /**
     * @param  list<array{key?: mixed, subtotal: float, total: float, class?: ?string, taxable?: bool}>  $lines
     * @param  array{cost: float, taxable?: bool}|null  $shipping
     * @param  array{enabled?: bool, inclusive?: bool, shipping_inclusive?: bool, rounding?: string, adjust?: bool,
     *               base?: TaxLocation, shipping_class?: string, base_location_for_all?: bool}  $options  defaults from TaxSettings
     */
    public function calculate(array $lines, ?array $shipping, ?TaxLocation $location, array $options = []): array
    {
        $enabled = $options['enabled'] ?? TaxSettings::enabled();
        $inclusive = $options['inclusive'] ?? TaxSettings::pricesIncludeTax();
        $shippingInclusive = $options['shipping_inclusive'] ?? TaxSettings::shippingPricesIncludeTax();
        $perLine = ($options['rounding'] ?? TaxSettings::rounding()) !== 'order';
        $adjust = $options['adjust'] ?? TaxSettings::adjustNonBasePrices();
        $base = $options['base'] ?? TaxSettings::baseLocation();
        $location ??= $base;

        $rateInfo = [];
        $itemTaxes = [];      // rate id => tax on the items (after discounts)
        $subtotalTaxes = [];  // rate id => tax on the items before discounts
        $out = [];
        $grossSubtotal = $grossTotal = $netSubtotal = $netTotal = 0.0;
        $classes = [];

        foreach (array_values($lines) as $i => $line) {
            $key = $line['key'] ?? $i;
            $subtotal = round((float) $line['subtotal'], 2);
            $total = round((float) $line['total'], 2);
            $class = TaxClass::normalise($line['class'] ?? null);
            $taxable = $enabled && ($line['taxable'] ?? true);
            $rates = $taxable ? TaxRates::find($class, $location) : [];
            if ($taxable) {
                $classes[$class] = true;
            }

            [$netS, $taxS] = $this->split($subtotal, $rates, $inclusive, $adjust && $taxable ? TaxRates::find($class, $base) : $rates);
            [$netT, $taxT] = $this->split($total, $rates, $inclusive, $adjust && $taxable ? TaxRates::find($class, $base) : $rates);

            if ($perLine) {
                $taxS = array_map(fn ($t) => round($t, 2), $taxS);
                $taxT = array_map(fn ($t) => round($t, 2), $taxT);
                // inclusive prices: net = price − rounded tax, so net + tax is exactly the price
                if ($inclusive && ! $this->adjusted($rates, $adjust && $taxable ? TaxRates::find($class, $base) : $rates)) {
                    $netS = round($subtotal - array_sum($taxS), 2);
                    $netT = round($total - array_sum($taxT), 2);
                }
            }
            foreach ($rates as $rate) {
                $rateInfo[$rate->id] ??= $rate;
                $itemTaxes[$rate->id] = ($itemTaxes[$rate->id] ?? 0) + ($taxT[$rate->id] ?? 0);
                $subtotalTaxes[$rate->id] = ($subtotalTaxes[$rate->id] ?? 0) + ($taxS[$rate->id] ?? 0);
            }

            $lineTax = round(array_sum($taxT), 2);
            $lineSubtotalTax = round(array_sum($taxS), 2);
            $out[$key] = [
                'class' => $class,
                'taxable' => $taxable,
                'net_subtotal' => round($netS, 2),
                'subtotal_tax' => $lineSubtotalTax,
                'net_total' => round($netT, 2),
                'tax' => $lineTax,
                'taxes' => array_map(fn ($t) => round($t, 2), $taxT),
                'gross_subtotal' => round($netS + array_sum($taxS), 2),
                'gross_total' => round($netT + array_sum($taxT), 2),
            ];
            $grossSubtotal += $netS + array_sum($taxS);
            $grossTotal += $netT + array_sum($taxT);
            $netSubtotal += $netS;
            $netTotal += $netT;
        }

        $itemsTax = round(array_sum(array_map(fn ($t) => round($t, 2), $itemTaxes)), 2);
        $subtotalTax = round(array_sum(array_map(fn ($t) => round($t, 2), $subtotalTaxes)), 2);
        $grossSubtotal = round($grossSubtotal, 2);
        $grossTotal = round($grossTotal, 2);
        if ($inclusive) {
            // what the customer saw, split into net + tax to the penny
            $netSubtotal = round($grossSubtotal - $subtotalTax, 2);
            $netTotal = round($grossTotal - $itemsTax, 2);
        } else {
            $netSubtotal = round($netSubtotal, 2);
            $netTotal = round($netTotal, 2);
        }

        // Shipping ---------------------------------------------------------------------------------------------
        $ship = ['net' => 0.0, 'tax' => 0.0, 'taxes' => [], 'gross' => 0.0, 'class' => null];
        if ($shipping !== null) {
            $cost = round((float) $shipping['cost'], 2);
            $rates = [];
            $class = null;
            if ($enabled && ($shipping['taxable'] ?? true) && $cost > 0) {
                $class = $this->shippingClass($options['shipping_class'] ?? TaxSettings::shippingTaxClass(), array_keys($classes), $location);
                $rates = TaxRates::find($class, $location, true);
            }
            [$net, $taxes] = $this->split($cost, $rates, $shippingInclusive, $adjust && $rates ? TaxRates::find($class, $base, true) : $rates);
            $taxes = array_map(fn ($t) => round($t, 2), $taxes);
            if ($shippingInclusive && ! $this->adjusted($rates, $adjust && $rates ? TaxRates::find($class, $base, true) : $rates)) {
                $net = round($cost - array_sum($taxes), 2);
            }
            foreach ($rates as $rate) {
                $rateInfo[$rate->id] ??= $rate;
            }
            $ship = ['net' => round($net, 2), 'tax' => round(array_sum($taxes), 2), 'taxes' => $taxes,
                'gross' => round($net + array_sum($taxes), 2), 'class' => $class];
        }

        $breakdown = [];
        foreach ($rateInfo as $id => $rate) {
            /** @var TaxRate $rate */
            $breakdown[$id] = [
                'id' => $id,
                'label' => $rate->label(),
                'rate' => (float) $rate->rate,
                'compound' => (bool) $rate->compound,
                'tax' => round($itemTaxes[$id] ?? 0, 2),
                'shipping_tax' => round($ship['taxes'][$id] ?? 0, 2),
            ];
        }

        $tax = round($itemsTax + $ship['tax'], 2);

        return [
            'lines' => $out,
            'shipping' => $ship,
            'rates' => $breakdown,
            'inclusive' => $inclusive,
            'subtotal' => $netSubtotal,
            'discount' => round($netSubtotal - $netTotal, 2),
            'items_total' => $netTotal,
            'items_tax' => $itemsTax,
            'subtotal_tax' => $subtotalTax,
            'shipping_net' => $ship['net'],
            'shipping_tax' => $ship['tax'],
            'tax' => $tax,
            'total' => max(0.0, round($netTotal + $ship['net'] + $tax, 2)),
            'gross_subtotal' => $grossSubtotal,
            'gross_discount' => round($grossSubtotal - $grossTotal, 2),
            'gross_shipping' => $ship['gross'],
        ];
    }

    /**
     * Net amount + taxes per rate id (unrounded) of an entered amount.
     *
     * @param  list<TaxRate>  $rates  rates at the customer's location
     * @param  list<TaxRate>  $baseRates  rates at the shop's address (inclusive prices outside the base rates)
     * @return array{0: float, 1: array<int, float>}
     */
    public function split(float $amount, array $rates, bool $inclusive, array $baseRates = []): array
    {
        if (! $inclusive) {
            return [$amount, static::exclusive($amount, $rates)];
        }
        if ($this->adjusted($rates, $baseRates)) {
            // the entered price includes the SHOP's tax: take that out, then charge the customer's own rates
            $net = $amount - array_sum(static::inclusive($amount, $baseRates));

            return [$net, static::exclusive($net, $rates)];
        }
        $taxes = static::inclusive($amount, $rates);

        return [$amount - array_sum($taxes), $taxes];
    }

    /** Taxes on a net amount: simple rates on the net, compound rates on net + the taxes before them. */
    public static function exclusive(float $net, array $rates): array
    {
        $taxes = [];
        foreach ($rates as $rate) {
            if (! $rate->compound) {
                $taxes[$rate->id] = $net * (float) $rate->rate / 100;
            }
        }
        $running = $net + array_sum($taxes);
        foreach ($rates as $rate) {
            if ($rate->compound) {
                $tax = $running * (float) $rate->rate / 100;
                $taxes[$rate->id] = $tax;
                $running += $tax;
            }
        }

        return $taxes;
    }

    /** Taxes contained in a gross amount. */
    public static function inclusive(float $gross, array $rates): array
    {
        if (! $rates) {
            return [];
        }
        $factor = 1 + array_sum(array_map(fn ($r) => $r->compound ? 0 : (float) $r->rate / 100, $rates));
        foreach ($rates as $rate) {
            if ($rate->compound) {
                $factor *= 1 + (float) $rate->rate / 100;
            }
        }

        return static::exclusive($gross / $factor, $rates);
    }

    /** Do the customer's rates differ from the shop's (so an inclusive price is re-based)? */
    protected function adjusted(array $rates, array $baseRates): bool
    {
        $ids = fn (array $list) => array_map(fn ($r) => $r->id, $list);

        return $ids($rates) !== $ids($baseRates);
    }

    /** Shipping tax class: a fixed class, or "inherit" = the basket's class with the highest rate (standard when none). */
    protected function shippingClass(string $setting, array $lineClasses, TaxLocation $location): string
    {
        if ($setting !== 'inherit') {
            return TaxClass::normalise($setting);
        }
        if (! $lineClasses) {
            return TaxClass::STANDARD;
        }
        if (in_array(TaxClass::STANDARD, $lineClasses, true)) {
            return TaxClass::STANDARD; // WooCommerce: the standard class wins when any item uses it
        }
        $best = null;
        $bestRate = -1.0;
        foreach ($lineClasses as $class) {
            $rate = TaxRates::totalPercent(TaxRates::find($class, $location, true));
            if ($rate > $bestRate) {
                [$best, $bestRate] = [$class, $rate];
            }
        }

        return $best ?? TaxClass::STANDARD;
    }
}
