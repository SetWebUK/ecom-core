<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Services\Admin\StoreSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Save one settings group (general / checkout / emails / seo). Rules come from Pine\Commerce\Services\Admin\StoreSettings. */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = \Pine\Commerce\Services\Admin\StoreSettings::groups()[$this->group()] ?? [];

        return (bool) $this->user()?->canAccessAdmin() && (empty($group['admin']) || $this->user()->isAdmin());
    }

    protected function group(): string
    {
        return (string) $this->route('group');
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (StoreSettings::fields($this->group()) as $field) {
            $name = StoreSettings::inputName($field['key']);
            $value = $this->input($name);
            if (in_array($field['type'], ['money', 'decimal'], true) && is_string($value)) {
                $clean[$name] = trim(str_replace(['£', ',', '%', ' '], '', $value));
            } elseif ($field['type'] === 'list' && ! $this->has($name)) {
                $clean[$name] = [];
            } elseif (is_string($value) && ! in_array($field['type'], ['textarea'], true)) {
                $clean[$name] = trim($value);
            }
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return StoreSettings::rules($this->group());
    }

    public function messages(): array
    {
        return [
            'tracking__gtm_id.regex' => 'A Tag Manager ID looks like GTM-ABC1234.',
            'tracking__ga4_id.regex' => 'A Google Analytics 4 ID looks like G-ABC123DEF4.',
            '*.url' => 'Enter a full web address starting with https://',
            '*.regex' => 'This doesn’t look right – check the format.',
        ];
    }

    public function attributes(): array
    {
        return StoreSettings::attributes($this->group());
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (StoreSettings::fields($this->group()) as $field) {
                    $name = StoreSettings::inputName($field['key']);
                    if ($field['type'] !== 'emails' || $validator->errors()->has($name)) {
                        continue;
                    }
                    foreach (StoreSettings::emailList((string) $this->input($name)) as $email) {
                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $validator->errors()->add($name, "“{$email}” isn’t a valid email address.");
                            break;
                        }
                    }
                }
            },
        ];
    }
}
