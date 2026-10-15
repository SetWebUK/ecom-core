<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

class FulfilOrderRequest extends SalesRequest
{
    /** "Other…" in the carrier drop-down: the typed name is the carrier. */
    protected function prepareForValidation(): void
    {
        if ($this->input('tracking_carrier') === '__other') {
            $this->merge(['tracking_carrier' => trim((string) $this->input('tracking_carrier_other'))]);
        }
    }

    public function rules(): array
    {
        return [
            'tracking_carrier' => ['nullable', 'string', 'max:60'],
            'tracking_carrier_other' => ['nullable', 'string', 'max:60'],
            'tracking_number' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9 \-\/_.]*$/'],
        ];
    }

    public function messages(): array
    {
        return ['tracking_number.regex' => 'Use letters, numbers, spaces and dashes only.'];
    }

    public function attributes(): array
    {
        return ['tracking_carrier' => 'carrier', 'tracking_carrier_other' => 'carrier name', 'tracking_number' => 'tracking number'];
    }
}
