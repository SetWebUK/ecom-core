<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

/**
 * Edit one of an order's addresses from the order page (modal). Fields are prefixed with the address type
 * (billing_first_name …, shipping_first_name …) so each modal keeps its own old input; billing also carries the order's
 * email + phone, shipping its delivery phone (shipping_phone). Errors go to the "address" bag so the right modal re-opens.
 */
class OrderAddressRequest extends SalesRequest
{
    protected $errorBag = 'address';

    public function type(): string
    {
        return $this->input('address_type') === 'billing' ? 'billing' : 'shipping';
    }

    public function rules(): array
    {
        $prefix = $this->type().'_';
        $rules = array_merge(['address_type' => ['required', 'in:billing,shipping']], $this->addressRules($prefix), [
            $prefix.'first_name' => ['required', 'string', 'max:100'],
        ]);
        if ($this->type() === 'billing') {
            $rules['email'] = ['required', 'email:rfc', 'max:190'];
            $rules['phone'] = ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()\-.]*$/'];
        } else {
            $rules['shipping_phone'] = ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()\-.]*$/'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Use numbers, spaces and + ( ) - only.', 'shipping_phone.regex' => 'Use numbers, spaces and + ( ) - only.'];
    }

    /** Order attributes to save. */
    public function addressData(): array
    {
        $type = $this->type();
        $data = $this->addressValues($type.'_', $type.'_');
        if ($type === 'billing') {
            $data['email'] = mb_strtolower((string) $this->nullableString('email'));
            $data['phone'] = $this->nullableString('phone');
        } else {
            $data['shipping_phone'] = $this->nullableString('shipping_phone');
        }

        return $data;
    }
}
