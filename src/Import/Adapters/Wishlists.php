<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\WishlistsStep;

/** YITH WooCommerce Wishlist / TI WooCommerce Wishlist tables – step extras.wishlists. */
class Wishlists extends AbstractAdapter
{
    public function key(): string
    {
        return 'wishlists';
    }

    public function label(): string
    {
        return 'Wishlists (YITH / TI)';
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $wp->hasTable('yith_wcwl') || $wp->hasTable('tinvwl_items');
    }

    public function steps(): array
    {
        return [WishlistsStep::class];
    }
}
