<?php

namespace Pine\Commerce\Import\Mapping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Support\WooCommerceContinents;
use Pine\Commerce\Services\Shipping\CostExpression;

/**
 * WooCommerce tax classes/rates and shipping zones/methods/classes as rows – shared by the database importer
 * (TaxStep, ShippingStep) and the REST API importer. Inputs are WooCommerce's own field values (method settings
 * as stored in the woocommerce_{method}_{instance}_settings option, which the REST API returns as settings.*.value).
 */
final class ShippingTaxRows
{
    /** Extra tax classes ("standard" is built in). @param array<string,string> $classes slug => name */
    public static function taxClasses(array $classes, string $now): array
    {
        $order = (int) DB::table('tax_classes')->max('sort_order');
        $rows = [];
        foreach ($classes as $slug => $name) {
            if ($slug !== '' && $slug !== 'standard') {
                $rows[] = ['slug' => (string) $slug, 'name' => Formatter::decode($name), 'sort_order' => ++$order, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        return $rows;
    }

    /**
     * A `tax_rates` row.
     *
     * @param  array{id:int, class:string, country:string, state:string, postcodes:list<string>, cities:list<string>, rate:string|float,
     *               name:string, priority:int, compound:bool, shipping:bool, order:int}  $r
     */
    public static function taxRate(array $r, string $now): array
    {
        return [
            'wp_id' => (int) $r['id'],
            'tax_class' => ((string) $r['class']) !== '' ? (string) $r['class'] : 'standard',
            'country' => strtoupper((string) $r['country']),
            'state' => (string) $r['state'],
            'postcodes' => implode("\n", $r['postcodes']) ?: null,
            'cities' => implode("\n", $r['cities']) ?: null,
            'rate' => round((float) $r['rate'], 4),
            'name' => Formatter::decode((string) $r['name']),
            'priority' => max(1, (int) $r['priority']),
            'compound' => (bool) $r['compound'],
            'shipping' => (bool) $r['shipping'],
            'sort_order' => (int) $r['order'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * A `shipping_zones` row from a zone and its locations ([type => codes], types country|state|continent|postcode).
     * Continents are expanded to WooCommerce's country lists.
     *
     * @param  callable(string):void  $warn
     */
    public static function zone(int $wpId, string $name, array $locations, int $order, string $now, callable $warn): array
    {
        $regions = array_map('strtoupper', array_merge($locations['country'] ?? [], $locations['state'] ?? []));
        foreach ($locations['continent'] ?? [] as $continent) {
            $countries = WooCommerceContinents::countries($continent);
            if ($countries) {
                $regions = array_merge($regions, $countries);
            } else {
                $warn("Shipping zone '{$name}': unknown continent '{$continent}' is not imported – add its countries in Settings › Shipping");
            }
        }

        return ['wp_id' => $wpId, 'name' => Formatter::decode($name),
            'regions' => $regions ? json_encode(array_values(array_unique($regions))) : null,
            'postcodes' => implode("\n", $locations['postcode'] ?? []) ?: null,
            'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now];
    }

    /**
     * A `shipping_methods` row: flat_rate (cost or cost formula, class costs, per class / per order), free_shipping
     * (requires, min_amount, ignore_discounts), local_pickup; other (plugin) methods are switched off with a warning.
     *
     * @param  array<string,mixed>  $settings  the method instance settings (title, cost, class_cost_{termId}, …)
     * @param  array<int,int>  $classMap  remote shipping class (term) id => shipping_classes.id
     * @param  callable(string):void  $warn
     */
    public static function method(string $type, bool $enabled, int $methodOrder, array $settings, string $code, int $zoneId, array $classMap,
        string $now, callable $warn): array
    {
        $title = Formatter::decode((string) ($settings['title'] ?? Str::headline($type)));
        $taxStatus = ($settings['tax_status'] ?? 'taxable') === 'none' ? 'none' : 'taxable';
        $cost = trim((string) ($settings['cost'] ?? ''));
        $row = ['code' => $code, 'name' => $title, 'description' => null, 'shipping_zone_id' => $zoneId, 'tax_status' => $taxStatus,
            'countries' => null, 'min_order_amount' => null, 'is_active' => $enabled, 'sort_order' => $methodOrder,
            'created_at' => $now, 'updated_at' => $now];

        switch ($type) {
            case 'free_shipping':
                return array_merge($row, ['type' => 'free_shipping', 'cost' => 0,
                    'settings' => json_encode(['requires' => in_array($settings['requires'] ?? '', ['coupon', 'min_amount', 'either', 'both'], true) ? $settings['requires'] : '',
                        'ignore_discounts' => ($settings['ignore_discounts'] ?? 'no') === 'yes']),
                    'min_order_amount' => WordPressSource::decimal($settings['min_amount'] ?? null) ?: null]);

            case 'local_pickup':
            case 'pickup_location':
                return array_merge($row, ['type' => 'local_pickup', 'cost' => WordPressSource::decimal($cost) ?? 0, 'settings' => null]);

            case 'flat_rate':
                $classCosts = [];
                foreach ($settings as $key => $value) {
                    if (preg_match('/^class_cost_(\d+)$/', (string) $key, $m) && trim((string) $value) !== '' && isset($classMap[(int) $m[1]])) {
                        $classCosts[(string) $classMap[(int) $m[1]]] = trim((string) $value);
                    }
                }
                $noClass = trim((string) ($settings['no_class_cost'] ?? ''));
                $formula = $cost !== '' && WordPressSource::decimal($cost) === null ? $cost : null;
                foreach (array_filter(array_merge([$formula, $noClass], $classCosts)) as $expression) {
                    if (! CostExpression::valid($expression)) {
                        $warn("Shipping method '$code': cost '$expression' uses a formula this platform cannot read – check it in Settings › Shipping");
                    }
                }

                return array_merge($row, ['type' => 'flat_rate', 'cost' => $formula ? 0 : (WordPressSource::decimal($cost) ?? 0),
                    'settings' => json_encode(array_filter([
                        'cost' => $formula,
                        'calculation' => $classCosts || $noClass !== '' ? 'class' : 'order',
                        'class_costs' => $classCosts ?: null,
                        'no_class_cost' => $noClass !== '' ? $noClass : null,
                        'class_mode' => ($settings['type'] ?? 'class') === 'order' ? 'max' : 'sum',
                    ], fn ($v) => $v !== null))]);

            default:
                $warn("Shipping method '$code' ($type) is a plugin method – imported switched off as a flat rate, set it up in Settings › Shipping");

                return array_merge($row, ['type' => 'flat_rate', 'cost' => WordPressSource::decimal($cost) ?? 0, 'is_active' => false, 'settings' => null]);
        }
    }

    /** A slug for a shipping class that no other class (of another source or made by hand) uses. */
    public static function uniqueClassSlug(string $slug, int $termId, ?string $source = null): string
    {
        $slug = Str::slug($slug) ?: 'class-'.$termId;
        $taken = DB::table('shipping_classes')->where('slug', $slug)->where(fn ($q) => $q->whereNull('wp_id')->orWhere('wp_id', '!=', $termId))->exists();

        return $taken ? $slug.'-'.$termId : $slug;
    }
}
