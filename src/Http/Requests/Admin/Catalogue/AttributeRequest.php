<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Models\Attribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Create/update a product attribute (e.g. Memory). attributeData() is the only thing the controller saves. */
class AttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');
        $name = $this->input('name');
        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'slug' => is_string($slug) && trim($slug) !== '' ? Str::slug(trim($slug)) : (is_string($name) ? Str::limit(Str::slug($name), 180, '') : ''),
        ]);
    }

    public function rules(): array
    {
        $attribute = $this->route('attribute');

        return [
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('attributes', 'slug')->ignore($attribute instanceof Attribute ? $attribute->id : null)],
            'is_filterable' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the attribute a name, e.g. “Memory”.',
            'slug.unique' => 'Another attribute already uses this slug.',
            'slug.regex' => 'Use lower-case letters, numbers and single dashes only.',
        ];
    }

    public function attributeData(): array
    {
        return [
            'name' => $this->input('name'),
            'slug' => $this->input('slug'),
            'is_filterable' => $this->boolean('is_filterable'),
        ];
    }
}
