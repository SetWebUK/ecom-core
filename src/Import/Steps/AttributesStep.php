<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Global WooCommerce attributes (woocommerce_attribute_taxonomies + pa_* terms) -> attributes / attribute_values.
 * `commerce-import.attributes.filterable` lists the attributes shown as shop filters (in display order); when it is
 * empty the WooCommerce "public"/archive flag is not a reliable signal, so every attribute stays non-filterable and
 * the admin decides. Local (per-product) attributes are created by the products step.
 */
class AttributesStep extends AbstractStep
{
    public function key(): string
    {
        return 'catalog.attributes';
    }

    public function section(): string
    {
        return 'catalog';
    }

    public function after(): array
    {
        return ['catalog.categories'];
    }

    protected function clear(): void
    {
        $slugs = $this->wp->table('woocommerce_attribute_taxonomies')->pluck('attribute_name')->all();
        DB::table('attributes')->whereIn('slug', $slugs)->delete();
    }

    protected function import(): void
    {
        $filters = array_values((array) $this->ctx->config('attributes.filterable', []));
        $taxes = $this->wp->table('woocommerce_attribute_taxonomies')->orderBy('attribute_id')->get();
        $rows = [];
        foreach ($taxes as $tax) {
            $filterPos = array_search($tax->attribute_name, $filters, true);
            $rows[] = [
                'slug' => $tax->attribute_name,
                'name' => Formatter::decode($tax->attribute_label) ?: Str::headline($tax->attribute_name),
                'type' => 'select',
                'is_filterable' => $filterPos !== false,
                'sort_order' => $filterPos !== false ? $filterPos + 1 : 10 + (int) $tax->attribute_id,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
        }
        $attributeIds = $this->ctx->save('attributes', $rows, 'slug', ['created_at']);

        $terms = $this->wp->terms($taxes->map(fn ($t) => 'pa_'.$t->attribute_name)->all());
        $meta = $this->wp->termMeta($terms->keys()->all());
        $values = [];
        foreach ($terms as $term) {
            $slug = substr($term->taxonomy, 3);
            $m = $meta[$term->term_id] ?? [];
            $values[] = [
                'attribute_id' => $attributeIds[$slug],
                'value' => Formatter::decode($term->name),
                'slug' => $term->slug,
                'sort_order' => (int) ($m['order'] ?? $m['order_'.$term->taxonomy] ?? 0),
                'wp_id' => $term->term_id,
            ];
        }
        self::saveValues($values, $this->now());

        $this->ctx->count('Attributes', $taxes->count(), DB::table('attributes')->count());
        $this->ctx->count('Attribute values', $terms->count(), $this->ctx->owned('attribute_values')->count());
    }

    /** Upsert attribute values on (attribute_id, slug). Returns ["attrId|slug" => id]. */
    public static function saveValues(array $values, string $now): array
    {
        $existing = [];
        foreach (DB::table('attribute_values')->get(['id', 'attribute_id', 'slug']) as $v) {
            $existing[$v->attribute_id.'|'.$v->slug] = $v->id;
        }
        foreach ($values as $v) {
            $key = $v['attribute_id'].'|'.$v['slug'];
            $data = $v + ['updated_at' => $now];
            if (isset($existing[$key])) {
                DB::table('attribute_values')->where('id', $existing[$key])->update($data);
            } else {
                $existing[$key] = DB::table('attribute_values')->insertGetId($data + ['created_at' => $now]);
            }
        }

        return $existing;
    }
}
