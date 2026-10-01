<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Models\Order;
use Illuminate\Validation\Rule;

class OrderStatusRequest extends SalesRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_keys(Order::STATUSES))],
            'status_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['status.in' => 'Choose a valid status.'];
    }

    public function attributes(): array
    {
        return ['status_note' => 'note'];
    }
}
