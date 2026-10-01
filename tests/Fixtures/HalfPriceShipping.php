<?php

namespace Pine\Commerce\Tests\Fixtures;

use Illuminate\Support\Collection;
use Pine\Commerce\Contracts\ShippingCalculator;
use Pine\Commerce\Models\ShippingMethod;

/** Half the flat cost, and not offered for more than 5 items (ExtensionApiTest). */
class HalfPriceShipping implements ShippingCalculator
{
    public function cost(ShippingMethod $method, float $cost, Collection $lines, array $context): ?float
    {
        return $lines->sum(fn ($line) => $line->quantity) > 5 ? null : $cost / 2;
    }
}
