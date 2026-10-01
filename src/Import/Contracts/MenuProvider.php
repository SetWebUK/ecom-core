<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\MenuTree;
use Pine\Commerce\Import\ImportContext;

/** Menus that are not WordPress nav menus (e.g. rebuilt from a rendered Elementor header). */
interface MenuProvider
{
    /** @return iterable<MenuTree> each replaces the menu at its location */
    public function menus(ImportContext $ctx): iterable;
}
