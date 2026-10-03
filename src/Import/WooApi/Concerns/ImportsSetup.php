<?php

namespace Pine\Commerce\Import\WooApi\Concerns;

use Illuminate\Support\Str;
use Pine\Commerce\Import\Mapping\ShippingTaxRows;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\WooApi\ApiMap;
use Pine\Commerce\Import\WooApi\WooApiException;
use Pine\Commerce\Services\Shipping\ShippingRates;
use Pine\Commerce\Services\Tax\TaxRates;

/** Tax classes + rates and shipping classes, zones and methods of the WooCommerce API import (REST mode only). */
trait ImportsSetup
{
    protected function importTax(): void
    {
        $entity = 'tax';
        if ($this->store()) {
            $this->entity($entity, 'skipped');

            return;
        }
        $classes = [];
        foreach ($this->all('', 'taxes/classes') as $c) {
            $classes[(string) ($c['slug'] ?? '')] = (string) ($c['name'] ?? '');
        }
        $rates = $this->all($entity, 'taxes', ['orderby' => 'order']);
        $this->bump($entity, 'fetched', count($rates));
        $this->transaction(function () use ($classes, $rates, $entity) {
            $classRows = ShippingTaxRows::taxClasses($classes, $this->now());
            if (! $this->dryRun) {
                $this->u->save('tax_classes', $classRows, 'slug', ['sort_order', 'created_at']);
            }
            $rows = [];
            foreach (array_values($rates) as $i => $t) {
                $rows[] = ShippingTaxRows::taxRate([
                    'id' => (int) $t['id'], 'class' => (string) ($t['class'] ?? 'standard'), 'country' => (string) ($t['country'] ?? ''),
                    'state' => (string) ($t['state'] ?? ''),
                    'postcodes' => array_values(array_filter((array) ($t['postcodes'] ?? (($t['postcode'] ?? '') !== '' ? explode(';', (string) $t['postcode']) : [])))),
                    'cities' => array_values(array_filter((array) ($t['cities'] ?? (($t['city'] ?? '') !== '' ? explode(';', (string) $t['city']) : [])))),
                    'rate' => $t['rate'] ?? 0, 'name' => (string) ($t['name'] ?? ''), 'priority' => (int) ($t['priority'] ?? 1),
                    'compound' => ! empty($t['compound']), 'shipping' => ! empty($t['shipping']), 'order' => (int) ($t['order'] ?? $i),
                ], $this->now());
            }
            $this->saveRows($entity, 'tax_rates', $this->withoutExisting($entity, 'tax_rates', $rows), 'wp_id', ['created_at']);
        });
        TaxRates::flush();
    }

    protected function importShipping(): void
    {
        $entity = 'shipping';
        if ($this->store()) {
            $this->entity($entity, 'skipped');

            return;
        }
        $classes = $this->all('', 'products/shipping_classes');
        $zones = $this->all('', 'shipping/zones');
        $this->bump($entity, 'fetched', count($classes) + count($zones));
        $details = [];
        foreach ($zones as $zone) {
            $id = (int) $zone['id'];
            try {
                $details[$id] = [
                    'locations' => $id === 0 ? [] : $this->all('', 'shipping/zones/'.$id.'/locations'),
                    'methods' => $this->all('', 'shipping/zones/'.$id.'/methods'),
                ];
            } catch (WooApiException $e) {
                $this->issue('error', $entity, 'zone '.$id, $e->getMessage());
            }
        }

        $this->transaction(function () use ($classes, $zones, $details, $entity) {
            $classRows = [];
            foreach ($classes as $c) {
                $classRows[] = ['wp_id' => (int) $c['id'], 'name' => Formatter::decode((string) ($c['name'] ?? '')),
                    'slug' => ShippingTaxRows::uniqueClassSlug((string) ($c['slug'] ?? ''), (int) $c['id']),
                    'description' => Formatter::decode((string) ($c['description'] ?? '')) ?: null, 'created_at' => $this->now(), 'updated_at' => $this->now()];
            }
            $classMap = $this->saveRows($entity, 'shipping_classes', $classRows, 'wp_id', ['created_at']);

            $zoneRows = [];
            foreach ($zones as $zone) {
                $id = (int) $zone['id'];
                if ($id === 0) {
                    if (! empty($details[0]['methods'])) {
                        $zoneRows[] = ['wp_id' => 1, 'name' => 'Rest of the world', 'regions' => null, 'postcodes' => null,
                            'sort_order' => 10000, 'created_at' => $this->now(), 'updated_at' => $this->now()];
                    }

                    continue;
                }
                $locations = [];
                foreach ($details[$id]['locations'] ?? [] as $loc) {
                    $locations[(string) ($loc['type'] ?? '')][] = (string) ($loc['code'] ?? '');
                }
                $zoneRows[] = ShippingTaxRows::zone($id + 1, (string) ($zone['name'] ?? ''), $locations, (int) ($zone['order'] ?? 0) + 1, $this->now(),
                    fn ($w) => $this->issue('warning', $entity, 'zone '.$id, $w));
            }
            $zoneMap = $this->saveRows($entity, 'shipping_zones', $zoneRows, 'wp_id', ['created_at', 'sort_order']);

            $rows = [];
            $seen = [];
            foreach ($zones as $zone) {
                $id = (int) $zone['id'];
                $zoneId = $zoneMap[$id + 1] ?? null;
                foreach ($details[$id]['methods'] ?? [] as $m) {
                    $code = (string) ($m['method_id'] ?? 'method');
                    if (isset($seen[$code])) {
                        $code .= '-'.$id;
                    }
                    $seen[$code] = true;
                    if (! $zoneId && ! $this->dryRun) {
                        continue;
                    }
                    $settings = ApiMap::settings((array) ($m['settings'] ?? []));
                    $settings['title'] ??= (string) ($m['title'] ?? $m['method_title'] ?? Str::headline($code));
                    $rows[] = ShippingTaxRows::method((string) ($m['method_id'] ?? ''), ! empty($m['enabled']), (int) ($m['order'] ?? 0), $settings,
                        $code, (int) $zoneId, $classMap, $this->now(), fn ($w) => $this->issue('warning', $entity, $code, $w));
                }
            }
            $this->saveRows($entity, 'shipping_methods', $rows, 'code', ['created_at', 'is_active']);
        });
        ShippingRates::flush();
        $this->catalog = null;
    }
}
