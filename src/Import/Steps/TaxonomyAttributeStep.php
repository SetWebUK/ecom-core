<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Support\Formatter;

/**
 * A product taxonomy without its own table (tags, brands) kept as a non-filterable attribute: attribute {slug}, one
 * value per term (wp_id = term id) linked to the products, product_attributes rows visible on the product page.
 */
class TaxonomyAttributeStep extends AbstractStep
{
    public function __construct(private readonly string $stepKey = 'catalog.tags', private readonly string $taxonomy = 'product_tag',
        private readonly string $slug = 'tags', private readonly string $name = 'Tags')
    {
        parent::__construct();
    }

    public function key(): string
    {
        return $this->stepKey;
    }

    public function section(): string
    {
        return 'catalog';
    }

    public function after(): array
    {
        return ['catalog.products'];
    }

    protected function clear(): void
    {
        DB::table('attributes')->where('slug', $this->slug)->delete();
    }

    protected function import(): void
    {
        $terms = $this->wp->terms($this->taxonomy);
        if ($terms->isEmpty()) {
            return;
        }
        $attributeId = $this->ctx->save('attributes', [[
            'slug' => $this->slug, 'name' => $this->name, 'type' => 'select', 'is_filterable' => false, 'sort_order' => 90,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]], 'slug', ['created_at', 'is_filterable', 'sort_order'])[$this->slug];

        $values = [];
        foreach ($terms as $t) {
            $values[] = ['attribute_id' => $attributeId, 'value' => Formatter::decode($t->name), 'slug' => urldecode($t->slug), 'sort_order' => 0, 'wp_id' => $t->term_id];
        }
        $valueIds = AttributesStep::saveValues($values, $this->now());
        $byTerm = [];
        foreach ($terms as $t) {
            $byTerm[(int) $t->term_id] = $valueIds[$attributeId.'|'.urldecode($t->slug)] ?? null;
        }

        $productMap = $this->ctx->map('products');
        $links = [];
        $attrs = [];
        foreach ($this->wp->objectTerms(array_keys($productMap), $this->taxonomy) as $wpId => $list) {
            $pid = $productMap[$wpId] ?? null;
            foreach ($pid ? $list : [] as $t) {
                if ($valueId = $byTerm[(int) $t->term_id] ?? null) {
                    $links[$pid.'|'.$valueId] = ['attribute_value_id' => $valueId, 'product_id' => $pid];
                    $attrs[$pid] = ['product_id' => $pid, 'attribute_id' => $attributeId, 'position' => 90, 'is_visible' => true, 'is_variation' => false];
                }
            }
        }
        $pids = array_keys($attrs);
        foreach (array_chunk(array_values($productMap), 500) as $chunk) {
            DB::table('product_attributes')->where('attribute_id', $attributeId)->whereIn('product_id', $chunk)->delete();
            DB::table('attribute_value_product')->whereIn('attribute_value_id', array_filter($byTerm))->whereIn('product_id', $chunk)->delete();
        }
        $this->ctx->insert('product_attributes', array_values($attrs));
        $this->ctx->insert('attribute_value_product', array_values($links));
        $this->ctx->count('Product '.$this->slug.' ('.$this->taxonomy.')', $terms->count(), count($links), count($pids).' products, kept as attribute "'.$this->slug.'"');
    }
}
