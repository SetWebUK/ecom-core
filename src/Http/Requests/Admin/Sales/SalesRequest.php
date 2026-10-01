<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Services\Admin\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base for the Sales area's form requests: staff-only, trims strings, cleans money inputs ("£1,025.50" -> "1025.50")
 * and shares the address field rules. Controllers save only each request's normalised ...Data() array.
 */
abstract class SalesRequest extends FormRequest
{
    /** Inputs holding money amounts (cleaned before validation). @var list<string> */
    protected array $moneyFields = [];

    public const ADDRESS_FIELDS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'county', 'postcode', 'country'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach ($this->moneyFields as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(str_replace(['£', ',', ' '], '', $this->input($field)));
            }
        }
        $this->merge($clean);
    }

    /** @return array<string, array> rules for "{prefix}first_name" … "{prefix}country" */
    protected function addressRules(string $prefix, bool $required = false): array
    {
        $need = $required ? 'required' : 'nullable';

        return [
            $prefix.'first_name' => [$need, 'string', 'max:100'],
            $prefix.'last_name' => [$need, 'string', 'max:100'],
            $prefix.'company' => ['nullable', 'string', 'max:150'],
            $prefix.'address_1' => [$need, 'string', 'max:190'],
            $prefix.'address_2' => ['nullable', 'string', 'max:190'],
            $prefix.'city' => [$need, 'string', 'max:100'],
            $prefix.'county' => ['nullable', 'string', 'max:100'],
            $prefix.'postcode' => [$need, 'string', 'max:20'],
            $prefix.'country' => ['nullable', 'string', 'size:2', Rule::in(array_keys(Countries::options()))],
        ];
    }

    /** Trimmed address values; UK postcodes upper-cased and spaced ("sn12ab" -> "SN1 2AB"). */
    protected function addressValues(string $prefix, string $as = ''): array
    {
        $values = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $value = $this->input($prefix.$field);
            $value = is_string($value) ? trim($value) : null;
            $values[$as.$field] = $value === '' ? null : $value;
        }
        $country = $values[$as.'country'] ?? null;
        $values[$as.'country'] = $country ? strtoupper($country) : 'GB';
        if ($values[$as.'postcode'] && $values[$as.'country'] === 'GB') {
            $values[$as.'postcode'] = static::ukPostcode($values[$as.'postcode']);
        }

        return $values;
    }

    public static function ukPostcode(string $postcode): string
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $postcode));
        if (preg_match('/^([A-Z]{1,2}\d[A-Z\d]?)(\d[A-Z]{2})$/', $compact, $m)) {
            return $m[1].' '.$m[2];
        }

        return strtoupper(trim($postcode));
    }

    protected function nullableString(string $key): ?string
    {
        $value = $this->input($key);
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach (['billing_' => 'billing ', 'shipping_' => 'shipping ', '' => ''] as $prefix => $label) {
            $attributes[$prefix.'first_name'] = $label.'first name';
            $attributes[$prefix.'last_name'] = $label.'last name';
            $attributes[$prefix.'address_1'] = $label.'address';
            $attributes[$prefix.'address_2'] = $label.'address line 2';
            $attributes[$prefix.'city'] = $label.'town / city';
            $attributes[$prefix.'county'] = $label.'county';
            $attributes[$prefix.'postcode'] = $label.'postcode';
            $attributes[$prefix.'country'] = $label.'country';
            $attributes[$prefix.'company'] = $label.'company';
        }

        return $attributes;
    }
}
