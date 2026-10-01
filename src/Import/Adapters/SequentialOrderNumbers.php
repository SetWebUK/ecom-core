<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\OrderNumberProvider;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * Sequential / custom order number plugins: WebToffee (wt-woocommerce-sequential-order-numbers: _order_number +
 * counter wt_last_order_number), SkyVerge (woocommerce-sequential-order-numbers[-pro]: _order_number /
 * _order_number_formatted), Custom Order Numbers (_alg_wc_custom_order_number), Booster (_wcj_order_number).
 * Also active when those meta keys exist although the plugin was deactivated (the numbers were shown to customers).
 */
class SequentialOrderNumbers extends AbstractAdapter implements OrderNumberProvider
{
    public const META_KEYS = ['_order_number_formatted', '_order_number', '_alg_wc_custom_order_number', '_wcj_order_number'];

    public const COUNTERS = ['wt_last_order_number', 'alg_wc_custom_order_numbers_counter', 'wcj_order_number_counter'];

    public function key(): string
    {
        return 'sequential-order-numbers';
    }

    public function label(): string
    {
        return 'Sequential order numbers';
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        if ($site->hasPlugin('wt-woocommerce-sequential-order-numbers/*', 'woocommerce-sequential-order-numbers/*',
            'woocommerce-sequential-order-numbers-pro/*', 'custom-order-numbers-for-woocommerce/*', 'woocommerce-jetpack/*', 'booster-plus-for-woocommerce/*')) {
            return true;
        }
        if ($site->ordersStorage === 'hpos') {
            return $wp->table('wc_orders_meta')->whereIn('meta_key', self::META_KEYS)->exists();
        }

        return $wp->metaKeyExists(...self::META_KEYS);
    }

    public function orderNumber(WcOrder $order): ?string
    {
        foreach (self::META_KEYS as $key) {
            $value = trim((string) ($order->meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function lastIssuedNumber(): ?int
    {
        $wp = $this->ctx->wp;
        if ($this->ctx->site->ordersStorage === 'hpos') {
            $max = (int) $wp->db()->selectOne('SELECT MAX(CAST(meta_value AS UNSIGNED)) AS n FROM '.$wp->t('wc_orders_meta').' WHERE meta_key = ?', ['_order_number'])->n;
        } else {
            $max = (int) $wp->db()->selectOne(
                'SELECT MAX(CAST(pm.meta_value AS UNSIGNED)) AS n FROM '.$wp->t('postmeta').' pm
                 JOIN '.$wp->t('posts').' p ON p.ID = pm.post_id
                 WHERE p.post_type = ? AND pm.meta_key = ?', ['shop_order', '_order_number'])->n;
        }
        foreach (self::COUNTERS as $option) {
            $max = max($max, (int) $wp->option($option, 0));
        }

        return $max ?: null;
    }
}
