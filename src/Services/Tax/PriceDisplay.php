<?php

namespace Pine\Commerce\Services\Tax;

use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;

/**
 * Shop (catalogue) prices as the customer should see them: prices are stored as entered (with or without tax,
 * Settings › Tax) and shown including or excluding tax at the shop's own address. When both settings agree – every
 * store before v1.1 – prices are returned untouched.
 */
class PriceDisplay
{
    /** Is any conversion needed at all? */
    public static function converts(): bool
    {
        return TaxSettings::enabled() && TaxSettings::displayShopIncl() !== TaxSettings::pricesIncludeTax();
    }

    /** A product/variation price as shown on shop pages. */
    public static function shop(?float $price, Product $product, ?ProductVariation $variation = null): ?float
    {
        if ($price === null || ! static::converts()) {
            return $price;
        }

        return static::convert($price, static::taxClass($product, $variation), ! in_array($product->tax_status, ['none', 'shipping'], true), TaxSettings::displayShopIncl());
    }

    /** Convert an entered amount to incl. ($incl) or excl. tax at the shop's address. */
    public static function convert(float $price, ?string $class, bool $taxable, bool $incl): float
    {
        return static::between($price, TaxSettings::pricesIncludeTax(), $incl, $class, $taxable);
    }

    /** An amount including ($fromIncl) or excluding tax, re-expressed including ($toIncl) or excluding tax. */
    public static function between(float $amount, bool $fromIncl, bool $toIncl, ?string $class, bool $taxable): float
    {
        if (! $taxable || $fromIncl === $toIncl || ! TaxSettings::enabled()) {
            return $amount;
        }
        $rates = TaxRates::find($class, TaxSettings::baseLocation());
        if (! $rates) {
            return $amount;
        }

        return $toIncl
            ? round($amount + array_sum(TaxEngine::exclusive($amount, $rates)), 2)
            : round($amount - array_sum(TaxEngine::inclusive($amount, $rates)), 2);
    }

    /** The configured price suffix (Settings › Tax, e.g. "inc. VAT") as HTML, '' when none. */
    public static function suffixHtml(?float $price = null, ?Product $product = null): string
    {
        $suffix = TaxSettings::priceSuffix();
        if ($suffix === '') {
            return '';
        }
        if ($price !== null && $product && (str_contains($suffix, '{price_incl}') || str_contains($suffix, '{price_excl}'))) {
            // $price is the shown price (Settings › Tax "prices in the shop")
            $shownIncl = TaxSettings::displayShopIncl();
            $taxable = ! in_array($product->tax_status, ['none', 'shipping'], true);
            $suffix = strtr($suffix, [
                '{price_incl}' => money(static::between($price, $shownIncl, true, static::taxClass($product), $taxable)),
                '{price_excl}' => money(static::between($price, $shownIncl, false, static::taxClass($product), $taxable)),
            ]);
        }

        return ' <small class="price-suffix">'.e($suffix).'</small>';
    }

    public static function taxClass(Product $product, ?ProductVariation $variation = null): ?string
    {
        $class = $variation?->tax_class;

        return $class !== null && $class !== '' && $class !== 'parent' ? $class : $product->tax_class;
    }
}
