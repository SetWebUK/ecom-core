<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\ProductMapper;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/** Cost-of-goods plugins (WPFactory _alg_wc_cog_cost, Ni _ni_cost_goods, SkyVerge _wc_cog_cost) → products.cost_price. */
class CostOfGoods extends AbstractAdapter implements ProductMapper
{
    public const META_KEYS = ['_alg_wc_cog_cost', '_ni_cost_goods', '_wc_cog_cost'];

    public function key(): string
    {
        return 'cost-of-goods';
    }

    public function label(): string
    {
        return 'Cost of goods';
    }

    public function priority(): int
    {
        return 40;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $wp->metaKeyExists(...self::META_KEYS);
    }

    public function mapProduct(array $row, WpProduct $product, ImportContext $ctx): array
    {
        $m = $product->meta;
        $row['cost_price'] = WordPressSource::decimal($m['_alg_wc_cog_cost'] ?? $m['_ni_cost_goods'] ?? $m['_wc_cog_cost'] ?? null) ?: null;

        return $row;
    }

    public function specRows(WpProduct $product, ImportContext $ctx): ?array
    {
        return null;
    }
}
