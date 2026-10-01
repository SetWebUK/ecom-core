<?php

namespace Pine\Commerce\Services\Tax;

/** Where tax (and shipping) is worked out for: country code + optional state/county, postcode and city. */
final class TaxLocation
{
    public readonly string $country;

    public function __construct(string $country, public readonly string $state = '', public readonly string $postcode = '', public readonly string $city = '')
    {
        $this->country = strtoupper(trim($country));
    }

    /** From an address array (country, county|state, postcode, city); null when it has no country. */
    public static function fromAddress(?array $address): ?self
    {
        $country = strtoupper(trim((string) ($address['country'] ?? '')));
        if ($country === '') {
            return null;
        }

        return new self($country, trim((string) ($address['state'] ?? $address['county'] ?? '')), trim((string) ($address['postcode'] ?? '')), trim((string) ($address['city'] ?? '')));
    }

    public function key(): string
    {
        return implode('|', [$this->country, strtoupper($this->state), strtoupper(str_replace(' ', '', $this->postcode)), strtoupper($this->city)]);
    }
}
