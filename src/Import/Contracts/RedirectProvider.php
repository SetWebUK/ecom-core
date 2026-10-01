<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\RedirectRule;

/** Redirect rules of a redirect plugin, in the plugin's own evaluation order (first match wins). */
interface RedirectProvider
{
    /** @return iterable<RedirectRule> */
    public function redirects(): iterable;
}
