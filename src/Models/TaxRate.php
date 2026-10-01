<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Pine\Commerce\Services\Admin\Countries;

/**
 * One tax rate: a percentage for a tax class in a country (optionally a state/county and postcode/city patterns).
 * Matching follows WooCommerce: for each priority the most specific matching rate wins; rates of different priorities
 * are added together, compound rates are charged on top of the others (Pine\Commerce\Services\Tax\TaxRates).
 */
class TaxRate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['rate' => 'float', 'priority' => 'integer', 'compound' => 'boolean', 'shipping' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::saved(fn () => \Pine\Commerce\Services\Tax\TaxRates::flush());
        static::deleted(fn () => \Pine\Commerce\Services\Tax\TaxRates::flush());
    }

    /** "VAT 20%" – the label shown on receipts (name + rate when the name has no % in it). */
    public function label(): string
    {
        $name = trim((string) $this->name) ?: (string) setting('tax.label', 'Tax');

        return str_contains($name, '%') ? $name : $name.' '.static::formatRate($this->rate).'%';
    }

    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
    }

    /** @return list<string> */
    public function postcodeList(): array
    {
        return static::lines($this->postcodes);
    }

    /** @return list<string> */
    public function cityList(): array
    {
        return static::lines($this->cities);
    }

    public function locationLabel(): string
    {
        $parts = [$this->country ? (Countries::name($this->country) ?? $this->country) : 'Every country'];
        if ($this->state !== '' && $this->state !== null) {
            $parts[] = $this->state;
        }
        if ($p = $this->postcodeList()) {
            $parts[] = implode(', ', array_slice($p, 0, 4)).(count($p) > 4 ? ' …' : '');
        }
        if ($c = $this->cityList()) {
            $parts[] = implode(', ', array_slice($c, 0, 3)).(count($c) > 3 ? ' …' : '');
        }

        return implode(' · ', $parts);
    }

    /** @return list<string> one entry per line / semicolon, trimmed */
    public static function lines(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string) $value) ?: []), fn ($v) => $v !== ''));
    }
}
