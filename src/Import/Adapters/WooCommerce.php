<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\SettingsProvider;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * WooCommerce itself: required for the catalogue/order steps (the command aborts when it is not active, unless only
 * content/menus/redirects/users are imported). Provides the store settings taken from WooCommerce options; which keys
 * are imported is `commerce-import.settings.woocommerce` (null = all of KEYS).
 */
class WooCommerce extends AbstractAdapter implements SettingsProvider
{
    public const KEYS = ['store.name', 'store.country', 'store.currency', 'store.currency_position', 'store.price_decimals',
        'store.thousand_separator', 'store.decimal_separator', 'store.weight_unit', 'store.dimension_unit', 'tax.enabled',
        'tax.prices_include_tax', 'tax.display_shop', 'tax.rate', 'tax.label', 'tax.rates', 'inventory.low_stock_threshold',
        'checkout.hold_stock_minutes', 'emails.from_name', 'emails.from_address',
        // v1.1: tax behaviour + the countries sold to (tax.rates also switches on the tax classes/rates import, TaxStep)
        'tax.display_cart', 'tax.based_on', 'tax.rounding', 'tax.shipping_tax_class', 'tax.shipping_prices_include_tax',
        'tax.price_suffix', 'checkout.countries'];

    public function key(): string
    {
        return 'woocommerce';
    }

    public function label(): string
    {
        return 'WooCommerce';
    }

    public function priority(): int
    {
        return 100; // first in the settings chain – everything else may override
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('woocommerce/woocommerce.php') || ($site->wooVersion !== null && $wp->hasTable('woocommerce_order_items'));
    }

    public function settings(ImportContext $ctx): array
    {
        $site = $ctx->site;
        $woo = $site->woo;
        $keys = $ctx->config('settings.woocommerce');
        $keys = is_array($keys) ? $keys : self::KEYS;
        $country = strtoupper(explode(':', (string) ($woo['default_country'] ?? ''))[0]);

        $all = [
            'store.name' => fn () => Formatter::decode($site->blogName) ?: config('app.name'),
            'store.country' => fn () => $country ?: null,
            'store.currency' => fn () => $woo['currency'] ?? null,
            'store.currency_position' => fn () => $woo['currency_pos'] ?? null,
            'store.price_decimals' => fn () => isset($woo['decimals']) ? (string) $woo['decimals'] : null,
            'store.thousand_separator' => fn () => $woo['thousand_sep'] ?? null,
            'store.decimal_separator' => fn () => $woo['decimal_sep'] ?? null,
            'store.weight_unit' => fn () => $woo['weight_unit'] ?? null,
            'store.dimension_unit' => fn () => $woo['dimension_unit'] ?? null,
            'tax.enabled' => fn () => (bool) ($woo['calc_taxes'] ?? false),
            'tax.prices_include_tax' => fn () => (bool) ($woo['prices_include_tax'] ?? false),
            'tax.display_shop' => fn () => $woo['tax_display_shop'] ?? null,
            'tax.rate' => fn () => ($r = $this->standardRate($ctx, $country)) ? (string) (float) $r->tax_rate : null,
            'tax.label' => fn () => ($r = $this->standardRate($ctx, $country)) ? (Formatter::decode($r->tax_rate_name) ?: null) : null,
            'tax.rates' => fn () => $this->rates($ctx) ?: null,
            'tax.display_cart' => fn () => in_array($woo['tax_display_cart'] ?? null, ['incl', 'excl'], true) ? $woo['tax_display_cart'] : null,
            'tax.based_on' => fn () => in_array($woo['tax_based_on'] ?? null, ['shipping', 'billing', 'base'], true) ? $woo['tax_based_on'] : null,
            'tax.rounding' => fn () => ($woo['tax_round_at_subtotal'] ?? false) ? 'order' : 'line',
            'tax.shipping_tax_class' => fn () => match ($class = (string) ($woo['shipping_tax_class'] ?? 'inherit')) {
                'inherit' => 'inherit',
                '' => 'standard',
                default => $class,
            },
            // WooCommerce always reads shipping costs as excluding tax
            'tax.shipping_prices_include_tax' => fn () => ($woo['prices_include_tax'] ?? false) ? 'no' : null,
            'tax.price_suffix' => fn () => ($woo['price_display_suffix'] ?? '') !== '' ? Formatter::decode((string) $woo['price_display_suffix']) : null,
            'checkout.countries' => fn () => ($woo['allowed_countries'] ?? '') === 'specific' && ! empty($woo['specific_allowed_countries'])
                ? array_values(array_map('strtoupper', (array) $woo['specific_allowed_countries'])) : null,
            'inventory.low_stock_threshold' => fn () => is_numeric($woo['notify_low_stock_amount'] ?? null) ? (string) (int) $woo['notify_low_stock_amount'] : null,
            'checkout.hold_stock_minutes' => fn () => is_numeric($woo['hold_stock_minutes'] ?? null) ? (string) (int) $woo['hold_stock_minutes'] : null,
            'emails.from_name' => fn () => $woo['email_from_name'] ? Formatter::decode((string) $woo['email_from_name']) : null,
            'emails.from_address' => fn () => $woo['email_from_address'] ?: null,
        ];
        $out = [];
        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $out[$key] = $all[$key]();
            }
        }

        return $out;
    }

    /** The standard-class rate for the store country (else the first global standard rate). */
    private function standardRate(ImportContext $ctx, string $country): ?object
    {
        if (! $ctx->wp->hasTable('woocommerce_tax_rates')) {
            return null;
        }
        $rates = $ctx->wp->table('woocommerce_tax_rates')->where('tax_rate_class', '')->orderBy('tax_rate_order')->orderBy('tax_rate_id')->get();

        return $rates->firstWhere('tax_rate_country', $country) ?? $rates->firstWhere('tax_rate_country', '');
    }

    /** @return list<array{country:string,state:string,rate:float,name:string,priority:int,compound:bool,shipping:bool,class:string,postcodes:list<string>,cities:list<string>}> */
    private function rates(ImportContext $ctx): array
    {
        if (! $ctx->wp->hasTable('woocommerce_tax_rates')) {
            return [];
        }
        $locations = [];
        if ($ctx->wp->hasTable('woocommerce_tax_rate_locations')) {
            foreach ($ctx->wp->table('woocommerce_tax_rate_locations')->get() as $l) {
                $locations[(int) $l->tax_rate_id][$l->location_type][] = $l->location_code;
            }
        }
        $out = [];
        foreach ($ctx->wp->table('woocommerce_tax_rates')->orderBy('tax_rate_order')->orderBy('tax_rate_id')->get() as $r) {
            $out[] = [
                'country' => (string) $r->tax_rate_country, 'state' => (string) $r->tax_rate_state, 'rate' => (float) $r->tax_rate,
                'name' => Formatter::decode($r->tax_rate_name), 'priority' => (int) $r->tax_rate_priority, 'compound' => (bool) $r->tax_rate_compound,
                'shipping' => (bool) $r->tax_rate_shipping, 'class' => (string) $r->tax_rate_class,
                'postcodes' => $locations[(int) $r->tax_rate_id]['postcode'] ?? [], 'cities' => $locations[(int) $r->tax_rate_id]['city'] ?? [],
            ];
        }

        return $out;
    }
}
