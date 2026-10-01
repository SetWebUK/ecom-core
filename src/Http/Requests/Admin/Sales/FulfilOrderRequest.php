<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

class FulfilOrderRequest extends SalesRequest
{
    public function rules(): array
    {
        return [
            'tracking_carrier' => ['nullable', 'string', 'max:60'],
            'tracking_number' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9 \-\/_.]*$/'],
        ];
    }

    public function messages(): array
    {
        return ['tracking_number.regex' => 'Use letters, numbers, spaces and dashes only.'];
    }

    public function attributes(): array
    {
        return ['tracking_carrier' => 'carrier', 'tracking_number' => 'tracking number'];
    }
}
