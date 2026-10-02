<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Str;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Mapping\ShippingTaxRows;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Services\Tax\TaxRates;

/**
 * WooCommerce tax classes and rates -> tax_classes / tax_rates (Settings › Tax), matched on the WooCommerce rate id
 * (tax_rates.wp_id). Classes come from wc_tax_rate_classes (WooCommerce 3.7+) or the old woocommerce_tax_classes
 * option; postcodes/cities from woocommerce_tax_rate_locations.
 *
 * Runs when the client imports WooCommerce's tax settings: `commerce-import.settings.woocommerce` is null (everything)
 * or lists 'tax.rates'. A client that keeps its own tax set-up leaves it out.
 */
class TaxStep extends AbstractStep
{
    public function key(): string
    {
        return 'extras.tax';
    }

    public function section(): string
    {
        return 'extras';
    }

    public function shouldRun(ImportContext $ctx): bool
    {
        $keys = $ctx->config('settings.woocommerce');

        return (! is_array($keys) || in_array('tax.rates', $keys, true)) && $ctx->wp->hasTable('woocommerce_tax_rates');
    }

    protected function import(): void
    {
        if (! $this->shouldRun($this->ctx)) {
            $this->ctx->count('Tax rates', '—', '—', 'not imported (commerce-import.settings.woocommerce leaves out tax.rates)');

            return;
        }

        // classes: standard + WooCommerce's additional classes
        $classes = [];
        if ($this->wp->hasTable('wc_tax_rate_classes')) {
            foreach ($this->wp->table('wc_tax_rate_classes')->orderBy('tax_rate_class_id')->get() as $row) {
                $classes[(string) $row->slug] = Formatter::decode((string) $row->name);
            }
        } else {
            foreach (array_filter(array_map('trim', explode("\n", (string) ($this->ctx->site->woo['tax_classes'] ?? '')))) as $name) {
                $classes[Str::slug($name)] = Formatter::decode($name);
            }
        }
        $classRows = ShippingTaxRows::taxClasses($classes, $this->now());
        $this->ctx->save('tax_classes', $classRows, 'slug', ['sort_order', 'created_at']);

        $locations = [];
        if ($this->wp->hasTable('woocommerce_tax_rate_locations')) {
            foreach ($this->wp->table('woocommerce_tax_rate_locations')->orderBy('location_id')->get() as $l) {
                $locations[(int) $l->tax_rate_id][(string) $l->location_type][] = (string) $l->location_code;
            }
        }
        $rows = [];
        foreach ($this->wp->table('woocommerce_tax_rates')->orderBy('tax_rate_order')->orderBy('tax_rate_id')->get() as $i => $r) {
            $rows[] = ShippingTaxRows::taxRate(['id' => (int) $r->tax_rate_id, 'class' => (string) $r->tax_rate_class, 'country' => (string) $r->tax_rate_country,
                'state' => (string) $r->tax_rate_state, 'postcodes' => $locations[(int) $r->tax_rate_id]['postcode'] ?? [],
                'cities' => $locations[(int) $r->tax_rate_id]['city'] ?? [], 'rate' => $r->tax_rate, 'name' => (string) $r->tax_rate_name,
                'priority' => (int) $r->tax_rate_priority, 'compound' => (bool) $r->tax_rate_compound, 'shipping' => (bool) $r->tax_rate_shipping,
                'order' => (int) ($r->tax_rate_order ?? $i)], $this->now());
        }
        $this->ctx->save('tax_rates', $rows, 'wp_id', ['created_at']);
        TaxRates::flush();
        $this->ctx->count('Tax rates', count($rows), $this->ctx->owned('tax_rates')->count(), count($classRows).' extra tax classes');
    }

    protected function clear(): void
    {
        $this->ctx->owned('tax_rates')->delete();
    }
}
