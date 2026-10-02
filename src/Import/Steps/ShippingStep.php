<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Mapping\ShippingTaxRows;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Support\WooCommerceContinents;
use Pine\Commerce\Services\Shipping\ShippingRates;

/**
 * WooCommerce shipping -> shipping zones, methods and classes.
 *
 * `commerce-import.shipping.zones` true (default): woocommerce_shipping_zones + their locations become shipping_zones
 * (countries, "GB:ENG" states, continents (AF, AN, AS, EU, NA, OC, SA) expanded to WooCommerce's countries, postcode patterns; "Locations not covered by your
 * other zones" = a last zone matching everywhere), methods keep their type: flat_rate (cost or cost formula
 * [qty]/[cost]/[fee …], shipping class costs, per class / per order), free_shipping (requires, min_amount,
 * ignore_discounts), local_pickup; other plugin methods are imported switched off with a warning.
 * product_shipping_class terms become shipping classes, assigned to products and variations.
 *
 * `shipping.zones` false: the pre-v1.1 flat list – every method becomes an unzoned method limited to its zone's
 * countries (the rest-of-the-world zone's methods have no limit); formulas are imported as cost 0 with a warning.
 * Methods are matched on the method code (a code used in several zones gets a "-{zone id}" suffix from the second
 * zone on); is_active is only set on insert (the admin may switch methods off).
 */
class ShippingStep extends AbstractStep
{
    /** WooCommerce's "EU" continent (Europe) – kept for compatibility; every continent: Support\WooCommerceContinents. */
    public const EUROPE = WooCommerceContinents::CONTINENTS['EU']['countries'];

    public function key(): string
    {
        return 'extras.shipping';
    }

    public function section(): string
    {
        return 'extras';
    }

    protected function import(): void
    {
        if (! $this->ctx->config('shipping.zones', true)) {
            $this->importFlat();

            return;
        }
        $classMap = $this->importClasses();
        $this->importZones($classMap);
        ShippingRates::flush();
    }

    /** @return array<int, int> WooCommerce term id => shipping_classes.id */
    protected function importClasses(): array
    {
        $terms = $this->wp->terms('product_shipping_class');
        $rows = [];
        foreach ($terms as $term) {
            $rows[] = ['wp_id' => (int) $term->term_id, 'name' => Formatter::decode((string) $term->name), 'slug' => $this->uniqueClassSlug((string) $term->slug, (int) $term->term_id),
                'description' => Formatter::decode((string) $term->description) ?: null, 'created_at' => $this->now(), 'updated_at' => $this->now()];
        }
        $map = $this->ctx->save('shipping_classes', $rows, 'wp_id', ['created_at']);

        $assigned = 0;
        foreach (['products' => $this->ctx->map('products'), 'product_variations' => $this->ctx->map('product_variations')] as $table => $ids) {
            if (! $ids || ! $map) {
                continue;
            }
            $byClass = [];
            foreach ($this->wp->objectTerms(array_keys($ids), 'product_shipping_class') as $objectId => $list) {
                $classId = $map[(int) $list[0]->term_id] ?? null;
                if ($classId && isset($ids[$objectId])) {
                    $byClass[$classId][] = $ids[$objectId];
                }
            }
            foreach ($byClass as $classId => $rowIds) {
                foreach (array_chunk($rowIds, 1000) as $chunk) {
                    $assigned += DB::table($table)->whereIn('id', $chunk)->update(['shipping_class_id' => $classId]);
                }
            }
        }
        $this->ctx->count('Shipping classes', $terms->count(), count($map), $assigned.' products/variations assigned');

        return $map;
    }

