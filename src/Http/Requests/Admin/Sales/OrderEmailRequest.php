<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Services\Admin\OrderManager;
use Illuminate\Validation\Rule;

class OrderEmailRequest extends SalesRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'string', Rule::in(array_keys(OrderManager::resendableEmails()))]];
    }

    public function messages(): array
    {
        return ['email.required' => 'Choose which email to send.', 'email.in' => 'Choose which email to send.'];
    }
}
