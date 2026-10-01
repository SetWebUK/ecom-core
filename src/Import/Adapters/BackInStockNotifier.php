<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\StockAlertsStep;

/** Back-in-stock / waitlist plugins (CWG Back In Stock Notifier, WooCommerce Waitlist, YITH Waiting List) – step extras.stock-alerts. */
class BackInStockNotifier extends AbstractAdapter
{
    public function key(): string
    {
        return 'back-in-stock';
    }

    public function label(): string
    {
        return 'Back-in-stock notifications';
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $wp->table('posts')->where('post_type', 'cwginstocknotifier')->exists()
            || $wp->metaKeyExists('woocommerce_waitlist', '_yith_wcwtl_users_list');
    }

    public function steps(): array
    {
        return [StockAlertsStep::class];
    }
}
