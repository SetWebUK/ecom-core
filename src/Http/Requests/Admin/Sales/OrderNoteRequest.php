<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

class OrderNoteRequest extends SalesRequest
{
    protected $errorBag = 'note';

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:private,customer'],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Write a note first.'];
    }

    public function toCustomer(): bool
    {
        return $this->input('type') === 'customer';
    }

    public function noteText(): string
    {
        return trim((string) $this->input('note'));
    }
}
