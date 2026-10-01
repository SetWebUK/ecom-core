<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Services\Admin\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Inline quick edit (Products list, Inventory page) of one product or variant: prices and stock.
 * Only the keys that are sent are changed. JSON in, JSON out.
 */
class QuickUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['regular_price', 'sale_price', 'stock_quantity'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = trim(str_replace(['£', ',', ' '], '', $this->input($field)));
                $clean[$field] = $value === '' ? null : $value;
            }
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'regular_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'stock_quantity' => ['sometimes', 'nullable', 'integer', 'min:-99999', 'max:9999999'],
            'manage_stock' => ['sometimes', 'boolean'],
            'stock_status' => ['sometimes', Rule::in(array_keys(OrderStatus::STOCK_STATUSES))],
        ];
    }

    public function messages(): array
    {
        return [
            '*.decimal' => 'Use at most 2 decimal places.',
            'stock_quantity.integer' => 'Stock must be a whole number.',
            'regular_price.numeric' => 'Enter a valid price.',
            'sale_price.numeric' => 'Enter a valid sale price.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $item = $this->route('product') ?? $this->route('variation');
                $regular = $this->has('regular_price') ? $this->input('regular_price') : $item?->regular_price;
                $sale = $this->has('sale_price') ? $this->input('sale_price') : $item?->sale_price;
                if ($sale !== null && $sale !== '' && ($regular === null || $regular === '' || (float) $sale >= (float) $regular)) {
                    $validator->errors()->add('sale_price', 'The sale price must be lower than the regular price.');
                }
            },
        ];
    }

    /** Normalised changes (only the fields that were sent). */
    public function changes(): array
    {
        $changes = [];
        foreach (['regular_price', 'sale_price'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = is_numeric($this->input($field)) ? round((float) $this->input($field), 2) : null;
            }
        }
        if ($this->has('stock_quantity')) {
            $qty = $this->input('stock_quantity');
            $changes['stock_quantity'] = is_numeric($qty) ? (int) $qty : null;
            $changes['manage_stock'] = $this->has('manage_stock') ? $this->boolean('manage_stock') : is_numeric($qty);
        } elseif ($this->has('manage_stock')) {
            $changes['manage_stock'] = $this->boolean('manage_stock');
        }
        if ($this->has('stock_status')) {
            $changes['stock_status'] = $this->input('stock_status');
        }

        return $changes;
    }
}
