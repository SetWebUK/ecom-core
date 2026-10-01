<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\Menu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create a menu (name + location) or rename it. */
class MenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('location'))) {
            $this->merge(['location' => strtolower(trim($this->input('location')))]);
        }
    }

    public function rules(): array
    {
        $menu = $this->route('menu');

        return [
            'name' => ['required', 'string', 'max:120'],
            'location' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('menus', 'location')->ignore($menu instanceof Menu ? $menu->id : null)],
        ];
    }

    public function messages(): array
    {
        return [
            'location.unique' => 'Another menu already uses this location. Edit that menu instead.',
            'location.regex' => 'Use lower-case letters, numbers and underscores.',
        ];
    }

    public function menuData(): array
    {
        return ['name' => trim($this->input('name')), 'location' => $this->input('location')];
    }
}
