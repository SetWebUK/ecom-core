<?php

namespace Pine\Commerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Posted by the bulk bar of <x-admin.table selectable>: ids[] + action.
 * Subclass or construct with the allowed actions:  class CouponBulkRequest extends BulkActionRequest { protected array $actions = […]; }
 * or use it directly and validate 'action' yourself. $request->ids() returns the unique integer ids.
 */
class BulkActionRequest extends FormRequest
{
    /** @var list<string> */
    protected array $actions = ['activate', 'deactivate', 'delete'];

    protected int $max = 500;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.$this->max],
            'ids.*' => ['integer', 'min:1'],
            'action' => ['required', 'string', Rule::in($this->actions)],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one row first.',
            'action.in' => 'Choose an action.',
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('ids', []))));
    }
}
