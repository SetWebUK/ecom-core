<?php

namespace Pine\Commerce\Import\Data;

/**
 * A WooCommerce product as the mappers see it.
 *  - terms: taxonomy => list<WpTerm>; product_cat in term-id order, pa_* ordered like wc_get_product_terms() (term order, then name)
 *  - attributes: unserialised _product_attributes
 *  - type: simple|variable|grouped|external (product_type term)
 */
final class WpProduct
{
    public function __construct(
        public readonly WpPost $post,
        public readonly array $meta,
        public readonly array $terms,
        public readonly array $categoryIds,
        public readonly array $attributes,
        public readonly string $type = 'simple',
    ) {}

    /** @return array<string,string> taxonomy => comma-joined decoded term names (pa_* only) */
    public function attributeValues(): array
    {
        $out = [];
        foreach ($this->terms as $taxonomy => $list) {
            if (str_starts_with($taxonomy, 'pa_')) {
                $out[$taxonomy] = implode(', ', array_map(fn (WpTerm $t) => \Pine\Commerce\Import\Support\Formatter::decode($t->name), $list));
            }
        }

        return $out;
    }

    /** @return array<string,list<string>> taxonomy => decoded term names (pa_* only) */
    public function attributeNames(): array
    {
        $out = [];
        foreach ($this->terms as $taxonomy => $list) {
            if (str_starts_with($taxonomy, 'pa_')) {
                $out[$taxonomy] = array_map(fn (WpTerm $t) => \Pine\Commerce\Import\Support\Formatter::decode($t->name), $list);
            }
        }

        return $out;
    }

    /** Decoded names of the product's categories (term-id order). */
    public function categoryNames(): array
    {
        return array_map(fn (WpTerm $t) => \Pine\Commerce\Import\Support\Formatter::decode($t->name), $this->terms['product_cat'] ?? []);
    }
}
