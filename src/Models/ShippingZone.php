<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pine\Commerce\Services\Admin\Countries;
use Pine\Commerce\Support\PostcodeMatcher;

/**
 * A shipping zone: countries (or "GB:ENG"-style country:region codes) plus optional postcode patterns. Checkout
 * uses the FIRST zone (sort_order) that matches the delivery address and offers that zone's methods. A zone with no
 * regions matches every address ("Everywhere" / rest of the world) – keep it last.
 */
class ShippingZone extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['regions' => 'array', 'sort_order' => 'integer'];

    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return list<string> */
    public function regionList(): array
    {
        return array_values(array_filter(array_map(fn ($r) => strtoupper(trim((string) $r)), (array) $this->regions)));
    }

    /** @return list<string> */
    public function postcodeList(): array
    {
        return TaxRate::lines($this->postcodes);
    }

    public function matches(string $country, ?string $state = null, ?string $postcode = null): bool
    {
        $country = strtoupper(trim($country));
        $regions = $this->regionList();
        if ($regions) {
            $state = strtoupper(trim((string) $state));
            $hit = false;
            foreach ($regions as $region) {
                [$c, $s] = array_pad(explode(':', $region, 2), 2, null);
                if ($c === $country && ($s === null || ($state !== '' && $s === $state))) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }
        $patterns = $this->postcodeList();

        return ! $patterns || (trim((string) $postcode) !== '' && PostcodeMatcher::matchesAny((string) $postcode, $patterns, $country));
    }

    public function regionsLabel(): string
    {
        $regions = $this->regionList();
        if (! $regions) {
            return 'Everywhere';
        }
        $names = array_map(fn ($r) => str_contains($r, ':') ? $r : (Countries::name($r) ?? $r), $regions);

        return implode(', ', array_slice($names, 0, 6)).(count($names) > 6 ? ' and '.(count($names) - 6).' more' : '');
    }
}
