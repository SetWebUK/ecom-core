<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Models\User;

/**
 * A customer's default billing / shipping address (modal on the customer page). Fields are prefixed with the type
 * (billing_first_name …) so each modal keeps its own old input; errors go to the "address" bag.
 */
class CustomerAddressRequest extends SalesRequest
{
    protected $errorBag = 'address';

    public function authorize(): bool
    {
        $customer = $this->route('customer');
        if ($customer instanceof User && $customer->isStaff() && ! $this->user()?->isAdmin()) {
            return false;
        }

        return parent::authorize();
    }

    public function type(): string
    {
        return $this->input('address_type') === 'shipping' ? 'shipping' : 'billing';
    }

    public function rules(): array
    {
        $p = $this->type().'_';

        return array_merge(['address_type' => ['required', 'in:billing,shipping']], $this->addressRules($p), [
            $p.'first_name' => ['required', 'string', 'max:100'],
            $p.'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()\-.]*$/'],
        ]);
    }

    public function messages(): array
    {
        return ['*.regex' => 'Use numbers, spaces and + ( ) - only.'];
    }

    public function addressData(): array
    {
        $p = $this->type().'_';

        return $this->addressValues($p) + ['phone' => $this->nullableString($p.'phone')];
    }
}
