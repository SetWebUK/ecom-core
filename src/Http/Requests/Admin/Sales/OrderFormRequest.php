<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\OrderManager;
use Pine\Commerce\Services\Admin\PaymentMethods;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create order (POST) and edit order (PUT). Lines are products (+ variation) with an optional price override, or a
 * custom line (name + price). Totals are never taken from the browser: OrderManager re-prices everything with
 * OrderPricing, exactly like the live preview.
 */
class OrderFormRequest extends SalesRequest
{
    protected array $moneyFields = ['manual_discount', 'shipping_cost'];

    protected function isCreate(): bool
    {
        return ! ($this->route('order') instanceof Order);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $lines = $this->input('lines');
        if (is_array($lines)) {
            foreach ($lines as $i => $line) {
                if (is_array($line) && is_string($line['unit_price'] ?? null)) {
                    $lines[$i]['unit_price'] = trim(str_replace(['£', ',', ' '], '', $line['unit_price']));
                }
            }
            $this->merge(['lines' => array_values(array_filter($lines, 'is_array'))]);
        }
        foreach (['email', 'billing_country', 'shipping_country'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'];
        $sameAsBilling = $this->boolean('shipping_same_as_billing');

        $rules = array_merge([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()\-.]*$/'],
            'shipping_same_as_billing' => ['boolean'],
            'shipping_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()\-.]*$/'],

            'lines' => [$this->isCreate() ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'lines.*.variation_id' => ['nullable', 'integer', Rule::exists('product_variations', 'id')],
            'lines.*.order_item_id' => ['nullable', 'integer'],
            'lines.*.name' => ['nullable', 'required_without:lines.*.product_id', 'string', 'max:190'],
            'lines.*.sku' => ['nullable', 'string', 'max:100'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.unit_price' => $money,

            'coupon_code' => ['nullable', 'string', 'max:100'],
            'manual_discount' => $money,
            'shipping_method' => ['nullable', 'string', Rule::exists('shipping_methods', 'code')],
            'shipping_cost' => $money,
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ], $this->addressRules('billing_'), $this->addressRules('shipping_'), [
            'billing_first_name' => ['required', 'string', 'max:100'],
            'billing_last_name' => ['required', 'string', 'max:100'],
        ]);

        if ($this->isCreate()) {
            $rules += [
                'status' => ['required', Rule::in(array_keys(OrderManager::MANUAL_STATUSES))],
                'payment_method' => ['nullable', Rule::in(array_keys(PaymentMethods::MANUAL_OPTIONS))],
                'transaction_id' => ['nullable', 'string', 'max:100'],
                'private_note' => ['nullable', 'string', 'max:5000'],
                'reduce_stock' => ['boolean'],
                'send_invoice' => ['boolean'],
                'save_addresses' => ['boolean'],
            ];
        }
        if (! $sameAsBilling) {
            $rules['shipping_first_name'] = ['nullable', 'required_with:shipping_address_1', 'string', 'max:100'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one product.',
            'lines.min' => 'Add at least one product.',
            'lines.*.product_id.exists' => 'One of the products no longer exists – remove it and add it again.',
            'lines.*.variation_id.exists' => 'One of the product options no longer exists.',
            'lines.*.name.required_without' => 'Give the custom item a name.',
            'lines.*.quantity.min' => 'Quantities must be at least 1.',
            'lines.*.unit_price.decimal' => 'Prices can have at most 2 decimal places.',
            '*.decimal' => 'Use at most 2 decimal places.',
            'phone.regex' => 'Use numbers, spaces and + ( ) - only.',
            'shipping_phone.regex' => 'Use numbers, spaces and + ( ) - only.',
            'shipping_method.exists' => 'Choose a shipping method from the list.',
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'user_id' => 'customer',
            'manual_discount' => 'discount',
            'shipping_cost' => 'shipping cost',
            'customer_note' => 'note from the customer',
            'private_note' => 'private note',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('lines') || ! is_array($this->input('lines'))) {
                return;
            }
            foreach ($this->input('lines') as $i => $line) {
                if (empty($line['product_id']) && ($line['unit_price'] ?? '') === '') {
                    $validator->errors()->add("lines.$i.unit_price", 'Enter a price for “'.($line['name'] ?? 'the custom item').'”.');
                }
            }
        }];
    }

    /** Normalised data for OrderManager::createManualOrder() / updateOrder(). */
    public function orderData(): array
    {
        $same = $this->boolean('shipping_same_as_billing');
        $data = array_merge([
            'user_id' => $this->filled('user_id') ? (int) $this->input('user_id') : null,
            'email' => mb_strtolower((string) $this->input('email')),
            'phone' => $this->nullableString('phone'),
            'shipping_same_as_billing' => $same,
            'shipping_phone' => $same ? $this->nullableString('phone') : $this->nullableString('shipping_phone'),
            'coupon_code' => $this->nullableString('coupon_code'),
            'manual_discount' => $this->filled('manual_discount') ? round((float) $this->input('manual_discount'), 2) : null,
            'shipping_method' => $this->nullableString('shipping_method'),
            'shipping_cost' => $this->filled('shipping_cost') ? round((float) $this->input('shipping_cost'), 2) : null,
            'customer_note' => $this->nullableString('customer_note'),
        ], $this->addressValues('billing_', 'billing_'), $same ? [] : $this->addressValues('shipping_', 'shipping_'));

        if ($this->has('lines')) {
            $data['lines'] = array_map(fn (array $line) => [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'variation_id' => ! empty($line['variation_id']) ? (int) $line['variation_id'] : null,
                'order_item_id' => ! empty($line['order_item_id']) ? (int) $line['order_item_id'] : null,
                'name' => empty($line['product_id']) ? trim((string) ($line['name'] ?? '')) : null,
                'sku' => empty($line['product_id']) ? (trim((string) ($line['sku'] ?? '')) ?: null) : null,
                'quantity' => (int) $line['quantity'],
                'unit_price' => isset($line['unit_price']) && $line['unit_price'] !== '' ? round((float) $line['unit_price'], 2) : null,
            ], (array) $this->input('lines'));
            // Custom lines only need a name when they have no product
            $data['lines'] = array_map(fn (array $l) => $l['product_id'] ? array_diff_key($l, ['name' => 1, 'sku' => 1]) : $l, $data['lines']);
        }

        if ($this->isCreate()) {
            $data += [
                'status' => $this->input('status'),
                'payment_method' => $this->nullableString('payment_method'),
                'transaction_id' => $this->nullableString('transaction_id'),
                'private_note' => $this->nullableString('private_note'),
                'reduce_stock' => $this->boolean('reduce_stock'),
                'send_invoice' => $this->boolean('send_invoice') && $this->input('status') === 'pending',
                'save_addresses' => $this->boolean('save_addresses'),
            ];
        }

        return $data;
    }
}
