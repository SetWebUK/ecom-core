<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Services\Admin\Countries;
use Pine\Commerce\Services\Shipping\CostExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a delivery option offered at checkout (Pine\Commerce\Services\Shipping\ShippingRates): its zone, type
 * (flat rate, free shipping, weight/price table, local pickup) and the type's settings. Posting without a type keeps
 * the pre-zones behaviour: a flat cost per order.
 */
class ShippingMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['cost', 'min_order_amount'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(str_replace(['£', ',', ' '], '', $this->input($field)));
            }
        }
        // a formula typed into the price box goes to cost_formula
        if (isset($clean['cost']) && $clean['cost'] !== '' && ! is_numeric($clean['cost']) && ! $this->filled('cost_formula')) {
            $clean['cost_formula'] = trim((string) $this->input('cost'));
            $clean['cost'] = '0';
        }
        $code = is_string($this->input('code')) && trim($this->input('code')) !== '' ? $this->input('code') : (string) $this->input('name');
        $clean['code'] = Str::slug($code, '_');
        if (! $this->filled('countries')) {
            $clean['countries'] = [];
        }
        if (! $this->filled('type')) {
            $clean['type'] = 'flat_rate';
        }
        // only a flat rate without a formula needs a price; other types store 0
        $type = $clean['type'] ?? $this->input('type');
        $cost = $clean['cost'] ?? $this->input('cost');
        if (($cost === null || $cost === '') && ($type !== 'flat_rate' || $this->filled('cost_formula') || isset($clean['cost_formula']))) {
            $clean['cost'] = '0';
        }
        $rates = [];
        foreach ((array) $this->input('rates', []) as $row) {
            if (is_array($row) && collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty()) {
                $rates[] = array_map(fn ($v) => is_string($v) ? trim(str_replace(['£', ','], '', $v)) : $v, $row);
            }
        }
        $clean['rates'] = $rates;
        $this->merge($clean);
    }

    public function rules(): array
    {
        $method = $this->route('shippingMethod');

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('shipping_methods', 'code')->ignore($method instanceof ShippingMethod ? $method->id : null)],
            'description' => ['nullable', 'string', 'max:1000'],
            'shipping_zone_id' => ['nullable', 'integer', 'exists:shipping_zones,id'],
            'type' => ['required', Rule::in(array_keys(ShippingMethod::TYPES))],
            'tax_status' => ['nullable', Rule::in(['taxable', 'none'])],
            'cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999.99'],
            'cost_formula' => ['nullable', 'string', 'max:255'],
            'calculation' => ['nullable', Rule::in(['order', 'item', 'class'])],
            'class_costs' => ['nullable', 'array', 'max:200'],
            'class_costs.*' => ['nullable', 'string', 'max:255'],
            'no_class_cost' => ['nullable', 'string', 'max:255'],
            'class_mode' => ['nullable', Rule::in(['sum', 'max'])],
            'requires' => ['nullable', Rule::in(['', 'coupon', 'min_amount', 'either', 'both'])],
            'ignore_discounts' => ['nullable', 'boolean'],
            'rates' => ['array', 'max:100'],
            'rates.*.min' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rates.*.max' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rates.*.cost' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'min_order_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'countries' => ['array', 'max:300'],
            'countries.*' => ['string', Rule::in(array_keys(Countries::options()))],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (['cost_formula' => $this->input('cost_formula'), 'no_class_cost' => $this->input('no_class_cost')] + collect((array) $this->input('class_costs', []))->mapWithKeys(fn ($v, $k) => ['class_costs.'.$k => $v])->all() as $key => $formula) {
                if (is_string($formula) && trim($formula) !== '' && ! CostExpression::valid($formula)) {
                    $validator->errors()->add($key, 'Use numbers, + − * / ( ) and [qty], [cost] or [fee percent="10"] only.');
                }
            }
            if (in_array($this->input('type'), ['weight_table', 'price_table'], true) && ! $this->input('rates')) {
                $validator->errors()->add('rates', 'Add at least one band.');
            }
            foreach ((array) $this->input('rates', []) as $i => $row) {
                if (is_numeric($row['min'] ?? null) && is_numeric($row['max'] ?? null) && (float) $row['max'] < (float) $row['min']) {
                    $validator->errors()->add("rates.$i.max", 'The “up to” value must be at least the “from” value.');
                }
            }
            if (in_array($this->input('requires'), ['min_amount', 'either', 'both'], true) && ! $this->filled('min_order_amount')) {
                $validator->errors()->add('min_order_amount', 'Enter the minimum order amount for free shipping.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another delivery option already uses this code.',
            'code.regex' => 'Use lower-case letters, numbers and underscores.',
            '*.decimal' => 'Use at most 2 decimal places.',
            'rates.*.cost.required' => 'Every band needs a price.',
        ];
    }

    public function attributes(): array
    {
        return ['min_order_amount' => 'minimum order', 'is_active' => 'active', 'shipping_zone_id' => 'zone', 'rates.*.cost' => 'band price', 'rates.*.min' => 'from', 'rates.*.max' => 'up to'];
    }

    public function methodData(): array
    {
        $type = (string) $this->input('type', 'flat_rate');
        $formula = trim((string) $this->input('cost_formula', ''));
        $settings = match ($type) {
            'flat_rate' => array_filter([
                'cost' => $formula !== '' ? $formula : null,
                'calculation' => $this->input('calculation') ?: 'order',
                'class_costs' => array_filter(array_map(fn ($v) => trim((string) $v), (array) $this->input('class_costs', [])), fn ($v) => $v !== '') ?: null,
                'no_class_cost' => trim((string) $this->input('no_class_cost', '')) ?: null,
                'class_mode' => $this->input('calculation') === 'class' ? ($this->input('class_mode') ?: 'sum') : null,
            ], fn ($v) => $v !== null),
            'free_shipping' => ['requires' => (string) $this->input('requires', ''), 'ignore_discounts' => $this->boolean('ignore_discounts')],
            'weight_table', 'price_table' => ['rates' => collect((array) $this->input('rates', []))->map(fn ($r) => [
                'min' => is_numeric($r['min'] ?? null) ? (float) $r['min'] : 0.0,
                'max' => is_numeric($r['max'] ?? null) ? (float) $r['max'] : null,
                'cost' => round((float) ($r['cost'] ?? 0), 2),
            ])->sortBy('min')->values()->all()],
            default => [],
        };

        $data = [
            'name' => trim($this->input('name')),
            'code' => $this->input('code'),
            'description' => $this->filled('description') ? trim($this->input('description')) : null,
            'type' => $type,
            'tax_status' => $this->input('tax_status') === 'none' ? 'none' : 'taxable',
            'settings' => $settings ?: null,
            'cost' => in_array($type, ['flat_rate', 'local_pickup'], true) && $formula === '' ? round((float) $this->input('cost'), 2) : 0,
            'min_order_amount' => $this->filled('min_order_amount') && (float) $this->input('min_order_amount') > 0 ? round((float) $this->input('min_order_amount'), 2) : null,
            'countries' => array_values(array_unique((array) $this->input('countries', []))) ?: null,
            'is_active' => $this->boolean('is_active'),
        ];
        if ($this->has('shipping_zone_id')) {
            $data['shipping_zone_id'] = $this->input('shipping_zone_id') ? (int) $this->input('shipping_zone_id') : null;
        }

        return $data;
    }
}