    protected function importZones(array $classMap): void
    {
        $zones = $this->wp->table('woocommerce_shipping_zones')->orderBy('zone_order')->orderBy('zone_id')->get();
        $locations = [];
        foreach ($this->wp->table('woocommerce_shipping_zone_locations')->get() as $loc) {
            $locations[(int) $loc->zone_id][(string) $loc->location_type][] = (string) $loc->location_code;
        }
        $methods = $this->wp->table('woocommerce_shipping_zone_methods')->orderBy('zone_id')->orderBy('method_order')->get();

        $zoneRows = [];
        foreach ($zones as $zone) {
            $zoneRows[] = ShippingTaxRows::zone((int) $zone->zone_id + 1, (string) $zone->zone_name, $locations[(int) $zone->zone_id] ?? [],
                (int) $zone->zone_order + 1, $this->now(), fn ($w) => $this->ctx->warn($w));
        }
        if ($methods->contains(fn ($m) => (int) $m->zone_id === 0)) {
            $zoneRows[] = ['wp_id' => 1, 'name' => 'Rest of the world', 'regions' => null, 'postcodes' => null,
                'sort_order' => 10000, 'created_at' => $this->now(), 'updated_at' => $this->now()];
        }
        $zoneMap = $this->ctx->save('shipping_zones', $zoneRows, 'wp_id', ['created_at', 'sort_order']);

        $rows = [];
        $seen = [];
        foreach ($methods as $method) {
            $zoneId = $zoneMap[(int) $method->zone_id + 1] ?? null;
            if (! $zoneId) {
                continue; // method of a deleted zone
            }
            $settings = (array) $this->wp->option('woocommerce_'.$method->method_id.'_'.$method->instance_id.'_settings', []);
            $code = (string) $method->method_id;
            if (isset($seen[$code])) {
                $code .= '-'.$method->zone_id;
            }
            $seen[$code] = true;
            $rows[] = $this->methodRow($method, $settings, $code, $zoneId, $classMap);
        }
        $this->ctx->save('shipping_methods', $rows, 'code', ['created_at', 'is_active']);
        $this->ctx->setState('shipping.codes', array_column($rows, 'code'));
        $this->ctx->count('Shipping zones', count($zoneRows), count($zoneMap));
        $this->ctx->count('Shipping methods', $methods->count(), count($rows));
    }

    protected function methodRow(object $method, array $settings, string $code, int $zoneId, array $classMap): array
    {
        return ShippingTaxRows::method((string) $method->method_id, (bool) $method->is_enabled, (int) $method->method_order, $settings, $code,
            $zoneId, $classMap, $this->now(), fn ($w) => $this->ctx->warn($w));
    }

    /** The pre-v1.1 import: one flat list of methods, country-limited, no zones. */
    protected function importFlat(): void
    {
        $zones = $this->wp->table('woocommerce_shipping_zones')->pluck('zone_name', 'zone_id')->all();
        $methods = $this->wp->table('woocommerce_shipping_zone_methods')->orderBy('zone_id')->orderBy('method_order')->get()
            ->filter(fn ($m) => (int) $m->zone_id === 0 || isset($zones[$m->zone_id]))
            ->sortBy(fn ($m) => [(int) $m->zone_id === 0 ? 1 : 0, (int) $m->method_order])->values();
        $countries = [];
        foreach ($this->wp->table('woocommerce_shipping_zone_locations')->where('location_type', 'country')->get() as $loc) {
            $countries[$loc->zone_id][] = $loc->location_code;
        }

        $rows = [];
        $seen = [];
        foreach ($methods as $method) {
            $settings = (array) $this->wp->option('woocommerce_'.$method->method_id.'_'.$method->instance_id.'_settings', []);
            $code = $method->method_id;
            if (isset($seen[$code])) {
                $code .= '-'.$method->zone_id;
            }
            $seen[$code] = true;
            $cost = $settings['cost'] ?? 0;
            if ($cost !== '' && $cost !== null && WordPressSource::decimal($cost) === null) {
                $this->ctx->warn("Shipping method '$code' has a cost formula ('$cost') – imported with cost 0, set it in the admin");
            }
            $rows[] = [
                'code' => $code,
                'name' => Formatter::decode($settings['title'] ?? Str::headline($method->method_id)),
                'description' => null,
                'cost' => WordPressSource::decimal($cost) ?? 0,
                'min_order_amount' => WordPressSource::decimal($settings['min_amount'] ?? null) ?: null,
                'countries' => isset($countries[$method->zone_id]) ? json_encode($countries[$method->zone_id]) : null,
                'is_active' => (bool) $method->is_enabled,
                'sort_order' => (int) $method->method_order,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
        }
        $this->ctx->save('shipping_methods', $rows, 'code', ['created_at', 'is_active']);
        $this->ctx->setState('shipping.codes', array_column($rows, 'code'));
        $this->ctx->count('Shipping methods', $methods->count(), count($rows));
    }

    protected function uniqueClassSlug(string $slug, int $termId): string
    {
        return ShippingTaxRows::uniqueClassSlug($slug, $termId);
    }
}
