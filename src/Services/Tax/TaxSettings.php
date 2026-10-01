<?php

namespace Pine\Commerce\Services\Tax;

/**
 * The store's tax settings (Admin › Settings › Tax). Each value is the saved setting, else config('commerce.tax.*'),
 * else the behaviour every store had before v1.1 (prices entered without tax, tax added on top, shown excluding tax,
 * rounded per line, based on the delivery address, shipping taxed).
 *
 *  tax.enabled                 bool     false = never charge tax (rates are ignored)
 *  tax.prices_include_tax      bool     prices are entered including tax (tax is extracted from them)
 *  tax.display_shop            incl|excl  product prices on the shop pages ('' = as entered)
 *  tax.display_cart            incl|excl  basket, checkout, receipts ('' = as entered)
 *  tax.rounding                line|order round tax per line (WooCommerce default) or once on the order total
 *  tax.based_on                shipping|billing|base  address used to pick rates (base = the shop's own address)
 *  tax.shipping_taxable        bool     charge tax on shipping (methods also have their own tax status)
 *  tax.shipping_tax_class      inherit|{class slug}  inherit = the basket's highest-rated class
 *  tax.shipping_prices_include_tax  ''|yes|no  shipping costs entered including tax ('' = like product prices)
 *  tax.adjust_non_base_prices  bool     prices incl. tax: customers outside the base rates pay net + their own rate
 *  tax.price_suffix            string   text after shop prices, e.g. "inc. VAT" ({price_excl}/{price_incl} allowed)
 *  tax.label                   string   name of the tax when an order has no per-rate lines (e.g. "VAT")
 */
class TaxSettings
{
    public static function enabled(): bool
    {
        return static::bool('enabled', true);
    }

    public static function pricesIncludeTax(): bool
    {
        return static::bool('prices_include_tax', false);
    }

    /** Show shop (catalogue) prices including tax? */
    public static function displayShopIncl(): bool
    {
        return static::display('display_shop');
    }

    /** Show basket/checkout/receipt amounts including tax? */
    public static function displayCartIncl(): bool
    {
        return static::display('display_cart');
    }

    public static function rounding(): string
    {
        return static::value('rounding', 'line') === 'order' ? 'order' : 'line';
    }

    public static function basedOn(): string
    {
        $value = (string) static::value('based_on', 'shipping');

        return in_array($value, ['shipping', 'billing', 'base'], true) ? $value : 'shipping';
    }

    public static function shippingTaxable(): bool
    {
        return static::bool('shipping_taxable', true);
    }

    public static function shippingTaxClass(): string
    {
        $value = trim((string) static::value('shipping_tax_class', 'inherit'));

        return $value === '' ? 'inherit' : $value;
    }

    public static function shippingPricesIncludeTax(): bool
    {
        $value = static::value('shipping_prices_include_tax', '');
        if ($value === '' || $value === null) {
            return static::pricesIncludeTax();
        }

        return static::toBool($value);
    }

    public static function adjustNonBasePrices(): bool
    {
        return static::bool('adjust_non_base_prices', true);
    }

    public static function priceSuffix(): string
    {
        return trim((string) static::value('price_suffix', ''));
    }

    public static function label(): string
    {
        return (string) setting('tax.label', config('commerce.tax.label', 'VAT'));
    }

    /** The shop's own address (store.country setting, else config commerce.store.country). */
    public static function baseLocation(): TaxLocation
    {
        $country = strtoupper(trim((string) (setting('store.country') ?: config('commerce.store.country', 'GB'))));

        return new TaxLocation($country ?: 'GB', (string) setting('tax.base_state', ''), (string) setting('tax.base_postcode', ''));
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        $value = setting('tax.'.$key);
        if ($value === null || $value === '') {
            $value = config('commerce.tax.'.$key, $default);
        }

        return $value ?? $default;
    }

    protected static function display(string $key): bool
    {
        $value = static::value($key, '');
        if ($value === 'incl' || $value === 'excl') {
            return $value === 'incl';
        }

        return static::pricesIncludeTax();
    }

    protected static function bool(string $key, bool $default): bool
    {
        return static::toBool(static::value($key, $default));
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'incl'], true);
    }
}
