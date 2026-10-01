<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Models\User;
use Illuminate\Validation\Rule;

/**
 * Create a customer (page) / edit a customer's contact details (modal on the customer page, "customer" error bag).
 * Staff accounts can only be edited by administrators.
 */
class CustomerRequest extends SalesRequest
{
    protected function customer(): ?User
    {
        $customer = $this->route('customer');

        return $customer instanceof User ? $customer : null;
    }

    public function authorize(): bool
    {
        $customer = $this->customer();
        if ($customer && $customer->isStaff() && ! $this->user()?->isAdmin()) {
            return false;
        }

        return parent::authorize();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if ($this->customer()) {
            $this->errorBag = 'customer';
        }
    }

    public function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($this->customer()?->id)],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()\-.]*$/'],
            'marketing_opt_in' => ['boolean'],
        ];
        if (! $this->customer()) {
            $rules += $this->addressRules('billing_') + [
                'send_invite' => ['boolean'],
                'admin_note' => ['nullable', 'string', 'max:5000'],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Another customer already uses this email address.',
            'phone.regex' => 'Use numbers, spaces and + ( ) - only.',
        ];
    }

    public function customerData(): array
    {
        $first = trim((string) $this->input('first_name'));
        $last = (string) $this->nullableString('last_name');

        return [
            'first_name' => $first,
            'last_name' => $last ?: null,
            'name' => trim($first.' '.$last),
            'email' => (string) $this->input('email'),
            'phone' => $this->nullableString('phone'),
            'marketing_opt_in' => $this->boolean('marketing_opt_in'),
        ];
    }

    /** Billing address from the create form, or null when left empty. */
    public function billingAddress(): ?array
    {
        $values = $this->addressValues('billing_');
        $filled = array_filter(array_diff_key($values, ['country' => 1]));
        if (! $filled) {
            return null;
        }

        $values['first_name'] = $values['first_name'] ?: trim((string) $this->input('first_name'));
        $values['last_name'] = $values['last_name'] ?: $this->nullableString('last_name');

        return $values + ['email' => $this->input('email'), 'phone' => $this->nullableString('phone')];
    }
}
