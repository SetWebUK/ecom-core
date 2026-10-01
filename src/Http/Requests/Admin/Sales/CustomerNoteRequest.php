<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

class CustomerNoteRequest extends SalesRequest
{
    public function rules(): array
    {
        return ['admin_note' => ['nullable', 'string', 'max:5000']];
    }

    public function attributes(): array
    {
        return ['admin_note' => 'note'];
    }

    public function note(): ?string
    {
        return $this->nullableString('admin_note');
    }
}
