<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Services\Admin\CategoryTree;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create/update a product category. categoryData() is the only thing the controller saves. */
class CategoryRequest extends FormRequest
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
            'parent_id' => $this->filled('parent_id') ? $this->input('parent_id') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'image' => ['nullable', 'string', 'max:255', 'regex:'.ProductRequest::IMAGE_PATH],
            'description' => ['nullable', 'string', 'max:500000'],
            'extra_content' => ['nullable', 'string', 'max:500000'],
            'is_visible' => ['boolean'],
            'show_in_menu' => ['boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
            'create_redirects' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the category a name.',
            'slug.regex' => 'Use lower-case letters, numbers and single dashes only.',
            'image.regex' => 'Choose a JPG, PNG, GIF, WebP or AVIF image.',
        ];
    }

    public function attributes(): array
    {
        return ['slug' => 'URL handle', 'parent_id' => 'parent category', 'meta_title' => 'page title', 'extra_content' => 'extra content'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();
                if ($errors->hasAny(['slug', 'parent_id'])) {
                    return;
                }
                $category = $this->category();
                $parentId = $this->input('parent_id') !== null ? (int) $this->input('parent_id') : null;
                if ($category && $parentId && CategoryTree::wouldCycle($category->id, $parentId)) {
                    $errors->add('parent_id', 'A category can’t be moved inside itself or one of its sub-categories.');

                    return;
                }
                $path = $this->path();
                if (Category::query()->where('path', $path)->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists()) {
                    $errors->add('slug', 'Another category already uses the address /'.$path.'/.');
                } elseif (Page::query()->where('path', $path)->exists()) {
                    $errors->add('slug', 'A page already uses the address /'.$path.'/ – choose a different URL handle.');
                }
            },
        ];
    }

    public function category(): ?Category
    {
        $category = $this->route('category');

        return $category instanceof Category ? $category : null;
    }

    /** The URL path this category will have after saving. */
    public function path(): string
    {
        $parentId = $this->input('parent_id') !== null ? (int) $this->input('parent_id') : null;
        $parent = $parentId ? Category::query()->find($parentId, ['id', 'path']) : null;

        return ($parent ? $parent->path.'/' : '').$this->input('slug');
    }

    public function categoryData(): array
    {
        $text = fn (string $field) => ($v = trim((string) $this->input($field))) !== '' ? $v : null;

        return [
            'name' => $this->input('name'),
            'slug' => $this->input('slug'),
            'parent_id' => $this->input('parent_id') !== null ? (int) $this->input('parent_id') : null,
            'image' => $text('image'),
            'description' => $this->input('description') ?: null,
            'extra_content' => $this->input('extra_content') ?: null,
            'is_visible' => $this->boolean('is_visible'),
            'show_in_menu' => $this->boolean('show_in_menu'),
            'meta_title' => $text('meta_title'),
            'meta_description' => $text('meta_description'),
        ];
    }
}
