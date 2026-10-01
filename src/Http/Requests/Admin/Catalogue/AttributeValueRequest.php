<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\AttributeValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Add or rename one attribute value (Attributes page, or inline from the product editor via JSON). */
class AttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('value'))) {
            $this->merge(['value' => trim(preg_replace('/\s+/', ' ', $this->input('value')))]);
        }
    }

    public function rules(): array
    {
        return ['value' => ['required', 'string', 'max:190']];
    }

    public function messages(): array
    {
        return ['value.required' => 'Enter a value, e.g. “16GB”.'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('value')) {
                    return;
                }
                $attribute = $this->route('attribute');
                $current = $this->route('value');
                if (! $attribute instanceof Attribute) {
                    return;
                }
                $duplicate = $attribute->values()->whereRaw('LOWER(value) = ?', [mb_strtolower($this->input('value'))])
                    ->when($current instanceof AttributeValue, fn ($q) => $q->whereKeyNot($current->id))->first();
                if ($duplicate && ($current instanceof AttributeValue || ! $this->expectsJson())) { // adding from the product editor reuses the existing value
                    $validator->errors()->add('value', "“{$duplicate->value}” already exists – use Merge to combine duplicates.");
                }
            },
        ];
    }
}
