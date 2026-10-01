<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Http\Requests\Admin\BulkActionRequest;
use Pine\Commerce\Services\Admin\Catalogue\PriceAdjuster;
use Illuminate\Validation\Rule;

/** Bulk actions of the Products list (bulk bar + the price/sale/category dialogs). */
class ProductBulkRequest extends BulkActionRequest
{
    protected array $actions = [
        'publish', 'draft', 'outofstock', 'instock', 'feature', 'unfeature',
        'add_category', 'remove_category', 'adjust_price', 'set_sale', 'end_sale', 'delete', 'restore',
    ];

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => trim(str_replace(['£', ',', '%', ' '], '', $this->input('amount')))]);
        }
        if (is_string($this->input('percent'))) {
            $this->merge(['percent' => trim(str_replace(['%', ' '], '', $this->input('percent')))]);
        }
    }

    public function rules(): array
    {
        $action = $this->input('action');

        return array_merge(parent::rules(), [
            'category_id' => [Rule::requiredIf(in_array($action, ['add_category', 'remove_category'], true)), 'nullable', 'integer', Rule::exists('categories', 'id')],
            'field' => [Rule::requiredIf($action === 'adjust_price'), 'nullable', Rule::in(array_keys(PriceAdjuster::FIELDS))],
            'mode' => [Rule::requiredIf($action === 'adjust_price'), 'nullable', Rule::in(array_keys(PriceAdjuster::MODES))],
            'amount' => [Rule::requiredIf($action === 'adjust_price'), 'nullable', 'numeric', 'min:0', 'max:999999.99',
                Rule::when(in_array($this->input('mode'), ['increase_percent', 'decrease_percent'], true), ['max:1000'])],
            'round' => ['nullable', Rule::in(array_keys(PriceAdjuster::ROUNDING))],
            'percent' => [Rule::requiredIf($action === 'set_sale'), 'nullable', 'numeric', 'min:1', 'max:95'],
            'sale_ends_at' => ['nullable', 'date'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'category_id.required' => 'Choose a category.',
            'amount.required' => 'Enter an amount.',
            'percent.required' => 'Enter the discount percentage.',
            'percent.max' => 'A sale can be at most 95% off.',
            'amount.max' => 'That amount is too large.',
        ]);
    }

    /** @return array{field:string, mode:string, amount:float, round:string} */
    public function priceParams(): array
    {
        return [
            'field' => (string) $this->input('field'),
            'mode' => (string) $this->input('mode'),
            'amount' => round((float) $this->input('amount'), 2),
            'round' => (string) ($this->input('round') ?: 'none'),
        ];
    }
}
