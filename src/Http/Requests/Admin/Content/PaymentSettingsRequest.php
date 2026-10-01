<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Payments\PaymentManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Validator;

/**
 * Payment method settings (administrators only). Keys: payments.{code}.{field}; the cards and fields come from each
 * gateway's adminSettings() (built-in Stripe / PayPal / bank transfer, and client gateways registered with
 * Commerce::gateway()), read back by the gateway (Pine\Commerce\Services\Payments\Gateway::setting()). Secret fields are
 * stored with Crypt::encryptString and never sent back to the browser: leave the box empty to keep the saved value,
 * tick "remove" to clear it.
 */
class PaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * One card per gateway, in checkout order.
     *
     * @return array<string, array{label:string, icon:string, description:string, fields: array<string, array>, webhook: ?array}>
     */
    public static function methods(): array
    {
        $methods = [];
        foreach (app(PaymentManager::class)->all() as $code => $gateway) {
            $settings = $gateway->adminSettings();
            $methods[$code] = [
                'label' => (string) ($settings['label'] ?? $gateway->title()),
                'icon' => (string) ($settings['icon'] ?? 'credit-card'),
                'description' => (string) ($settings['description'] ?? ''),
                'fields' => (array) ($settings['fields'] ?? []),
                'webhook' => $settings['webhook'] ?? null,
            ];
        }

        return $methods;
    }

    /** Current values for the form (secrets as true/false = saved or not). */
    public static function currentValues(): array
    {
        $values = [];
        $firstAccount = static::legacyAccount();
        foreach (static::methods() as $code => $method) {
            foreach ($method['fields'] as $key => $field) {
                $raw = setting("payments.{$code}.{$key}");
                $values[$code][$key] = match ($field['type']) {
                    'bool' => filter_var($raw ?? false, FILTER_VALIDATE_BOOL),
                    'secret' => is_string($raw) && trim($raw) !== '',
                    default => (string) ($raw ?? ($code === 'bacs' ? ($firstAccount[$key] ?? '') : '')),
                };
            }
        }

        return $values;
    }

    /** Enabled / fully configured per method (from the checkout's own gateways). */
    public static function status(): array
    {
        $out = [];
        $manager = app(PaymentManager::class);
        foreach (static::methods() as $code => $method) {
            $gateway = $manager->get($code);
            $out[$code] = [
                'label' => $method['label'],
                'enabled' => filter_var(setting("payments.{$code}.enabled", false), FILTER_VALIDATE_BOOL),
                'configured' => (bool) $gateway?->isConfigured(),
            ];
        }

        return $out;
    }

    protected function prepareForValidation(): void
    {
        $payments = (array) $this->input('payments', []);
        foreach ($payments as $code => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                if (is_string($value)) {
                    $payments[$code][$key] = trim($value);
                }
            }
            if (isset($payments[$code]['sort_code']) && preg_match('/^\d{6}$/', str_replace([' ', '-'], '', $payments[$code]['sort_code']))) {
                $digits = str_replace([' ', '-'], '', $payments[$code]['sort_code']);
                $payments[$code]['sort_code'] = implode('-', str_split($digits, 2));
            }
            if (isset($payments[$code]['account_number'])) {
                $payments[$code]['account_number'] = str_replace(' ', '', $payments[$code]['account_number']);
            }
            if (isset($payments[$code]['iban'])) {
                $payments[$code]['iban'] = strtoupper(str_replace(' ', '', $payments[$code]['iban']));
            }
        }
        $this->merge(['payments' => $payments]);
    }

    public function rules(): array
    {
        $rules = ['payments' => ['required', 'array']];
        foreach (static::methods() as $code => $method) {
            foreach ($method['fields'] as $key => $field) {
                $path = "payments.{$code}.{$key}";
                $rules[$path] = match ($field['type']) {
                    'bool' => ['nullable', 'boolean'],
                    'textarea' => ['nullable', 'string', 'max:2000'],
                    default => ['nullable', 'string', 'max:255'],
                };
                if (! empty($field['pattern'])) {
                    $rules[$path][] = 'regex:'.$field['pattern'];
                }
                if ($field['type'] === 'secret') {
                    $rules["payments.{$code}.{$key}_clear"] = ['nullable', 'boolean'];
                }
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [];
        foreach (static::methods() as $code => $method) {
            foreach ($method['fields'] as $key => $field) {
                if (! empty($field['message'])) {
                    $messages["payments.{$code}.{$key}.regex"] = (string) $field['message'];
                }
            }
        }

        return $messages;
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach (static::methods() as $code => $method) {
            foreach ($method['fields'] as $key => $field) {
                $attributes["payments.{$code}.{$key}"] = mb_strtolower($field['label']);
            }
        }

        return $attributes;
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (app(PaymentManager::class)->all() as $code => $gateway) {
                    $gateway->validateSettings((array) $this->input("payments.{$code}", []), $validator);
                }
            },
        ];
    }

    /** A failed save must not flash any secret field back into the session (the handler only knows the core ones). */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $request = app('request');
        $payments = $request->request->all('payments');
        foreach (static::methods() as $code => $method) {
            foreach ($method['fields'] as $key => $field) {
                if ($field['type'] === 'secret') {
                    unset($payments[$code][$key]);
                }
            }
        }
        $request->request->set('payments', $payments);

        parent::failedValidation($validator);
    }

    /** Save everything; returns how many settings changed. */
    public function save(): int
    {
        $changed = 0;
        $set = function (string $key, mixed $value) use (&$changed) {
            $current = Setting::query()->where('key', $key)->value('value');
            $stored = is_bool($value) ? json_encode($value) : (string) $value;
            if ($current === $stored || ($current === null && $stored === '')) {
                return;
            }
            Setting::set($key, $value);
            $changed++;
        };

        foreach (static::methods() as $code => $method) {
            foreach ($method['fields'] as $key => $field) {
                $input = $this->input("payments.{$code}.{$key}");
                $fullKey = "payments.{$code}.{$key}";
                switch ($field['type']) {
                    case 'bool':
                        $set($fullKey, filter_var($input, FILTER_VALIDATE_BOOL));
                        break;
                    case 'secret':
                        if (is_string($input) && $input !== '') {
                            if (static::decrypt(setting($fullKey)) !== $input) {
                                Setting::set($fullKey, Crypt::encryptString($input));
                                $changed++;
                            }
                        } elseif ($this->boolean("payments.{$code}.{$key}_clear")) {
                            $set($fullKey, '');
                        }
                        break;
                    default:
                        $set($fullKey, is_string($input) ? str_replace("\r\n", "\n", $input) : '');
                }
            }
        }
        // The single account entered here replaces an imported list of accounts
        if (filled(setting('payments.bacs.accounts'))) {
            Setting::set('payments.bacs.accounts', '');
            $changed++;
        }

        return $changed;
    }

    protected static function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    /** First account of an imported payments.bacs.accounts list, to pre-fill the form. */
    protected static function legacyAccount(): array
    {
        $list = setting('payments.bacs.accounts');
        if (is_string($list)) {
            $list = json_decode($list, true);
        }

        return is_array($list) && isset($list[0]) && is_array($list[0]) ? $list[0] : [];
    }
}
