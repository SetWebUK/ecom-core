<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\PostCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PostCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $slug = is_string($this->input('slug')) && trim($this->input('slug')) !== '' ? $this->input('slug') : (string) $this->input('name');
        $this->merge(['slug' => Str::slug($slug), 'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name')]);
    }

    public function rules(): array
    {
        $category = $this->route('postCategory');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('post_categories', 'slug')->ignore($category instanceof PostCategory ? $category->id : null)],
        ];
    }

    public function messages(): array
    {
        return ['slug.unique' => 'Another blog category already uses this address.', 'slug.required' => 'Enter a name.'];
    }

    public function attributes(): array
    {
        return ['slug' => 'URL handle'];
    }

    public function categoryData(): array
    {
        return ['name' => $this->input('name'), 'slug' => $this->input('slug')];
    }
}
